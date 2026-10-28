<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EncounterVessel extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = [];

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(VesselEncounter::class, 'encounter_id');
    }

    public function vessel(): BelongsTo
    {
        return $this->belongsTo(Vessel::class);
    }
}
