<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VesselAttributeObservation extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'confidence_score' => 'float',
        'is_conflicting' => 'boolean',
        'raw_payload' => 'array',
        'retrieved_at' => 'datetime',
    ];

    public function vessel(): BelongsTo
    {
        return $this->belongsTo(Vessel::class);
    }
}

