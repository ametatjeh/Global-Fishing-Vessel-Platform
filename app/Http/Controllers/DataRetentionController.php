<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Services\DataRetentionPolicyService;
use Illuminate\Http\Request;

class DataRetentionController extends Controller
{
    public function __construct(
        protected DataRetentionPolicyService $retentionService
    ) {}

    /**
     * Display the AIS Data Retention Policy management interface.
     */
    public function index(Request $request)
    {
        $policies = $this->retentionService->getPolicies();
        $settings = $this->retentionService->getSettings();
        $stats = $this->retentionService->getStorageStatistics();
        $nextRun = $this->retentionService->getNextScheduledRun();

        $recentLogs = AuditLog::with('user')
            ->whereIn('entity_type', ['ais_retention_policy', 'ais_retention_cleanup'])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        $simulation = session('simulation');

        return view('settings.retention', compact(
            'policies',
            'settings',
            'stats',
            'nextRun',
            'recentLogs',
            'simulation'
        ));
    }

    /**
     * Update AIS retention policies and automatic cleanup settings.
     */
    public function update(Request $request)
    {
        $validated = $request->validate([
            'policies' => ['required', 'array'],
            'policies.*.retention_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'policies.*.is_enabled' => ['nullable'],
            'settings' => ['required', 'array'],
            'settings.auto_cleanup_enabled' => ['nullable'],
            'settings.cleanup_schedule' => ['required', 'string', 'in:daily,weekly,monthly'],
            'settings.cleanup_time' => ['required', 'string', 'regex:/^(?:2[0-3]|[01][0-9]):[0-5][0-9]$/'],
        ], [
            'settings.cleanup_time.regex' => 'Format waktu pembersihan harus HH:MM (contoh 03:00).',
            'policies.*.retention_days.min' => 'Durasi retensi minimal 1 hari.',
            'policies.*.retention_days.max' => 'Durasi retensi maksimal 3650 hari (10 tahun).',
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
    public function simulate(Request $request)
    {
        $simulation = $this->retentionService->simulateRetentionPurge();

        return redirect()->route('settings.retention.index')
            ->with('simulation', $simulation)
            ->with('status', 'Simulasi dry-run retensi berhasil diselesaikan. Tidak ada data yang dihapus dari database.');
    }

    /**
     * Manually execute retention cleanup (Super Admin authorization).
     */
    public function executeManualPurge(Request $request)
    {
        $request->validate([
            'confirm_purge' => ['required', 'accepted'],
        ], [
            'confirm_purge.accepted' => 'Anda harus mencentang konfirmasi pembersihan manual untuk mengeksekusi tindakan ini.',
        ]);

        $result = $this->retentionService->executeRetentionPurge(
            force: true,
            userId: $request->user()->id
        );

        if ($result['success']) {
            return redirect()->route('settings.retention.index')
                ->with('status', $result['message']);
        }

        return redirect()->route('settings.retention.index')
            ->with('warning', $result['message']);
    }
}
