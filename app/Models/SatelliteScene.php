<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SatelliteScene extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = [];

    protected $casts = [
        'acquisition_start' => 'datetime',
        'acquisition_end' => 'datetime',
        'cloud_cover_percent' => 'float',
        'metadata' => 'array',
    ];

    public function detections(): HasMany
    {
        return $this->hasMany(SatelliteDetection::class);
    }
}
