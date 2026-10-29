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

        $filterMeta = [
            'flags' => Vessel::whereNotNull('flag_state')
                ->distinct()
                ->pluck('flag_state')
                ->filter()
                ->map(function ($code) {
                    $info = FlagStateService::resolveFromCodeAndMmsi($code, null);
                    return [
                        'code' => $code,
                        'name' => $info['country_name'] ?? $code,
                        'emoji' => $info['flag_emoji'] ?? '🌐',
                        'label' => ($info['flag_emoji'] ?? '🌐') . ' ' . ($info['country_name'] ?? $code) . " ({$code})",
                    ];
                })
                ->sortBy('name')
                ->values(),
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
