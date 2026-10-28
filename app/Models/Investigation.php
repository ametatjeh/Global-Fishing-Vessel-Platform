<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Investigation extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = [];

    protected $casts = [
        'closed_at' => 'datetime',
    ];

    public function targetVessel(): BelongsTo
    {
        return $this->belongsTo(Vessel::class, 'target_vessel_id');
    }

    public function relatedEncounter(): BelongsTo
    {
        return $this->belongsTo(VesselEncounter::class, 'related_encounter_id');
    }

    public function relatedDetection(): BelongsTo
    {
        return $this->belongsTo(SatelliteDetection::class, 'related_detection_id');
    }

    public function relatedComplianceEvent(): BelongsTo
    {
        return $this->belongsTo(ComplianceEvent::class, 'related_compliance_event_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
