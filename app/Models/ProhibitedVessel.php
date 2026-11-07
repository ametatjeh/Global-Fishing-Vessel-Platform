<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProhibitedVessel extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'exception_granted_at' => 'datetime',
    ];

    public function blockedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocked_by_user_id');
    }

    /**
     * Check if an MMSI is actively prohibited (no active exception)
     */
    public static function isProhibited(string $mmsi): bool
    {
        $cleanMmsi = preg_replace('/\D/', '', $mmsi);
        if (!$cleanMmsi) {
            return false;
        }

        return static::where('mmsi', $cleanMmsi)
            ->whereNull('exception_granted_at')
            ->exists();
    }
}

