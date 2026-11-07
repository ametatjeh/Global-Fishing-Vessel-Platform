<?php

namespace App\Services\Maritime;

use App\Models\AuditLog;
use App\Models\DataProvider;
use App\Models\Vessel;
use App\Models\VesselIdentifier;
use App\Services\Providers\AisStreamAdapter;
use App\Services\Providers\GfwApiService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class GfwVesselEnrichmentService
{
    protected GfwApiService $apiService;
    protected string $vesselIdentityDataset;

    public function __construct(GfwApiService $apiService)
    {
        $this->apiService = $apiService;
        $this->vesselIdentityDataset = config('maritime.providers.gfw.dataset', GfwApiService::DATASET_VESSEL_IDENTITY);
    }

    public function isConfigured(): bool
    {
        return $this->apiService->isConfigured();
    }

    /**
     * Enrich a single vessel with GFW v3 data.
     *
     * @param Vessel $vessel
     * @param bool $dryRun
     * @return array Enrichment result details
     */
    public function enrichVessel(Vessel $vessel, bool $dryRun = false): array
    {
        $result = [
            'vessel_id' => $vessel->id,
            'local_identity' => [
                'name' => $vessel->name,
                'mmsi' => $vessel->mmsi,
                'imo' => $vessel->imo,
                'callsign' => $vessel->callsign,
                'flag_state' => $vessel->flag_state,
                'vessel_type' => $vessel->vessel_type,
                'length_meters' => $vessel->length_meters,
                'beam_meters' => $vessel->beam_meters,
                'tonnage' => $vessel->tonnage,
                'engine_power_kw' => $vessel->engine_power_kw,
                'build_year' => $vessel->build_year,
                'owner' => $vessel->owner,
                'operator' => $vessel->operator,
                'verification_status' => $vessel->verification_status,
            ],
            'is_manually_verified' => $vessel->isManuallyVerified(),
            'search_attempts' => [],
            'candidate_found' => false,
            'gfw_candidate' => null,
            'gfw_vessel_id' => null,
            'match_method' => null,
            'match_confidence' => null,
            'matching_identifiers' => [],
            'fields_updated' => [],
            'fields_preserved' => [],
            'fields_missing_in_gfw' => [],
            'conflicts' => [],
            'events_summary' => null,
            'api_permissions' => [],
            'dry_run' => $dryRun,
            'status' => 'not_enriched',
            'message' => '',
        ];

        if (!$this->isConfigured()) {
            $result['status'] = 'not_configured';
            $result['message'] = 'GFW API token is not configured in .env (GFW_API_TOKEN).';
            return $result;
        }

        // 1. Search for matching candidate using hierarchy: IMO -> MMSI -> Vessel name
        $searchResult = $this->findMatchingCandidate($vessel);
        $result['search_attempts'] = $searchResult['attempts'];

        if (!$searchResult['candidate']) {
            // Check if any attempt failed due to rate limiting (HTTP 429)
            $isRateLimited = false;
            foreach ($searchResult['attempts'] as $att) {
                if (($att['status'] ?? 0) === 429) {
                    $isRateLimited = true;
                    break;
                }
            }
            if ($isRateLimited) {
                $result['is_rate_limited'] = true;
                $result['status'] = 'no_match';
                $result['message'] = 'GFW API rate limit exceeded (HTTP 429 Too Many Requests).';
                // Do not mark vessel as permanent no_match in database during rate limiting
                return $result;
            }

            $isReview = ($searchResult['review_required'] ?? false) || str_contains($searchResult['message'] ?? '', 'Multiple candidates');
            $status = $isReview ? 'conflict_detected' : 'no_match';
            $result['status'] = $status;
            $result['sync_status'] = $isReview ? 'match_review_required' : 'not_found';
            $result['message'] = $searchResult['message'] ?? 'No verified GFW candidate found matching local identifiers.';
            if (!$dryRun) {
                $meta = $vessel->metadata ?? [];
                if (!is_array($meta)) {
                    $meta = [];
                }
                $meta['enrichment'] = [
                    'provider' => 'global-fishing-watch',
                    'gfw_vessel_id' => null,
                    'dataset' => $this->vesselIdentityDataset,
                    'match_method' => null,
                    'match_confidence' => null,
                    'matching_identifiers' => [],
                    'enriched_at' => now()->toIso8601String(),
                    'status' => $result['sync_status'],
                    'message' => $result['message'],
                    'search_attempts' => $result['search_attempts'],
                ];
                $vessel->update([
                    'enrichment_status' => $status,
                    'last_enriched_at' => now(),
                    'metadata' => $meta,
                ]);
            }
            return $result;
        }

        $candidate = $searchResult['candidate'];
        $result['candidate_found'] = true;
        $result['gfw_candidate'] = $candidate;
        $result['gfw_vessel_id'] = $searchResult['gfw_vessel_id'];
        $result['match_method'] = $searchResult['match_method'];
        $result['match_confidence'] = $searchResult['match_confidence'];
        $result['matching_identifiers'] = $searchResult['matching_identifiers'];

        // 2. Fetch full vessel details from GET /v3/vessels/{vesselId} if available
        $vesselDetails = $this->getGfwVesselDetails($result['gfw_vessel_id']);
        $result['raw_vessel_details'] = $vesselDetails;

        // 3. Query events summary (port visits, loitering, encounters, fishing)
        $eventsResult = $this->getGfwEventsSummary($result['gfw_vessel_id']);
        $result['events_summary'] = $eventsResult['summary'];
        $result['api_permissions'] = $eventsResult['permissions'];

        // 4. Multi-source reconciliation and field mapping
        $reconciliation = $this->reconcileAndMap($vessel, $candidate, $vesselDetails, $eventsResult['summary']);
        $result['fields_updated'] = $reconciliation['updates'];
        $result['fields_preserved'] = $reconciliation['preserved'];
        $result['fields_missing_in_gfw'] = $reconciliation['missing_in_gfw'];
        $result['conflicts'] = $reconciliation['conflicts'];

        // 5. Apply changes if not dry-run
        if (!$dryRun) {
            $this->applyEnrichment($vessel, $result, $reconciliation);
            $result['status'] = count($reconciliation['conflicts']) > 0 ? 'conflict_detected' : 'enriched';
            $result['sync_status'] = count($reconciliation['conflicts']) > 0 ? 'match_review_required' : 'synced';
            $result['message'] = "Enrichment successfully applied (" . count($reconciliation['updates']) . " fields updated).";
        } else {
            $result['status'] = count($reconciliation['conflicts']) > 0 ? 'conflict_detected' : 'preview_ready';
            $result['sync_status'] = count($reconciliation['conflicts']) > 0 ? 'match_review_required' : 'preview_ready';
            $result['message'] = "Dry run completed: " . count($reconciliation['updates']) . " fields eligible for update.";
        }

        return $result;
    }

    /**
     * Search GFW candidate using hierarchy: IMO -> MMSI -> Vessel name.
     */
    public function findMatchingCandidate(Vessel $vessel): array
    {
        $attempts = [];
        $candidate = null;
        $matchMethod = null;
        $matchConfidence = null;
        $matchingIdentifiers = [];
        $gfwVesselId = null;

        // Step 1: Search by IMO (highest authority if available)
        if (!empty($vessel->imo) && preg_match('/^[0-9]{7}$/', trim($vessel->imo))) {
            $imoQuery = trim($vessel->imo);
            $search = $this->querySearchEndpoint($imoQuery);
            $attempts[] = [
                'type' => 'imo',
                'query' => $imoQuery,
                'status' => $search['status'],
                'total_found' => $search['total'],
            ];

            if ($search['status'] === 200 && !empty($search['entries'])) {
                foreach ($search['entries'] as $entry) {
                    $entryImo = $this->extractEntryImo($entry);
                    if ($entryImo === $imoQuery) {
                        $candidate = $entry;
                        $matchMethod = 'imo_exact_match';
                        $matchConfidence = 'high_authoritative';
                        $matchingIdentifiers[] = 'IMO: ' . $imoQuery;
                        $gfwVesselId = $this->extractEntryVesselId($entry);
                        break;
                    }
                }
            }
        }

        // Step 2: Search by MMSI (if IMO search didn't match or wasn't available)
        if (!$candidate && !empty($vessel->mmsi) && preg_match('/^[0-9]{9}$/', trim($vessel->mmsi))) {
            $mmsiQuery = trim($vessel->mmsi);
            $search = $this->querySearchEndpoint($mmsiQuery);
            $attempts[] = [
                'type' => 'mmsi',
                'query' => $mmsiQuery,
                'status' => $search['status'],
                'total_found' => $search['total'],
            ];

            if ($search['status'] === 200 && !empty($search['entries'])) {
                foreach ($search['entries'] as $entry) {
                    $entryMmsi = $this->extractEntryMmsi($entry);
                    if ($entryMmsi === $mmsiQuery) {
                        $candidate = $entry;
                        $matchMethod = 'mmsi_exact_match';
                        $matchConfidence = 'high_telemetry';
                        $matchingIdentifiers[] = 'MMSI: ' . $mmsiQuery;
                        $gfwVesselId = $this->extractEntryVesselId($entry);
                        break;
                    }
                }
            }
        }

        // Step 3: Secondary search by Vessel Name (Strict Verification Required)
        if (!$candidate && !empty($vessel->name) && strlen(trim($vessel->name)) >= 3) {
            $nameQuery = trim($vessel->name);
            $search = $this->querySearchEndpoint($nameQuery);
            $attempts[] = [
                'type' => 'name_secondary',
                'query' => $nameQuery,
                'status' => $search['status'],
                'total_found' => $search['total'],
            ];

            if ($search['status'] === 200 && !empty($search['entries'])) {
                // NEVER match by name alone if ambiguous! Must verify with MMSI, IMO, or Callsign.
                $verifiedMatches = [];
                foreach ($search['entries'] as $entry) {
                    $entryMmsi = $this->extractEntryMmsi($entry);
                    $entryImo = $this->extractEntryImo($entry);
                    $entryCallsign = $this->extractEntryCallsign($entry);

                    $hasMmsiMatch = !empty($vessel->mmsi) && $entryMmsi === $vessel->mmsi;
                    $hasImoMatch = !empty($vessel->imo) && $entryImo === $vessel->imo;
                    $hasCallsignMatch = !empty($vessel->callsign) && !empty($entryCallsign) && strtoupper($entryCallsign) === strtoupper($vessel->callsign);

                    if ($hasMmsiMatch || $hasImoMatch || $hasCallsignMatch) {
                        $matchedOn = [];
                        if ($hasMmsiMatch) $matchedOn[] = 'MMSI: ' . $entryMmsi;
                        if ($hasImoMatch) $matchedOn[] = 'IMO: ' . $entryImo;
                        if ($hasCallsignMatch) $matchedOn[] = 'Callsign: ' . $entryCallsign;

                        $verifiedMatches[] = [
                            'entry' => $entry,
                            'matched_on' => $matchedOn,
                        ];
                    }
                }

                if (count($verifiedMatches) === 1) {
                    $candidate = $verifiedMatches[0]['entry'];
                    $matchMethod = 'name_verified_with_identifiers';
                    $matchConfidence = 'medium_verified';
                    $matchingIdentifiers = array_merge(['Name: ' . $nameQuery], $verifiedMatches[0]['matched_on']);
                    $gfwVesselId = $this->extractEntryVesselId($candidate);
                } elseif (count($verifiedMatches) > 1) {
                    return [
                        'candidate' => null,
                        'review_required' => true,
                        'attempts' => $attempts,
                        'message' => 'Multiple candidates found matching name with conflicting identifiers. Refusing ambiguous link.',
                    ];
                } else {
                    return [
                        'candidate' => null,
                        'attempts' => $attempts,
                        'message' => 'Candidate(s) returned for name query, but none matched any local hard identifier (MMSI/IMO/Callsign). Ambiguous match rejected.',
                    ];
                }
            }
        }

        // Additional check for GFW vessel ID fallback from candidate
        if ($candidate && empty($gfwVesselId)) {
            $gfwVesselId = $this->extractEntryVesselId($candidate);
        }

        // Also check if candidate has matching callsign or other identifiers to append
        if ($candidate) {
            $entryMmsi = $this->extractEntryMmsi($candidate);
            $entryImo = $this->extractEntryImo($candidate);
            $entryCallsign = $this->extractEntryCallsign($candidate);

            if ($entryMmsi && !in_array('MMSI: ' . $entryMmsi, $matchingIdentifiers)) {
                if ($entryMmsi === $vessel->mmsi) $matchingIdentifiers[] = 'MMSI: ' . $entryMmsi;
            }
            if ($entryImo && !in_array('IMO: ' . $entryImo, $matchingIdentifiers)) {
                if ($entryImo === $vessel->imo) $matchingIdentifiers[] = 'IMO: ' . $entryImo;
            }
            if ($entryCallsign && !in_array('Callsign: ' . $entryCallsign, $matchingIdentifiers)) {
                if ($vessel->callsign && strtoupper($entryCallsign) === strtoupper($vessel->callsign)) {
                    $matchingIdentifiers[] = 'Callsign: ' . $entryCallsign;
                }
            }
        }

        return [
            'candidate' => $candidate,
            'gfw_vessel_id' => $gfwVesselId,
            'match_method' => $matchMethod,
            'match_confidence' => $matchConfidence,
            'matching_identifiers' => $matchingIdentifiers,
            'attempts' => $attempts,
            'message' => $candidate ? 'Candidate matched.' : 'No candidate matched.',
        ];
    }

    /**
     * Query GET /v3/vessels/search with cache for stable identifiers
     */
    public function querySearchEndpoint(string $query): array
    {
        $cacheKey = 'gfw_vessels_search_' . md5(trim($query) . '_' . $this->vesselIdentityDataset);
        $ttl = (int) config('maritime.providers.gfw.search_cache_ttl', 604800);

        if (Cache::has($cacheKey)) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $res = $this->apiService->searchVessels($query, $this->vesselIdentityDataset);

        // Only cache successful HTTP 200 responses (do NOT cache 429, 500, or network timeouts)
        if (($res['status'] ?? 0) === 200) {
            Cache::put($cacheKey, $res, $ttl);
        }

        return $res;
    }

    /**
     * Query GET /v3/vessels/{vesselId} for complete vessel record.
     */
    public function getGfwVesselDetails(string $gfwVesselId): ?array
    {
        return $this->apiService->getVessel($gfwVesselId, $this->vesselIdentityDataset);
    }

    /**
     * Query GFW events API for events summary.
     */
    public function getGfwEventsSummary(string $gfwVesselId): array
    {
        $summary = [
            'port_visits' => ['count' => null, 'status' => 'not_queried'],
            'loitering' => ['count' => null, 'status' => 'not_queried'],
            'encounters' => ['count' => null, 'status' => 'not_queried'],
            'fishing' => ['count' => null, 'status' => 'not_queried'],
            'ais_gaps' => ['count' => null, 'status' => 'not_queried'],
        ];
        $permissions = [];

        $datasets = [
            'port_visits' => GfwApiService::DATASET_PORT_VISITS,
            'loitering' => GfwApiService::DATASET_LOITERING,
            'encounters' => GfwApiService::DATASET_ENCOUNTERS,
            'fishing' => GfwApiService::DATASET_FISHING,
            'ais_gaps' => GfwApiService::DATASET_AIS_GAPS,
        ];

        foreach ($datasets as $key => $dataset) {
            $response = $this->apiService->getEvents([
                'datasets[0]' => $dataset,
                'vessels[0]' => $gfwVesselId,
                'limit' => 1,
                'offset' => 0,
            ]);

            $status = $response['status'];

            if ($status === 200) {
                $summary[$key] = [
                    'count' => (int) $response['total'],
                    'status' => 'available',
                    'dataset' => $dataset,
                ];
                $permissions[$key] = 'authorized';
            } elseif ($status === 403) {
                $summary[$key] = [
                    'count' => null,
                    'status' => 'permission_restricted',
                    'message' => 'HTTP 403: Research Access permission required for ' . $dataset,
                    'dataset' => $dataset,
                ];
                $permissions[$key] = 'permission_restricted';
            } elseif ($status === 429) {
                $summary[$key] = [
                    'count' => null,
                    'status' => 'rate_limited',
                    'message' => 'HTTP 429: Rate limited',
                    'dataset' => $dataset,
                ];
                $permissions[$key] = 'rate_limited';
            } else {
                $summary[$key] = [
                    'count' => null,
                    'status' => 'unavailable',
                    'message' => "HTTP {$status}",
                    'dataset' => $dataset,
                ];
                $permissions[$key] = 'unavailable';
            }
        }

        return [
            'summary' => $summary,
            'permissions' => $permissions,
        ];
    }

    /**
     * Reconcile candidate data with local vessel following multi-source rules.
     */
    public function reconcileAndMap(Vessel $vessel, array $candidate, ?array $vesselDetails, ?array $eventsSummary): array
    {
        $updates = [];
        $preserved = [];
        $missingInGfw = [];
        $conflicts = [];
        $alternativeNames = [];

        $isVerified = $vessel->isManuallyVerified();

        // 1. GFW Vessel Identifier
        $gfwVesselId = $this->extractEntryVesselId($candidate);
        if ($gfwVesselId && $vessel->gfw_vessel_id !== $gfwVesselId) {
            $updates['gfw_vessel_id'] = [
                'current' => $vessel->gfw_vessel_id,
                'new' => $gfwVesselId,
                'source' => 'GFW_VESSEL_IDENTITY',
                'confidence' => 'high',
            ];
        }

        // 2. Vessel Name & Alternative Names
        $extractedNames = $this->extractAllNames($candidate, $vesselDetails);
        foreach ($extractedNames as $nm) {
            if ($nm !== $vessel->name && !in_array($nm, $alternativeNames)) {
                $alternativeNames[] = $nm;
            }
        }

        if (empty($vessel->name) && !empty($extractedNames[0])) {
            $updates['name'] = [
                'current' => $vessel->name,
                'new' => $extractedNames[0],
                'source' => 'GFW_SELF_REPORTED_OR_REGISTRY',
                'confidence' => 'high',
            ];
        } else {
            $preserved['name'] = [
                'value' => $vessel->name,
                'reason' => 'Existing local vessel name is preserved. Alternative names registered.',
                'alternative_names' => $alternativeNames,
            ];
        }

        // 3. Identifiers (MMSI, IMO, Callsign)
        $gfwMmsi = $this->extractEntryMmsi($candidate);
        $gfwImo = $this->extractEntryImo($candidate);
        $gfwCallsign = $this->extractEntryCallsign($candidate);

        // MMSI Check
        if (empty($vessel->mmsi) && !empty($gfwMmsi)) {
            $updates['mmsi'] = [
                'current' => null,
                'new' => $gfwMmsi,
                'source' => 'GFW_IDENTITY',
                'confidence' => 'high',
            ];
        } elseif (!empty($vessel->mmsi) && !empty($gfwMmsi) && $vessel->mmsi !== $gfwMmsi) {
            $conflicts['mmsi'] = [
                'local' => $vessel->mmsi,
                'gfw' => $gfwMmsi,
                'message' => 'Local MMSI and GFW MMSI mismatch. Preserving local MMSI.',
            ];
            $preserved['mmsi'] = [
                'value' => $vessel->mmsi,
                'reason' => 'Local MMSI preserved against conflicting GFW MMSI.',
            ];
        } else {
            $preserved['mmsi'] = [
                'value' => $vessel->mmsi,
                'reason' => 'MMSI matches or not provided.',
            ];
        }

        // IMO Check
        if (empty($vessel->imo) && !empty($gfwImo)) {
            $updates['imo'] = [
                'current' => null,
                'new' => $gfwImo,
                'source' => 'GFW_REGISTRY',
                'confidence' => 'high',
            ];
        } elseif (!empty($vessel->imo) && !empty($gfwImo) && $vessel->imo !== $gfwImo) {
            $conflicts['imo'] = [
                'local' => $vessel->imo,
                'gfw' => $gfwImo,
                'message' => 'Local IMO and GFW IMO mismatch. Preserving local IMO.',
            ];
            $preserved['imo'] = [
                'value' => $vessel->imo,
                'reason' => 'Local IMO preserved against conflicting GFW IMO.',
            ];
        } else {
            $preserved['imo'] = [
                'value' => $vessel->imo,
                'reason' => 'IMO matches or not provided.',
            ];
        }

        // Callsign Check
        if (empty($vessel->callsign) && !empty($gfwCallsign)) {
            $updates['callsign'] = [
                'current' => null,
                'new' => $gfwCallsign,
                'source' => 'GFW_REGISTRY_OR_AIS',
                'confidence' => 'medium',
            ];
        } elseif (!empty($vessel->callsign) && !empty($gfwCallsign) && strtoupper($vessel->callsign) !== strtoupper($gfwCallsign)) {
            $conflicts['callsign'] = [
                'local' => $vessel->callsign,
                'gfw' => $gfwCallsign,
                'message' => 'Local Callsign and GFW Callsign mismatch. Preserving local Callsign.',
            ];
            $preserved['callsign'] = [
                'value' => $vessel->callsign,
                'reason' => 'Local Callsign preserved.',
            ];
        } else {
            $preserved['callsign'] = [
                'value' => $vessel->callsign,
                'reason' => 'Callsign matches or not provided.',
            ];
        }

        // 4. Flag State
        $gfwFlag = $this->extractEntryFlag($candidate);
        if (empty($vessel->flag_state) && !empty($gfwFlag)) {
            $updates['flag_state'] = [
                'current' => null,
                'new' => $gfwFlag,
                'source' => 'GFW_REGISTRY',
                'confidence' => 'high',
            ];
        } else {
            $preserved['flag_state'] = [
                'value' => $vessel->flag_state,
                'reason' => 'Local flag preserved.',
            ];
        }

        // 5. Vessel Type / Classification
        $gfwShiptype = $this->extractEntryShiptype($candidate);
        if ($isVerified) {
            $preserved['vessel_type'] = [
                'value' => $vessel->vessel_type,
                'reason' => 'Vessel has verified manual classification. Automatic update blocked.',
            ];
        } elseif (!empty($gfwShiptype)) {
            $normalizedType = AisStreamAdapter::normalizeVesselType($gfwShiptype);
            if ($vessel->vessel_type === 'unknown' || empty($vessel->vessel_type)) {
                if ($normalizedType !== 'unknown') {
                    $updates['vessel_type'] = [
                        'current' => $vessel->vessel_type,
                        'new' => $normalizedType,
                        'source' => 'GFW_VESSELS_API_V3',
                        'confidence' => 'high',
                    ];
                } else {
                    $preserved['vessel_type'] = [
                        'value' => 'unknown',
                        'reason' => 'GFW reports ambiguous or unclassified vessel type. Unknown classification retained.',
                    ];
                }
            } elseif ($vessel->vessel_type !== $normalizedType) {
                $conflicts['vessel_type'] = [
                    'local' => $vessel->vessel_type,
                    'gfw' => $normalizedType,
                    'message' => "Local vessel type '{$vessel->vessel_type}' differs from GFW type '{$normalizedType}'. Local preserved.",
                ];
                $preserved['vessel_type'] = [
                    'value' => $vessel->vessel_type,
                    'reason' => 'Local classification preserved against conflicting source.',
                ];
            } else {
                $preserved['vessel_type'] = [
                    'value' => $vessel->vessel_type,
                    'reason' => 'Vessel type matches.',
                ];
            }
        } else {
            $missingInGfw['vessel_type'] = 'Not reported by GFW.';
            if ($vessel->vessel_type === 'unknown') {
                $preserved['vessel_type'] = [
                    'value' => 'unknown',
                    'reason' => 'No classification evidence in GFW. Unknown classification retained.',
                ];
            }
        }

        // 6. Fishing Gear (if fishing vessel)
        $gfwGear = $this->extractEntryGear($candidate);
        if ($gfwGear && strtolower($gfwGear) !== 'passenger') {
            if (empty($vessel->gear_type)) {
                $updates['gear_type'] = [
                    'current' => null,
                    'new' => strtolower($gfwGear),
                    'source' => 'GFW_GEARTYPE_REGISTRY',
                    'confidence' => 'medium',
                ];
            } else {
                $preserved['gear_type'] = [
                    'value' => $vessel->gear_type,
                    'reason' => 'Local gear type preserved.',
                ];
            }
        } else {
            $missingInGfw['gear_type'] = 'Not applicable or not reported as fishing gear.';
        }

        // 7. Gross Tonnage (GT)
        $gfwTonnage = $this->extractEntryTonnage($candidate, $vesselDetails);
        if ($gfwTonnage !== null) {
            if ($vessel->tonnage === null) {
                $updates['tonnage'] = [
                    'current' => null,
                    'new' => (float) $gfwTonnage,
                    'source' => 'GFW_IMO_REGISTRY_AND_MODEL',
                    'confidence' => 'high',
                ];
            } elseif (abs($vessel->tonnage - $gfwTonnage) > 5) {
                $conflicts['tonnage'] = [
                    'local' => $vessel->tonnage,
                    'gfw' => $gfwTonnage,
                    'message' => "Local tonnage ({$vessel->tonnage} GT) differs from GFW tonnage ({$gfwTonnage} GT). Local preserved.",
                ];
                $preserved['tonnage'] = [
                    'value' => $vessel->tonnage,
                    'reason' => 'Local tonnage preserved against conflicting source.',
                ];
            } else {
                $preserved['tonnage'] = [
                    'value' => $vessel->tonnage,
                    'reason' => 'Tonnage matches.',
                ];
            }
        } else {
            $missingInGfw['tonnage'] = 'Gross tonnage not available in GFW candidate.';
        }

        // 8. Length (LOA)
        $gfwLength = $this->extractEntryLength($candidate, $vesselDetails);
        if ($gfwLength !== null) {
            if ($vessel->length_meters === null) {
                $updates['length_meters'] = [
                    'current' => null,
                    'new' => round((float) $gfwLength, 2),
                    'source' => 'GFW_SECONDARY_MODEL',
                    'confidence' => 'medium',
                ];
            } elseif (abs($vessel->length_meters - $gfwLength) > 1.0) {
                $conflicts['length_meters'] = [
                    'local' => $vessel->length_meters,
                    'gfw' => round((float) $gfwLength, 2),
                    'message' => "AIS static reported length ({$vessel->length_meters}m) differs from GFW model length (" . round($gfwLength, 2) . "m). AIS reported length preserved.",
                ];
                $preserved['length_meters'] = [
                    'value' => $vessel->length_meters,
                    'reason' => 'Local AIS-reported length preserved.',
                ];
            } else {
                $preserved['length_meters'] = [
                    'value' => $vessel->length_meters,
                    'reason' => 'Length matches closely.',
                ];
            }
        } else {
            $missingInGfw['length_meters'] = 'Length not available in GFW candidate.';
        }

        // 9. Beam / Width
        $gfwBeam = $this->extractEntryBeam($candidate, $vesselDetails);
        if ($gfwBeam !== null) {
            if ($vessel->beam_meters === null) {
                $updates['beam_meters'] = [
                    'current' => null,
                    'new' => round((float) $gfwBeam, 2),
                    'source' => 'GFW_MODEL',
                    'confidence' => 'medium',
                ];
            } else {
                $preserved['beam_meters'] = [
                    'value' => $vessel->beam_meters,
                    'reason' => 'Local beam preserved.',
                ];
            }
        } else {
            $missingInGfw['beam_meters'] = 'Beam not available in GFW candidate.';
        }

        // 10. Engine Power (kW)
        $gfwEnginePower = $this->extractEntryEnginePower($candidate, $vesselDetails);
        if ($gfwEnginePower !== null) {
            if ($vessel->engine_power_kw === null) {
                $updates['engine_power_kw'] = [
                    'current' => null,
                    'new' => round((float) $gfwEnginePower, 2),
                    'source' => 'GFW_SECONDARY_MODEL',
                    'confidence' => 'medium',
                ];
            } else {
                $preserved['engine_power_kw'] = [
                    'value' => $vessel->engine_power_kw,
                    'reason' => 'Local engine power preserved.',
                ];
            }
        } else {
            $missingInGfw['engine_power_kw'] = 'Engine power not available in GFW candidate.';
        }

        // 11. Build Year
        $gfwBuildYear = $this->extractEntryBuildYear($candidate, $vesselDetails);
        if ($gfwBuildYear !== null) {
            if ($vessel->build_year === null) {
                $updates['build_year'] = [
                    'current' => null,
                    'new' => (int) $gfwBuildYear,
                    'source' => 'GFW_REGISTRY',
                    'confidence' => 'high',
                ];
            } else {
                $preserved['build_year'] = [
                    'value' => $vessel->build_year,
                    'reason' => 'Local build year preserved.',
                ];
            }
        } else {
            $missingInGfw['build_year'] = 'Build year not available in GFW candidate.';
        }

        // 12. Owner & Operator
        $ownerInfo = $this->extractOwnerInfo($candidate, $vesselDetails);
        if (!empty($ownerInfo['owner'])) {
            if (empty($vessel->owner)) {
                $updates['owner'] = [
                    'current' => null,
                    'new' => $ownerInfo['owner'],
                    'source' => $ownerInfo['source'] ?? 'GFW_IMO_REGISTRY',
                    'confidence' => 'high',
                ];
            } else {
                $preserved['owner'] = [
                    'value' => $vessel->owner,
                    'reason' => 'Local owner preserved.',
                ];
            }
        } else {
            $missingInGfw['owner'] = 'Owner not listed in GFW registry records.';
        }

        if (!empty($ownerInfo['owner_country']) && empty($vessel->owner_country)) {
            $updates['owner_country'] = [
                'current' => null,
                'new' => $ownerInfo['owner_country'],
                'source' => $ownerInfo['source'] ?? 'GFW_IMO_REGISTRY',
                'confidence' => 'high',
            ];
        }

        if (!empty($ownerInfo['operator'])) {
            if (empty($vessel->operator)) {
                $updates['operator'] = [
                    'current' => null,
                    'new' => $ownerInfo['operator'],
                    'source' => $ownerInfo['source'] ?? 'GFW_REGISTRY',
                    'confidence' => 'medium',
                ];
            } else {
                $preserved['operator'] = [
                    'value' => $vessel->operator,
                    'reason' => 'Local operator preserved.',
                ];
            }
        } else {
            $missingInGfw['operator'] = 'Operator not listed in GFW registry records.';
        }

        if (!empty($ownerInfo['operator_country']) && empty($vessel->operator_country)) {
            $updates['operator_country'] = [
                'current' => null,
                'new' => $ownerInfo['operator_country'],
                'source' => $ownerInfo['source'] ?? 'GFW_REGISTRY',
                'confidence' => 'medium',
            ];
        }

        return [
            'updates' => $updates,
            'preserved' => $preserved,
            'missing_in_gfw' => $missingInGfw,
            'conflicts' => $conflicts,
            'alternative_names' => $alternativeNames,
        ];
    }

    /**
     * Apply enrichment updates to database.
     */
    protected function applyEnrichment(Vessel $vessel, array $result, array $reconciliation): void
    {
        $dbUpdates = [];
        $fieldProvenance = [];

        foreach ($reconciliation['updates'] as $field => $upd) {
            $dbUpdates[$field] = $upd['new'];
            $fieldProvenance[$field] = [
                'source' => $upd['source'],
                'confidence' => $upd['confidence'],
                'updated_at' => now()->toIso8601String(),
            ];
        }

        // Handle metadata enrichment node
        $currentMetadata = $vessel->metadata ?? [];
        if (!is_array($currentMetadata)) {
            $currentMetadata = [];
        }

        // Merge alternative names
        $existingAltNames = $currentMetadata['alternative_names'] ?? [];
        if (!is_array($existingAltNames)) {
            $existingAltNames = [];
        }
        $mergedAltNames = array_values(array_unique(array_merge($existingAltNames, $reconciliation['alternative_names'])));
        $currentMetadata['alternative_names'] = $mergedAltNames;

        $dbStatus = count($reconciliation['conflicts']) > 0 ? 'conflict_detected' : 'enriched';
        $syncStatus = count($reconciliation['conflicts']) > 0 ? 'match_review_required' : 'synced';

        // Structured enrichment block
        $currentMetadata['enrichment'] = [
            'provider' => 'global-fishing-watch',
            'gfw_vessel_id' => $result['gfw_vessel_id'],
            'dataset' => $this->vesselIdentityDataset,
            'match_method' => $result['match_method'],
            'match_confidence' => $result['match_confidence'],
            'matching_identifiers' => $result['matching_identifiers'],
            'enriched_at' => now()->toIso8601String(),
            'status' => $syncStatus,
            'enrichment_status' => $dbStatus,
            'field_provenance' => $fieldProvenance,
            'conflicts' => $reconciliation['conflicts'],
            'events_summary' => $result['events_summary'],
            'api_permissions' => $result['api_permissions'],
            'raw_gfw_candidate' => $result['gfw_candidate'],
            'raw_vessel_details' => $result['raw_vessel_details'] ?? null,
        ];

        $dbUpdates['metadata'] = $currentMetadata;
        $dbUpdates['last_enriched_at'] = now();
        $dbUpdates['enrichment_status'] = $dbStatus;

        $vessel->update($dbUpdates);

        // Store GFW ID into vessel_identifiers table if not present
        if (!empty($result['gfw_vessel_id'])) {
            $provider = DataProvider::where('slug', 'global-fishing-watch')->first();
            VesselIdentifier::firstOrCreate(
                [
                    'vessel_id' => $vessel->id,
                    'identifier_type' => 'gfw_id',
                    'identifier_value' => $result['gfw_vessel_id'],
                ],
                [
                    'source_provider_id' => $provider?->id,
                    'is_current' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        // Store alternative names as identifiers if helpful
        foreach ($mergedAltNames as $altName) {
            VesselIdentifier::firstOrCreate(
                [
                    'vessel_id' => $vessel->id,
                    'identifier_type' => 'alternative_name',
                    'identifier_value' => $altName,
                ],
                [
                    'source_provider_id' => $provider?->id ?? null,
                    'is_current' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        // Optional AuditLog
        try {
            if (class_exists(AuditLog::class)) {
                AuditLog::create([
                    'action' => 'vessel_enrichment_gfw',
                    'auditable_type' => Vessel::class,
                    'auditable_id' => $vessel->id,
                    'actor_type' => 'system',
                    'actor_id' => null,
                    'changes' => [
                        'fields_updated' => array_keys($reconciliation['updates']),
                        'gfw_vessel_id' => $result['gfw_vessel_id'],
                        'conflicts_count' => count($reconciliation['conflicts']),
                    ],
                    'created_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            // Ignore audit log error
        }
    }

    // Helper extractors for GFW response structures

    protected function extractEntryVesselId(array $entry): ?string
    {
        // 1. From combinedSourcesInfo
        if (!empty($entry['combinedSourcesInfo'][0]['vesselId'])) {
            return $entry['combinedSourcesInfo'][0]['vesselId'];
        }
        // 2. From selfReportedInfo
        if (!empty($entry['selfReportedInfo'][0]['id'])) {
            return $entry['selfReportedInfo'][0]['id'];
        }
        // 3. Top-level id
        if (!empty($entry['id'])) {
            return $entry['id'];
        }
        return null;
    }

    protected function extractEntryMmsi(array $entry): ?string
    {
        if (!empty($entry['selfReportedInfo'][0]['ssvid'])) {
            return (string) $entry['selfReportedInfo'][0]['ssvid'];
        }
        if (!empty($entry['registryInfo'][0]['ssvid'])) {
            return (string) $entry['registryInfo'][0]['ssvid'];
        }
        return null;
    }

    protected function extractEntryImo(array $entry): ?string
    {
        if (!empty($entry['registryInfo'][0]['imo'])) {
            return (string) $entry['registryInfo'][0]['imo'];
        }
        if (!empty($entry['selfReportedInfo'][0]['imo'])) {
            return (string) $entry['selfReportedInfo'][0]['imo'];
        }
        return null;
    }

    protected function extractEntryCallsign(array $entry): ?string
    {
        if (!empty($entry['registryInfo'][0]['callsign'])) {
            return (string) $entry['registryInfo'][0]['callsign'];
        }
        if (!empty($entry['selfReportedInfo'][0]['callsign'])) {
            return (string) $entry['selfReportedInfo'][0]['callsign'];
        }
        return null;
    }

    protected function extractEntryFlag(array $entry): ?string
    {
        $flag = null;
        if (!empty($entry['registryInfo'][0]['flag'])) {
            $flag = (string) $entry['registryInfo'][0]['flag'];
        } elseif (!empty($entry['selfReportedInfo'][0]['flag'])) {
            $flag = (string) $entry['selfReportedInfo'][0]['flag'];
        }
        return $flag ? strtoupper($flag) : null;
    }

    protected function extractAllNames(array $candidate, ?array $vesselDetails): array
    {
        $names = [];
        $sources = [$candidate, $vesselDetails];
        foreach ($sources as $src) {
            if (!$src) continue;
            foreach ($src['selfReportedInfo'] ?? [] as $item) {
                if (!empty($item['shipname'])) $names[] = trim($item['shipname']);
            }
            foreach ($src['registryInfo'] ?? [] as $item) {
                if (!empty($item['shipname'])) $names[] = trim($item['shipname']);
            }
        }
        return array_values(array_unique(array_filter($names)));
    }

    protected function extractEntryShiptype(array $entry): ?string
    {
        // 1. Check combinedSourcesInfo shiptypes / vesselClassSc
        if (!empty($entry['combinedSourcesInfo'][0]['shiptypes'][0]['name'])) {
            return $entry['combinedSourcesInfo'][0]['shiptypes'][0]['name'];
        }
        if (!empty($entry['combinedSourcesInfo'][0]['vesselClassSc'][0]['value'])) {
            return $entry['combinedSourcesInfo'][0]['vesselClassSc'][0]['value'];
        }
        // 2. Check registryInfo shiptypes
        if (!empty($entry['registryInfo'][0]['shiptypes'][0]['name'])) {
            return $entry['registryInfo'][0]['shiptypes'][0]['name'];
        }
        if (!empty($entry['registryInfo'][0]['shiptype']) && is_string($entry['registryInfo'][0]['shiptype'])) {
            return $entry['registryInfo'][0]['shiptype'];
        }
        // 3. Check selfReportedInfo shiptypes
        if (!empty($entry['selfReportedInfo'][0]['shiptypes'][0]['name'])) {
            return $entry['selfReportedInfo'][0]['shiptypes'][0]['name'];
        }
        if (!empty($entry['selfReportedInfo'][0]['shiptype']) && is_string($entry['selfReportedInfo'][0]['shiptype'])) {
            return $entry['selfReportedInfo'][0]['shiptype'];
        }
        // 4. Fallback to gear types (if fishing gear is present, it indicates fishing)
        if (!empty($entry['registryInfo'][0]['geartypes'][0])) {
            $gear = is_array($entry['registryInfo'][0]['geartypes'][0]) ? ($entry['registryInfo'][0]['geartypes'][0]['name'] ?? null) : $entry['registryInfo'][0]['geartypes'][0];
            if ($gear) return 'fishing';
        }
        return null;
    }

    protected function extractEntryGear(array $entry): ?string
    {
        if (!empty($entry['combinedSourcesInfo'][0]['geartypes'][0]['name'])) {
            return $entry['combinedSourcesInfo'][0]['geartypes'][0]['name'];
        }
        if (!empty($entry['registryInfo'][0]['geartypes'][0])) {
            return $entry['registryInfo'][0]['geartypes'][0];
        }
        return null;
    }

    protected function extractEntryTonnage(array $candidate, ?array $vesselDetails): ?float
    {
        $sources = [$vesselDetails, $candidate];
        foreach ($sources as $src) {
            if (!$src) continue;
            // 1. Authoritative registryInfo
            if (!empty($src['registryInfo'][0]['tonnageGt'])) {
                return (float) $src['registryInfo'][0]['tonnageGt'];
            }
            // 2. bestTonnageGt
            if (!empty($src['combinedSourcesInfo'][0]['bestTonnageGt'][0]['value'])) {
                return (float) $src['combinedSourcesInfo'][0]['bestTonnageGt'][0]['value'];
            }
        }
        return null;
    }

    protected function extractEntryLength(array $candidate, ?array $vesselDetails): ?float
    {
        $sources = [$vesselDetails, $candidate];
        foreach ($sources as $src) {
            if (!$src) continue;
            if (!empty($src['registryInfo'][0]['lengthM'])) {
                return (float) $src['registryInfo'][0]['lengthM'];
            }
            if (!empty($src['combinedSourcesInfo'][0]['bestLengthM'][0]['value'])) {
                return (float) $src['combinedSourcesInfo'][0]['bestLengthM'][0]['value'];
            }
        }
        return null;
    }

    protected function extractEntryBeam(array $candidate, ?array $vesselDetails): ?float
    {
        $sources = [$vesselDetails, $candidate];
        foreach ($sources as $src) {
            if (!$src) continue;
            if (!empty($src['registryInfo'][0]['widthM'])) {
                return (float) $src['registryInfo'][0]['widthM'];
            }
            if (!empty($src['registryInfo'][0]['beamM'])) {
                return (float) $src['registryInfo'][0]['beamM'];
            }
            if (!empty($src['combinedSourcesInfo'][0]['bestWidthM'][0]['value'])) {
                return (float) $src['combinedSourcesInfo'][0]['bestWidthM'][0]['value'];
            }
        }
        return null;
    }

    protected function extractEntryEnginePower(array $candidate, ?array $vesselDetails): ?float
    {
        $sources = [$vesselDetails, $candidate];
        foreach ($sources as $src) {
            if (!$src) continue;
            if (!empty($src['combinedSourcesInfo'][0]['bestEnginePowerKw'][0]['value'])) {
                return (float) $src['combinedSourcesInfo'][0]['bestEnginePowerKw'][0]['value'];
            }
            if (!empty($src['registryInfo'][0]['enginePowerKw'])) {
                return (float) $src['registryInfo'][0]['enginePowerKw'];
            }
        }
        return null;
    }

    protected function extractEntryBuildYear(array $candidate, ?array $vesselDetails): ?int
    {
        $sources = [$vesselDetails, $candidate];
        foreach ($sources as $src) {
            if (!$src) continue;
            if (!empty($src['registryInfo'][0]['buildYear'])) {
                return (int) $src['registryInfo'][0]['buildYear'];
            }
            if (!empty($src['registryInfo'][0]['builtYear'])) {
                return (int) $src['registryInfo'][0]['builtYear'];
            }
        }
        return null;
    }

    protected function extractOwnerInfo(array $candidate, ?array $vesselDetails): array
    {
        $sources = [$vesselDetails, $candidate];
        foreach ($sources as $src) {
            if (!$src) continue;
            if (!empty($src['registryOwners'][0]['name'])) {
                $ownerFlag = $src['registryOwners'][0]['flag'] ?? ($src['registryOwners'][0]['country'] ?? null);
                return [
                    'owner' => trim($src['registryOwners'][0]['name']),
                    'owner_country' => $ownerFlag ? strtoupper(trim($ownerFlag)) : null,
                    'operator' => null,
                    'operator_country' => null,
                    'source' => 'GFW_IMO_REGISTRY',
                ];
            }
        }
        return ['owner' => null, 'owner_country' => null, 'operator' => null, 'operator_country' => null, 'source' => null];
    }
}

