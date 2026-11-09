<?php

namespace App\Services\Maritime;

use App\Models\AuditLog;
use App\Models\Vessel;
use App\Services\Providers\GfwApiService;
use Illuminate\Support\Facades\Log;

class VesselClassificationService
{
    public const TAXONOMY = [
        'fishing'           => 'Fishing',
        'towing'            => 'Towing',
        'dredging'          => 'Dredging / Underwater Operations',
        'diving'            => 'Diving Operations',
        'military'          => 'Military Operations',
        'sailing'           => 'Sailing',
        'pleasure'          => 'Pleasure Craft',
        'high_speed_craft'  => 'High Speed Craft',
        'pilot'             => 'Pilot Vessel',
        'sar'               => 'Search and Rescue',
        'tug'               => 'Tug',
        'port_tender'       => 'Port Tender',
        'anti_pollution'    => 'Anti-pollution',
        'law_enforcement'   => 'Law Enforcement',
        'medical'           => 'Medical Transport',
        'passenger'         => 'Passenger',
        'cargo'             => 'Cargo',
        'tanker'            => 'Tanker',
        'other'             => 'Other',
        'not_available'     => 'Not Available',
        'unmapped'          => 'Unmapped AIS Code',
        'awaiting_static'   => 'Awaiting Static Data',
        'unknown'           => 'Unknown',
        // aliases
        'patrol'            => 'Law Enforcement',
        'carrier'           => 'Carrier',
        'support'           => 'Support',
        'research'          => 'Research',
        'non_combatant'     => 'Other',
    ];

    protected GfwApiService $gfwApi;

    public function __construct(GfwApiService $gfwApi)
    {
        $this->gfwApi = $gfwApi;
    }

    /**
     * Map AIS ShipType integer code to standard taxonomy.
     * Strictly adheres to ITU-R M.1371 / IMO standard taxonomy:
     * - 30: Fishing
     * - 31–32: Towing
     * - 33: Dredging
     * - 34: Diving
     * - 35: Military
     * - 36: Sailing
     * - 37: Pleasure Craft
     * - 40–49: High Speed Craft
     * - 50: Pilot
     * - 51: Search and Rescue
     * - 52: Tug
     * - 53: Port Tender
     * - 54: Anti-pollution
     * - 55: Law Enforcement
     * - 58: Medical
     * - 59: Non-combatant
     * - 60–69: Passenger
     * - 70–79: Cargo
     * - 80–89: Tanker
     * - 90–99: Other
     */
    public static function mapAisCodeToTaxonomy($code): string
    {
        if ($code === null || $code === '' || !is_numeric($code)) {
            return 'unknown';
        }

        $code = (int) $code;

        if ($code === 0) {
            return 'not_available';
        }
        if ($code >= 20 && $code <= 29) {
            return 'high_speed_craft';
        }
        if ($code === 30) {
            return 'fishing';
        }
        if ($code === 31 || $code === 32) {
            return 'towing';
        }
        if ($code === 33) {
            return 'dredging';
        }
        if ($code === 34) {
            return 'diving';
        }
        if ($code === 35) {
            return 'military';
        }
        if ($code === 36) {
            return 'sailing';
        }
        if ($code === 37) {
            return 'pleasure';
        }
        if ($code >= 40 && $code <= 49) {
            return 'high_speed_craft';
        }
        if ($code === 50) {
            return 'pilot';
        }
        if ($code === 51) {
            return 'sar';
        }
        if ($code === 52) {
            return 'tug';
        }
        if ($code === 53) {
            return 'port_tender';
        }
        if ($code === 54) {
            return 'anti_pollution';
        }
        if ($code === 55) {
            return 'law_enforcement';
        }
        if ($code === 58) {
            return 'medical';
        }
        if ($code === 59) {
            return 'other';
        }
        if ($code >= 60 && $code <= 69) {
            return 'passenger';
        }
        if ($code >= 70 && $code <= 79) {
            return 'cargo';
        }
        if ($code >= 80 && $code <= 89) {
            return 'tanker';
        }
        if ($code >= 90 && $code <= 99) {
            return 'other';
        }

        return 'unmapped';
    }

