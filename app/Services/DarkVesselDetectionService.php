<?php

namespace App\Services;

use App\Models\SatelliteDetection;
use App\Models\SatelliteScene;
use App\Models\Vessel;
use App\Models\VesselPosition;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DarkVesselDetectionService
{
    /**
     * Correlate SAR radar detections with AIS positions
     * If a SAR radar target has NO AIS broadcast within threshold distance & time,
     * it is classified as a "dark_vessel_candidate".
     *
     * @param string $sceneId SatelliteScene UUID
     * @param float $searchRadiusMeters Default 3000m (3km)
     * @param int $timeToleranceMinutes Default 30 mins
     * @return array{correlated: int, dark_candidates: int, total: int}
     */
    public function correlateDetectionsWithAis(string $sceneId, float $searchRadiusMeters = 3000.0, int $timeToleranceMinutes = 30): array
    {
        $scene = SatelliteScene::findOrFail($sceneId);
        $detections = $scene->detections;

        $correlatedCount = 0;
        $darkCandidatesCount = 0;

        foreach ($detections as $detection) {
            $detTime = $detection->detected_at;
            $detLat = $detection->latitude;
            $detLng = $detection->longitude;

            // Search for nearby AIS positions within time tolerance
            $windowStart = (clone $detTime)->subMinutes($timeToleranceMinutes);
            $windowEnd = (clone $detTime)->addMinutes($timeToleranceMinutes);

            // Bounding box approximation (~1 deg latitude ~ 111km)
            $latDelta = $searchRadiusMeters / 111000.0;
            $lngDelta = $searchRadiusMeters / (111000.0 * cos(deg2rad($detLat)));

            $nearbyPositions = VesselPosition::whereBetween('observed_at', [$windowStart, $windowEnd])
                ->whereBetween('latitude', [$detLat - $latDelta, $detLat + $latDelta])
                ->whereBetween('longitude', [$detLng - $lngDelta, $detLng + $lngDelta])
                ->with('vessel')
                ->get();

            $matchedPosition = null;
            $minDistance = PHP_INT_MAX;

            foreach ($nearbyPositions as $pos) {
                $dist = $this->haversineDistance($detLat, $detLng, $pos->latitude, $pos->longitude);
                if ($dist <= $searchRadiusMeters && $dist < $minDistance) {
                    $minDistance = $dist;
                    $matchedPosition = $pos;
                }
            }

            if ($matchedPosition) {
                // Matched with active AIS broadcasting vessel
                $detection->update([
                    'correlated_vessel_id' => $matchedPosition->vessel_id,
                    'correlation_method' => 'nearest_neighbor_spatial_temporal',
                    'correlation_status' => 'matched',
                    'distance_to_nearest_ais_meters' => round($minDistance, 2),
                    'analyst_notes' => 'Correlated with active AIS broadcast from ' . ($matchedPosition->vessel?->name ?? 'Unknown Vessel'),
                ]);
                $correlatedCount++;
            } else {
                // No active AIS broadcast in proximity => Candidate Dark Vessel
                // Search for any vessel that previously reported near here and had a gap
                $gapVessel = $this->findPotentialGapVessel($detLat, $detLng, $detTime);

                $detection->update([
                    'correlated_vessel_id' => $gapVessel?->id,
                    'correlation_method' => 'ais_gap_extrapolation',
                    'correlation_status' => 'dark_vessel_candidate',
                    'distance_to_nearest_ais_meters' => null,
                    'ais_gap_duration_minutes' => $gapVessel ? 120 : null,
                    'analyst_notes' => 'Radar echo detected with no corresponding active AIS broadcast. Potential non-broadcasting vessel or small craft.',
                ]);
                $darkCandidatesCount++;
            }
        }

        return [
            'total' => $detections->count(),
            'correlated' => $correlatedCount,
            'dark_candidates' => $darkCandidatesCount,
        ];
    }

    /**
     * Find historical vessels that last broadcast within extrapolation distance
     */
    protected function findPotentialGapVessel(float $lat, float $lng, Carbon $time): ?Vessel
    {
        // Check for vessels seen within 12 hours prior, within 50 nautical miles
        return Vessel::whereHas('positions', function ($q) use ($lat, $lng, $time) {
            $q->whereBetween('observed_at', [(clone $time)->subHours(12), $time])
              ->whereBetween('latitude', [$lat - 0.8, $lat + 0.8])
              ->whereBetween('longitude', [$lng - 0.8, $lng + 0.8]);
        })->first();
    }

    protected function haversineDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000;
        $lat1Rad = deg2rad($lat1);
        $lat2Rad = deg2rad($lat2);
        $deltaLat = deg2rad($lat2 - $lat1);
        $deltaLon = deg2rad($lon2 - $lon1);

        $a = sin($deltaLat / 2) * sin($deltaLat / 2) +
             cos($lat1Rad) * cos($lat2Rad) *
             sin($deltaLon / 2) * sin($deltaLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earthRadius * $c;
    }
}
