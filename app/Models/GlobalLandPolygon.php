<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class GlobalLandPolygon extends Model
{
    protected $table = 'global_land_polygons';

    protected $guarded = [];

    protected $casts = [
        'min_lat' => 'float',
        'max_lat' => 'float',
        'min_lng' => 'float',
        'max_lng' => 'float',
    ];

    /**
     * Scope to quickly filter candidate polygons by bounding box.
     */
    public function scopeCoveringPoint(Builder $query, float $latitude, float $longitude): Builder
    {
        return $query->where('min_lat', '<=', $latitude)
            ->where('max_lat', '>=', $latitude)
            ->where('min_lng', '<=', $longitude)
            ->where('max_lng', '>=', $longitude);
    }
}

