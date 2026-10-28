<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VesselPosition extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = [];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'speed_knots' => 'float',
        'course_degrees' => 'float',
        'heading_degrees' => 'float',
        'data_quality_score' => 'float',
        'is_gap_indicator' => 'boolean',
        'observed_at' => 'datetime',
        'received_at' => 'datetime',
        'raw_payload' => 'array',
    ];

    public function vessel(): BelongsTo
    {
        return $this->belongsTo(Vessel::class);
    }

    public function sourceProvider(): BelongsTo
    {
        return $this->belongsTo(DataProvider::class, 'source_provider_id');
    }

    /**
     * Check if position is considered fresh (e.g. within 2 hours of its observation time)
     */
    public function isFresh(): bool
    {
        return $this->observed_at && $this->observed_at->gt(now()->subHours(2));
    }
}
