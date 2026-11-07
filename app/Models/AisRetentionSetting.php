<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AisRetentionSetting extends Model
{
    use HasFactory;

    protected $table = 'ais_retention_settings';

    protected $fillable = [
        'auto_cleanup_enabled',
        'cleanup_schedule',
        'cleanup_time',
        'chunk_size',
        'last_cleanup_at',
        'last_cleanup_records_purged',
        'last_cleanup_status',
        'last_cleanup_summary',
        'updated_by',
    ];

    protected $casts = [
        'auto_cleanup_enabled' => 'boolean',
        'chunk_size' => 'integer',
        'last_cleanup_records_purged' => 'integer',
        'last_cleanup_at' => 'datetime',
        'last_cleanup_summary' => 'array',
    ];

    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Get or create the singleton settings record.
     */
    public static function instance(): self
    {
        return self::firstOrCreate(
            ['id' => 1],
            [
                'auto_cleanup_enabled' => false,
                'cleanup_schedule' => 'daily',
                'cleanup_time' => '03:00',
                'chunk_size' => 1000,
            ]
        );
    }
}
