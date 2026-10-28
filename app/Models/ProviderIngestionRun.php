<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderIngestionRun extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'metadata_json' => 'array',
        'items_fetched' => 'integer',
        'items_inserted' => 'integer',
        'items_skipped' => 'integer',
    ];

    public function dataProvider(): BelongsTo
    {
        return $this->belongsTo(DataProvider::class);
    }
}
