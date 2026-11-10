<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FisheriesManagementArea extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'crosses_antimeridian' => 'boolean',
        'import_date' => 'datetime',
        'bbox' => 'array',
    ];

    /**
     * Scope to search by RFB code, name or ocean basin
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (empty($term)) {
            return $query;
        }

        return $query->where(function ($q) use ($term) {
            $q->where('rfb_code', 'ilike', "%{$term}%")
              ->orWhere('name', 'ilike', "%{$term}%")
              ->orWhere('ocean_basin', 'ilike', "%{$term}%");
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
                return $query->where(function ($w) use ($minLng, $maxLng, $minY, $maxY) {
                    $w->whereRaw(
                        "(geom && ST_MakeEnvelope(?, ?, 180, ?, 4326) OR geom && ST_MakeEnvelope(-180, ?, ?, ?, 4326))",
                        [$minLng, $minY, $maxY, $minY, $maxLng, $maxY]
                    )->orWhereNull('geom');
                });
            }

            return $query->where(function ($w) use ($minLng, $maxLng, $minY, $maxY) {
                $w->whereRaw(
                    "geom && ST_MakeEnvelope(?, ?, ?, ?, 4326)",
                    [$minLng, $minY, $maxLng, $maxY]
                )->orWhereNull('geom');
            });
        }

        return $query;
    }
}

