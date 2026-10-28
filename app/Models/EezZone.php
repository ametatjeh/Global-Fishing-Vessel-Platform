<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EezZone extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'mrgid' => 'integer',
        'area_sqkm' => 'float',
        'crosses_antimeridian' => 'boolean',
        'import_date' => 'datetime',
        'bbox' => 'array',
    ];

    /**
     * Scope to search EEZ zones by sovereign state, country code or territory name
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (empty($term)) {
            return $query;
        }

        return $query->where(function ($q) use ($term) {
            $q->where('geoname', 'like', "%{$term}%")
              ->orWhere('sovereign_state', 'like', "%{$term}%")
              ->orWhere('sovereign_iso3', 'like', "%{$term}%");
        });
    }

    /**
     * Scope to filter by viewport bounding box
     */
    public function scopeInBoundingBox(Builder $query, ?float $minLng, ?float $minLat, ?float $maxLng, ?float $maxLat): Builder
    {
        if ($minLng === null || $minLat === null || $maxLng === null || $maxLat === null) {
            return $query;
        }

        $minY = min($minLat, $maxLat);
        $maxY = max($minLat, $maxLat);

        $driver = $query->getConnection()->getDriverName();
        if ($driver === 'pgsql') {
            if ($minLng > $maxLng) {
                return $query->whereRaw(
                    "(geom && ST_MakeEnvelope(?, ?, 180, ?, 4326) OR geom && ST_MakeEnvelope(-180, ?, ?, ?, 4326))",
                    [$minLng, $minY, $maxY, $minY, $maxLng, $maxY]
                );
            }

            return $query->whereRaw(
                "geom && ST_MakeEnvelope(?, ?, ?, ?, 4326)",
                [$minLng, $minY, $maxLng, $maxY]
            );
        }

        return $query;
    }
}
