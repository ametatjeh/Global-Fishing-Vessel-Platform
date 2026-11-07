<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AisRetentionPolicy extends Model
{
    use HasFactory;

    protected $table = 'ais_retention_policies';

    protected $fillable = [
        'vessel_type',
        'label',
        'retention_days',
        'is_immutable',
        'is_enabled',
        'description',
    ];

    protected $casts = [
        'retention_days' => 'integer',
        'is_immutable' => 'boolean',
        'is_enabled' => 'boolean',
    ];

    /**
     * Check if this policy is immutable (e.g. Fishing).
     */
    public function isImmutable(): bool
    {
        return (bool) $this->is_immutable || $this->vessel_type === 'fishing';
    }

    /**
     * Scope for non-fishing vessel policies.
     */
    public function scopeNonFishing($query)
    {
        return $query->where('vessel_type', '!=', 'fishing');
    }
}
