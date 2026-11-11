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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        // 1. Compute genuine counts directly from database
        $totalVessels = Vessel::count();

        // 2. Compute authentic Vessel Type Breakdown (Cached for 60 seconds)
        $vesselTypeBreakdown = Cache::remember('dashboard_vessel_type_breakdown', 60, function () use ($totalVessels) {
            $rawBreakdown = DB::table('vessels')
                ->selectRaw("COALESCE(NULLIF(TRIM(vessel_type), ''), 'unknown') as raw_type, COUNT(*) as count")
                ->groupBy(DB::raw("COALESCE(NULLIF(TRIM(vessel_type), ''), 'unknown')"))
                ->orderByDesc('count')
                ->get();

            $typeLabels = [
                'pleasure' => 'Pleasure',
                'unknown' => 'Unknown',
                'cargo' => 'Cargo',
                'sailing' => 'Sailing',
                'tanker' => 'Tanker',
                'passenger' => 'Passenger',
                'tug' => 'Tug',
                'other' => 'Other',
                'not_available' => 'Not Available',
                'fishing' => 'Fishing',
                'high_speed_craft' => 'High Speed Craft',
                'dredging' => 'Dredging',
                'unmapped' => 'Unmapped',
                'towing' => 'Towing',
                'sar' => 'SAR (Search & Rescue)',
                'pilot' => 'Pilot Vessel',
                'law_enforcement' => 'Law Enforcement',
                'port_tender' => 'Port Tender',
                'military' => 'Military',
                'diving' => 'Diving',
                'anti_pollution' => 'Anti-Pollution',
                'medical' => 'Medical Transport',
                'patrol' => 'Patrol',
            ];

            $colorPalette = [
                'pleasure' => '#f97316',        // Vibrant Orange
                'unknown' => '#94a3b8',         // Slate Gray
                'cargo' => '#3b82f6',           // Bright Blue
                'sailing' => '#06b6d4',         // Cyan
                'tanker' => '#0d9488',          // Teal
                'passenger' => '#f59e0b',       // Amber
                'tug' => '#d97706',             // Ochre/Warm Amber
                'other' => '#64748b',           // Cool Slate
                'not_available' => '#a8a29e',   // Stone
                'fishing' => '#2563eb',         // Royal Maritime Blue
                'high_speed_craft' => '#8b5cf6',// Violet
                'dredging' => '#84cc16',        // Lime
                'unmapped' => '#cbd5e1',        // Light Slate
                'towing' => '#eab308',          // Yellow
                'sar' => '#ef4444',             // Rescue Red
                'pilot' => '#60a5fa',           // Sky Blue
                'law_enforcement' => '#1d4ed8', // Dark Blue
                'port_tender' => '#10b981',     // Emerald
                'military' => '#475569',        // Dark Slate
                'diving' => '#14b8a6',          // Light Teal
                'anti_pollution' => '#059669',  // Deep Emerald
                'medical' => '#ec4899',         // Pink
                'patrol' => '#6366f1',          // Indigo
            ];

            $fallbackColors = [
                '#10b981', '#6366f1', '#ec4899', '#8b5cf6', '#14b8a6',
                '#f43f5e', '#0284c7', '#ca8a04', '#4f46e5', '#059669',
            ];

            $breakdown = [];
            $i = 0;
            foreach ($rawBreakdown as $row) {
                $rawKey = strtolower(trim((string) $row->raw_type));
                $label = $typeLabels[$rawKey] ?? ucwords(str_replace(['_', '-'], ' ', $rawKey));
                $color = $colorPalette[$rawKey] ?? ($fallbackColors[$i % count($fallbackColors)]);
                $count = (int) $row->count;
                $percentage = $totalVessels > 0 ? round(($count / $totalVessels) * 100, 1) : 0;

                $breakdown[] = [
                    'key' => $rawKey,
                    'label' => $label,
                    'count' => $count,
                    'percentage' => $percentage,
                    'color' => $color,
                ];
                $i++;
            }

            return $breakdown;
        });

        $vesselTypeCategoryCount = count($vesselTypeBreakdown);
        $topVesselType = !empty($vesselTypeBreakdown) ? $vesselTypeBreakdown[0] : null;

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
            'recentAlerts',
            'vesselTypeBreakdown',
            'vesselTypeCategoryCount',
            'topVesselType'
        ));
    }
}
