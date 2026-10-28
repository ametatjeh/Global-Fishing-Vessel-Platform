<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MaritimeZone extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = [];

    protected $casts = [
        'restrictions' => 'array',
        'is_active' => 'boolean',
    ];

    public function authorizations(): HasMany
    {
        return $this->hasMany(VesselAuthorization::class);
    }
}
