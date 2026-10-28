<?php

namespace App\Http\Controllers;

use App\Models\DataProvider;
use App\Models\Vessel;
use App\Models\VesselPosition;
use Illuminate\Http\Request;

class TrackingController extends Controller
{
    public function index()
    {
        $aisProvider = DataProvider::where('type', 'ais_stream')->first();
        $recentPositionsCount = VesselPosition::where('observed_at', '>=', now()->subHours(24))->count();

        $activeVessels = Vessel::whereHas('positions', function ($q) {
            $q->where('observed_at', '>=', now()->subHours(24));
        })->with('latestPosition')->limit(20)->get();

        return view('tracking.index', compact('aisProvider', 'recentPositionsCount', 'activeVessels'));
    }
}
