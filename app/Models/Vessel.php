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
        'tonnage' => 'float',
        'engine_power_kw' => 'float',
        'build_year' => 'integer',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'last_enriched_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function identifiers(): HasMany
    {
        return $this->hasMany(VesselIdentifier::class);
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
     */
    public function getDisplayVesselTypeAttribute(): string
    {
        $raw = strtolower(trim((string) $this->vessel_type));
        if (empty($raw) || $raw === 'unknown') {
            return 'Unknown';
        }

        if (is_numeric($raw)) {
            $mappedKey = \App\Services\Maritime\VesselClassificationService::mapAisCodeToTaxonomy((int) $raw);
            return \App\Services\Maritime\VesselClassificationService::TAXONOMY[$mappedKey] ?? 'Unknown';
        }

        return \App\Services\Maritime\VesselClassificationService::TAXONOMY[$raw]
            ?? ucwords(str_replace('_', ' ', $raw));
    }

    /**
     * Determine if vessel identity has been manually verified by an analyst
     */
    public function isManuallyVerified(): bool
    {
        return $this->verification_status === 'verified';
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
}
