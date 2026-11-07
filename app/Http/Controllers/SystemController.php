<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Services\SystemHealthService;
use Illuminate\Http\Request;

class SystemController extends Controller
{
    public function __construct(
        protected SystemHealthService $healthService
    ) {}

    public function auditLogs(Request $request)
    {
        $logs = AuditLog::with('user')->orderBy('created_at', 'desc')->paginate(25);

        return view('system.audit-logs', compact('logs'));
    }

    public function settings()
    {
        $config = config('maritime');
        $health = $this->healthService->getComprehensiveHealth();

        $backupManifest = $health['backup'] ?? null;
        $recoveryReport = $health['disaster_recovery'] ?? null;
        $offsiteGate = $health['offsite_backup'] ?? null;
        $diskStorage = $health['disk_storage'] ?? null;
        $aisFreshness = $health['ais'] ?? null;
        $alerts = $health['alerts'] ?? [];

        return view('system.settings', compact(
            'config',
            'health',
            'backupManifest',
            'recoveryReport',
            'offsiteGate',
            'diskStorage',
            'aisFreshness',
            'alerts'
        ));
    }

    public function updateOffsiteBackupGate(Request $request)
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:DISABLED,DRY_RUN,READY,ACTIVE'],
            'provider' => ['required', 'string', 'in:S3_COMPATIBLE,AWS_S3,AZURE_BLOB,GCS,SECURE_NAS'],
            'destination_uri' => ['required', 'string', 'max:255'],
            'confirm_operator' => ['required', 'accepted'],
        ], [
            'confirm_operator.accepted' => 'Konfirmasi otorisasi eksplisit operator diperlukan untuk mengubah gerbang offsite backup.',
        ]);

        $dryRun = ($validated['status'] !== 'ACTIVE');

        $this->healthService->setOffsiteBackupStatus(
            $validated['status'],
            $validated['provider'],
            $validated['destination_uri'],
            $dryRun,
            auth()->user()?->name ?? 'super_admin'
        );

        return redirect()->route('settings.index')
            ->with('status', "Status Offsite Backup Activation Gate berhasil diperbarui menjadi [{$validated['status']}]. Seluruh perubahan dicatat pada Audit Log.");
    }

    public function about()
    {
        $gfwConfig = config('maritime.providers.gfw');
        $health = $this->healthService->getGfwStatus();

        return view('pages.about', compact('gfwConfig', 'health'));
    }
}
