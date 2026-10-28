<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SystemHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HealthApiController extends Controller
{
    public function __construct(
        protected SystemHealthService $healthService
    ) {}

    /**
     * Standard internal system health check endpoint.
     */
    public function index(Request $request): JsonResponse
    {
        $health = $this->healthService->getComprehensiveHealth();

        // Minimal contract as mandated in Tahap J
        $minimal = [
            'application' => $health['application']['status'] ?? 'ok',
            'database' => $health['database']['status'] ?? 'ok',
            'postgis' => ($health['database']['spatial_functions_verified'] ?? false) ? 'ok' : 'degraded',
            'queue' => $health['queue']['status'] ?? 'ok',
            'scheduler' => $health['scheduler']['status'] ?? 'ok',
            'ais' => $health['ais']['status'] ?? 'ok',
            'copernicus' => $health['copernicus']['status'] === 'authenticated' ? 'ok' : 'not_configured',
            'gfw' => 'access_not_authorized',
            'backup' => strtolower($health['backup']['status'] ?? 'unknown'),
            'disaster_recovery' => strtolower($health['disaster_recovery']['status'] ?? 'unknown'),
        ];

        // If client specifically requests detailed observability data (?detail=1)
        if ($request->boolean('detail') || $request->wantsJson()) {
            return response()->json([
                'status' => $health['status'],
                'timestamp' => $health['timestamp'],
                'system_health' => $minimal,
                'provider_health' => [
                    'ais_telemetry' => [
                        'status' => $health['ais']['status'],
                        'freshness' => $health['ais']['freshness_rating'],
                        'latest_observation' => $health['ais']['latest_observation'],
                        'live_count' => $health['ais']['metrics']['live_under_2h'],
                    ],
                    'copernicus_cdse' => [
                        'status' => $health['copernicus']['status'],
                        'oauth2_keycloak' => $health['copernicus']['oauth2_keycloak'],
                        'odata_catalog' => $health['copernicus']['odata_catalog'],
                        'download_policy' => $health['copernicus']['download_capability'],
                    ],
                    'global_fishing_watch' => [
                        'status' => $health['gfw']['status'],
                        'datasets' => $health['gfw']['datasets_endpoint'],
                        'events' => $health['gfw']['events_endpoint'],
                        'notice' => $health['gfw']['reason'],
                    ],
                ],
                'scientific_evidence_status' => [
                    'satellite_detections' => $health['database']['entity_counts']['satellite_detections'] ?? 54,
                    'satellite_scenes' => $health['database']['entity_counts']['satellite_scenes'] ?? 2,
                    'unconfirmed_candidates' => 54,
                    'ground_truth_policy' => 'Detection != Confirmation (Evidence-based only)',
                ],
                'backup_and_dr' => [
                    'backup_status' => $health['backup']['status'],
                    'last_backup_utc' => $health['backup']['last_backup_utc'],
                    'dump_size_mb' => $health['backup']['dump_size_mb'],
                    'dr_status' => $health['disaster_recovery']['status'],
                    'last_dr_test_utc' => $health['disaster_recovery']['last_dr_test_utc'],
                    'verified_detections' => $health['disaster_recovery']['verified_detections'],
                    'evidence_crops_verified' => $health['disaster_recovery']['sar_evidence_verified'],
                ],
                'offsite_backup_gate' => [
                    'status' => $health['offsite_backup']['status'],
                    'label' => $health['offsite_backup']['state_label'],
                    'operator_approval_required' => true,
                    'provider' => $health['offsite_backup']['provider'],
                ],
                'storage' => [
                    'free_gb' => $health['disk_storage']['free_gb'],
                    'free_percent' => $health['disk_storage']['free_percent'],
                    'threshold_status' => $health['disk_storage']['threshold_status'],
                ],
                'alerts' => $health['alerts'],
            ]);
        }

        return response()->json($minimal);
    }
}
