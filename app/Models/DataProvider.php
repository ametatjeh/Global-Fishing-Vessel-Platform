<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DataProvider extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'is_enabled' => 'boolean',
        'commercial_use_allowed' => 'boolean',
        'retention_allowed' => 'boolean',
        'redistribution_allowed' => 'boolean',
        'rate_limit_info' => 'array',
        'config_meta' => 'array',
        'last_health_check_at' => 'datetime',
        'last_sync_at' => 'datetime',
    ];

    public function ingestionRuns(): HasMany
    {
        return $this->hasMany(ProviderIngestionRun::class);
    }

    public function vessels(): HasMany
    {
        return $this->hasMany(Vessel::class, 'source_provider_id');
    }
}
