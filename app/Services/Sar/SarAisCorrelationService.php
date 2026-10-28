<?php

namespace App\Services\Sar;

use App\Models\SatelliteDetection;
use App\Models\VesselPosition;
use App\Models\Vessel;
use Illuminate\Support\Facades\DB;

class SarAisCorrelationService
{
    protected float $maxDistanceMeters;
    protected int $timeToleranceMinutes;

    public const VERSION = '2.0';
    public const ALGORITHM_VERSION = '2.0-GFVIP';

    public function __construct()
    {
        $this->maxDistanceMeters = (float) config('maritime.providers.copernicus_sar.pipeline.correlation_max_distance_meters', 1500.0);
        $this->timeToleranceMinutes = (int) config('maritime.providers.copernicus_sar.pipeline.correlation_time_tolerance_minutes', 30);
    }

    /**
     * Correlate a satellite SAR candidate detection with AIS tracks
     */
    public function correlateDetection(SatelliteDetection $detection, ?float $maxDistance = null, ?int $timeTolerance = null): array
    {
        $maxDist = $maxDistance ?? $this->maxDistanceMeters;
        $toleranceMin = $timeTolerance ?? $this->timeToleranceMinutes;

        $detectedAt = $detection->detected_at ?? now();
        $startTime = $detectedAt->copy()->subMinutes($toleranceMin);
        $endTime = $detectedAt->copy()->addMinutes($toleranceMin);

        $lat = (float) $detection->latitude;
        $lng = (float) $detection->longitude;

        // Query candidate positions within spatial & temporal window
        $latDelta = ($maxDist * 2) / 111000.0;
        $lngDelta = ($maxDist * 2) / (111000.0 * max(0.1, cos(deg2rad($lat))));

        $candidatePositions = VesselPosition::with('vessel')
            ->whereBetween('observed_at', [$startTime, $endTime])
            ->whereBetween('latitude', [$lat - $latDelta, $lat + $latDelta])
            ->whereBetween('longitude', [$lng - $lngDelta, $lng + $lngDelta])
            ->get();

        $closestPos = null;
        $minDistance = INF;

        foreach ($candidatePositions as $pos) {
            $dist = $this->calculateHaversineDistance($lat, $lng, (float) $pos->latitude, (float) $pos->longitude);
            if ($dist < $minDistance) {
                $minDistance = $dist;
                $closestPos = $pos;
            }
        }

        // Calculate enhanced correlation metrics
        $enhanced = $this->calculateEnhancedCorrelation($detection, $toleranceMin);

        $reviewMeta = $detection->review_metadata ?? [];
        $reviewMeta['correlation_evidence'] = $enhanced;
        $reviewMeta['correlation_version'] = self::VERSION;
        $reviewMeta['correlation_algorithm'] = self::ALGORITHM_VERSION;

        if ($closestPos && $minDistance <= $maxDist) {
            $detection->update([
                'correlated_vessel_id' => $closestPos->vessel_id,
                'correlation_status' => 'matched',
                'correlation_method' => 'spatio_temporal_proximity',
                'distance_to_nearest_ais_meters' => round($minDistance, 2),
                'analyst_notes' => "Terkorelasi dengan kapal AIS: {$closestPos->vessel?->name} (MMSI: {$closestPos->vessel?->mmsi}) pada jarak " . round($minDistance, 1) . " meter.",
                'review_metadata' => $reviewMeta,
            ]);

            return [
                'status' => 'matched',
                'vessel_id' => $closestPos->vessel_id,
                'vessel_name' => $closestPos->vessel?->name,
                'mmsi' => $closestPos->vessel?->mmsi,
                'distance_meters' => round($minDistance, 2),
                'enhanced' => $enhanced,
            ];
        }

        // Check if regional AIS coverage exists (within 50 km in last 6 hours)
        $regionalDelta = 50000.0 / 111000.0;
        $hasRegionalCoverage = VesselPosition::whereBetween('observed_at', [$detectedAt->copy()->subHours(6), $detectedAt->copy()->addHours(6)])
            ->whereBetween('latitude', [$lat - $regionalDelta, $lat + $regionalDelta])
            ->whereBetween('longitude', [$lng - $regionalDelta, $lng + $regionalDelta])
            ->exists();

        if (!$hasRegionalCoverage) {
            $detection->update([
                'correlation_status' => 'insufficient_data',
                'distance_to_nearest_ais_meters' => $closestPos ? round($minDistance, 2) : null,
                'analyst_notes' => 'Cakupan stasiun penerima AIS tidak memadai pada sektor geografis dan rentang waktu ini. Status dark vessel tidak dapat disimpulkan secara otomatis.',
                'review_metadata' => $reviewMeta,
            ]);

            return [
                'status' => 'insufficient_data',
                'message' => 'Cakupan AIS regional tidak memadai.',
                'distance_meters' => $closestPos ? round($minDistance, 2) : null,
                'enhanced' => $enhanced,
            ];
        }

        // Active coverage verified but no vessel matched -> Dark Vessel Candidate
        $detection->update([
            'correlation_status' => 'dark_vessel_candidate',
            'distance_to_nearest_ais_meters' => $closestPos ? round($minDistance, 2) : null,
            'analyst_notes' => 'Kandidat kapal terdeteksi pada citra SAR tanpa transmisi AIS aktif di area dengan cakupan terverifikasi. Memerlukan penelaahan analis maritim.',
            'review_metadata' => $reviewMeta,
        ]);

        return [
            'status' => 'dark_vessel_candidate',
            'message' => 'Kandidat dark vessel teridentifikasi (tidak ada transmisi AIS dalam radius toleransi).',
            'distance_meters' => $closestPos ? round($minDistance, 2) : null,
            'enhanced' => $enhanced,
        ];
    }

