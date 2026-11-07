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

    public const CONFIDENTIALITY_PRIVATE = 'private';
    public const CONFIDENTIALITY_RESTRICTED = 'restricted';
    public const CONFIDENTIALITY_STRICTLY_CONFIDENTIAL = 'strictly_confidential';

    public const VERDICT_CONFIRMED = 'confirmed';
    public const VERDICT_SUSPECTED = 'suspected';
    public const VERDICT_POTENTIAL = 'potential';
    public const VERDICT_UNDER_REVIEW = 'under_review';
    public const VERDICT_INCONCLUSIVE = 'inconclusive';

    public const FOCUS_IDENTITY_FLAG = 'identity_flag';
    public const FOCUS_CORPORATE_OWNERSHIP = 'corporate_ownership';
    public const FOCUS_EEZ_VIOLATION = 'eez_violation';
    public const FOCUS_IUU_FISHING = 'iuu_fishing';

    protected $casts = [
        'closed_at' => 'datetime',
        'collaborator_ids' => 'array',
        'focus_areas' => 'array',
        'evidence_matrix' => 'array',
    ];

    public function leadResearcher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lead_user_id');
    }

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
