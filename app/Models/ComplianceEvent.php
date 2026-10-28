<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComplianceEvent extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = [];

    protected $casts = [
        'event_time' => 'datetime',
        'latitude' => 'float',
        'longitude' => 'float',
        'supporting_data' => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(ComplianceRule::class, 'compliance_rule_id');
    }

    public function vessel(): BelongsTo
    {
        return $this->belongsTo(Vessel::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function scopeActiveAlerts(Builder $query): Builder
    {
        return $query->whereIn('status', ['open', 'under_review']);
    }
}