    /**
     * Normalize string value to internal taxonomy key.
     */
    public static function normalize(string $type): string
    {
        $clean = strtolower(trim($type));
        $clean = str_replace(['-', ' '], '_', $clean);
        if (array_key_exists($clean, self::TAXONOMY)) {
            return $clean;
        }

        // Common synonym aliases
        return match ($clean) {
            'search_and_rescue' => 'sar',
            'pleasure_craft' => 'pleasure',
            'high_speed_craft', 'hsc' => 'high_speed_craft',
            'pilot_vessel' => 'pilot',
            'dredger' => 'dredging',
            'supply' => 'support',
            'tender' => 'port_tender',
            'patrol', 'patrol_vessel' => 'law_enforcement',
            'carrier', 'bunker' => 'carrier',
            'medical_transport' => 'medical',
            'non_combatant' => 'other',
            default => 'unknown',
        };
    }

    /**
     * Return user-facing display label for taxonomy.
     */
    public static function label(?string $type): string
    {
        $key = self::normalize($type ?? 'unknown');
        return self::TAXONOMY[$key] ?? 'Unknown';
    }

    /**
     * Resolve classification for a single vessel applying multi-source priority.
     */
    public function resolveClassification(Vessel $vessel, bool $queryGfwIfMissing = false): array
    {
        $currentType = $vessel->vessel_type ?: 'unknown';
        $currentNormalized = self::normalize($currentType);

        // Priority 1: Verified manual classification (PROTECTED)
        if ($vessel->isManuallyVerified()) {
            return [
                'vessel_id' => $vessel->id,
                'current_type' => $currentType,
                'resolved_type' => $currentNormalized,
                'source' => 'VERIFIED_MANUAL_REVIEW',
                'confidence' => 'authoritative_verified',
                'is_protected' => true,
                'eligible_for_change' => false,
                'has_conflict' => false,
                'conflict_reason' => null,
                'reason' => 'Manually verified by maritime analyst. Protected from automatic alteration.',
            ];
        }

        // Priority 2: Authoritative Registry / IMO data
        $registryType = $this->extractRegistryClassification($vessel);
        if ($registryType && $registryType !== 'unknown') {
            $hasConflict = $currentNormalized !== 'unknown' && $currentNormalized !== $registryType;
            return [
                'vessel_id' => $vessel->id,
                'current_type' => $currentType,
                'resolved_type' => $registryType,
                'source' => 'AUTHORITATIVE_REGISTRY',
                'confidence' => 'high_registry',
                'is_protected' => false,
                'eligible_for_change' => $currentNormalized !== $registryType,
                'has_conflict' => $hasConflict,
                'conflict_reason' => $hasConflict ? "Local type '{$currentType}' contradicts registry type '{$registryType}'." : null,
                'reason' => "Resolved from authoritative vessel registry ({$registryType}).",
            ];
        }

        // Priority 3: GFW API v3 Identity & Classification
        $gfwType = $this->extractGfwClassification($vessel, $queryGfwIfMissing);
        // Rule: Jangan menggunakan GFW sebagai sumber klasifikasi cargo/tanker.
        if ($gfwType && $gfwType !== 'unknown' && !in_array($gfwType, ['cargo', 'tanker'])) {
            $hasConflict = $currentNormalized !== 'unknown' && $currentNormalized !== $gfwType;
            return [
                'vessel_id' => $vessel->id,
                'current_type' => $currentType,
                'resolved_type' => $gfwType,
                'source' => 'GFW_CLASSIFIER_MODEL_AND_REGISTRY',
                'confidence' => 'high_gfw',
                'is_protected' => false,
                'eligible_for_change' => $currentNormalized !== $gfwType,
                'has_conflict' => $hasConflict,
                'conflict_reason' => $hasConflict ? "Local type '{$currentType}' contradicts GFW type '{$gfwType}'." : null,
                'reason' => "Resolved from GFW API v3 identity models ({$gfwType}).",
            ];
        }

        // Priority 4: AIS Static Message data (ShipType in raw payload or metadata)
        $aisType = $this->extractAisShipType($vessel);
        if ($aisType && $aisType !== 'unknown') {
            $hasConflict = $currentNormalized !== 'unknown' && $currentNormalized !== $aisType;
            return [
                'vessel_id' => $vessel->id,
                'current_type' => $currentType,
                'resolved_type' => $aisType,
                'source' => 'AIS_STATIC_MESSAGE_SHIPTYPE',
                'confidence' => 'medium_telemetry',
                'is_protected' => false,
                'eligible_for_change' => $currentNormalized !== $aisType,
                'has_conflict' => $hasConflict,
                'conflict_reason' => $hasConflict ? "Local type '{$currentType}' contradicts AIS ShipType '{$aisType}'." : null,
                'reason' => "Resolved from AIS Type 5/24 static payload ({$aisType}).",
            ];
        }

        // Default Priority 5: Unknown
        return [
            'vessel_id' => $vessel->id,
            'current_type' => $currentType,
            'resolved_type' => $currentNormalized,
            'source' => 'UNRESOLVED_SOURCE',
            'confidence' => 'none',
            'is_protected' => false,
            'eligible_for_change' => false,
            'has_conflict' => false,
            'conflict_reason' => null,
            'reason' => 'No authoritative or telemetry source available to determine classification. Kept as ' . $currentNormalized . '.',
        ];
    }

