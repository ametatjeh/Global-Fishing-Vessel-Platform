<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Port extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'buffer_radius_meters' => 'integer',
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::saved(function (Port $port) {
            if (\Illuminate\Support\Facades\DB::connection()->getDriverName() === 'pgsql') {
                try {
                    \Illuminate\Support\Facades\DB::statement(
                        "UPDATE ports SET geom = ST_SetSRID(ST_MakePoint(?, ?), 4326) WHERE id = ?",
                        [$port->longitude, $port->latitude, $port->id]
                    );
                } catch (\Throwable $e) {
                    // Ignore if extension is absent
                }
            }
        });
    }

    /**
     * Standard buffer exclusion radiuses based on port importance & harbor traffic limits.
     */
    public static function defaultBufferMetersForSize(?string $size): int
    {
        $normalized = strtolower(trim($size ?? ''));

        return match ($normalized) {
            'major', 'large' => 9260,     // 5.0 Nautical Miles (covers major anchorages, basins, and port approach channels)
            'medium', 'minor' => 5556,    // 3.0 Nautical Miles
            'small' => 3704,              // 2.0 Nautical Miles
            'very small' => 2500,         // ~1.35 Nautical Miles
            default => 5000,              // 5,000 meters default (~2.7 NM)
        };
    }

    public function scopeInBoundingBox(Builder $query, float $minLat, float $minLng, float $maxLat, float $maxLng): Builder
    {
        return $query->whereBetween('latitude', [$minLat, $maxLat])
                     ->whereBetween('longitude', [$minLng, $maxLng]);
    }

    public function scopeByCountry(Builder $query, string $country): Builder
    {
        return $query->where('country', $country);
    }
}
