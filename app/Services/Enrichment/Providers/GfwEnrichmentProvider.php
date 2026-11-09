<?php

namespace App\Services\Enrichment\Providers;

use App\Models\Vessel;
use App\Services\Enrichment\Contracts\EnrichmentProviderInterface;
use App\Services\Maritime\GfwVesselEnrichmentService;
use App\Services\Providers\AisStreamAdapter;
use Illuminate\Support\Facades\Log;

class GfwEnrichmentProvider implements EnrichmentProviderInterface
{
    public function __construct(
        protected GfwVesselEnrichmentService $gfwEnrichmentService
    ) {
    }

    public function getName(): string
    {
        return 'gfw';
    }

    public function isConfigured(): bool
    {
        return $this->gfwEnrichmentService->isConfigured();
    }

    public function enrich(Vessel $vessel): array
    {
        if (!$this->isConfigured()) {
            return [
                'provider' => $this->getName(),
                'status' => 'NOT_CONFIGURED',
                'external_record_id' => null,
                'confidence_score' => null,
                'attributes' => [],
                'raw_payload' => null,
                'message' => 'Global Fishing Watch API token is not configured in .env (GFW_API_TOKEN)',
            ];
        }

        try {
            $search = $this->gfwEnrichmentService->findMatchingCandidate($vessel);

            // Check if rate limited
            if (!empty($search['is_rate_limited'])) {
                return [
                    'provider' => $this->getName(),
                    'status' => 'PROVIDER_UNAVAILABLE',
                    'external_record_id' => null,
                    'confidence_score' => null,
                    'attributes' => [],
                    'raw_payload' => null,
                    'message' => 'GFW API rate limited (HTTP 429)',
                ];
            }

            if (empty($search['candidate'])) {
                return [
                    'provider' => $this->getName(),
                    'status' => 'NOT_FOUND',
                    'external_record_id' => null,
                    'confidence_score' => null,
                    'attributes' => [],
                    'raw_payload' => ['attempts' => $search['attempts'] ?? []],
                    'message' => $search['message'] ?? 'No verified GFW candidate found matching local identifiers.',
                ];
            }

            $candidate = $search['candidate'];
            $gfwVesselId = $search['gfw_vessel_id'] ?? null;
            $details = $gfwVesselId ? $this->gfwEnrichmentService->getGfwVesselDetails($gfwVesselId) : null;

            // Extract standardized attributes
            $attrs = [];

            // 1. Vessel Name
            $gfwName = $candidate['shipname'] ?? ($details['shipname'] ?? null);
            if (!empty($gfwName) && is_string($gfwName)) {
                $attrs['name'] = trim($gfwName);
            }

            // 2. MMSI
            $gfwMmsi = $candidate['mmsi'] ?? ($details['mmsi'] ?? null);
            if (!empty($gfwMmsi)) {
                $attrs['mmsi'] = (string) $gfwMmsi;
            }

            // 3. IMO
            $gfwImo = $candidate['imo'] ?? ($details['imo'] ?? null);
            if (!empty($gfwImo) && preg_match('/^[0-9]{7}$/', (string) $gfwImo)) {
                $attrs['imo'] = (string) $gfwImo;
            }

            // 4. Callsign
            $gfwCallsign = $candidate['callsign'] ?? ($details['callsign'] ?? null);
            if (!empty($gfwCallsign)) {
                $attrs['callsign'] = trim((string) $gfwCallsign);
            }

            // 5. Flag State
            $gfwFlag = $candidate['flag'] ?? ($details['flag'] ?? null);
            if (!empty($gfwFlag)) {
                $attrs['flag_state'] = strtoupper(trim((string) $gfwFlag));
            }

            // 6. Vessel Type & Category
            $gfwShiptype = $candidate['shiptype'] ?? ($details['shiptype'] ?? null);
            if (!empty($gfwShiptype)) {
                $normalizedType = AisStreamAdapter::normalizeVesselType((string) $gfwShiptype);
                if (!in_array($normalizedType, ['unknown', 'not_available', 'unmapped'])) {
                    $attrs['vessel_type'] = $normalizedType;
                    $attrs['vessel_category'] = match ($normalizedType) {
                        'fishing' => 'fishing',
                        'cargo', 'tanker', 'carrier' => 'commercial_cargo',
                        'passenger' => 'passenger',
                        'patrol', 'military', 'law_enforcement', 'sar' => 'enforcement_and_security',
                        'tug', 'towing', 'dredging' => 'special_craft',
                        default => 'other',
                    };
                }
            }

            // 7. Fishing Gear
            $gfwGear = $candidate['geartype'] ?? ($details['geartype'] ?? null);
            if (!empty($gfwGear) && strtolower($gfwGear) !== 'passenger') {
                $attrs['gear_type'] = strtolower((string) $gfwGear);
            }

            // 8. Length & Beam
            $length = $candidate['length'] ?? ($details['length'] ?? null);
            if ($length !== null && is_numeric($length) && (float) $length > 0) {
                $attrs['length_meters'] = round((float) $length, 2);
            }
            $beam = $candidate['width'] ?? ($details['width'] ?? ($candidate['beam'] ?? null));
            if ($beam !== null && is_numeric($beam) && (float) $beam > 0) {
                $attrs['beam_meters'] = round((float) $beam, 2);
            }

            // 9. Tonnage & DWT
            $tonnage = $candidate['tonnage'] ?? ($details['tonnage'] ?? ($candidate['grossTonnage'] ?? null));
            if ($tonnage !== null && is_numeric($tonnage) && (float) $tonnage > 0) {
                $attrs['tonnage'] = (float) $tonnage;
            }
            $dwt = $candidate['deadweight'] ?? ($details['deadweight'] ?? ($details['dwt'] ?? null));
            if ($dwt !== null && is_numeric($dwt) && (float) $dwt > 0) {
                $attrs['deadweight_tonnage'] = (float) $dwt;
            }

            // 10. Engine Power & Year Built
            $enginePower = $candidate['enginePower'] ?? ($details['enginePower'] ?? null);
            if ($enginePower !== null && is_numeric($enginePower) && (float) $enginePower > 0) {
                $attrs['engine_power_kw'] = round((float) $enginePower, 2);
            }
            $buildYear = $candidate['buildYear'] ?? ($details['buildYear'] ?? ($details['yearBuilt'] ?? null));
            if ($buildYear !== null && is_numeric($buildYear) && (int) $buildYear >= 1900 && (int) $buildYear <= 2030) {
                $attrs['build_year'] = (int) $buildYear;
            }

            // 11. Owner & Operator
            $owner = $candidate['owner'] ?? ($details['owner'] ?? null);
            if (!empty($owner) && is_string($owner)) {
                $attrs['owner'] = trim($owner);
            }
            $operator = $candidate['operator'] ?? ($details['operator'] ?? null);
            if (!empty($operator) && is_string($operator)) {
                $attrs['operator'] = trim($operator);
            }

            $confidenceScore = match ($search['match_confidence'] ?? '') {
                'high_authoritative' => 0.95,
                'high_telemetry' => 0.90,
                'medium_verified' => 0.75,
                default => 0.60,
            };

            return [
                'provider' => $this->getName(),
                'status' => 'MATCH_FOUND',
                'external_record_id' => (string) $gfwVesselId,
                'confidence_score' => $confidenceScore,
                'attributes' => $attrs,
                'raw_payload' => [
                    'candidate' => $candidate,
                    'details' => $details,
                    'match_method' => $search['match_method'] ?? null,
                    'match_confidence' => $search['match_confidence'] ?? null,
                ],
                'message' => 'Candidate matched via GFW API v3 (' . ($search['match_method'] ?? 'unknown') . ').',
            ];
        } catch (\Throwable $e) {
            Log::warning("GfwEnrichmentProvider exception: " . $e->getMessage());
            return [
                'provider' => $this->getName(),
                'status' => 'PROVIDER_UNAVAILABLE',
                'external_record_id' => null,
                'confidence_score' => null,
                'attributes' => [],
                'raw_payload' => ['error' => $e->getMessage()],
                'message' => 'Provider error: ' . $e->getMessage(),
            ];
        }
    }
}

