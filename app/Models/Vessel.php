<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Vessel extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = [];

    protected $casts = [
        'length_meters' => 'float',
        'beam_meters' => 'float',
        'draught' => 'float',
        'tonnage' => 'float',
        'deadweight_tonnage' => 'float',
        'engine_power_kw' => 'float',
        'build_year' => 'integer',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'last_enriched_at' => 'datetime',
        'classified_at' => 'datetime',
        'static_data_received_at' => 'datetime',
        'ais_ship_type_code' => 'integer',
        'metadata' => 'array',
    ];

    public function identifiers(): HasMany
    {
        return $this->hasMany(VesselIdentifier::class);
    }

    public function attributeObservations(): HasMany
    {
        return $this->hasMany(VesselAttributeObservation::class)->orderBy('retrieved_at', 'desc');
    }

    public function enrichmentJobs(): HasMany
    {
        return $this->hasMany(EnrichmentJob::class)->orderBy('created_at', 'desc');
    }

    public function positions(): HasMany
    {
        return $this->hasMany(VesselPosition::class)->orderBy('observed_at', 'desc');
    }

    public function latestPosition(): HasOne
    {
        return $this->hasOne(VesselPosition::class)->latestOfMany('observed_at');
    }

    public function tracks(): HasMany
    {
        return $this->hasMany(VesselTrack::class);
    }

    public function encountersAsVessel1(): HasMany
    {
        return $this->hasMany(VesselEncounter::class, 'vessel_1_id');
    }

    public function encountersAsVessel2(): HasMany
    {
        return $this->hasMany(VesselEncounter::class, 'vessel_2_id');
    }

    public function authorizations(): HasMany
    {
        return $this->hasMany(VesselAuthorization::class);
    }

    public function complianceEvents(): HasMany
    {
        return $this->hasMany(ComplianceEvent::class);
    }

    public function sourceProvider(): BelongsTo
    {
        return $this->belongsTo(DataProvider::class, 'source_provider_id');
    }

    /**
     * Check whether vessel has recent AIS activity (e.g. within 24 hours)
     */
    public function isRecentlyActive(): bool
    {
        if (!$this->last_seen_at) {
            return false;
        }

        return $this->last_seen_at->gt(now()->subHours(24));
    }

    /**
     * Get resolved Flag State metadata (ITU MID, ISO, Flag Emoji, and verification status)
     */
    public function getFlagInfoAttribute(): array
    {
        return \App\Services\Maritime\FlagStateService::resolveForVessel($this);
    }

    /**
     * Get synchronized display string for Flag State (e.g. "KR • Korea (Republic of)")
     */
    public function getDisplayFlagCountryAttribute(): string
    {
        $info = $this->flag_info;
        if (!empty($info['is_known']) && !empty($info['iso2']) && $info['iso2'] !== 'UN') {
            return "{$info['iso2']} • {$info['country_name']}";
        }

        return 'Unknown';
    }

    /**
     * Get synchronized complete Flag label with emoji (e.g. "🇰🇷 KR • Korea (Republic of)")
     */
    public function getDisplayFlagAttribute(): string
    {
        $info = $this->flag_info;
        if (!empty($info['is_known']) && !empty($info['iso2']) && $info['iso2'] !== 'UN') {
            return "{$info['flag_emoji']} {$info['iso2']} • {$info['country_name']}";
        }

        return '🌐 Unknown';
    }

    /**
     * Get strict Flag State country details without guessing from MMSI or name
     */
    public function getFlagDetailsAttribute(): array
    {
        return \App\Services\Maritime\FlagStateService::resolveCountryDetails($this->flag_state);
    }

    /**
     * Get resolved Owner Country metadata
     */
    public function getOwnerCountryInfoAttribute(): array
    {
        return \App\Services\Maritime\FlagStateService::resolveCountryDetails($this->owner_country);
    }

    /**
     * Get resolved Operator Country metadata
     */
    public function getOperatorCountryInfoAttribute(): array
    {
        return \App\Services\Maritime\FlagStateService::resolveCountryDetails($this->operator_country);
    }

    /**
     * Get synchronized display string for IMO number
     * Strictly verifies IMO format without guessing from MMSI or name:
     * - Preserves all digits (including leading zeroes)
     * - Cleans "IMO" or "IMO-" prefix if present
     * - Rejects values confused with MMSI (matches MMSI or length 9)
     * - Falls back to vessel_identifiers if table column is empty
     * - Returns '—' when missing or invalid
     */
    public function getDisplayImoAttribute(): string
    {
        $raw = trim((string) ($this->imo ?? ''));

        // If empty on column, inspect identifiers relation if loaded
        if ($raw === '' || $raw === '-' || strtolower($raw) === 'unknown' || $raw === '0') {
            if ($this->relationLoaded('identifiers')) {
                $idImo = $this->identifiers->firstWhere('identifier_type', 'imo')?->identifier_value;
                if (!empty($idImo)) {
                    $raw = trim((string) $idImo);
                }
            }
        }

        if ($raw === '' || $raw === '-' || strtolower($raw) === 'unknown' || $raw === '0') {
            return '—';
        }

        // Clean prefix "IMO" or "IMO-" or "IMO "
        $clean = preg_replace('/^IMO[-:\s]*/i', '', $raw);

        // Reject if confused with vessel's MMSI
        if (!empty($this->mmsi) && $clean === trim((string) $this->mmsi)) {
            return '—';
        }

        // Must consist of digits
        if (!ctype_digit($clean)) {
            return '—';
        }

        // Reject if length is 9 digits (likely an MMSI placed in IMO field)
        if (strlen($clean) === 9) {
            return '—';
        }

        return $clean;
    }

    /**
     * Get synchronized display string for Operator Kapal
     */
    public function getDisplayOperatorAttribute(): string
    {
        return !empty($this->operator) ? $this->operator : 'Not available';
    }

    /**
     * Get synchronized display string for Pemilik (Owner)
     * Strictly uses owner field (never falls back to operator).
     */
    public function getDisplayOwnerAttribute(): string
    {
        return !empty($this->owner) ? $this->owner : 'Not available';
    }

    /**
     * Get synchronized human-readable vessel type label based on AIS taxonomy.
     * Replaces ambiguous UNKNOWN with explicit status labels:
     * - Valid categories: FISHING, CARGO, TANKER, etc.
     * - Code 0: NOT AVAILABLE
     * - Not yet received static data: AWAITING STATIC DATA
     * - Unmapped / unrecognized code: UNMAPPED AIS CODE
     */
    public function getDisplayVesselTypeAttribute(): string
    {
        $type = strtolower(trim((string) $this->vessel_type));
        $code = $this->ais_ship_type_code;

        // If explicitly 'not_available' or (code 0 with unknown/empty vessel_type)
        if ($type === 'not_available' || (($type === 'unknown' || empty($type)) && $code === 0)) {
            return 'NOT AVAILABLE';
        }

        // Unmapped code
        if ($type === 'unmapped') {
            return 'UNMAPPED AIS CODE';
        }

        // Awaiting static data (only position report received so far)
        if ((empty($type) || $type === 'unknown') && $code === null && empty($this->static_data_received_at)) {
            return 'AWAITING STATIC DATA';
        }

        if (is_numeric($type)) {
            $type = \App\Services\Maritime\VesselClassificationService::mapAisCodeToTaxonomy((int) $type);
        }

        return match ($type) {
            'fishing' => 'FISHING',
            'towing' => 'TOWING',
            'dredging' => 'DREDGING / UNDERWATER OPS',
            'diving' => 'DIVING OPERATIONS',
            'military' => 'MILITARY OPERATIONS',
            'sailing' => 'SAILING',
            'pleasure' => 'PLEASURE CRAFT',
            'high_speed_craft' => 'HIGH SPEED CRAFT',
            'pilot' => 'PILOT VESSEL',
            'sar' => 'SEARCH AND RESCUE',
            'tug' => 'TUG',
            'port_tender' => 'PORT TENDER',
            'anti_pollution' => 'ANTI-POLLUTION',
            'law_enforcement', 'patrol' => 'LAW ENFORCEMENT',
            'medical' => 'MEDICAL TRANSPORT',
            'passenger' => 'PASSENGER',
            'cargo' => 'CARGO',
            'tanker' => 'TANKER',
            'other', 'non_combatant' => 'OTHER',
            'carrier' => 'CARRIER',
            'support' => 'SUPPORT',
            'research' => 'RESEARCH',
            'not_available' => 'NOT AVAILABLE',
            'unmapped' => 'UNMAPPED AIS CODE',
            'awaiting_static', 'unknown' => 'AWAITING STATIC DATA',
            default => strtoupper(str_replace('_', ' ', $type)),
        };
    }

    /**
     * Five historical unverified vessels protected from classification change and retention purge
     */
    public const HISTORICAL_PROTECTED_MMSIS = [
        '211233660', // WESERLOTSE
        '563051800', // MAERSK MONTE VERDE
        '311002359', // XIN TAI YUAN
        '211443110', // FRANKEN
        '232056936', // HANS
    ];

    /**
     * Determine if vessel identity has been manually verified by an analyst
     */
    public function isManuallyVerified(): bool
    {
        return $this->verification_status === 'verified';
    }

    /**
     * Determine if vessel is Verified Fishing with legitimate evidence (AIS ShipType 30 or manual analyst verification).
     * Strictly enforces non-conflict rule: if AIS telemetry provides a non-fishing code (e.g. 70 Cargo, 80 Tanker),
     * manual verification CANNOT override objective AIS evidence.
     */
    public function isVerifiedFishing(): bool
    {
        if (strtolower((string) $this->vessel_type) !== 'fishing') {
            return false;
        }

        // 1. Conflict check: if AIS ShipType is known and is NOT 30, it CANNOT be verified fishing
        if ($this->ais_ship_type_code !== null && (int) $this->ais_ship_type_code !== 30) {
            return false;
        }

        // 2. Legitimate AIS ShipType 30 (Fishing)
        if ((int) $this->ais_ship_type_code === 30) {
            return true;
        }

        // 3. Manual analyst verification is accepted ONLY when ShipType is null (no conflicting AIS evidence)
        return $this->verification_status === 'verified';
    }

    /**
     * Determine if there is an active conflict between vessel label/verification and objective AIS telemetry.
     * E.g. vessel_type is 'fishing' and/or verification_status is 'verified', BUT ais_ship_type_code is a non-fishing code (e.g. 70, 80).
     */
    public function isClassificationDisputed(): bool
    {
        $isClaimedFishing = strtolower((string) $this->vessel_type) === 'fishing';

        $hasNonFishingAisCode = $this->ais_ship_type_code !== null
            && (int) $this->ais_ship_type_code > 0
            && (int) $this->ais_ship_type_code !== 30;

        return $isClaimedFishing && $hasNonFishingAisCode;
    }

    /**
     * Determine if vessel is Unverified Fishing (classified as fishing but lacks AIS code 30 or analyst verification).
     */
    public function isUnverifiedFishing(): bool
    {
        return strtolower((string) $this->vessel_type) === 'fishing' && !$this->isVerifiedFishing();
    }

    /**
     * Determine if vessel is one of the 5 historical protected vessels.
     */
    public function isHistoricalProtected(): bool
    {
        return in_array((string) $this->mmsi, self::HISTORICAL_PROTECTED_MMSIS, true)
            || $this->verification_status === 'unverified_historical';
    }

    /**
     * Determine if vessel is under Protective Hold:
     * - The 5 historical unverified vessels
     * - Unverified fishing vessels (lacking code 30 & analyst verification)
     * - Disputed vessels with conflicting manual vs AIS evidence
     * - Vessels with verification_status in ('under_review', 'unverified', 'unverified_historical', 'disputed')
     */
    public function isUnderProtectiveHold(): bool
    {
        return $this->isHistoricalProtected()
            || $this->isClassificationDisputed()
            || $this->isUnverifiedFishing()
            || in_array($this->verification_status, ['under_review', 'unverified', 'unverified_historical', 'disputed'], true);
    }

    /**
     * Determine if vessel is Verified Non-Fishing:
     * - vessel_type is not 'fishing' and not 'unknown'
     * - Disputed fishing vessels are strictly excluded (they must NOT automatically become verified non-fishing)
     * - AND either verification_status is 'verified' or has legitimate non-fishing AIS code
     */
    public function isVerifiedNonFishing(): bool
    {
        $raw = strtolower((string) $this->vessel_type);
        if ($raw === 'fishing' || $raw === 'unknown' || empty($raw)) {
            return false;
        }

        if ($this->isClassificationDisputed()) {
            return false;
        }

        if ($this->verification_status === 'verified') {
            return true;
        }

        return $this->ais_ship_type_code !== null && (int) $this->ais_ship_type_code > 0 && (int) $this->ais_ship_type_code !== 30;
    }

    /**
     * Scope query to vessels that meet the Verified Fishing criteria.
     * Centralized to ensure identical logic between Model helper and Retention Service query.
     */
    public function scopeVerifiedFishing($query)
    {
        return $query->where('vessel_type', 'fishing')
            ->where(function ($q) {
                // Legitimate AIS ShipType 30
                $q->where('ais_ship_type_code', 30)
                  // Or manual verification ONLY when ShipType is null or 30 (no conflicting AIS code)
                  ->orWhere(function ($sub) {
                      $sub->where('verification_status', 'verified')
                          ->where(function ($codeQ) {
                              $codeQ->whereNull('ais_ship_type_code')
                                    ->orWhere('ais_ship_type_code', 30);
                          });
                  });
            });
    }

    /**
     * Scope query to vessels under Protective Hold (unverified fishing, historical 5, disputed, or under review).
     */
    public function scopeUnderProtectiveHold($query)
    {
        return $query->where(function ($q) {
            // 1. The 5 historical vessels
            $q->whereIn('mmsi', self::HISTORICAL_PROTECTED_MMSIS)
            // 2. Explicit protective hold or disputed statuses
              ->orWhereIn('verification_status', ['unverified_historical', 'under_review', 'disputed'])
            // 3. Unverified fishing
              ->orWhere(function ($fQ) {
                  $fQ->where('vessel_type', 'fishing')
                     ->where('verification_status', '!=', 'verified')
                     ->where(function ($cQ) {
                         $cQ->whereNull('ais_ship_type_code')
                            ->orWhere('ais_ship_type_code', '!=', 30);
                     });
              })
            // 4. Conflicting classification: fishing + verified BUT carrying non-fishing AIS code (!= 30)
              ->orWhere(function ($cQ) {
                  $cQ->where('vessel_type', 'fishing')
                     ->where('verification_status', 'verified')
                     ->whereNotNull('ais_ship_type_code')
                     ->where('ais_ship_type_code', '!=', 30);
              });
        });
    }

    /**
     * Scope query to vessels with active classification dispute.
     * Claimed as fishing, but AIS ship type code is non-fishing (> 0 and != 30).
     */
    public function scopeDisputedClassification($query)
    {
        return $query->where('vessel_type', 'fishing')
            ->whereNotNull('ais_ship_type_code')
            ->where('ais_ship_type_code', '>', 0)
            ->where('ais_ship_type_code', '!=', 30);
    }

    /**
     * Classification audit logs relation
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'entity_id')
            ->where(function ($q) {
                $q->where('entity_type', 'vessel')
                  ->orWhere('entity_type', self::class);
            })
            ->orderBy('created_at', 'desc');
    }

    /**
     * Get conflict status label for UI.
     */
    public function getConflictStatusAttribute(): string
    {
        if ($this->isClassificationDisputed()) {
            return 'Disputed (Fishing vs AIS ' . $this->ais_ship_type_code . ')';
        }

        if ($this->isHistoricalProtected()) {
            return 'Historical Protected';
        }

        return 'Normal';
    }

    /**
     * Get classification change history from metadata.
     */
    public function getClassificationHistoryAttribute(): array
    {
        $metaHistory = $this->metadata['classification_history'] ?? [];
        if (!is_array($metaHistory)) {
            $metaHistory = [];
        }

        return $metaHistory;
    }


    /**
     * Get alternative names recorded in metadata / identifiers
     */
    public function getAlternativeNamesAttribute(): array
    {
        $names = $this->metadata['alternative_names'] ?? [];
        if (!is_array($names)) {
            $names = [];
        }
        return array_values(array_unique(array_filter($names, fn ($n) => !empty($n) && $n !== $this->name)));
    }

    /**
     * Get enrichment conflicts recorded during multi-source reconciliation
     */
    public function getEnrichmentConflictsAttribute(): array
    {
        return $this->metadata['enrichment']['conflicts'] ?? [];
    }

    /**
     * Get GFW events intelligence summary
     */
    public function getGfwEventsSummaryAttribute(): ?array
    {
        return $this->metadata['enrichment']['events_summary'] ?? null;
    }

    /**
     * Check if vessel has eligible identifiers for GFW v3 API query (MMSI or IMO)
     */
    public function isEligibleForGfwSync(): bool
    {
        $hasImo = !empty($this->imo) && preg_match('/^[0-9]{7}$/', trim($this->imo));
        $hasMmsi = !empty($this->mmsi) && preg_match('/^[0-9]{9}$/', trim($this->mmsi));
        return $hasImo || $hasMmsi;
    }

    /**
     * Get normalized GFW sync status:
     * 'synced', 'syncing', 'not_found', 'match_review_required', 'failed', 'pending'
     */
    public function getGfwSyncStatusAttribute(): string
    {
        $status = $this->enrichment_status ?? 'pending';

        return match ($status) {
            'syncing' => 'syncing',
            'synced', 'enriched' => 'synced',
            'not_found', 'no_match' => 'not_found',
            'match_review_required', 'conflict_detected', 'review_required' => 'match_review_required',
            'failed', 'error' => 'failed',
            default => 'pending',
        };
    }

    /**
     * Get GFW intelligence metadata payload
     */
    public function getGfwIntelligenceAttribute(): array
    {
        return $this->metadata['enrichment'] ?? [];
    }

    /**
     * Scope query to vessels Awaiting AIS Static Data
     */
    public function scopeAwaitingStatic($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('static_data_received_at')
              ->where(function ($sub) {
                  $sub->whereNull('vessel_type')
                      ->orWhere('vessel_type', 'unknown')
                      ->orWhereNull('ais_ship_type_code')
                      ->orWhere('ais_ship_type_code', 0);
              });
        });
    }

    /**
     * Scope query to active vessels awaiting static data (seen within last 24h)
     */
    public function scopeActiveAwaiting($query)
    {
        return $query->awaitingStatic()
            ->whereNotNull('last_seen_at')
            ->where('last_seen_at', '>=', now()->subHours(24));
    }

    /**
     * Distinct AIS Static Data status:
     * - RECEIVED: Valid static data packet received from AIS stream
     * - AWAITING: No static packet received yet from AIS
     * - INVALID: MMSI is missing or malformed
     */
    public function getAisStaticStatusAttribute(): string
    {
        $mmsi = trim((string) ($this->mmsi ?? ''));
        if (empty($mmsi) || !preg_match('/^[0-9]{9}$/', $mmsi) || $mmsi === '000000000' || $mmsi === '111111111') {
            return 'INVALID';
        }

        if ($this->static_data_received_at !== null) {
            return 'RECEIVED';
        }

        return 'AWAITING';
    }

    /**
     * Normalized Enrichment Status for Awaiting Static Data Module:
     * NOT_STARTED, SEARCHING, MATCH_FOUND, PARTIALLY_ENRICHED, ENRICHED, CONFLICTING, NOT_FOUND, PROVIDER_UNAVAILABLE, NOT_CONFIGURED
     */
    public function getNormalizedEnrichmentStatusAttribute(): string
    {
        $raw = strtolower(trim((string) ($this->enrichment_status ?? 'not_started')));

        return match ($raw) {
            'searching', 'syncing' => 'SEARCHING',
            'match_found' => 'MATCH_FOUND',
            'partially_enriched', 'partial' => 'PARTIALLY_ENRICHED',
            'enriched', 'synced' => 'ENRICHED',
            'conflicting', 'conflict_detected', 'match_review_required' => 'CONFLICTING',
            'not_found', 'no_match' => 'NOT_FOUND',
            'provider_unavailable', 'timeout' => 'PROVIDER_UNAVAILABLE',
            'not_configured' => 'NOT_CONFIGURED',
            default => 'NOT_STARTED',
        };
    }

    /**
     * Get multi-source attribute comparison matrix for review UI:
     * | Attribute | Profile Value | AISStream | GFW | Flag / Registry | FAO Record | Conflicting? |
     */
    public function getAttributeMatrixAttribute(): array
    {
        $observations = $this->attributeObservations()->get();
        $bySourceAndAttr = [];
        foreach ($observations as $obs) {
            $source = strtolower($obs->source_name);
            $attr = strtolower($obs->attribute_name);
            if (!isset($bySourceAndAttr[$attr][$source])) {
                $bySourceAndAttr[$attr][$source] = $obs;
            }
        }

        $fields = [
            'name' => 'Vessel Name',
            'mmsi' => 'MMSI',
            'imo' => 'IMO Number',
            'callsign' => 'Call Sign',
            'flag_state' => 'Flag State',
            'vessel_type' => 'Vessel Type',
            'vessel_category' => 'Vessel Category',
            'ais_class' => 'AIS Class',
            'length_meters' => 'Length (LOA)',
            'beam_meters' => 'Beam / Width',
            'draught' => 'Draught',
            'tonnage' => 'Gross Tonnage (GT)',
            'deadweight_tonnage' => 'Deadweight (DWT)',
            'build_year' => 'Year Built',
            'ship_builder' => 'Ship Builder',
            'home_port' => 'Home Port',
            'registry_info' => 'Registry Information',
            'owner' => 'Owner',
            'operator' => 'Operator',
        ];

        $matrix = [];
        foreach ($fields as $key => $label) {
            $profileVal = match ($key) {
                'name' => $this->name,
                'mmsi' => $this->mmsi,
                'imo' => $this->display_imo !== '—' ? $this->display_imo : null,
                'callsign' => $this->callsign,
                'flag_state' => $this->flag_state,
                'vessel_type' => $this->vessel_type,
                'vessel_category' => $this->vessel_category,
                'ais_class' => $this->ais_class,
                'length_meters' => $this->length_meters ? $this->length_meters . ' m' : null,
                'beam_meters' => $this->beam_meters ? $this->beam_meters . ' m' : null,
                'draught' => $this->draught ? $this->draught . ' m' : null,
                'tonnage' => $this->tonnage ? number_format($this->tonnage) . ' GT' : null,
                'deadweight_tonnage' => $this->deadweight_tonnage ? number_format($this->deadweight_tonnage) . ' DWT' : null,
                'build_year' => $this->build_year ? (string) $this->build_year : null,
                'ship_builder' => $this->ship_builder,
                'home_port' => $this->home_port,
                'registry_info' => $this->registry_port,
                'owner' => $this->owner,
                'operator' => $this->operator,
                default => null,
            };

            $aisObs = $bySourceAndAttr[$key]['aisstream'] ?? null;
            $gfwObs = $bySourceAndAttr[$key]['gfw'] ?? null;
            $regObs = $bySourceAndAttr[$key]['flag_registry'] ?? null;
            $faoObs = $bySourceAndAttr[$key]['fao'] ?? null;

            $hasConflict = false;
            $conflictMsg = null;
            foreach ([$aisObs, $gfwObs, $regObs, $faoObs] as $obs) {
                if ($obs && $obs->is_conflicting) {
                    $hasConflict = true;
                    $conflictMsg = $obs->conflict_reason;
                    break;
                }
            }

            $matrix[$key] = [
                'label' => $label,
                'profile' => $profileVal,
                'aisstream' => $aisObs?->attribute_value,
                'gfw' => $gfwObs?->attribute_value,
                'registry' => $regObs?->attribute_value,
                'fao' => $faoObs?->attribute_value,
                'is_conflicting' => $hasConflict,
                'conflict_reason' => $conflictMsg,
                'observations' => array_filter([
                    'aisstream' => $aisObs,
                    'gfw' => $gfwObs,
                    'flag_registry' => $regObs,
                    'fao' => $faoObs,
                ]),
            ];
        }

        return $matrix;
    }
}
