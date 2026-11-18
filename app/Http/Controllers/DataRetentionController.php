<?php

namespace App\Http\Controllers;

use App\Jobs\ExecuteRetentionCleanupJob;
use App\Models\AisRetentionRun;
use App\Models\AuditLog;
use App\Services\DataRetentionPolicyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DataRetentionController extends Controller
{
    public function __construct(
        protected DataRetentionPolicyService $retentionService
    ) {}

    /**
     * Display the AIS Data Retention Policy management interface.
     */
    public function index(Request $request): View
    {
        $policies = $this->retentionService->getPolicies();
        $settings = $this->retentionService->getSettings();
        $stats = $this->retentionService->getStorageStatistics();
        $encounterStats = $this->retentionService->getEncounterStorageStatistics();
        $nextRun = $this->retentionService->getNextScheduledRun();

        // Active running or pending background retention job
        $activeRun = AisRetentionRun::whereIn('status', ['pending', 'running'])->latest()->first();

        // Latest completed run for quick reference
        $latestRun = AisRetentionRun::where('status', 'completed')->latest()->first();

        // Paginated retention execution history
        $runs = AisRetentionRun::with('initiatedByUser')
            ->orderByDesc('created_at')
            ->paginate(10, ['*'], 'runs_page');

        // Paginated audit logs for retention policy changes and cleanups
        $recentLogs = AuditLog::with('user')
            ->whereIn('entity_type', ['ais_retention_policy', 'ais_retention_cleanup', 'ais_retention_run'])
            ->orderByDesc('created_at')
            ->paginate(10, ['*'], 'logs_page');

        $simulation = session('simulation') ?? ($latestRun?->is_dry_run ? $latestRun->summary : null);

        return view('settings.retention', compact(
            'policies',
            'settings',
            'stats',
            'encounterStats',
            'nextRun',
            'activeRun',
            'latestRun',
            'runs',
            'recentLogs',
            'simulation'
        ));
    }

    /**
     * Update AIS retention policies and automatic cleanup settings.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'policies' => ['required', 'array'],
            'policies.*.retention_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'policies.*.is_enabled' => ['nullable'],
            'settings' => ['required', 'array'],
            'settings.auto_cleanup_enabled' => ['nullable'],
            'settings.cleanup_schedule' => ['required', 'string', 'in:daily,weekly,monthly'],
            'settings.cleanup_time' => ['required', 'string', 'regex:/^(?:2[0-3]|[01][0-9]):[0-5][0-9]$/'],
            'settings.chunk_size' => ['nullable', 'integer', 'min:100', 'max:5000'],
        ], [
            'settings.cleanup_time.regex' => 'Cleanup time must be in HH:MM format (e.g. 03:00).',
            'policies.*.retention_days.min' => 'Retention duration must be at least 1 day.',
            'policies.*.retention_days.max' => 'Retention duration cannot exceed 3650 days (10 years).',
            'settings.chunk_size.min' => 'Batch chunk size must be at least 100.',
            'settings.chunk_size.max' => 'Batch chunk size cannot exceed 5000.',
        ]);

        $policiesData = $validated['policies'] ?? [];
        $settingsData = $validated['settings'] ?? [];

        // Ensure auto_cleanup_enabled is boolean
        $settingsData['auto_cleanup_enabled'] = !empty($settingsData['auto_cleanup_enabled']);

        $result = $this->retentionService->updatePoliciesAndSettings(
            $policiesData,
            $settingsData,
            $request->user()
        );

        return redirect()->route('settings.retention.index')
            ->with('status', $result['message']);
    }

    /**
     * Execute a safe dry-run simulation without touching data.
     */
    public function simulate(Request $request): JsonResponse|RedirectResponse
    {
        // Create an audit run tracking record
        $run = AisRetentionRun::create([
            'run_type' => 'simulation',
            'status' => 'pending',
            'is_dry_run' => true,
            'initiated_by_user_id' => $request->user()->id,
            'target_policies' => $this->retentionService->getPolicies()->toArray(),
        ]);

        // When running in testing or sync queue, execute immediately for backward-compatibility
        if (config('queue.default') === 'sync' || app()->environment('testing')) {
            $simulation = $this->retentionService->simulateRetentionPurge($run);

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'run_id' => $run->id,
                    'status' => 'completed',
                    'simulation' => $simulation,
                    'message' => 'Retention dry-run simulation completed successfully.',
                ]);
            }

            return redirect()->route('settings.retention.index')
                ->with('simulation', $simulation)
                ->with('status', 'Retention dry-run simulation completed successfully. No records were modified or deleted from the database.');
        }

        // Asynchronous queue execution (prevents Cloudflare 524 HTTP timeouts on large databases)
        ExecuteRetentionCleanupJob::dispatch($run);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'run_id' => $run->id,
                'status' => 'pending',
                'message' => 'Simulasi pemeriksaan retensi data AIS telah dijadwalkan dan sedang diproses di antrean latar belakang.',
            ]);
        }

        return redirect()->route('settings.retention.index')
            ->with('status', 'Simulasi pemeriksaan retensi data AIS telah dijadwalkan dan sedang diproses di antrean latar belakang.');
    }

    /**
     * Manually execute retention cleanup (Super Admin authorization).
     */
    public function executeManualPurge(Request $request): JsonResponse|RedirectResponse
    {
        $request->validate([
            'confirm_purge' => ['required', 'accepted'],
        ], [
            'confirm_purge.accepted' => 'You must check the confirmation box to authorize manual cleanup execution.',
        ]);

        $settings = $this->retentionService->getSettings();

        // Create an audit run tracking record
        $run = AisRetentionRun::create([
            'run_type' => 'manual_purge',
            'status' => 'pending',
            'is_dry_run' => false,
            'chunk_size' => $settings->chunk_size ?: 1000,
            'initiated_by_user_id' => $request->user()->id,
            'target_policies' => $this->retentionService->getPolicies()->toArray(),
        ]);

        // When running in testing or sync queue, execute immediately
        if (config('queue.default') === 'sync' || app()->environment('testing')) {
            $result = $this->retentionService->executeRetentionPurge(
                force: true,
                userId: $request->user()->id,
                run: $run
            );

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => $result['success'],
                    'run_id' => $run->id,
                    'status' => $result['success'] ? 'completed' : 'failed',
                    'records_purged' => $result['records_purged'],
                    'message' => $result['message'],
                ]);
            }

            if ($result['success']) {
                return redirect()->route('settings.retention.index')
                    ->with('status', $result['message']);
            }

            return redirect()->route('settings.retention.index')
                ->with('warning', $result['message']);
        }

        // Asynchronous queue execution: prevents Cloudflare 524 timeouts and server resource starvation
        ExecuteRetentionCleanupJob::dispatch($run);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'run_id' => $run->id,
                'status' => 'pending',
                'message' => 'Pembersihan retensi data AIS sedang dijalankan di antrean latar belakang dalam batch terkontrol.',
            ]);
        }

        return redirect()->route('settings.retention.index')
            ->with('status', 'Pembersihan retensi data AIS sedang dijalankan di antrean latar belakang dalam batch terkontrol.');
    }

    /**
     * Return live status of an active or recent retention run (for frontend polling).
     */
    public function runStatus(AisRetentionRun $run): JsonResponse
    {
        return response()->json([
            'id' => $run->id,
            'status' => $run->status,
            'run_type' => $run->run_type,
            'type_label' => $run->typeLabel(),
            'is_dry_run' => $run->is_dry_run,
            'total_records_purged' => $run->total_records_purged,
            'batch_count' => $run->batch_count,
            'duration_seconds' => $run->duration_seconds,
            'formatted_duration' => $run->formattedDuration(),
            'is_active' => $run->isActive(),
            'error_message' => $run->error_message,
            'summary' => $run->summary,
            'started_at' => $run->started_at?->format('Y-m-d H:i:s'),
            'completed_at' => $run->completed_at?->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Cancel an in-progress retention run (Super Admin authorization).
     */
    public function cancelRun(Request $request, AisRetentionRun $run): JsonResponse|RedirectResponse
    {
        if ($run->isActive()) {
            $run->update([
                'status' => 'cancelled',
                'completed_at' => now(),
                'error_message' => 'Proses retensi dibatalkan oleh Super Admin.',
            ]);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => "Proses retensi #{$run->id} berhasil dibatalkan.",
            ]);
        }

        return redirect()->route('settings.retention.index')
            ->with('status', "Proses retensi #{$run->id} berhasil dibatalkan.");
    }

    /**
     * Force recalculate fresh storage metrics and refresh the statistics cache.
     */
    public function refreshStats(Request $request): RedirectResponse
    {
        $this->retentionService->getStorageStatistics(forceFresh: true);
        $this->retentionService->getEncounterStorageStatistics(forceFresh: true);

        return redirect()->route('settings.retention.index')
            ->with('status', 'Statistik retensi data AIS berhasil diperbarui secara langsung dari database.');
    }

    /**
     * Purge all historical Vessel Encounter Events (Super Admin authorization).
     */
    public function purgeEncounters(Request $request): JsonResponse|RedirectResponse
    {
        $request->validate([
            'confirm_encounter_purge' => ['required', 'accepted'],
        ], [
            'confirm_encounter_purge.accepted' => 'Anda harus mencentang kotak konfirmasi untuk menyetujui penghapusan seluruh data Vessel Encounter.',
        ]);

        $run = AisRetentionRun::create([
            'run_type' => 'encounter_purge',
            'status' => 'pending',
            'is_dry_run' => false,
            'chunk_size' => 1000,
            'initiated_by_user_id' => $request->user()->id,
            'target_policies' => ['type' => 'all_vessel_encounters'],
        ]);

        if (config('queue.default') === 'sync' || app()->environment('testing')) {
            $result = $this->retentionService->purgeAllVesselEncounters(
                chunkSize: 1000,
                userId: $request->user()->id,
                run: $run
            );

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => $result['success'],
                    'run_id' => $run->id,
                    'status' => $result['success'] ? 'completed' : 'failed',
                    'deleted_encounters' => $result['deleted_encounters'],
                    'deleted_participants' => $result['deleted_participants'],
                    'message' => $result['message'],
                ]);
            }

            return redirect()->route('settings.retention.index')
                ->with('status', $result['message']);
        }

        ExecuteRetentionCleanupJob::dispatch($run);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'run_id' => $run->id,
                'status' => 'pending',
                'message' => 'Penghapusan seluruh data Vessel Encounter Events sedang diproses di antrean latar belakang.',
            ]);
        }

        return redirect()->route('settings.retention.index')
            ->with('status', 'Penghapusan seluruh data Vessel Encounter Events sedang diproses di antrean latar belakang.');
    }

    /**
     * Return live JSON statistics for Vessel Encounter Events.
     */
    public function encounterStats(Request $request): JsonResponse
    {
        $fresh = $request->boolean('fresh');
        $stats = $this->retentionService->getEncounterStorageStatistics(forceFresh: $fresh);

        return response()->json($stats);
    }
}