    /**
     * Calculate comprehensive correlation evidence metrics without synthetic assumptions (Tahap 9).
     */
    public function calculateEnhancedCorrelation(SatelliteDetection $detection, int $windowMinutes = 60): array
    {
        $lat = (float) $detection->latitude;
        $lng = (float) $detection->longitude;
        $detectedAt = $detection->detected_at ?? now();

        $positions = $this->getHistoricalTrack($detection, $windowMinutes);

        if (empty($positions)) {
            return [
                'spatial' => [
                    'nearest_ais_distance_meters' => null,
                    'nearest_ais_timestamp' => null,
                    'distance_at_acquisition_meters' => null,
                    'min_distance_in_window_meters' => null,
                ],
                'temporal' => [
                    'time_difference_seconds' => null,
                    'time_difference_minutes' => null,
                ],
                'motion' => [
                    'sog_difference_knots' => null,
                    'cog_difference_degrees' => null,
                    'heading_consistency' => 'not_available',
                ],
                'track_continuity' => [
                    'trajectory' => 'no_relevant_track',
                    'positions_evaluated' => 0,
                    'description' => 'Tidak ditemukan posisi AIS dalam rentang waktu ±' . $windowMinutes . ' menit.',
                ],
                'correlation_evidence_score' => null,
                'score_breakdown' => null,
                'provenance' => [
                    'version' => self::VERSION,
                    'algorithm_version' => self::ALGORITHM_VERSION,
                    'temporal_window_minutes' => $windowMinutes,
                    'evaluated_at' => now()->toIso8601String(),
                ],
            ];
        }

        // Find nearest position and analyze track
        $closestPos = null;
        $minDistance = INF;
        $acquisitionPos = null;
        $minAcquisitionDeltaSec = INF;

        $distances = [];

        foreach ($positions as $p) {
            $dist = $this->calculateHaversineDistance($lat, $lng, (float) $p['latitude'], (float) $p['longitude']);
            $distances[] = $dist;

            if ($dist < $minDistance) {
                $minDistance = $dist;
                $closestPos = $p;
            }

            $deltaSec = abs(strtotime($p['observed_at']) - $detectedAt->getTimestamp());
            if ($deltaSec < $minAcquisitionDeltaSec) {
                $minAcquisitionDeltaSec = $deltaSec;
                $acquisitionPos = $p;
            }
        }

        $nearestTimestamp = $closestPos ? $closestPos['observed_at'] : null;
        $timeDiffSec = $closestPos ? abs(strtotime($closestPos['observed_at']) - $detectedAt->getTimestamp()) : null;
        $timeDiffMin = $timeDiffSec !== null ? round($timeDiffSec / 60, 1) : null;
        $acquisitionDist = $acquisitionPos ? round($this->calculateHaversineDistance($lat, $lng, (float) $acquisitionPos['latitude'], (float) $acquisitionPos['longitude']), 2) : null;

        // Motion analysis
        $sog = $closestPos['speed_knots'] ?? null;
        $cog = $closestPos['course_degrees'] ?? null;
        $heading = $closestPos['heading_degrees'] ?? null;

        $cogDiff = null;
        $headingConsistency = 'not_available';

        if ($closestPos && $cog !== null) {
            $bearingToTarget = $this->calculateBearing((float) $closestPos['latitude'], (float) $closestPos['longitude'], $lat, $lng);
            $cogDiff = round(min(abs($cog - $bearingToTarget), 360 - abs($cog - $bearingToTarget)), 1);
        }

        if ($heading !== null && $cog !== null) {
            $hDiff = min(abs($heading - $cog), 360 - abs($heading - $cog));
            $headingConsistency = $hDiff <= 15.0 ? 'consistent' : ($hDiff <= 45.0 ? 'moderate' : 'divergent');
        }

        // Track continuity
        $trajectory = 'no_relevant_track';
        $count = count($distances);
        if ($count >= 2) {
            $firstDist = $distances[0];
            $lastDist = $distances[$count - 1];
            $midMin = min($distances);

            if ($firstDist > $midMin && $lastDist > $midMin) {
                $trajectory = 'passing';
            } elseif ($firstDist > $lastDist) {
                $trajectory = 'approaching';
            } elseif ($firstDist < $lastDist) {
                $trajectory = 'receding';
            } else {
                $trajectory = 'passing';
            }
        }

        // Calculate explainable correlation evidence score
        $scoreBreakdown = $this->computeCorrelationScore(
            $minDistance,
            $timeDiffMin,
            $cogDiff,
            $sog,
            $trajectory
        );

        return [
            'spatial' => [
                'nearest_ais_distance_meters' => round($minDistance, 2),
                'nearest_ais_timestamp' => $nearestTimestamp,
                'distance_at_acquisition_meters' => $acquisitionDist,
                'min_distance_in_window_meters' => round($minDistance, 2),
            ],
            'temporal' => [
                'time_difference_seconds' => $timeDiffSec,
                'time_difference_minutes' => $timeDiffMin,
            ],
            'motion' => [
                'sog_difference_knots' => $sog !== null ? round((float) $sog, 1) : null,
                'cog_difference_degrees' => $cogDiff,
                'heading_consistency' => $headingConsistency,
            ],
            'track_continuity' => [
                'trajectory' => $trajectory,
                'positions_evaluated' => $count,
                'description' => $this->getTrajectoryDescription($trajectory),
            ],
            'correlation_evidence_score' => $scoreBreakdown['score'],
            'score_breakdown' => $scoreBreakdown['components'],
            'provenance' => [
                'version' => self::VERSION,
                'algorithm_version' => self::ALGORITHM_VERSION,
                'temporal_window_minutes' => $windowMinutes,
                'evaluated_at' => now()->toIso8601String(),
            ],
        ];
    }

