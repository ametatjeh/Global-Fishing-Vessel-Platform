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
        'tonnage' => 'float',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
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
}
