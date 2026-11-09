<?php

namespace App\Http\Controllers;

use App\Models\DataProvider;
use App\Models\SatelliteDetection;
use App\Models\SatelliteScene;
use App\Models\Vessel;
use App\Services\Maritime\FlagStateService;
use Illuminate\Http\Request;

class MapController extends Controller
{
    public function index()
    {
        $providers = DataProvider::all()->keyBy('slug');

        // Build available flag countries derived from actual vessel records in DB (both explicit and MMSI MID clues)
        $explicitCodes = Vessel::whereNotNull('flag_state')
            ->where('flag_state', '!=', '')
            ->distinct()
            ->pluck('flag_state');

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
            ->values()
            ->map(function ($item) {
                return [
                    'code' => $item['iso2'],
                    'name' => $item['name'],
                    'emoji' => $item['flag_emoji'],
                    'label' => "{$item['flag_emoji']} {$item['iso2']} • {$item['name']}",
                ];
            });

        $filterMeta = [
            'flags' => $availableFlagCountries,
            'vessel_types' => Vessel::whereNotNull('vessel_type')
                ->distinct()
                ->orderBy('vessel_type')
                ->pluck('vessel_type')
                ->filter()
                ->values(),
            'review_statuses' => SatelliteDetection::getReviewStatuses(),
            'correlation_statuses' => [
                'insufficient_data' => 'Insufficient Data (No AIS)',
                'matched' => 'Matched AIS Transponder',
                'possible_match' => 'Possible Match',
                'unmatched' => 'Unmatched / Dark Suspect',
            ],
            'platforms' => SatelliteScene::whereNotNull('platform_name')
                ->distinct()
                ->orderBy('platform_name')
                ->pluck('platform_name')
                ->values(),
        ];

        return view('map.index', compact('providers', 'filterMeta'));
    }
}