    /**
     * Compute explainable correlation evidence score (NOT dark vessel probability).
     */
    protected function computeCorrelationScore(
        float $minDist,
        ?float $timeDiffMin,
        ?float $cogDiff,
        ?float $sog,
        string $trajectory
    ): array {
        // Component 1: Spatial Proximity (40%)
        // 1.0 at <= 500m, decays linearly to 0 at 5000m
        $spatialScore = max(0.0, min(1.0, 1.0 - max(0.0, ($minDist - 500.0) / 4500.0)));

        // Component 2: Temporal Proximity (25%)
        // 1.0 at <= 5m, decays linearly to 0 at 60m
        $temporalScore = 0.0;
        if ($timeDiffMin !== null) {
            $temporalScore = max(0.0, min(1.0, 1.0 - max(0.0, ($timeDiffMin - 5.0) / 55.0)));
        }

        // Component 3: Heading Consistency (15%)
        $headingScore = null;
        if ($cogDiff !== null) {
            $headingScore = max(0.0, min(1.0, 1.0 - max(0.0, ($cogDiff - 15.0) / 75.0)));
        }

        // Component 4: Speed Consistency (10%)
        $speedScore = null;
        if ($sog !== null) {
            $speedScore = ($sog >= 1.0 && $sog <= 25.0) ? 1.0 : ($sog > 0 ? 0.6 : 0.4);
        }

        // Component 5: Track Continuity (10%)
        $trackScore = match ($trajectory) {
            'passing' => 1.0,
            'approaching' => 0.85,
            'receding' => 0.70,
            default => 0.20,
        };

        // Calculate available weighted sum
        $totalWeight = 0.40 + 0.25 + 0.10; // core weights
        $weightedSum = ($spatialScore * 0.40) + ($temporalScore * 0.25) + ($trackScore * 0.10);

        if ($headingScore !== null) {
            $totalWeight += 0.15;
            $weightedSum += ($headingScore * 0.15);
        }
        if ($speedScore !== null) {
            $totalWeight += 0.10;
            $weightedSum += ($speedScore * 0.10);
        }

        $finalScore = $totalWeight > 0 ? round($weightedSum / $totalWeight, 4) : 0.0;

        return [
            'score' => $finalScore,
            'components' => [
                'spatial_proximity' => [
                    'score' => round($spatialScore, 2),
                    'weight' => 0.40,
                    'raw_distance_m' => round($minDist, 1),
                ],
                'temporal_proximity' => [
                    'score' => round($temporalScore, 2),
                    'weight' => 0.25,
                    'raw_delta_min' => $timeDiffMin,
                ],
                'heading_consistency' => [
                    'score' => $headingScore !== null ? round($headingScore, 2) : null,
                    'weight' => 0.15,
                    'status' => $headingScore !== null ? 'evaluated' : 'not_available',
                    'raw_cog_diff_deg' => $cogDiff,
                ],
                'speed_consistency' => [
                    'score' => $speedScore !== null ? round($speedScore, 2) : null,
                    'weight' => 0.10,
                    'status' => $speedScore !== null ? 'evaluated' : 'not_available',
                    'raw_sog_knots' => $sog !== null ? round((float) $sog, 1) : null,
                ],
                'track_continuity' => [
                    'score' => round($trackScore, 2),
                    'weight' => 0.10,
                    'trajectory' => $trajectory,
                ],
            ],
        ];
    }

