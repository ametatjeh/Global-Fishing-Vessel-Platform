<?php

namespace App\Http\Controllers;

use App\Jobs\EnrichVesselGfwJob;
use App\Models\Vessel;
use App\Services\Maritime\FlagStateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class VesselController extends Controller
{
    public function index(Request $request)
    {
        $query = Vessel::with(['latestPosition', 'sourceProvider'])
            ->withCount('identifiers');

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
            $query->where('vessel_type', $type);
        }

        // 1. Flag Country Filter (supports specific ISO code or 'unknown')
        $flagCountry = $request->input('flag_country') ?? $request->input('flag_state');
        if ($flagCountry !== null && $flagCountry !== '') {
            if (strtolower($flagCountry) === 'unknown') {
                $query->where(function ($q) {
                    $q->whereNull('flag_state')->orWhere('flag_state', '');
                });
            } else {
                $query->where('flag_state', $flagCountry);
            }
        }

        // 2. Owner Country Filter (independent, supports specific ISO code or 'unknown')
        $ownerCountry = $request->input('owner_country');
        if ($ownerCountry !== null && $ownerCountry !== '') {
            if (strtolower($ownerCountry) === 'unknown') {
                $query->where(function ($q) {
                    $q->whereNull('owner_country')->orWhere('owner_country', '');
                });
            } else {
                $query->where('owner_country', $ownerCountry);
            }
        }

        // 3. Operator Country Filter (independent, supports specific ISO code or 'unknown')
        $operatorCountry = $request->input('operator_country');
        if ($operatorCountry !== null && $operatorCountry !== '') {
            if (strtolower($operatorCountry) === 'unknown') {
                $query->where(function ($q) {
                    $q->whereNull('operator_country')->orWhere('operator_country', '');
                });
            } else {
                $query->where('operator_country', $operatorCountry);
            }
        }

        $perPage = (int) $request->query('per_page', 50);
        if (!in_array($perPage, [10, 25, 50, 100], true)) {
            $perPage = 50;
        }

        $vessels = $query->orderBy('name')->paginate($perPage)->withQueryString();

        // Efficient, distinct country list for Flag State dropdown (derived directly from database records)
        $availableFlagCountries = Vessel::whereNotNull('flag_state')
            ->where('flag_state', '!=', '')
            ->distinct()
            ->pluck('flag_state')
            ->map(fn ($code) => FlagStateService::resolveCountryDetails($code))
            ->unique('code')
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        // Calculate active filter count
        $activeFiltersCount = collect([
            $search,
            $type,
            $flagCountry,
            $ownerCountry,
            $operatorCountry,
        ])->filter(fn ($v) => $v !== null && $v !== '')->count();

        return view('vessels.index', compact(
            'vessels',
            'availableFlagCountries',
            'activeFiltersCount',
            'flagCountry'
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
        ])->findOrFail($id);

        // Automatic non-blocking GFW Intelligence Sync:
        // For eligible vessels that have not yet been enriched, dispatch background job.
        if ($vessel->isEligibleForGfwSync() && in_array($vessel->enrichment_status, ['not_enriched', 'pending'], true)) {
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
                ->with('warning', 'Kapal tidak memenuhi syarat sinkronisasi GFW (memerlukan IMO valid 7 digit atau MMSI valid 9 digit).');
        }

        // Dispatch background job with force = true
        EnrichVesselGfwJob::dispatch($vessel->id, force: true);

        return redirect()->route('vessels.show', $vessel->id)
            ->with('success', 'Permintaan sinkronisasi intelijen GFW API v3 telah dijadwalkan dan sedang diproses di latar belakang.');
    }
}
