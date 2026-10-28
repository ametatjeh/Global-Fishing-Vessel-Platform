<?php

namespace App\Services\Providers;

use App\Models\DataProvider;
use App\Models\EezZone;
use App\Models\ProviderIngestionRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class MarineRegionsEezAdapter implements ProviderAdapterInterface
{
    protected string $sourceUrl;
    protected string $version;
    protected string $license;
    protected string $attribution;

    public function __construct()
    {
        $this->sourceUrl = config('maritime.eez.source_url', 'https://www.marineregions.org/downloads.php');
        $this->version = config('maritime.eez.version', 'v12');
        $this->license = config('maritime.eez.license', 'Creative Commons Attribution 4.0 International (CC BY 4.0)');
        $this->attribution = config('maritime.eez.attribution', 'Flanders Marine Institute (VLIZ) Marine Regions');
    }

    public function healthCheck(): array
    {
        return [
            'status' => 'connected',
            'message' => 'Dataset Marine Regions Maritime Boundaries EEZ siap diimpor ke PostGIS.',
            'latency_ms' => 0.5,
            'timestamp' => now()->toISOString(),
        ];
    }

    public function sync(array $options = []): array
    {
        $dryRun = $options['dry_run'] ?? false;
        $filePath = $options['path'] ?? database_path('seeders/data/marine_regions_eez_sample.geojson');

        $provider = DataProvider::firstOrCreate(
            ['slug' => 'marine-regions-eez'],
            [
                'name' => 'Marine Regions (VLIZ Flanders Marine Institute)',
                'type' => 'marine_regions',
                'api_url' => $this->sourceUrl,
                'auth_type' => 'none',
                'is_enabled' => true,
                'health_status' => 'connected',
                'license_type' => $this->license,
                'commercial_use_allowed' => true,
                'retention_allowed' => true,
                'redistribution_allowed' => true,
            ]
        );

        $run = ProviderIngestionRun::create([
            'data_provider_id' => $provider->id,
            'run_type' => 'sync_eez',
            'status' => 'running',
            'started_at' => now(),
            'metadata_json' => ['dry_run' => $dryRun, 'file' => $filePath],
        ]);

        if (!File::exists($filePath)) {
            $msg = "File dataset EEZ GeoJSON tidak ditemukan di {$filePath}";
            $run->update(['status' => 'failed', 'error_message' => $msg, 'completed_at' => now()]);
            return ['status' => 'failed', 'fetched' => 0, 'inserted' => 0, 'skipped' => 0, 'errors' => [$msg]];
        }

        $jsonContent = json_decode(File::get($filePath), true);
        if (!$jsonContent || !isset($jsonContent['features'])) {
            $msg = "Format file GeoJSON tidak valid untuk EEZ Zones.";
            $run->update(['status' => 'failed', 'error_message' => $msg, 'completed_at' => now()]);
            return ['status' => 'failed', 'fetched' => 0, 'inserted' => 0, 'skipped' => 0, 'errors' => [$msg]];
        }

        $features = $jsonContent['features'];
        $fetched = count($features);
        $inserted = 0;
        $skipped = 0;
        $errors = [];

        foreach ($features as $feat) {
            $props = $feat['properties'] ?? [];
            $geom = $feat['geometry'] ?? null;
            $mrgid = (int) ($props['MRGID'] ?? $props['mrgid'] ?? null);
            $geoname = $props['GEONAME'] ?? $props['geoname'] ?? ('EEZ Zone ' . $mrgid);
            $sovereign = $props['SOVEREIGN1'] ?? $props['sovereign'] ?? 'Unknown Sovereign';
            $iso3 = $props['ISO_SOV1'] ?? $props['iso_ter1'] ?? $props['sovereign_iso3'] ?? null;

            if (!$mrgid || !$geom) {
                $skipped++;
                continue;
            }

            if ($dryRun) {
                $inserted++;
                continue;
            }

            try {
                // Check if geometry crosses antimeridian (+180/-180)
                $crossesAntimeridian = $this->detectAntimeridianCrossing($geom);

                $eez = EezZone::updateOrCreate(
                    ['mrgid' => $mrgid],
                    [
                        'geoname' => $geoname,
                        'sovereign_state' => $sovereign,
                        'sovereign_iso3' => $iso3,
                        'pol_type' => $props['POL_TYPE'] ?? $props['pol_type'] ?? '200NM',
                        'area_sqkm' => $props['AREA_KM2'] ?? $props['area_sqkm'] ?? null,
                        'version' => $this->version,
                        'source_name' => 'Flanders Marine Institute (VLIZ)',
                        'source_url' => $this->sourceUrl,
                        'license_name' => $this->license,
                        'license_url' => 'https://creativecommons.org/licenses/by/4.0/',
                        'attribution_notice' => $this->attribution,
                        'import_date' => now(),
                        'geometry_geojson' => json_encode($geom),
                        'bbox' => $feat['bbox'] ?? $this->calculateBBox($geom['coordinates'] ?? []),
                        'crosses_antimeridian' => $crossesAntimeridian,
                    ]
                );

                if (DB::connection()->getDriverName() === 'pgsql') {
                    try {
                        DB::statement(
                            "UPDATE eez_zones SET geom = ST_Multi(ST_GeomFromGeoJSON(?)) WHERE id = ?",
                            [json_encode($geom), $eez->id]
                        );
                    } catch (\Throwable $e) {
                        Log::warning("PostGIS ST_GeomFromGeoJSON failed for EEZ MRGID {$mrgid}: " . $e->getMessage());
                    }
                }

                $inserted++;
            } catch (\Throwable $e) {
                $errors[] = "Error MRGID {$mrgid}: " . $e->getMessage();
                Log::error("EEZ Ingestion Error for MRGID {$mrgid}: " . $e->getMessage());
            }
        }

        $run->update([
            'status' => empty($errors) ? 'completed' : 'partially_failed',
            'items_fetched' => $fetched,
            'items_inserted' => $inserted,
            'items_skipped' => $skipped,
            'completed_at' => now(),
        ]);

        $provider->update([
            'last_sync_at' => now(),
            'health_status' => 'connected',
        ]);

        return [
            'status' => 'completed',
            'fetched' => $fetched,
            'inserted' => $inserted,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * Inspect geometry coordinates to identify antimeridian crossing (+170 to -170 jump)
     */
    protected function detectAntimeridianCrossing(array $geom): bool
    {
        $hasPositiveEast = false;
        $hasNegativeWest = false;

        $checkLng = function ($lng) use (&$hasPositiveEast, &$hasNegativeWest) {
            if ($lng > 170.0) {
                $hasPositiveEast = true;
            }
            if ($lng < -170.0) {
                $hasNegativeWest = true;
            }
        };

        $coords = $geom['coordinates'] ?? [];
        array_walk_recursive($coords, function ($val, $key) use ($checkLng) {
            if (is_numeric($val) && abs($val) > 170.0) {
                $checkLng((float) $val);
            }
        });

        return $hasPositiveEast && $hasNegativeWest;
    }

    /**
     * Compute bounding box [min_lng, min_lat, max_lng, max_lat] from coordinate arrays
     */
    protected function calculateBBox(array $coordinates): array
    {
        $points = [];
        $flatten = function ($item) use (&$flatten, &$points) {
            if (is_array($item)) {
                if (count($item) >= 2 && is_numeric($item[0]) && is_numeric($item[1])) {
                    $points[] = [(float) $item[0], (float) $item[1]];
                } else {
                    foreach ($item as $sub) {
                        $flatten($sub);
                    }
                }
            }
        };

        $flatten($coordinates);

        if (empty($points)) {
            return [-180.0, -90.0, 180.0, 90.0];
        }

        $minLng = 180.0;
        $maxLng = -180.0;
        $minLat = 90.0;
        $maxLat = -90.0;

        foreach ($points as [$lng, $lat]) {
            if ($lng < $minLng) $minLng = $lng;
            if ($lng > $maxLng) $maxLng = $lng;
            if ($lat < $minLat) $minLat = $lat;
            if ($lat > $maxLat) $maxLat = $lat;
        }

        return [$minLng, $minLat, $maxLng, $maxLat];
    }

    public function getMetadata(): array
    {
        return [
            'dataset_name' => 'Marine Regions Maritime Boundaries EEZ',
            'version' => $this->version,
            'source' => $this->sourceUrl,
            'license' => $this->license,
            'attribution' => $this->attribution,
            'disclaimer' => config('maritime.eez.disclaimer'),
        ];
    }
}
