<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ComplianceRule extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = [];

    protected $casts = [
        'rule_logic' => 'array',
        'is_active' => 'boolean',
    ];

    public function events(): HasMany
    {
        return $this->hasMany(ComplianceEvent::class);
    }
}
