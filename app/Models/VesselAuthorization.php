<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VesselAuthorization extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = [];

    protected $casts = [
        'valid_from' => 'datetime',
        'valid_to' => 'datetime',
        'conditions' => 'array',
    ];

    public function vessel(): BelongsTo
    {
        return $this->belongsTo(Vessel::class);
    }

    public function maritimeZone(): BelongsTo
    {
        return $this->belongsTo(MaritimeZone::class);
    }

    public function isValid(): bool
    {
        $now = now();
        return $this->status === 'active' &&
               $this->valid_from->lte($now) &&
               $this->valid_to->gte($now);
    }
}
