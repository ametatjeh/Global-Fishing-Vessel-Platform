<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EezBoundary extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'line_id' => 'integer',
        'length_km' => 'float',
        'geometry_geojson' => 'array',
    ];

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
