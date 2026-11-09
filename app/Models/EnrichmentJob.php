<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnrichmentJob extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'sources_attempted' => 'array',
        'matched_attributes_count' => 'integer',
        'conflicts_count' => 'integer',
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function vessel(): BelongsTo
    {
        return $this->belongsTo(Vessel::class);
    }
}

