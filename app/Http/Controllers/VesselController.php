<?php

namespace App\Http\Controllers;

use App\Jobs\EnrichAwaitingVesselJob;
use App\Jobs\EnrichVesselGfwJob;
use App\Models\Vessel;
use App\Models\VesselAttributeObservation;
use App\Services\Enrichment\VesselEnrichmentManager;
use App\Services\Maritime\FlagStateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class VesselController extends Controller
{
    public function index(Request $request)
    {
        $query = Vessel::with(['latestPosition', 'sourceProvider']);

        $search = $request->input('search') ?? $request->input('q');
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('mmsi', 'like', "%{$search}%")
                  ->orWhere('imo', 'like', "%{$search}%")
                  ->orWhere('callsign', 'like', "%{$search}%")
                  ->orWhere('hull_code', 'like', "%{$search}%")
                  ->orWhere('owner', 'like', "%{$search}%")
                  ->orWhere('operator', 'like', "%{$search}%");
            });
        }

        if ($type = $request->input('vessel_type')) {
            if ($type === 'awaiting_static') {
                $query->where(function ($q) {
                    $q->where(function ($sq) {
                        $sq->whereNull('vessel_type')->orWhere('vessel_type', 'unknown');
                    })
                    ->whereNull('ais_ship_type_code')
                    ->whereNull('static_data_received_at');
                });
            } elseif ($type === 'not_available') {
                $query->where(function ($q) {
                    $q->where('vessel_type', 'not_available')
                      ->orWhere('ais_ship_type_code', 0);
                });
            } elseif ($type === 'unmapped') {
                $query->where('vessel_type', 'unmapped');
            } elseif ($type === 'patrol' || $type === 'law_enforcement') {
                $query->whereIn('vessel_type', ['law_enforcement', 'patrol']);
            } elseif ($type === 'medical' || $type === 'medical_transport') {
                $query->whereIn('vessel_type', ['medical', 'medical_transport']);
            } else {
                $query->where('vessel_type', $type);
            }
        }

        // 1. Flag Country Filter (supports specific ISO code or 'unknown')
        $flagCountry = $request->input('flag_country') ?? $request->input('flag_state');
        if ($flagCountry !== null && $flagCountry !== '') {
            $criteria = FlagStateService::getFilterCriteria($flagCountry);
            if ($criteria['is_unknown']) {
                $query->where(function ($q) {
                    $q->whereNull('flag_state')
                      ->orWhere('flag_state', '')
                      ->orWhereRaw('LOWER(flag_state) IN (?, ?, ?)', ['unknown', 'un', '-']);
                });
            } else {
                $query->where(function ($sub) use ($criteria) {
                    $sub->whereIn('flag_state', $criteria['codes']);
                    if (!empty($criteria['mids'])) {
                        $sub->orWhere(function ($midQ) use ($criteria) {
                            $midQ->where(function ($emptyFlag) {
                                $emptyFlag->whereNull('flag_state')
                                    ->orWhere('flag_state', '')
                                    ->orWhereRaw('LOWER(flag_state) IN (?, ?, ?)', ['unknown', 'un', '-']);
                            })->where(function ($prefixQ) use ($criteria) {
                                foreach ($criteria['mids'] as $mid) {
                                    $prefixQ->orWhere('mmsi', 'like', "{$mid}%");
                                }
                            });
                        });
                    }
                });
            }
        }

        // 2. Owner Country Filter (independent, supports specific ISO code or 'unknown')
        $ownerCountry = $request->input('owner_country');
        if ($ownerCountry !== null && $ownerCountry !== '') {
            $ownerCrit = FlagStateService::getFilterCriteria($ownerCountry);
            if ($ownerCrit['is_unknown']) {
                $query->where(function ($q) {
                    $q->whereNull('owner_country')->orWhere('owner_country', '');
                });
            } else {
                $query->whereIn('owner_country', $ownerCrit['codes']);
            }
        }

        // 3. Operator Country Filter (independent, supports specific ISO code or 'unknown')
        $operatorCountry = $request->input('operator_country');
        if ($operatorCountry !== null && $operatorCountry !== '') {
            $opCrit = FlagStateService::getFilterCriteria($operatorCountry);
            if ($opCrit['is_unknown']) {
                $query->where(function ($q) {
                    $q->whereNull('operator_country')->orWhere('operator_country', '');
                });
            } else {
                $query->whereIn('operator_country', $opCrit['codes']);
            }
        }

        // 4. Enrichment Status Filter
        $enrichmentStatus = $request->input('enrichment_status');
        if ($enrichmentStatus !== null && $enrichmentStatus !== '') {
            if ($enrichmentStatus === 'awaiting') {
                $query->awaitingStatic();
            } elseif ($enrichmentStatus === 'enriched') {
                $query->whereIn('enrichment_status', ['enriched', 'synced']);
            } elseif ($enrichmentStatus === 'partial' || $enrichmentStatus === 'partially_enriched') {
                $query->whereIn('enrichment_status', ['partially_enriched', 'partial', 'match_found']);
            } elseif ($enrichmentStatus === 'conflicting') {
                $query->whereIn('enrichment_status', ['conflicting', 'conflict_detected', 'match_review_required']);
            } elseif ($enrichmentStatus === 'not_found') {
                $query->whereIn('enrichment_status', ['not_found', 'no_match']);
            } elseif ($enrichmentStatus === 'not_started') {
                $query->where(function ($q) {
                    $q->whereNull('enrichment_status')
                      ->orWhereIn('enrichment_status', ['not_started', 'not_enriched', 'awaiting']);
                });
            } else {
                $query->where('enrichment_status', $enrichmentStatus);
            }
        }

        // 5. Activity Filter (Active within 24h vs Inactive)
        $activity = $request->input('activity');
        if ($activity === 'active') {
            $query->whereNotNull('last_seen_at')->where('last_seen_at', '>=', now()->subHours(24));
        } elseif ($activity === 'inactive') {
            $query->where(function ($q) {
                $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', now()->subHours(24));
            });
        }

        // 6. Waiting Duration Filter
        $waitingDuration = $request->input('waiting_duration');
        if ($waitingDuration === '1d') {
            $query->where('first_seen_at', '<=', now()->subDay());
        } elseif ($waitingDuration === '7d') {
            $query->where('first_seen_at', '<=', now()->subDays(7));
        } elseif ($waitingDuration === '30d') {
            $query->where('first_seen_at', '<=', now()->subDays(30));
        }

        $perPageInput = strtolower(trim((string) $request->query('per_page', '50')));
        if ($perPageInput === 'all' || $perPageInput === 'semua') {
            $totalCount = (clone $query)->count();
            $perPage = max(1, $totalCount);
        } else {
            $perPage = (int) $perPageInput;
            if (!in_array($perPage, [10, 25, 50, 100], true)) {
                $perPage = 50;
            }
        }

        $vessels = $query->orderBy('name')->orderBy('id')->paginate($perPage)->withQueryString();

        // Total registered vessels in registry (Cached for performance)
        $totalVessels = Cache::remember('vessels_total_count', 30, function () {
            return Vessel::count();
        });

        // Summary KPI Metrics for Awaiting Static Data Module (Cached for performance)
        $awaitingStats = Cache::remember('vessels_awaiting_summary_metrics', 30, function () {
            $baseAwaiting = Vessel::awaitingStatic();
            return [
                'total_awaiting' => (clone $baseAwaiting)->count(),
                'active_awaiting' => (clone $baseAwaiting)->whereNotNull('last_seen_at')->where('last_seen_at', '>=', now()->subHours(24))->count(),
                'enriched_count' => (clone $baseAwaiting)->whereIn('enrichment_status', ['enriched', 'synced'])->count(),
                'partial_count' => (clone $baseAwaiting)->whereIn('enrichment_status', ['partially_enriched', 'partial', 'match_found'])->count(),
                'conflicting_count' => (clone $baseAwaiting)->whereIn('enrichment_status', ['conflicting', 'conflict_detected', 'match_review_required'])->count(),
                'not_found_count' => (clone $baseAwaiting)->whereIn('enrichment_status', ['not_found', 'no_match'])->count(),
                'not_started_count' => (clone $baseAwaiting)->where(function ($q) {
                    $q->whereNull('enrichment_status')
                      ->orWhereIn('enrichment_status', ['not_started', 'not_enriched', 'awaiting']);
                })->count(),
            ];
        });

        // Distinct country list for Flag State dropdown derived from actual vessel records in DB (Cached for performance)
        $availableFlagCountries = Cache::remember('vessels_available_flag_countries', 300, function () {
            // 1. Explicit flag_state in vessels table
            $explicitCodes = Vessel::whereNotNull('flag_state')
                ->where('flag_state', '!=', '')
                ->distinct()
                ->pluck('flag_state');

            // 2. MMSI MID prefixes for vessels without explicit flag_state
            $mmsiPrefixes = Vessel::where(function ($q) {
                    $q->whereNull('flag_state')->orWhere('flag_state', '');
                })
                ->whereNotNull('mmsi')
                ->where('mmsi', '!=', '')
                ->distinct()
                ->selectRaw('SUBSTRING(TRIM(mmsi), 1, 3) as prefix')
                ->pluck('prefix')
                ->filter(fn ($p) => strlen($p) === 3 && ctype_digit($p));

            $countries = collect();
            foreach ($explicitCodes as $code) {
                $detail = FlagStateService::resolveCountryDetails($code);
                if (!empty($detail['is_known']) && !empty($detail['iso2']) && $detail['iso2'] !== 'UN') {
                    $countries->push($detail);
                }
            }

            foreach ($mmsiPrefixes as $mid) {
                $midInfo = FlagStateService::lookupMid($mid . '000000');
                if ($midInfo) {
                    $detail = FlagStateService::resolveCountryDetails($midInfo['iso2']);
                    if (!empty($detail['is_known']) && !empty($detail['iso2']) && $detail['iso2'] !== 'UN') {
                        $countries->push($detail);
                    }
                }
            }

            return $countries
                ->unique('iso2')
                ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
                ->values()
                ->all();
        });

        // Check if there are vessels with unknown/unverified flag state in DB (Cached for performance)
        $hasUnknownFlag = Cache::remember('vessels_has_unknown_flag', 300, function () {
            return Vessel::where(function ($q) {
                $q->whereNull('flag_state')
                  ->orWhere('flag_state', '')
                  ->orWhereRaw('LOWER(flag_state) IN (?, ?, ?)', ['unknown', 'un', '-']);
            })->exists();
        });

        // Calculate active filter count
        $activeFiltersCount = collect([
            $search,
            $type,
            $flagCountry,
            $ownerCountry,
            $operatorCountry,
            $enrichmentStatus,
            $activity,
            $waitingDuration,
        ])->filter(fn ($v) => $v !== null && $v !== '')->count();

        return view('vessels.index', compact(
            'vessels',
            'totalVessels',
            'availableFlagCountries',
            'hasUnknownFlag',
            'activeFiltersCount',
            'flagCountry',
            'awaitingStats',
            'enrichmentStatus',
            'activity',
            'waitingDuration'
        ));
    }

    public function show(string $id)
    {
        $vessel = Vessel::with([
            'identifiers',
            'latestPosition',
            'positions' => fn ($q) => $q->orderBy('observed_at', 'desc')->limit(50),
            'tracks',
            'authorizations.maritimeZone',
            'complianceEvents.rule',
            'encountersAsVessel1.vessel2',
            'encountersAsVessel2.vessel1',
            'attributeObservations',
            'enrichmentJobs' => fn ($q) => $q->latest()->limit(10),
        ])->findOrFail($id);

        // Automatic non-blocking GFW Intelligence Sync:
        // For eligible vessels that have not yet been enriched, dispatch background job.
        if ($vessel->isEligibleForGfwSync() && in_array($vessel->enrichment_status, ['not_enriched', 'pending', 'not_started'], true)) {
            $lockKey = 'gfw_auto_sync_dispatch_' . $vessel->id;
            if (Cache::add($lockKey, true, 3600)) {
                EnrichVesselGfwJob::dispatch($vessel->id, force: false);
            }
        }

        return view('vessels.show', compact('vessel'));
    }

    /**
     * Trigger manual background GFW intelligence sync or retry.
     */
    public function syncGfw(string $id, Request $request)
    {
        $vessel = Vessel::findOrFail($id);

        if (!$vessel->isEligibleForGfwSync()) {
            return redirect()->route('vessels.show', $vessel->id)
                ->with('warning', 'Vessel is not eligible for GFW synchronization (requires a valid 7-digit IMO or valid 9-digit MMSI).');
        }

        // Dispatch background job with force = true
        EnrichVesselGfwJob::dispatch($vessel->id, force: true);

        return redirect()->route('vessels.show', $vessel->id)
            ->with('success', 'GFW API v3 intelligence synchronization request scheduled and processing in background.');
    }

    /**
     * Trigger multi-source enrichment for a single vessel.
     */
    public function enrichSingle(string $id, Request $request, VesselEnrichmentManager $manager)
    {
        $vessel = Vessel::findOrFail($id);

        if ($request->boolean('sync')) {
            $result = $manager->enrichVessel($vessel, dryRun: false);
            $msg = "Enrichment completed: {$result['message']}";
            return back()->with('success', $msg);
        }

        EnrichAwaitingVesselJob::dispatch($vessel->id, force: true);

        return back()->with('success', "Multi-source enrichment for vessel {$vessel->name} (MMSI: {$vessel->mmsi}) scheduled in background.");
    }

    /**
     * Trigger bulk enrichment for awaiting vessels with rate limiting.
     */
    public function enrichBulk(Request $request)
    {
        $limit = min(100, max(1, (int) $request->input('limit', 25)));
        $activeOnly = $request->boolean('active_only', true);

        $query = Vessel::awaitingStatic();
        if ($activeOnly) {
            $query->whereNotNull('last_seen_at')->where('last_seen_at', '>=', now()->subHours(24));
        }
        $query->where(function ($q) {
            $q->whereNull('enrichment_status')
              ->orWhereIn('enrichment_status', ['not_started', 'not_enriched', 'awaiting']);
        });

        $targets = $query->orderByDesc('last_seen_at')->limit($limit)->get();

        if ($targets->isEmpty()) {
            return back()->with('warning', 'No active vessels found awaiting static data for enrichment.');
        }

        foreach ($targets as $vessel) {
            EnrichAwaitingVesselJob::dispatch($vessel->id, force: false);
        }

        return back()->with('success', "Successfully scheduled {$targets->count()} background enrichment jobs (Awaiting Static Data Multi-Source Enrichment).");
    }

    /**
     * Accept an external observation to update primary vessel profile.
     */
    public function acceptObservation(string $id, int $obsId, Request $request)
    {
        $vessel = Vessel::findOrFail($id);
        $observation = VesselAttributeObservation::where('vessel_id', $vessel->id)->findOrFail($obsId);

        $attrName = $observation->attribute_name;
        $attrVal = $observation->attribute_value;

        // Verify valid column
        $allowed = [
            'name', 'imo', 'callsign', 'flag_state', 'vessel_type', 'vessel_category',
            'ais_class', 'length_meters', 'beam_meters', 'draught', 'tonnage',
            'deadweight_tonnage', 'build_year', 'ship_builder', 'home_port',
            'registry_port', 'owner', 'operator'
        ];

        if (!in_array($attrName, $allowed, true)) {
            return back()->with('error', "Attribute '{$attrName}' is not permitted for vessel profile update.");
        }

        // Apply update to vessel
        $vessel->update([
            $attrName => $attrVal,
            'enrichment_status' => 'enriched',
        ]);

        // Resolve conflict on observation
        $observation->update([
            'is_conflicting' => false,
            'conflict_reason' => 'Manually approved by analyst.',
        ]);

        return back()->with('success', "Attribute '{$attrName}' updated successfully to: {$attrVal} (based on {$observation->source_name} observation).");
    }
}
