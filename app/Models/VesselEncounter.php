<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VesselEncounter extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = [];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'duration_minutes' => 'integer',
        'min_distance_meters' => 'float',
        'median_distance_meters' => 'float',
        'latitude' => 'float',
        'longitude' => 'float',
        'parameters_used' => 'array',
        'verified_at' => 'datetime',
    ];

    public function vessel1(): BelongsTo
    {
        return $this->belongsTo(Vessel::class, 'vessel_1_id');
    }

    public function vessel2(): BelongsTo
    {
        return $this->belongsTo(Vessel::class, 'vessel_2_id');
    }

    public function encounterVessels(): HasMany
    {
        return $this->hasMany(EncounterVessel::class, 'encounter_id');
    }

    public function sourceProvider(): BelongsTo
    {
        return $this->belongsTo(DataProvider::class, 'source_provider_id');
    }

    public function verifiedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }

    public function scopeInBoundingBox(Builder $query, ?float $minLng, ?float $minLat, ?float $maxLng, ?float $maxLat): Builder
    {
        if ($minLng === null || $minLat === null || $maxLng === null || $maxLat === null) {
            return $query;
        }

        $minY = min($minLat, $maxLat);
        $maxY = max($minLat, $maxLat);

        $query->whereBetween('latitude', [$minY, $maxY]);

        if ($minLng > $maxLng) {
            return $query->where(function ($q) use ($minLng, $maxLng) {
                $q->where('longitude', '>=', $minLng)
                  ->orWhere('longitude', '<=', $maxLng);
            });
        }

        return $query->whereBetween('longitude', [$minLng, $maxLng]);
    }

    public function scopeDateRange(Builder $query, ?string $startDate, ?string $endDate): Builder
    {
        if ($startDate) {
            $query->where('start_time', '>=', $startDate);
        }
        if ($endDate) {
            $query->where('end_time', '<=', $endDate . ' 23:59:59');
        }

        return $query;
    }
}
