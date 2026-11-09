<?php

namespace App\Services\Enrichment;

use App\Models\EnrichmentJob;
use App\Models\Vessel;
use App\Models\VesselAttributeObservation;
use App\Services\Enrichment\Contracts\EnrichmentProviderInterface;
use App\Services\Enrichment\Providers\AisStreamEnrichmentProvider;
use App\Services\Enrichment\Providers\FaoRecordEnrichmentProvider;
use App\Services\Enrichment\Providers\GfwEnrichmentProvider;
use App\Services\Enrichment\Providers\OfficialFlagRegistryProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class VesselEnrichmentManager
{
    /**
     * @var array<EnrichmentProviderInterface>
     */
    protected array $providers;

    public function __construct(
        ?EnrichmentProviderInterface $aisStreamProvider = null,
        ?EnrichmentProviderInterface $gfwProvider = null,
        ?EnrichmentProviderInterface $faoProvider = null,
        ?EnrichmentProviderInterface $flagRegistryProvider = null
    ) {
        // Prioritized order:
        // 1. AISStream
        // 2. Global Fishing Watch
        // 3. FAO Global Record
        // 4. Official Flag Registry
        $this->providers = array_values(array_filter([
            $aisStreamProvider ?: app(AisStreamEnrichmentProvider::class),
            $gfwProvider ?: app(GfwEnrichmentProvider::class),
            $faoProvider ?: app(FaoRecordEnrichmentProvider::class),
            $flagRegistryProvider ?: app(OfficialFlagRegistryProvider::class),
        ]));
    }

    /**
     * Set or override providers array (useful for custom pipelines or testing).
     *
     * @param array<EnrichmentProviderInterface> $providers
     */
    public function setProviders(array $providers): self
    {
        $this->providers = $providers;
        return $this;
    }

    /**
     * Get list of registered providers and their configuration status.
     */
    public function getProvidersStatus(): array
    {
        $status = [];
        foreach ($this->providers as $p) {
            $status[$p->getName()] = [
                'name' => $p->getName(),
                'is_configured' => $p->isConfigured(),
            ];
        }
        return $status;
    }

    /**
     * Enrich a single vessel through the multi-source enrichment pipeline.
     */
    public function enrichVessel(Vessel $vessel, bool $dryRun = false): array
    {
        $startTime = now();
        $mmsi = trim((string) ($vessel->mmsi ?? ''));

        // 1. Validate MMSI
        $isValidMmsi = !empty($mmsi) && preg_match('/^[0-9]{9}$/', $mmsi) && $mmsi !== '000000000' && $mmsi !== '111111111';

        $report = [
            'vessel_id' => $vessel->id,
            'mmsi' => $vessel->mmsi,
            'is_valid_mmsi' => $isValidMmsi,
            'dry_run' => $dryRun,
            'sources_attempted' => [],
            'provider_results' => [],
            'matched_attributes' => [],
            'conflicts' => [],
            'preserved_attributes' => [],
            'updates_applied' => [],
            'ais_status' => $vessel->ais_static_status,
            'enrichment_status' => 'not_started',
            'message' => '',
        ];

        if (!$isValidMmsi) {
            $report['enrichment_status'] = 'not_found';
            $report['message'] = 'Vessel MMSI is invalid or malformed. Telemetry enrichment cannot proceed.';
            return $report;
        }

        $allObservations = [];
        $hasAnyConfigured = false;
        $allTimeouts = true;
        $matchedAnyCandidate = false;

        // 2. Execute each provider in priority order
        foreach ($this->providers as $provider) {
            $sourceName = $provider->getName();
            $report['sources_attempted'][] = $sourceName;

            if (!$provider->isConfigured()) {
                $report['provider_results'][$sourceName] = [
                    'status' => 'NOT_CONFIGURED',
                    'message' => "Provider '{$sourceName}' is not configured.",
                ];
                continue;
            }

            // Enforce distinct rate limiting per provider
            $rateLimitKey = 'enrichment_rate_limit_' . $sourceName;
            $maxPerMinute = match ($sourceName) {
                'aisstream' => (int) config('maritime.providers.ais_stream.rate_limit_per_minute', 120),
                'gfw' => (int) config('maritime.providers.gfw.rate_limit_per_minute', 60),
                'fao' => (int) config('maritime.providers.fao_global_record.rate_limit_per_minute', 30),
                'flag_registry' => (int) config('maritime.providers.flag_registry.rate_limit_per_minute', 300),
                default => 60,
            };

            if (RateLimiter::tooManyAttempts($rateLimitKey, $maxPerMinute)) {
                $availableIn = RateLimiter::availableIn($rateLimitKey);
                Log::warning("VesselEnrichmentManager: Provider '{$sourceName}' reached rate limit ({$maxPerMinute}/min). Retry in {$availableIn}s.");
                $report['provider_results'][$sourceName] = [
                    'status' => 'PROVIDER_UNAVAILABLE',
                    'external_record_id' => null,
                    'confidence_score' => null,
                    'attributes' => [],
                    'raw_payload' => null,
                    'message' => "Provider '{$sourceName}' rate limit reached ({$maxPerMinute}/min). Available in {$availableIn}s.",
                ];
                continue;
            }

            RateLimiter::hit($rateLimitKey, 60);

            $hasAnyConfigured = true;

            try {
                $res = $provider->enrich($vessel);
                $report['provider_results'][$sourceName] = $res;

                if ($res['status'] !== 'PROVIDER_UNAVAILABLE') {
                    $allTimeouts = false;
                }

                if ($res['status'] === 'MATCH_FOUND' && !empty($res['attributes'])) {
                    $matchedAnyCandidate = true;
                    foreach ($res['attributes'] as $attrName => $attrValue) {
                        if ($attrValue === null || $attrValue === '') {
                            continue;
                        }
                        $allObservations[] = [
                            'source_name' => $sourceName,
                            'external_record_id' => $res['external_record_id'],
                            'attribute_name' => $attrName,
                            'attribute_value' => (string) $attrValue,
                            'confidence_score' => $res['confidence_score'],
                            'raw_payload' => $res['raw_payload'],
                        ];
                    }
                }
            } catch (\Throwable $e) {
                Log::error("Enrichment error on provider {$sourceName}: " . $e->getMessage());
                $report['provider_results'][$sourceName] = [
                    'status' => 'PROVIDER_UNAVAILABLE',
                    'message' => $e->getMessage(),
                ];
            }
        }

        // 3. Multi-source Reconciliation and Conflict Detection
        $updatesToApply = [];
        $conflictCount = 0;
        $observationsToRecord = [];

        foreach ($allObservations as $obs) {
            $attrName = $obs['attribute_name'];
            $newVal = $obs['attribute_value'];
            $sourceName = $obs['source_name'];
            $conf = $obs['confidence_score'];

            $isConflicting = false;
            $conflictReason = null;

            // Existing profile value
            $existingVal = match ($attrName) {
                'name' => $vessel->name,
                'mmsi' => $vessel->mmsi,
                'imo' => $vessel->imo,
                'callsign' => $vessel->callsign,
                'flag_state' => $vessel->flag_state,
                'vessel_type' => $vessel->vessel_type,
                'vessel_category' => $vessel->vessel_category,
                'ais_class' => $vessel->ais_class,
                'length_meters' => $vessel->length_meters ? (string) $vessel->length_meters : null,
                'beam_meters' => $vessel->beam_meters ? (string) $vessel->beam_meters : null,
                'draught' => $vessel->draught ? (string) $vessel->draught : null,
                'tonnage' => $vessel->tonnage ? (string) $vessel->tonnage : null,
                'deadweight_tonnage' => $vessel->deadweight_tonnage ? (string) $vessel->deadweight_tonnage : null,
                'build_year' => $vessel->build_year ? (string) $vessel->build_year : null,
                'ship_builder' => $vessel->ship_builder,
                'home_port' => $vessel->home_port,
                'registry_info' => $vessel->registry_port,
                'owner' => $vessel->owner,
                'operator' => $vessel->operator,
                default => null,
            };

            // Rule: Check if incoming data conflicts with existing valid data
            if (!empty($existingVal)) {
                // If existing is already valid AIS static data, never overwrite with external data!
                if ($vessel->static_data_received_at !== null && $sourceName !== 'aisstream') {
                    if (strtolower(trim((string) $existingVal)) !== strtolower(trim((string) $newVal))) {
                        // Numeric tolerance check for dimensions / tonnage
                        if (in_array($attrName, ['length_meters', 'beam_meters', 'draught', 'tonnage']) && is_numeric($existingVal) && is_numeric($newVal)) {
                            if (abs((float)$existingVal - (float)$newVal) > 1.0) {
                                $isConflicting = true;
                                $conflictReason = "Verified AIS static data '{$existingVal}' differs from external '{$newVal}'. Preserving AIS static.";
                            }
                        } else {
                            $isConflicting = true;
                            $conflictReason = "Verified AIS static data '{$existingVal}' differs from external {$sourceName} data '{$newVal}'. Preserving AIS static.";
                        }
                    }
                } elseif (strtolower(trim((string) $existingVal)) !== strtolower(trim((string) $newVal))) {
                    // Check specific identifier conflicts
                    if ($attrName === 'imo' && preg_match('/^[0-9]{7}$/', (string) $existingVal) && preg_match('/^[0-9]{7}$/', (string) $newVal)) {
                        $isConflicting = true;
                        $conflictReason = "Profile IMO '{$existingVal}' conflicts with {$sourceName} IMO '{$newVal}'. Preserving local IMO.";
                    } elseif ($attrName === 'name' && $existingVal !== 'UNKNOWN' && $existingVal !== $vessel->mmsi) {
                        // Check string distance/equality
                        $normLocal = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)$existingVal));
                        $normNew = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)$newVal));
                        if ($normLocal !== $normNew && !str_contains($normLocal, $normNew) && !str_contains($normNew, $normLocal)) {
                            $isConflicting = true;
                            $conflictReason = "Local vessel name '{$existingVal}' conflicts with {$sourceName} name '{$newVal}'. Local preserved.";
                        }
                    }
                }
            }

            if ($isConflicting) {
                $conflictCount++;
                $report['conflicts'][$attrName] = [
                    'attribute' => $attrName,
                    'profile_value' => $existingVal,
                    'incoming_value' => $newVal,
                    'source' => $sourceName,
                    'reason' => $conflictReason,
                ];
                $report['preserved_attributes'][$attrName] = [
                    'value' => $existingVal,
                    'reason' => $conflictReason,
                ];
            } else {
                // If profile doesn't have this attribute yet, or if it was unknown/placeholder, queue update
                $isPlaceholder = empty($existingVal) || $existingVal === 'UNKNOWN' || $existingVal === 'unknown' || $existingVal === $vessel->mmsi;
                if ($isPlaceholder && !empty($newVal) && !in_array($newVal, ['unknown', 'not_available', 'unmapped'])) {
                    if (!isset($updatesToApply[$attrName])) {
                        $updatesToApply[$attrName] = [
                            'value' => $newVal,
                            'source' => $sourceName,
                            'confidence' => $conf,
                        ];
                        $report['updates_applied'][$attrName] = $newVal;
                    }
                }
            }

            $obs['is_conflicting'] = $isConflicting;
            $obs['conflict_reason'] = $conflictReason;
            $observationsToRecord[] = $obs;
        }

        // 4. Determine final enrichment status
        if (!$hasAnyConfigured) {
            $finalStatus = 'not_configured';
        } elseif ($allTimeouts && !$matchedAnyCandidate) {
            $finalStatus = 'provider_unavailable';
        } elseif ($conflictCount > 0) {
            $finalStatus = 'conflicting';
        } elseif (!empty($updatesToApply)) {
            // Check if key identifiers were populated
            $hasKeyAttrs = isset($updatesToApply['name']) || isset($updatesToApply['vessel_type']) || isset($updatesToApply['imo']) || isset($updatesToApply['flag_state']);
            $finalStatus = $hasKeyAttrs ? 'enriched' : 'partially_enriched';
        } elseif ($matchedAnyCandidate) {
            $finalStatus = 'match_found';
        } else {
            $finalStatus = 'not_found';
        }

        $report['enrichment_status'] = $finalStatus;
        $report['matched_attributes'] = array_keys($updatesToApply);

        // 5. Database Persistence (Strictly additive, safe & non-destructive)
        if (!$dryRun) {
            DB::transaction(function () use ($vessel, $updatesToApply, $observationsToRecord, $finalStatus, $report, $startTime, $conflictCount) {
                // A. Update vessel profile without altering AIS static status
                $vesselUpdates = [
                    'enrichment_status' => $finalStatus,
                    'last_enriched_at' => now(),
                ];

                foreach ($updatesToApply as $col => $upd) {
                    $vesselUpdates[$col] = $upd['value'];
                }

                // If GFW vessel ID matched, link it
                if (!empty($report['provider_results']['gfw']['external_record_id'])) {
                    $vesselUpdates['gfw_vessel_id'] = $report['provider_results']['gfw']['external_record_id'];
                }

                $vessel->update($vesselUpdates);

                // B. Record attribute observations (idempotent: prevent duplicate records)
                foreach ($observationsToRecord as $obs) {
                    VesselAttributeObservation::updateOrCreate(
                        [
                            'vessel_id' => $vessel->id,
                            'source_name' => $obs['source_name'],
                            'attribute_name' => $obs['attribute_name'],
                        ],
                        [
                            'external_record_id' => $obs['external_record_id'],
                            'attribute_value' => $obs['attribute_value'],
                            'confidence_score' => $obs['confidence_score'],
                            'is_conflicting' => $obs['is_conflicting'],
                            'conflict_reason' => $obs['conflict_reason'],
                            'raw_payload' => $obs['raw_payload'],
                            'retrieved_at' => now(),
                        ]
                    );
                }

                // C. Record enrichment job audit
                EnrichmentJob::create([
                    'vessel_id' => $vessel->id,
                    'status' => 'completed',
                    'sources_attempted' => $report['sources_attempted'],
                    'matched_attributes_count' => count($updatesToApply),
                    'conflicts_count' => $conflictCount,
                    'scheduled_at' => $startTime,
                    'started_at' => $startTime,
                    'completed_at' => now(),
                ]);
            });
        }

        $report['message'] = "Enrichment completed with status '{$finalStatus}' (" . count($updatesToApply) . " updates, {$conflictCount} conflicts).";
        return $report;
    }
}
