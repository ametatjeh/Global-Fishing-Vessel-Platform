<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Vessel;
use App\Services\Maritime\VesselClassificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VesselClassificationReviewController extends Controller
{
    /**
     * Display a listing of vessels for analyst classification review & reconciliation.
     */
    public function index(Request $request)
    {
        $query = Vessel::query()->with(['latestPosition', 'sourceProvider']);

        // 1. Text Search (name, MMSI, IMO, callsign)
        $search = trim((string) ($request->input('search') ?? $request->input('q') ?? ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('mmsi', 'like', "%{$search}%")
                  ->orWhere('imo', 'like', "%{$search}%")
                  ->orWhere('callsign', 'like', "%{$search}%");
            });
        }

        // 2. Filter by Verification Status
        $status = $request->input('verification_status');
        if (!empty($status) && $status !== 'all') {
            $query->where('verification_status', $status);
        }

        // 3. Filter by Vessel Type
        $vesselType = $request->input('vessel_type');
        if (!empty($vesselType) && $vesselType !== 'all') {
            $query->where('vessel_type', $vesselType);
        }

        // 4. Filter by Classification Conflict (Disputed)
        $conflict = $request->input('conflict_status');
        if ($conflict === 'disputed_only') {
            $query->disputedClassification();
        } elseif ($conflict === 'clean_only') {
            $query->where(function ($q) {
                $q->where('vessel_type', '!=', 'fishing')
                  ->orWhereNull('ais_ship_type_code')
                  ->orWhere('ais_ship_type_code', 30);
            });
        }

        // 5. Filter by Protective Hold
        $hold = $request->input('protective_hold');
        if ($hold === 'held_only') {
            $query->underProtectiveHold();
        }

        // 6. Metrics Summary for Header Pills
        $metrics = [
            'total_disputed' => Vessel::disputedClassification()->count(),
            'total_historical' => Vessel::whereIn('mmsi', Vessel::HISTORICAL_PROTECTED_MMSIS)
                ->orWhere('verification_status', 'unverified_historical')
                ->count(),
            'total_unverified' => Vessel::where('verification_status', 'unverified')->count(),
            'total_provider_reported' => Vessel::where('verification_status', 'provider_reported')->count(),
            'total_under_review' => Vessel::where('verification_status', 'under_review')->count(),
            'total_verified_fishing' => Vessel::verifiedFishing()->count(),
        ];

        // 7. Sorting
        $allowedSorts = ['name', 'mmsi', 'vessel_type', 'verification_status', 'classified_at', 'last_seen_at'];
        $sortBy = in_array($request->input('sort_by'), $allowedSorts, true) ? $request->input('sort_by') : 'name';
        $sortDir = strtolower((string) $request->input('sort_dir')) === 'desc' ? 'desc' : 'asc';

        $query->orderBy($sortBy, $sortDir);

        // 8. Server-side Pagination
        $perPage = (int) $request->input('per_page', 25);
        if (!in_array($perPage, [15, 25, 50, 100], true)) {
            $perPage = 25;
        }

        $vessels = $query->paginate($perPage)->withQueryString();

        $taxonomy = VesselClassificationService::TAXONOMY;

        return view('vessels.classification_review.index', compact(
            'vessels',
            'metrics',
            'search',
            'status',
            'vesselType',
            'conflict',
            'hold',
            'sortBy',
            'sortDir',
            'perPage',
            'taxonomy'
        ));
    }

    /**
     * Display vessel classification details, evidence, and review form.
     */
    public function show(string $id)
    {
        $vessel = Vessel::with([
            'identifiers',
            'latestPosition',
            'positions' => fn ($q) => $q->orderBy('observed_at', 'desc')->limit(10),
            'sourceProvider',
        ])->findOrFail($id);

        $auditLogs = AuditLog::where('entity_id', (string) $vessel->id)
            ->where(function ($q) {
                $q->where('entity_type', 'vessel')
                  ->orWhere('entity_type', Vessel::class);
            })
            ->orderBy('created_at', 'desc')
            ->get();

        $taxonomy = VesselClassificationService::TAXONOMY;

        return view('vessels.classification_review.show', compact('vessel', 'auditLogs', 'taxonomy'));
    }

    /**
     * Submit an analyst classification review decision.
     */
    public function decision(Request $request, string $id)
    {
        $vessel = Vessel::findOrFail($id);

        $validated = $request->validate([
            'action' => 'required|string|in:verify_fishing,verify_non_fishing,reclassify,reject',
            'reason' => 'required|string|min:5|max:1000',
            'evidence' => 'nullable|string|max:1000',
            'target_vessel_type' => 'nullable|string|max:50',
        ], [
            'reason.required' => 'Analyst decision rationale is required (minimum 5 characters).',
            'reason.min' => 'Analyst decision rationale must provide a meaningful explanation (minimum 5 characters).',
            'action.in' => 'Invalid classification action selected.',
        ]);

        $action = $validated['action'];
        $reason = trim($validated['reason']);
        $evidence = trim((string) ($validated['evidence'] ?? ''));
        $targetVesselType = trim((string) ($validated['target_vessel_type'] ?? ''));

        // Evidence validation: required for verification and reclassification
        if (in_array($action, ['verify_fishing', 'verify_non_fishing', 'reclassify'], true) && $evidence === '') {
            throw ValidationException::withMessages([
                'evidence' => 'Supporting evidence is mandatory for vessel verification or reclassification.',
            ]);
        }

        // Target vessel type validation
        if (in_array($action, ['verify_non_fishing', 'reclassify'], true) && empty($targetVesselType)) {
            throw ValidationException::withMessages([
                'target_vessel_type' => 'Please select an appropriate target vessel category.',
            ]);
        }

        // Strict Non-Conflict Rule Enforcement:
        // AIS ShipType telemetry takes precedence over manual fishing classification.
        // If AIS provides a non-fishing code (e.g. 70 Cargo, 80 Tanker), it CANNOT be verified as Fishing!
        if ($action === 'verify_fishing') {
            if ($vessel->ais_ship_type_code !== null && (int) $vessel->ais_ship_type_code > 0 && (int) $vessel->ais_ship_type_code !== 30) {
                throw ValidationException::withMessages([
                    'action' => "Non-Conflict Rule Violation: Vessel has objective AIS ShipType {$vessel->ais_ship_type_code} (non-fishing) telemetry. It cannot be verified as Fishing. Please select 'Verify Non-Fishing' or 'Reclassify'.",
                ]);
            }
        }

        if ($action === 'reclassify' && strtolower($targetVesselType) === 'fishing') {
            if ($vessel->ais_ship_type_code !== null && (int) $vessel->ais_ship_type_code > 0 && (int) $vessel->ais_ship_type_code !== 30) {
                throw ValidationException::withMessages([
                    'target_vessel_type' => "Non-Conflict Rule Violation: Vessel has objective AIS ShipType {$vessel->ais_ship_type_code} (non-fishing) telemetry. Cannot be reclassified as Fishing.",
                ]);
            }
        }

        // Snapshot previous state
        $previousVesselType = $vessel->vessel_type;
        $previousVerificationStatus = $vessel->verification_status;
        $previousClassificationSource = $vessel->classification_source;
        $previousAisShipTypeCode = $vessel->ais_ship_type_code;

        $actor = auth()->user();
        $actorId = $actor?->id;
        $actorName = $actor?->name ?? 'Analyst';
        $now = now();

        // Atomic Transaction: All classification updates and audit logs succeed together or rollback
        DB::transaction(function () use (
            $vessel,
            $action,
            $reason,
            $evidence,
            $targetVesselType,
            $previousVesselType,
            $previousVerificationStatus,
            $previousClassificationSource,
            $previousAisShipTypeCode,
            $actorId,
            $actorName,
            $now
        ) {
            $newVesselType = $previousVesselType;
            $newVerificationStatus = $previousVerificationStatus;
            $newClassificationSource = $previousClassificationSource;

            switch ($action) {
                case 'verify_fishing':
                    $newVesselType = 'fishing';
                    $newVerificationStatus = 'verified';
                    $newClassificationSource = 'ANALYST_VERIFICATION';
                    break;

                case 'verify_non_fishing':
                    $newVesselType = strtolower($targetVesselType);
                    $newVerificationStatus = 'verified';
                    $newClassificationSource = 'ANALYST_VERIFICATION';
                    break;

                case 'reclassify':
                    $newVesselType = strtolower($targetVesselType);
                    $newVerificationStatus = 'reclassified';
                    $newClassificationSource = 'ANALYST_RECLASSIFICATION';
                    break;

                case 'reject':
                    // Maintain safe hold or rejected state
                    $newVerificationStatus = 'rejected';
                    $newClassificationSource = $previousClassificationSource ?: 'ANALYST_REJECTION';
                    break;
            }

            // Append to metadata classification history (preserves all historical decisions)
            $meta = $vessel->metadata ?? [];
            $history = $meta['classification_history'] ?? [];
            if (!is_array($history)) {
                $history = [];
            }

            $historyEntry = [
                'action' => $action,
                'previous_vessel_type' => $previousVesselType,
                'new_vessel_type' => $newVesselType,
                'previous_verification_status' => $previousVerificationStatus,
                'new_verification_status' => $newVerificationStatus,
                'previous_ais_ship_type_code' => $previousAisShipTypeCode,
                'reason' => $reason,
                'evidence' => $evidence ?: 'Insufficient or rejected evidence',
                'analyst_id' => $actorId,
                'analyst_name' => $actorName,
                'timestamp' => $now->toIso8601String(),
            ];

            $history[] = $historyEntry;
            $meta['classification_history'] = $history;
            unset($meta['pending_conflict_review']); // Resolved

            // Save vessel
            $vessel->vessel_type = $newVesselType;
            $vessel->verification_status = $newVerificationStatus;
            $vessel->classification_source = $newClassificationSource;
            $vessel->classified_at = $now;
            $vessel->metadata = $meta;
            $vessel->save();

            // Append-only Audit Log
            AuditLog::log(
                action: 'vessel_classification_review',
                entityType: 'vessel',
                entityId: (string) $vessel->id,
                details: [
                    'vessel_id' => $vessel->id,
                    'mmsi' => $vessel->mmsi,
                    'vessel_name' => $vessel->name,
                    'action' => $action,
                    'previous_vessel_type' => $previousVesselType,
                    'new_vessel_type' => $newVesselType,
                    'previous_verification_status' => $previousVerificationStatus,
                    'new_verification_status' => $newVerificationStatus,
                    'previous_ais_ship_type_code' => $previousAisShipTypeCode,
                    'reason' => $reason,
                    'evidence' => $evidence ?: 'None',
                    'actor_id' => $actorId,
                    'actor_name' => $actorName,
                    'timestamp' => $now->toIso8601String(),
                ],
                userId: $actorId
            );
        });

        return redirect()->route('vessels.classification-review.show', $vessel->id)
            ->with('success', "Classification decision for vessel {$vessel->name} (MMSI: {$vessel->mmsi}) saved and recorded in audit log successfully.");
    }
}