    /**
     * Retrieve historical AIS positions for a detection within temporal window.
     * Supported windows: 30, 60, 180, 360, 1440 (24h) minutes.
     */
    public function getHistoricalTrack(SatelliteDetection $detection, int $windowMinutes = 60): array
    {
        $lat = (float) $detection->latitude;
        $lng = (float) $detection->longitude;
        $detectedAt = $detection->detected_at ?? now();

        $startTime = $detectedAt->copy()->subMinutes($windowMinutes);
        $endTime = $detectedAt->copy()->addMinutes($windowMinutes);

        // Search bounding box proportional to window (max vessel speed ~30 knots = 55.5 km/h)
        $speedKmH = 55.5;
        $radiusKm = min(500.0, max(30.0, ($windowMinutes / 60.0) * $speedKmH + 15.0));
        $latDelta = $radiusKm / 111.0;
        $lngDelta = $radiusKm / (111.0 * max(0.1, cos(deg2rad($lat))));

        $positions = VesselPosition::with('vessel')
            ->whereBetween('observed_at', [$startTime, $endTime])
            ->whereBetween('latitude', [$lat - $latDelta, $lat + $latDelta])
            ->whereBetween('longitude', [$lng - $lngDelta, $lng + $lngDelta])
            ->orderBy('observed_at', 'asc')
            ->get();

        $result = [];
        foreach ($positions as $pos) {
            $dist = $this->calculateHaversineDistance($lat, $lng, (float) $pos->latitude, (float) $pos->longitude);
            $result[] = [
                'id' => $pos->id,
                'vessel_id' => $pos->vessel_id,
                'vessel_name' => $pos->vessel?->name ?? 'Unknown Vessel',
                'mmsi' => $pos->vessel?->mmsi,
                'latitude' => (float) $pos->latitude,
                'longitude' => (float) $pos->longitude,
                'speed_knots' => $pos->speed_knots !== null ? (float) $pos->speed_knots : null,
                'course_degrees' => $pos->course_degrees !== null ? (float) $pos->course_degrees : null,
                'heading_degrees' => $pos->heading_degrees !== null ? (float) $pos->heading_degrees : null,
                'observed_at' => $pos->observed_at?->toIso8601String(),
                'distance_to_candidate_meters' => round($dist, 2),
                'time_delta_seconds' => $pos->observed_at ? ($pos->observed_at->getTimestamp() - $detectedAt->getTimestamp()) : 0,
            ];
        }

        return $result;
    }

