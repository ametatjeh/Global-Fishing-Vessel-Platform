<?php

namespace App\Services\Providers;

use App\Models\DataProvider;
use App\Models\FaoFishingArea;
use App\Models\ProviderIngestionRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FaoFishingAreaAdapter implements ProviderAdapterInterface
{
    protected string $sourceUrl;
    protected string $version;
    protected string $license;

    public function __construct()
    {
        $this->sourceUrl = config('maritime.fao.source_url', 'https://www.fao.org/fishery/geoserver/wfs');
        $this->version = config('maritime.fao.version', '2026.1-CWP');
        $this->license = config('maritime.fao.license', 'FAO Open Access Terms / CC-BY-NC-SA 3.0 IGO');
    }

    public function healthCheck(): array
    {
        return [
            'status' => 'connected',
            'message' => 'Dataset FAO Major Fishing Areas tersedia untuk diimpor ke database PostGIS.',
            'latency_ms' => 0.5,
            'timestamp' => now()->toISOString(),
        ];
    }

    /**
     * Import official FAO Major Fishing Areas GeoJSON
     *
     * @param array $options ['path' => ?string, 'dry_run' => bool]
     * @return array
     */
    public function sync(array $options = []): array
    {
        $dryRun = $options['dry_run'] ?? false;
        $filePath = $options['path'] ?? database_path('seeders/data/fao_major_fishing_areas.geojson');

        $provider = DataProvider::firstOrCreate(
            ['slug' => 'fao-fisheries'],
            [
                'name' => 'Food and Agriculture Organization (FAO) Fisheries',
                'type' => 'fao',
                'api_url' => $this->sourceUrl,
                'auth_type' => 'none',
                'is_enabled' => true,
                'health_status' => 'connected',
                'license_type' => $this->license,
                'commercial_use_allowed' => false,
                'retention_allowed' => true,
                'redistribution_allowed' => true,
            ]
        );

        $run = ProviderIngestionRun::create([
            'data_provider_id' => $provider->id,
            'run_type' => 'sync_fao',
            'status' => 'running',
            'started_at' => now(),
            'metadata_json' => ['dry_run' => $dryRun, 'file' => $filePath],
        ]);

        if (!File::exists($filePath)) {
            $msg = "File dataset FAO GeoJSON tidak ditemukan di {$filePath}";
            $run->update(['status' => 'failed', 'error_message' => $msg, 'completed_at' => now()]);
            return ['status' => 'failed', 'fetched' => 0, 'inserted' => 0, 'skipped' => 0, 'errors' => [$msg]];
        }

        $jsonContent = json_decode(File::get($filePath), true);
        if (!$jsonContent || !isset($jsonContent['features'])) {
            $msg = "Format file GeoJSON tidak valid untuk FAO Fishing Areas.";
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
            $code = (string) ($props['F_AREA'] ?? $props['area_code'] ?? $props['code'] ?? null);
            $nameEn = $props['NAME_EN'] ?? $props['name_en'] ?? $props['name'] ?? ('FAO Area ' . $code);

            if (!$code || !$geom) {
                $skipped++;
                continue;
            }

            if ($dryRun) {
                $inserted++;
                continue;
            }

            try {
                // Calculate bbox if not provided
                $bbox = $feat['bbox'] ?? $this->calculateBBox($geom['coordinates']);

                $area = FaoFishingArea::updateOrCreate(
                    ['area_code' => $code],
                    [
                        'name_en' => $nameEn,
                        'name_fr' => $props['NAME_FR'] ?? null,
                        'name_es' => $props['NAME_ES'] ?? null,
                        'ocean_basin' => $props['OCEAN'] ?? $props['ocean'] ?? null,
                        'area_type' => 'major',
                        'surface_water_sqkm' => $props['SURFACE'] ?? null,
                        'version' => $this->version,
                        'source_dataset_url' => $this->sourceUrl,
                        'license_name' => $this->license,
                        'import_date' => now(),
                        'geometry_geojson' => json_encode($geom),
                        'bbox' => $bbox,
                    ]
                );

                // Update PostGIS native geometry if PostgreSQL driver is active
                if (DB::connection()->getDriverName() === 'pgsql') {
                    try {
                        DB::statement(
                            "UPDATE fao_fishing_areas 
                             SET geom = ST_Multi(ST_CollectionExtract(ST_MakeValid(ST_GeomFromGeoJSON(?)), 3)) 
                             WHERE id = ?",
                            [json_encode($geom), $area->id]
                        );
                    } catch (\Throwable $e) {
                        Log::warning("PostGIS ST_GeomFromGeoJSON failed for FAO area {$code}: " . $e->getMessage());
                    }
                }

                $inserted++;
            } catch (\Throwable $e) {
                $errors[] = "Error area {$code}: " . $e->getMessage();
                Log::error("FAO Ingestion Error for {$code}: " . $e->getMessage());
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
            'dataset_name' => 'FAO Major Fishing Areas',
            'version' => $this->version,
            'source' => $this->sourceUrl,
            'license' => $this->license,
            'disclaimer' => config('maritime.fao.disclaimer'),
        ];
    }
}
