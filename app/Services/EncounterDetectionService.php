<?php

namespace App\Services;

use App\Models\Vessel;
use App\Models\VesselPosition;
use App\Models\VesselEncounter;
use App\Models\EncounterVessel;
use App\Models\AuditLog;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EncounterDetectionService
{
    protected float $maxDistanceMeters;
    protected int $minDurationMinutes;
    protected int $timestampToleranceMinutes;
    protected string $algorithmVersion;

    public function __construct(
        ?float $maxDistanceMeters = null,
        ?int $minDurationMinutes = null,
        ?int $timestampToleranceMinutes = null
    ) {
        $this->maxDistanceMeters = $maxDistanceMeters ?? (float) config('maritime.encounters.max_distance_meters', 500.0);
        $this->minDurationMinutes = $minDurationMinutes ?? (int) config('maritime.encounters.min_duration_minutes', 30);
        $this->timestampToleranceMinutes = $timestampToleranceMinutes ?? (int) config('maritime.encounters.timestamp_tolerance_minutes', 10);
        $this->algorithmVersion = config('maritime.encounters.algorithm_version', 'v1.0-spatial-temporal');
    }

    /**
     * Run the native encounter detection algorithm on historical or recent AIS positions
     *
     * @param Carbon $startTime
     * @param Carbon $endTime
     * @param array $options
     * @return array{detected: int, skipped: int, encounters: Collection}
     */
    public function detectEncounters(Carbon $startTime, Carbon $endTime, array $options = []): array
    {
        $detectedCount = 0;
        $skippedCount = 0;
        $newEncounters = collect();

        // 1. Fetch active vessels with sufficient telemetry in the time window (at least 2 positions)
        $vesselIdsWithTelemetry = VesselPosition::whereBetween('observed_at', [$startTime, $endTime])
            ->where('data_quality_score', '>=', 0.5) // filter low quality noise
            ->groupBy('vessel_id')
            ->havingRaw('COUNT(id) >= 2')
            ->pluck('vessel_id')
            ->toArray();

        if (count($vesselIdsWithTelemetry) < 2) {
            return [
                'detected' => 0,
                'skipped' => 0,
                'encounters' => $newEncounters,
                'message' => 'Data posisi AIS tidak mencukupi untuk analisis spasio-temporal (minimal 2 kapal aktif diperlukan).'
            ];
        }

        // Compare each pair of vessels
        $vesselCount = count($vesselIdsWithTelemetry);
        for ($i = 0; $i < $vesselCount; $i++) {
            for ($j = $i + 1; $j < $vesselCount; $j++) {
                $vesselAId = $vesselIdsWithTelemetry[$i];
                $vesselBId = $vesselIdsWithTelemetry[$j];

                // Ensure consistent ordered IDs for deduplication
                if ($vesselAId > $vesselBId) {
                    [$vesselAId, $vesselBId] = [$vesselBId, $vesselAId];
                }

                $pairResult = $this->evaluateVesselPair(
                    $vesselAId,
                    $vesselBId,
                    $startTime,
                    $endTime
                );

                if ($pairResult) {
                    // Check deduplication
                    $exists = VesselEncounter::where('vessel_1_id', $vesselAId)
                        ->where('vessel_2_id', $vesselBId)
                        ->whereBetween('start_time', [
                            Carbon::parse($pairResult['start_time'])->subMinutes(15),
                            Carbon::parse($pairResult['start_time'])->addMinutes(15)
                        ])
                        ->exists();

                    if ($exists) {
                        $skippedCount++;
                        continue;
                    }

                    $encounter = $this->storeEncounter($vesselAId, $vesselBId, $pairResult);
                    $newEncounters->push($encounter);
                    $detectedCount++;
                }
            }
        }

        AuditLog::log(
            'detect_encounters',
            'vessel_encounter',
            null,
            [
                'detected' => $detectedCount,
                'skipped' => $skippedCount,
                'window' => [$startTime->toIso8601String(), $endTime->toIso8601String()],
                'parameters' => [
                    'max_distance_meters' => $this->maxDistanceMeters,
                    'min_duration_minutes' => $this->minDurationMinutes,
                    'tolerance_minutes' => $this->timestampToleranceMinutes,
                ]
            ]
        );

        return [
            'detected' => $detectedCount,
            'skipped' => $skippedCount,
            'encounters' => $newEncounters,
        ];
    }

    /**
     * Evaluate two vessels for close proximity over time
     */
    protected function evaluateVesselPair(string $vessel1Id, string $vessel2Id, Carbon $windowStart, Carbon $windowEnd): ?array
    {
        $positionsA = VesselPosition::where('vessel_id', $vessel1Id)
            ->whereBetween('observed_at', [$windowStart, $windowEnd])
            ->orderBy('observed_at')
            ->get();

        $positionsB = VesselPosition::where('vessel_id', $vessel2Id)
            ->whereBetween('observed_at', [$windowStart, $windowEnd])
            ->orderBy('observed_at')
            ->get();

        if ($positionsA->isEmpty() || $positionsB->isEmpty()) {
            return null;
        }

        $closeMatches = [];

        foreach ($positionsA as $posA) {
            $timeA = $posA->observed_at;

            // Find closest position in B within timestamp tolerance
            $posB = $positionsB->first(function ($item) use ($timeA) {
                return abs($item->observed_at->diffInMinutes($timeA)) <= $this->timestampToleranceMinutes;
            });

            if (!$posB) {
                continue;
            }

            $distance = $this->haversineDistance(
                $posA->latitude,
                $posA->longitude,
                $posB->latitude,
                $posB->longitude
            );

            if ($distance <= $this->maxDistanceMeters) {
                $closeMatches[] = [
                    'distance' => $distance,
                    'time_a' => $posA->observed_at,
                    'time_b' => $posB->observed_at,
                    'lat' => ($posA->latitude + $posB->latitude) / 2,
                    'lng' => ($posA->longitude + $posB->longitude) / 2,
                ];
            }
        }

        if (count($closeMatches) < 2) {
            return null;
        }

        $firstMatch = $closeMatches[0];
        $lastMatch = $closeMatches[count($closeMatches) - 1];
        $durationMinutes = (int) abs(round($lastMatch['time_a']->diffInMinutes($firstMatch['time_a'])));

        if ($durationMinutes < $this->minDurationMinutes) {
            return null;
        }

        $distances = array_column($closeMatches, 'distance');
        $minDist = min($distances);
        sort($distances);
        $medianDist = $distances[(int) floor(count($distances) / 2)];

        return [
            'start_time' => $firstMatch['time_a'],
            'end_time' => $lastMatch['time_a'],
            'duration_minutes' => $durationMinutes,
            'min_distance_meters' => round($minDist, 2),
            'median_distance_meters' => round($medianDist, 2),
            'latitude' => round($firstMatch['lat'], 7),
            'longitude' => round($firstMatch['lng'], 7),
            'match_count' => count($closeMatches),
        ];
    }

    /**
     * Store detected encounter with metadata and relations
     */
    protected function storeEncounter(string $vessel1Id, string $vessel2Id, array $data): VesselEncounter
    {
        $v1 = Vessel::find($vessel1Id);
        $v2 = Vessel::find($vessel2Id);

        $category = $this->classifyEncounter($v1?->vessel_type, $v2?->vessel_type);
        $encounterCode = 'ENC-NAT-' . strtoupper(substr(md5($vessel1Id . $vessel2Id . $data['start_time']), 0, 8));

        return DB::transaction(function () use ($vessel1Id, $vessel2Id, $v1, $v2, $category, $encounterCode, $data) {
            $encounter = VesselEncounter::create([
                'encounter_code' => $encounterCode,
                'vessel_1_id' => $vessel1Id,
                'vessel_2_id' => $vessel2Id,
                'category' => $category,
                'status' => 'candidate',
                'start_time' => $data['start_time'],
                'end_time' => $data['end_time'],
                'duration_minutes' => $data['duration_minutes'],
                'min_distance_meters' => $data['min_distance_meters'],
                'median_distance_meters' => $data['median_distance_meters'],
                'latitude' => $data['latitude'],
                'longitude' => $data['longitude'],
                'detection_method' => 'native_ais',
                'algorithm_version' => $this->algorithmVersion,
                'parameters_used' => [
                    'max_distance_meters' => $this->maxDistanceMeters,
                    'min_duration_minutes' => $this->minDurationMinutes,
                    'timestamp_tolerance_minutes' => $this->timestampToleranceMinutes,
                    'match_count' => $data['match_count'],
                ],
                'analyst_notes' => 'Generated by native spatial-temporal AIS proximity algorithm. Review required before verification.',
            ]);

            EncounterVessel::create([
                'encounter_id' => $encounter->id,
                'vessel_id' => $vessel1Id,
                'role' => $v1?->vessel_type === 'carrier' ? 'carrier' : 'participant',
                'flag_state' => $v1?->flag_state,
                'vessel_type' => $v1?->vessel_type,
            ]);

            EncounterVessel::create([
                'encounter_id' => $encounter->id,
                'vessel_id' => $vessel2Id,
                'role' => $v2?->vessel_type === 'bunker' ? 'bunker' : 'participant',
                'flag_state' => $v2?->flag_state,
                'vessel_type' => $v2?->vessel_type,
            ]);

            if (DB::connection()->getDriverName() === 'pgsql') {
                try {
                    DB::statement(
                        "UPDATE vessel_encounters SET geom = ST_SetSRID(ST_MakePoint(?, ?), 4326) WHERE id = ?",
                        [$data['longitude'], $data['latitude'], $encounter->id]
                    );
                } catch (\Throwable $e) {
                    // Ignore if extension is absent
                }
            }

            return $encounter;
        });
    }

    protected function classifyEncounter(?string $type1, ?string $type2): string
    {
        $types = strtolower(($type1 ?? '') . ' ' . ($type2 ?? ''));
        if (str_contains($types, 'carrier') || str_contains($types, 'reefer')) {
            return 'fishing_carrier';
        }
        if (str_contains($types, 'bunker') || str_contains($types, 'tanker')) {
            return 'fishing_bunker';
        }
        if (str_contains($types, 'support') || str_contains($types, 'tug')) {
            return 'fishing_support';
        }
        if (str_contains($types, 'fishing')) {
            return 'fishing_fishing';
        }
        return 'other';
    }

    /**
     * Calculate Great-Circle distance in meters between two lat/long points
     */
    protected function haversineDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000; // Earth radius in meters

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