    /**
     * Generate GeoJSON LineString and observation points for MapLibre track visualization.
     */
    public function getHistoricalTrackGeoJson(SatelliteDetection $detection, int $windowMinutes = 60): array
    {
        $positions = $this->getHistoricalTrack($detection, $windowMinutes);
        $features = [];

        // 1. SAR Candidate Target Feature (Primary Reticle)
        $features[] = [
            'type' => 'Feature',
            'geometry' => [
                'type' => 'Point',
                'coordinates' => [(float) $detection->longitude, (float) $detection->latitude],
            ],
            'properties' => [
                'feature_type' => 'sar_candidate',
                'identifier' => $detection->detection_identifier,
                'tcr_db' => $detection->tcr_db,
                'confidence_score' => $detection->confidence_score,
                'detected_at' => $detection->detected_at?->toIso8601String(),
                'review_status' => $detection->review_status,
                'correlation_status' => $detection->correlation_status,
            ],
        ];

        if (empty($positions)) {
            return [
                'type' => 'FeatureCollection',
                'features' => $features,
                'properties' => [
                    'window_minutes' => $windowMinutes,
                    'position_count' => 0,
                    'track_status' => 'empty',
                ],
            ];
        }

        // Group by vessel
        $vesselGroups = [];
        foreach ($positions as $p) {
            $vesselKey = $p['vessel_id'] ?? $p['mmsi'] ?? 'unknown';
            $vesselGroups[$vesselKey][] = $p;
        }

        foreach ($vesselGroups as $vKey => $vPositions) {
            // Find nearest position in this vessel track
            $minDist = INF;
            $nearestIdx = 0;
            foreach ($vPositions as $idx => $vp) {
                if ($vp['distance_to_candidate_meters'] < $minDist) {
                    $minDist = $vp['distance_to_candidate_meters'];
                    $nearestIdx = $idx;
                }
            }

            // LineString feature
            if (count($vPositions) >= 2) {
                $coords = array_map(fn($p) => [$p['longitude'], $p['latitude']], $vPositions);
                $features[] = [
                    'type' => 'Feature',
                    'geometry' => [
                        'type' => 'LineString',
                        'coordinates' => $coords,
                    ],
                    'properties' => [
                        'feature_type' => 'ais_track_line',
                        'vessel_id' => $vPositions[0]['vessel_id'],
                        'vessel_name' => $vPositions[0]['vessel_name'],
                        'mmsi' => $vPositions[0]['mmsi'],
                        'points_count' => count($vPositions),
                    ],
                ];
            }

            // Point features for each observation
            foreach ($vPositions as $idx => $vp) {
                $role = 'intermediate';
                if ($idx === $nearestIdx) {
                    $role = 'nearest';
                } elseif ($vp['time_delta_seconds'] < 0 && $idx === 0) {
                    $role = 'before_sar';
                } elseif ($vp['time_delta_seconds'] > 0 && $idx === count($vPositions) - 1) {
                    $role = 'after_sar';
                }

                $features[] = [
                    'type' => 'Feature',
                    'geometry' => [
                        'type' => 'Point',
                        'coordinates' => [$vp['longitude'], $vp['latitude']],
                    ],
                    'properties' => [
                        'feature_type' => 'ais_position',
                        'role' => $role,
                        'position_id' => $vp['id'],
                        'vessel_name' => $vp['vessel_name'],
                        'mmsi' => $vp['mmsi'],
                        'observed_at' => $vp['observed_at'],
                        'speed_knots' => $vp['speed_knots'],
                        'course_degrees' => $vp['course_degrees'],
                        'heading_degrees' => $vp['heading_degrees'],
                        'distance_to_sar_meters' => $vp['distance_to_candidate_meters'],
                        'time_delta_seconds' => $vp['time_delta_seconds'],
                        'time_delta_minutes' => round($vp['time_delta_seconds'] / 60, 1),
                    ],
                ];
            }
        }

        return [
            'type' => 'FeatureCollection',
            'features' => $features,
            'properties' => [
                'window_minutes' => $windowMinutes,
                'position_count' => count($positions),
                'track_status' => 'available',
            ],
        ];
    }

    /**
     * Initial bearing from point 1 to point 2 in degrees (0-360)
     */
    protected function calculateBearing(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $deltaLambda = deg2rad($lon2 - $lon1);

        $y = sin($deltaLambda) * cos($phi2);
        $x = cos($phi1) * sin($phi2) - sin($phi1) * cos($phi2) * cos($deltaLambda);
        $theta = atan2($y, $x);

        return fmod((rad2deg($theta) + 360.0), 360.0);
    }

    protected function getTrajectoryDescription(string $trajectory): string
    {
        return match ($trajectory) {
            'passing' => 'Kapal terpantau melintas mendekati kemudian menjauhi lokasi sentroid SAR.',
            'approaching' => 'Kapal terpantau bergerak mendekati koordinat sentroid SAR.',
            'receding' => 'Kapal terpantau bergerak menjauhi koordinat sentroid SAR.',
            default => 'Tidak terdapat kontinuitas lintasan AIS yang relevan.',
        };
    }

    /**
     * Great-circle distance between two coordinates in meters
     */
    public function calculateHaversineDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000.0; // meters

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
}