    /**
     * Batch inspect and optionally classify vessels.
     */
    public function auditAndClassify(array $options = []): array
    {
        $apply = (bool) ($options['apply'] ?? false);
        $vesselId = $options['vessel'] ?? null;
        $queryGfw = (bool) ($options['query_gfw'] ?? false);
        $limit = isset($options['limit']) ? (int) $options['limit'] : 100;

        $query = Vessel::query();
        if ($vesselId) {
            $query->where(function ($q) use ($vesselId) {
                $q->where('id', $vesselId)
                  ->orWhere('mmsi', $vesselId)
                  ->orWhere('imo', $vesselId);
            });
        }

        $vessels = $query->orderBy('name')->limit($limit)->get();

        $stats = [
            'total_inspected' => $vessels->count(),
            'total_unknown' => 0,
            'total_null' => 0,
            'total_eligible_changes' => 0,
            'total_applied' => 0,
            'total_conflicts' => 0,
            'total_protected_skipped' => 0,
            'changes' => [],
            'conflicts' => [],
        ];

        foreach ($vessels as $vessel) {
            if ($vessel->vessel_type === null || $vessel->vessel_type === '') {
                $stats['total_null']++;
            } elseif ($vessel->vessel_type === 'unknown') {
                $stats['total_unknown']++;
            }

            $res = $this->resolveClassification($vessel, $queryGfw);

            if ($res['is_protected']) {
                $stats['total_protected_skipped']++;
            }

            if ($res['has_conflict']) {
                $stats['total_conflicts']++;
                $stats['conflicts'][] = [
                    'vessel_id' => $vessel->id,
                    'name' => $vessel->name,
                    'current' => $res['current_type'],
                    'candidate' => $res['resolved_type'],
                    'source' => $res['source'],
                    'reason' => $res['conflict_reason'],
                ];
            }

            if ($res['eligible_for_change']) {
                $stats['total_eligible_changes']++;
                $changeRecord = [
                    'vessel_id' => $vessel->id,
                    'name' => $vessel->name,
                    'mmsi' => $vessel->mmsi,
                    'current' => $res['current_type'],
                    'resolved' => $res['resolved_type'],
                    'source' => $res['source'],
                    'confidence' => $res['confidence'],
                    'reason' => $res['reason'],
                ];
                $stats['changes'][] = $changeRecord;

                if ($apply) {
                    $metadata = $vessel->metadata ?? [];
                    if (!is_array($metadata)) $metadata = [];

                    $metadata['classification_audit'] = [
                        'previous_type' => $vessel->vessel_type,
                        'new_type' => $res['resolved_type'],
                        'source' => $res['source'],
                        'confidence' => $res['confidence'],
                        'classified_at' => now()->toIso8601String(),
                    ];

                    $vessel->update([
                        'vessel_type' => $res['resolved_type'],
                        'metadata' => $metadata,
                    ]);
                    $stats['total_applied']++;

                    try {
                        AuditLog::create([
                            'action' => 'vessel_classification_repaired',
                            'auditable_type' => Vessel::class,
                            'auditable_id' => $vessel->id,
                            'actor_type' => 'system',
                            'changes' => [
                                'previous_type' => $res['current_type'],
                                'resolved_type' => $res['resolved_type'],
                                'source' => $res['source'],
                            ],
                            'created_at' => now(),
                        ]);
                    } catch (\Throwable $e) {}
                }
            }
        }

        return $stats;
    }

