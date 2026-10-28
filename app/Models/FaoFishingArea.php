<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FaoFishingArea extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'surface_water_sqkm' => 'float',
        'import_date' => 'datetime',
        'bbox' => 'array',
    ];

    /**
     * Scope to search areas by name, code, or ocean basin
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (empty($term)) {
            return $query;
        }

        return $query->where(function ($q) use ($term) {
            $q->where('area_code', 'like', "%{$term}%")
              ->orWhere('name_en', 'like', "%{$term}%")
              ->orWhere('ocean_basin', 'like', "%{$term}%");
        });
    }

    /**
     * Scope to filter by viewport bounding box [min_lng, min_lat, max_lng, max_lat]
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
                // Crosses antimeridian: wrap around 180 / -180
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
