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

        $vessels = $query->orderBy('name')->paginate($perPage)->withQueryString();

        // Distinct country list for Flag State dropdown derived from actual vessel records in DB:
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
            ->pluck('mmsi')
            ->map(fn ($m) => substr(trim($m), 0, 3))
            ->unique()
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

        $availableFlagCountries = $countries
            ->unique('iso2')
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        // Check if there are vessels with unknown/unverified flag state in DB
        $hasUnknownFlag = Vessel::where(function ($q) {
            $q->whereNull('flag_state')
              ->orWhere('flag_state', '')
              ->orWhereRaw('LOWER(flag_state) IN (?, ?, ?)', ['unknown', 'un', '-']);
        })->exists();

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
            'hasUnknownFlag',
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
