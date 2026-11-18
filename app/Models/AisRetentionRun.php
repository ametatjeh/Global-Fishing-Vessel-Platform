<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AisRetentionRun extends Model
{
    use HasFactory;

    protected $table = 'ais_retention_runs';

    protected $fillable = [
        'run_type',
        'status',
        'is_dry_run',
        'chunk_size',
        'batch_count',
        'total_records_purged',
        'purged_by_type',
        'target_policies',
        'summary',
        'started_at',
        'completed_at',
        'duration_seconds',
        'error_message',
        'initiated_by_user_id',
    ];

    protected $casts = [
        'is_dry_run' => 'boolean',
        'chunk_size' => 'integer',
        'batch_count' => 'integer',
        'total_records_purged' => 'integer',
        'purged_by_type' => 'array',
        'target_policies' => 'array',
        'summary' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'duration_seconds' => 'float',
    ];

    public function initiatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }

    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['pending', 'running'], true);
    }

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            'completed' => 'bg-emerald-500/20 text-emerald-300 border-emerald-500/40',
            'running' => 'bg-cyan-500/20 text-cyan-300 border-cyan-500/40 animate-pulse',
            'pending' => 'bg-amber-500/20 text-amber-300 border-amber-500/40',
            'failed' => 'bg-rose-500/20 text-rose-300 border-rose-500/40',
            'cancelled' => 'bg-slate-700/40 text-slate-400 border-slate-600',
            default => 'bg-slate-800 text-slate-300 border-slate-700',
        };
    }

    public function formattedDuration(): string
    {
        if ($this->duration_seconds === null) {
            if ($this->started_at && $this->isRunning()) {
                $secs = (int) now()->diffInSeconds($this->started_at);
                return "{$secs}s (Running)";
            }
            return '—';
        }

        $secs = (float) $this->duration_seconds;
        if ($secs < 60) {
            return round($secs, 1) . 's';
        }

        $mins = floor($secs / 60);
        $remSecs = round($secs % 60);
        return "{$mins}m {$remSecs}s";
    }

    public function typeLabel(): string
    {
        return match ($this->run_type) {
            'manual_purge' => 'Manual Purge (Ad-Hoc)',
            'scheduled_cleanup' => 'Scheduled Automatic Purge',
            'simulation' => 'Dry-Run Simulation',
            'recalculate_stats' => 'Recalculate Metrics',
            default => ucfirst(str_replace('_', ' ', $this->run_type)),
        };
    }
}
