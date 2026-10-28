<?php

namespace App\Http\Controllers;

use App\Models\ComplianceEvent;
use App\Models\DataProvider;
use App\Models\Investigation;
use App\Models\SatelliteDetection;
use App\Models\Vessel;
use App\Models\VesselEncounter;
use App\Models\VesselPosition;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index()
    {
        // 1. Compute genuine counts directly from database
        $totalVessels = Vessel::count();

        // Active positions within last 24 hours
        $activeVesselsCount = VesselPosition::where('observed_at', '>=', now()->subHours(24))
            ->distinct('vessel_id')
            ->count('vessel_id');

        // Encounters requiring review
        $encountersNeedingReview = VesselEncounter::whereIn('status', ['candidate', 'needs_review'])->count();

        // Dark vessel candidates (SAR radar detections without corresponding AIS)
        $darkVesselCandidates = SatelliteDetection::where('correlation_status', 'dark_vessel_candidate')->count();

        // Open compliance alerts
        $openComplianceAlerts = ComplianceEvent::whereIn('status', ['open', 'under_review'])->count();

        // Active investigations
        $openInvestigations = Investigation::whereIn('status', ['open', 'in_progress'])->count();

        // Latest positions recorded (for freshness indicator)
        $latestPosition = VesselPosition::orderBy('observed_at', 'desc')->first();
        $latestTelemetryAge = $latestPosition
            ? $latestPosition->observed_at->diffForHumans()
            : 'Belum ada data posisi masuk';

        // 2. Data Providers operational status
        $providers = DataProvider::all();

        // 3. Recent Encounters (real records only)
        $recentEncounters = VesselEncounter::with(['vessel1', 'vessel2'])
            ->orderBy('start_time', 'desc')
            ->limit(5)
            ->get();

        // 4. Recent Compliance Alerts
        $recentAlerts = ComplianceEvent::with(['vessel', 'rule'])
            ->orderBy('event_time', 'desc')
            ->limit(5)
            ->get();

        return view('dashboard.index', compact(
            'totalVessels',
            'activeVesselsCount',
            'encountersNeedingReview',
            'darkVesselCandidates',
            'openComplianceAlerts',
            'openInvestigations',
            'latestTelemetryAge',
            'providers',
            'recentEncounters',
            'recentAlerts'
        ));
    }
}
