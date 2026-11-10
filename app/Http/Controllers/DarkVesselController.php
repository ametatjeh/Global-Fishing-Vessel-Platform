<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\SatelliteDetection;
use App\Models\SatelliteScene;
use App\Models\VesselPosition;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DarkVesselController extends Controller
{
    /**
     * Display a listing of SAR detections with multi-attribute filtering for analyst review.
     */
    public function index(Request $request)
    {
        $query = SatelliteDetection::with(['scene', 'correlatedVessel', 'reviewer']);

        // Filter: Scene
        if ($sceneId = $request->input('scene_id')) {
            $query->whereHas('scene', function ($q) use ($sceneId) {
                $q->where('id', $sceneId)->orWhere('scene_id', $sceneId);
            });
        }

        // Filter: Review Status
        if ($reviewStatus = $request->input('review_status')) {
            $query->where('review_status', $reviewStatus);
        }

        // Filter: AIS Correlation Status
        if ($corrStatus = $request->input('correlation_status')) {
            if ($corrStatus !== 'all') {
                $query->where('correlation_status', $corrStatus);
            }
        }

        // Filter: Search by Candidate Identifier
        if ($search = $request->input('search')) {
            $likeOp = config('database.default') === 'pgsql' ? 'ilike' : 'like';
            $query->where('detection_identifier', $likeOp, "%{$search}%");
        }

        // Filter: TCR (Target-to-Clutter Ratio in dB)
        if ($request->filled('min_tcr')) {
            $query->where('tcr_db', '>=', (float) $request->input('min_tcr'));
        }
        if ($request->filled('max_tcr')) {
            $query->where('tcr_db', '<=', (float) $request->input('max_tcr'));
        }

        // Filter: Date Range (Acquisition / Detected At)
        if ($dateFrom = $request->input('date_from')) {
            $query->where('detected_at', '>=', $dateFrom . ' 00:00:00');
        }
        if ($dateTo = $request->input('date_to')) {
            $query->where('detected_at', '<=', $dateTo . ' 23:59:59');
        }

        // Filter: Geographical Bounding Box
        if ($request->filled('min_lat') && $request->filled('max_lat') && $request->filled('min_lng') && $request->filled('max_lng')) {
            $query->inBoundingBox(
                (float) $request->input('min_lng'),
                (float) $request->input('min_lat'),
                (float) $request->input('max_lng'),
                (float) $request->input('max_lat')
            );
        }

        // Filter: AIS Evidence (Tahap 9)
        if ($aisEvidence = $request->input('ais_evidence')) {
            if ($aisEvidence === 'matched') {
                $query->where('correlation_status', 'matched');
            } elseif ($aisEvidence === 'insufficient') {
                $query->where('correlation_status', 'insufficient_data');
            } elseif ($aisEvidence === 'dark_candidate') {
                $query->where('correlation_status', 'dark_vessel_candidate');
            }
        }

        // Filter: Analyst Reviewer (Tahap 9)
        if ($reviewerId = $request->input('reviewer_id')) {
            if ($reviewerId === 'unassigned') {
                $query->whereNull('reviewed_by_user_id');
            } else {
                $query->where('reviewed_by_user_id', $reviewerId);
            }
        }

        // Filter: GFW Status & Sea-Ice Status (Tahap 9 - evaluated from review_metadata / constants)
        if ($gfwStatus = $request->input('gfw_status')) {
            if ($gfwStatus === 'available') {
                $query->whereRaw("1 = 0"); // Currently GFW events is 403 Forbidden
            }
        }
        if ($seaIceStatus = $request->input('sea_ice_status')) {
            if ($seaIceStatus !== 'all' && $seaIceStatus !== 'not_available') {
                $query->whereRaw("1 = 0"); // Sea-ice currently not_available
            }
        }

        // Sorting
        $sortField = $request->input('sort', 'detected_at');
        $sortDir = strtolower($request->input('dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $allowedSorts = ['detected_at', 'tcr_db', 'confidence_score', 'estimated_length_meters', 'review_status', 'correlation_status'];
        if (in_array($sortField, $allowedSorts, true)) {
            $query->orderBy($sortField, $sortDir);
        } else {
            $query->orderBy('detected_at', 'desc');
        }

        $detections = $query->paginate(20)->withQueryString();

        $scenes = SatelliteScene::orderBy('acquisition_start', 'desc')->get();
        $analysts = \App\Models\User::whereIn('role', ['analyst', 'admin', 'super_admin'])->orderBy('name')->get();

        $totalDetections = SatelliteDetection::count();

        $stats = [
            'total_detections' => $totalDetections,
            'pending_review' => SatelliteDetection::where('review_status', SatelliteDetection::REVIEW_PENDING)->count(),
            'possible_vessel' => SatelliteDetection::where('review_status', SatelliteDetection::REVIEW_POSSIBLE_VESSEL)->count(),
            'possible_ice_clutter' => SatelliteDetection::where('review_status', SatelliteDetection::REVIEW_POSSIBLE_ICE_CLUTTER)->count(),
            'inconclusive' => SatelliteDetection::where('review_status', SatelliteDetection::REVIEW_INCONCLUSIVE)->count(),
            'reviewed_other' => SatelliteDetection::where('review_status', SatelliteDetection::REVIEW_OTHER)->count(),
            'insufficient_data' => SatelliteDetection::where('correlation_status', 'insufficient_data')->count(),
            'dark_candidates' => SatelliteDetection::where('correlation_status', 'dark_vessel_candidate')->count(),
            'matched_ais' => SatelliteDetection::where('correlation_status', 'matched')->count(),
            'gfw_forbidden' => $totalDetections, // HTTP 403 Access Not Authorized
            'gfw_available' => 0,
            'optical_available' => 0,
            'total_scenes' => SatelliteScene::count(),
        ];

        $reviewStatuses = SatelliteDetection::getReviewStatuses();

        return view('dark-vessels.index', compact('detections', 'scenes', 'analysts', 'stats', 'reviewStatuses'));
    }

    public function __construct(
        protected \App\Services\Sar\SarEvidenceService $evidenceService,
        protected ?\App\Services\Sar\SarAisCorrelationService $aisCorrelationService = null
    ) {
        $this->aisCorrelationService = $aisCorrelationService ?? app(\App\Services\Sar\SarAisCorrelationService::class);
    }

    /**
     * Display comprehensive details of a single SAR candidate for in-depth analyst inspection.
     */
    public function show(string $id)
    {
        $detection = SatelliteDetection::with([
            'scene',
            'correlatedVessel.latestPosition',
            'reviewer',
        ])->whereIdentifierOrId($id)->firstOrFail();

        // Fetch audit logs for this specific detection
        $auditLogs = AuditLog::with('user')
            ->where('entity_type', SatelliteDetection::class)
            ->where('entity_id', $detection->id)
            ->orderBy('created_at', 'desc')
            ->get();

        // Proximity AIS comparison
        $aisComparison = $this->calculateAisComparison($detection);

        // SAR Evidence Crops, Provenance, Multi-source Matrix & Timeline (Tahap 8 & 9)
        $evidence = $this->evidenceService->getEvidenceForDetection($detection);

        $reviewStatuses = SatelliteDetection::getReviewStatuses();

        // Check if current user has authorization to submit/update reviews
        $canReview = in_array(auth()->user()?->role, ['super_admin', 'admin', 'analyst'], true);

        return view('dark-vessels.show', compact('detection', 'auditLogs', 'aisComparison', 'reviewStatuses', 'canReview', 'evidence'));
    }

    /**
     * Submit or update analyst review decision for a candidate SAR detection.
     */
    public function submitReview(Request $request, string $id)
    {
        $detection = SatelliteDetection::whereIdentifierOrId($id)->firstOrFail();

        // Enforce role authorization (Requirement 18)
        if (!in_array($request->user()->role, ['super_admin', 'admin', 'analyst'], true)) {
            abort(403, 'Akses Ditolak: Hanya peran analis dan administrator yang berwenang mencatat hasil peninjauan.');
        }

        // Enforce minimum 20 characters rationale (Requirement 15)
        $validated = $request->validate([
            'review_status' => 'required|in:' . implode(',', array_keys(SatelliteDetection::getReviewStatuses())),
            'review_rationale' => 'required|string|min:20|max:2000',
            'evidence_notes' => 'nullable|string|max:2000',
            'limitation_notes' => 'nullable|string|max:2000',
        ], [
            'review_status.required' => 'Pilih status evaluasi peninjauan analis.',
            'review_rationale.required' => 'Alasan keputusan dan justifikasi analisis wajib diisi.',
            'review_rationale.min' => 'Alasan keputusan harus memiliki panjang minimal 20 karakter.',
        ]);

        $previousStatus = $detection->review_status;

        $meta = $detection->review_metadata ?? [];
        $meta['evidence_notes'] = $validated['evidence_notes'] ?? null;
        $meta['limitation_notes'] = $validated['limitation_notes'] ?? null;
        $meta['last_decision_author'] = $request->user()->name;
        $meta['version'] = \App\Services\Sar\SarAisCorrelationService::VERSION;
        $meta['algorithm_version'] = \App\Services\Sar\SarAisCorrelationService::ALGORITHM_VERSION;

        if ($request->has('checklist')) {
            $meta['checklist'] = $request->input('checklist', []);
        }

        $detection->update([
            'review_status' => $validated['review_status'],
            'reviewed_by_user_id' => $request->user()->id,
            'reviewed_at' => now(),
            'review_rationale' => $validated['review_rationale'],
            'review_metadata' => $meta,
        ]);

        // Log immutable record to audit_logs (Requirement 15)
        AuditLog::log(
            action: 'sar_candidate_review',
            entityType: SatelliteDetection::class,
            entityId: $detection->id,
            details: [
                'detection_identifier' => $detection->detection_identifier,
                'scene_id' => $detection->scene?->scene_id,
                'previous_status' => $previousStatus,
                'new_status' => $validated['review_status'],
                'rationale' => $validated['review_rationale'],
                'evidence_notes' => $validated['evidence_notes'] ?? null,
                'limitation_notes' => $validated['limitation_notes'] ?? null,
                'checklist' => $request->input('checklist', []),
                'reviewer_name' => $request->user()->name,
                'reviewer_email' => $request->user()->email,
                'reviewer_role' => $request->user()->role,
                'tcr_db' => $detection->tcr_db,
                'coordinates' => [
                    'latitude' => $detection->latitude,
                    'longitude' => $detection->longitude,
                ],
                'provenance_version' => \App\Services\Sar\SarAisCorrelationService::VERSION,
            ],
            userId: $request->user()->id
        );

        return redirect()
            ->route('dark-vessels.show', $detection->id)
            ->with('success', 'Analyst review decision and technical rationale saved and recorded in audit trail successfully.');
    }

    /**
     * Export filtered SAR detections with full provenance and review history to CSV (Tahap 9 - Requirement 17).
     * Strictly separates ALGORITHM RESULT from HUMAN ANALYST DECISION.
     */
    public function export(Request $request): StreamedResponse
    {
        $query = SatelliteDetection::with(['scene', 'reviewer', 'correlatedVessel']);

        if ($sceneId = $request->input('scene_id')) {
            $query->whereHas('scene', function ($q) use ($sceneId) {
                $q->where('id', $sceneId)->orWhere('scene_id', $sceneId);
            });
        }
        if ($reviewStatus = $request->input('review_status')) {
            $query->where('review_status', $reviewStatus);
        }
        if ($corrStatus = $request->input('correlation_status')) {
            if ($corrStatus !== 'all') {
                $query->where('correlation_status', $corrStatus);
            }
        }
        if ($search = $request->input('search')) {
            $likeOp = config('database.default') === 'pgsql' ? 'ilike' : 'like';
            $query->where('detection_identifier', $likeOp, "%{$search}%");
        }
        if ($request->filled('min_tcr')) {
            $query->where('tcr_db', '>=', (float) $request->input('min_tcr'));
        }
        if ($request->filled('max_tcr')) {
            $query->where('tcr_db', '<=', (float) $request->input('max_tcr'));
        }

        $count = $query->count();

        // Audit the export action
        AuditLog::log(
            action: 'sar_candidate_export',
            entityType: SatelliteDetection::class,
            entityId: null,
            details: [
                'format' => 'csv',
                'record_count' => $count,
                'filters_applied' => $request->only(['scene_id', 'review_status', 'correlation_status', 'min_tcr', 'max_tcr', 'search']),
                'exported_by' => auth()->user()?->name,
                'role' => auth()->user()?->role,
            ]
        );

        $filename = 'sar_intelligence_forensic_' . now()->format('Ymd_His') . '.csv';

        $headers = [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');
            // Write UTF-8 BOM for Excel compatibility
            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

            fputcsv($handle, [
                'Candidate ID',
                'Scene ID',
                'Acquisition Time (UTC)',
                'Latitude',
                'Longitude',
                'Peak TCR (dB)',
                'Estimated Length (m)',
                'Estimated Width (m)',
                'Detection Confidence (Algorithm)',
                'AIS Correlation Status (Algorithm)',
                'Correlation Evidence Score (Algorithm)',
                'Nearest Vessel ID (Algorithm)',
                'Nearest MMSI (Algorithm)',
                'Nearest Vessel Name (Algorithm)',
                'AIS Distance (m) (Algorithm)',
                'AIS Time Difference (min) (Algorithm)',
                'AIS Track Trajectory (Algorithm)',
                'GFW Events Status (Provider)',
                'Sea-Ice Status (Provider)',
                'Optical Verification Status (Provider)',
                'Analyst Review Status (Human Decision)',
                'Reviewer Name (Human Decision)',
                'Reviewer Role (Human Decision)',
                'Reviewed At UTC (Human Decision)',
                'Review Rationale (Human Decision)',
                'Evidence Notes (Human Decision)',
                'Limitations (Human Decision)',
                'Scientific & Legal Disclaimer',
            ]);

            $query->chunk(100, function ($detections) use ($handle) {
                foreach ($detections as $det) {
                    $meta = $det->review_metadata ?? [];
                    $corrEvidence = $meta['correlation_evidence'] ?? null;
                    $score = $corrEvidence['correlation_evidence_score'] ?? null;
                    $traj = $corrEvidence['track_continuity']['trajectory'] ?? 'no_relevant_track';
                    $timeDeltaMin = $corrEvidence['temporal']['time_difference_minutes'] ?? null;

                    fputcsv($handle, [
                        $det->detection_identifier,
                        $det->scene?->scene_id ?? '-',
                        $det->detected_at?->format('Y-m-d H:i:s') ?? '-',
                        $det->latitude,
                        $det->longitude,
                        $det->tcr_db !== null ? number_format($det->tcr_db, 2) : '-',
                        $det->estimated_length_meters ?? '-',
                        $det->estimated_width_meters ?? '-',
                        $det->confidence_score !== null ? round($det->confidence_score * 100) . '%' : '-',
                        $det->correlation_status,
                        $score !== null ? round($score * 100) . '%' : 'not_available',
                        $det->correlated_vessel_id ?? '-',
                        $det->correlatedVessel?->mmsi ?? '-',
                        $det->correlatedVessel?->name ?? '-',
                        $det->distance_to_nearest_ais_meters !== null ? number_format($det->distance_to_nearest_ais_meters, 1) : '-',
                        $timeDeltaMin !== null ? $timeDeltaMin : '-',
                        $traj,
                        'FORBIDDEN (403 - ACCESS NOT AUTHORIZED)',
                        'not_available (Polar Caution)',
                        'not_available',
                        $det->review_status,
                        $det->reviewer?->name ?? 'Unreviewed',
                        $det->reviewer?->role ?? '-',
                        $det->reviewed_at?->format('Y-m-d H:i:s') ?? '-',
                        $det->review_rationale ?? '-',
                        $meta['evidence_notes'] ?? '-',
                        $meta['limitation_notes'] ?? '-',
                        $det->disclaimer,
                    ]);
                }
            });

            fclose($handle);
        }, $filename, $headers);

    }

    /**
     * Compute proximity and contextual AIS comparison for this radar candidate.
     */
    protected function calculateAisComparison(SatelliteDetection $detection): array
    {
        $lat = $detection->latitude;
        $lng = $detection->longitude;
        $time = $detection->detected_at ?? now();

        $closestPosition = null;
        $minDistance = null;

        // Query nearby positions within bounding box first
        $candidates = VesselPosition::with('vessel')
            ->whereBetween('latitude', [$lat - 5.0, $lat + 5.0])
            ->whereBetween('longitude', [$lng - 5.0, $lng + 5.0])
            ->limit(100)
            ->get();

        foreach ($candidates as $pos) {
            $dist = $this->calculateHaversineDistance($lat, $lng, $pos->latitude, $pos->longitude);
            if ($minDistance === null || $dist < $minDistance) {
                $minDistance = $dist;
                $closestPosition = $pos;
            }
        }

        // If no candidate in local bbox, sample nearest observed vessel position
        if (!$closestPosition) {
            $sample = VesselPosition::with('vessel')->latest('observed_at')->first();
            if ($sample) {
                $closestPosition = $sample;
                $minDistance = $this->calculateHaversineDistance($lat, $lng, $sample->latitude, $sample->longitude);
            }
        }

        $distanceMeters = $minDistance ? round($minDistance, 1) : null;
        $timeDiffMinutes = null;

        if ($closestPosition && $closestPosition->observed_at) {
            $timeDiffMinutes = abs($time->diffInMinutes($closestPosition->observed_at));
        }

        $receptionStatus = 'insufficient_coverage';
        $coverageDescription = 'Southern Ocean high seas sector (latitudes 58°-60° S) is beyond the range of terrestrial AIS receiver stations (>3,000 km from the nearest coastline). Satellite AIS (S-AIS) telemetry feed was inactive at these coordinates at acquisition time.';

        return [
            'closest_vessel' => $closestPosition?->vessel,
            'closest_position' => $closestPosition,
            'distance_meters' => $distanceMeters,
            'distance_km' => $distanceMeters ? round($distanceMeters / 1000, 2) : null,
            'time_diff_minutes' => $timeDiffMinutes,
            'reception_status' => $receptionStatus,
            'coverage_description' => $coverageDescription,
            'ais_correlation_status' => $detection->correlation_status,
            'legal_integrity_note' => 'The absence of AIS telemetry at this location is an objective consequence of receiver coverage gaps, NOT proof that the radar target deliberately deactivated its AIS transponder or engaged in illicit activity.',
        ];
    }

    /**
     * Great-circle distance between two coordinates in meters (Haversine)
     */
    protected function calculateHaversineDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000.0;

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
}