    /**
     * Extract classification from local metadata or identifiers.
     */
    protected function extractRegistryClassification(Vessel $vessel): ?string
    {
        $meta = $vessel->metadata ?? [];
        if (!empty($meta['registry_vessel_type'])) {
            return self::normalize($meta['registry_vessel_type']);
        }
        if (!empty($meta['authoritative_type'])) {
            return self::normalize($meta['authoritative_type']);
        }
        return null;
    }

    /**
     * Extract classification from GFW cached data or API.
     */
    protected function extractGfwClassification(Vessel $vessel, bool $queryIfMissing = false): ?string
    {
        // 1. Check existing GFW metadata on vessel
        $enrichment = $vessel->metadata['enrichment'] ?? [];
        if (!empty($enrichment['raw_gfw_candidate']['combinedSourcesInfo'][0]['shiptypes'][0]['name'])) {
            $raw = $enrichment['raw_gfw_candidate']['combinedSourcesInfo'][0]['shiptypes'][0]['name'];
            return self::normalize($raw);
        }
        if (!empty($enrichment['raw_gfw_candidate']['registryInfo'][0]['geartypes'][0])) {
            $raw = $enrichment['raw_gfw_candidate']['registryInfo'][0]['geartypes'][0];
            return self::normalize($raw);
        }

        // 2. Query GFW if requested and IMO/MMSI present
        if ($queryIfMissing && $this->gfwApi->isConfigured()) {
            $query = $vessel->imo ?: $vessel->mmsi;
            if ($query && preg_match('/^[0-9]+$/', $query)) {
                $search = $this->gfwApi->searchVessels($query);
                if ($search['status'] === 200 && !empty($search['entries'][0])) {
                    $entry = $search['entries'][0];
                    if (!empty($entry['combinedSourcesInfo'][0]['shiptypes'][0]['name'])) {
                        return self::normalize($entry['combinedSourcesInfo'][0]['shiptypes'][0]['name']);
                    }
                    if (!empty($entry['registryInfo'][0]['geartypes'][0])) {
                        return self::normalize($entry['registryInfo'][0]['geartypes'][0]);
                    }
                }
            }
        }

        return null;
    }

    /**
     * Extract AIS ShipType from positions raw payload.
     */
    protected function extractAisShipType(Vessel $vessel): ?string
    {
        $latestPos = $vessel->latestPosition;
        if ($latestPos && !empty($latestPos->raw_payload)) {
            $payload = is_array($latestPos->raw_payload) ? $latestPos->raw_payload : json_decode($latestPos->raw_payload, true);
            if (is_array($payload)) {
                $code = $payload['ShipType'] ?? ($payload['ship_type'] ?? null);
                if ($code !== null) {
                    return self::mapAisCodeToTaxonomy($code);
                }
            }
        }

        return null;
    }
}

