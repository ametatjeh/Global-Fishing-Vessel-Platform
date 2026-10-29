<?php

namespace App\Console\Commands;

use App\Models\DataProvider;
use App\Models\FisheriesManagementArea;
use App\Models\ProviderIngestionRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class ImportFisheriesManagementAreasCommand extends Command
{
    protected $signature = 'fma:import {--path= : Path to GeoJSON file} {--dry-run : Dry run without committing changes}';
    protected $description = 'Import official FAO Regional Fishery Bodies (RFBs / RFMOs) competence areas into PostGIS';

    public function handle(): int
    {
        $filePath = $this->option('path') ?? database_path('seeders/data/fao_fisheries_management_areas.geojson');
        $dryRun = (bool) $this->option('dry-run');

        $this->info("Importing FAO Fisheries Management Areas from: {$filePath}");

        if (!File::exists($filePath)) {
            $this->error("File not found: {$filePath}");
            return Command::FAILURE;
        }

        $json = json_decode(File::get($filePath), true);
        $features = $json['features'] ?? [];
        $total = count($features);
        $this->info("Total features in file: {$total}");

        if ($dryRun) {
            $this->warn("Dry-run complete. Found {$total} features.");
            return Command::SUCCESS;
        }

        $provider = DataProvider::firstOrCreate(
            ['slug' => 'fao-rfb-fma'],
            [
                'name' => 'FAO Regional Fishery Bodies (RFMO/RFB)',
                'type' => 'fao',
                'api_url' => 'https://www.fao.org/fishery/geoserver/fifao/ows',
                'auth_type' => 'none',
                'is_enabled' => true,
                'health_status' => 'connected',
                'license_type' => 'FAO Open Access Terms / CC-BY-NC-SA 3.0 IGO',
                'commercial_use_allowed' => false,
                'retention_allowed' => true,
                'redistribution_allowed' => true,
            ]
        );

        $run = ProviderIngestionRun::create([
            'data_provider_id' => $provider->id,
            'run_type' => 'sync_fma',
            'status' => 'running',
            'started_at' => now(),
            'metadata_json' => ['file' => $filePath],
        ]);

        $inserted = 0;
        foreach ($features as $feat) {
            $p = $feat['properties'] ?? [];
            $geom = $feat['geometry'] ?? null;
            if (!$geom) continue;

            $fma = FisheriesManagementArea::create([
                'rfb_code' => $p['rfb_code'] ?? 'UNKNOWN',
                'name' => $p['name'] ?? 'Regional Fishery Body',
                'mandate_type' => $p['mandate_type'] ?? 'RFMO',
                'ocean_basin' => $p['ocean_basin'] ?? null,
                'competence_summary' => $p['competence_summary'] ?? null,
                'established_year' => $p['established_year'] ?? null,
                'legal_instrument' => $p['legal_instrument'] ?? null,
                'version' => $p['version'] ?? '2024.1',
                'source_dataset_url' => $p['source_dataset_url'] ?? 'https://www.fao.org/fishery/geoserver/fifao/ows',
                'license_name' => $p['license_name'] ?? 'FAO Open Access Terms',
                'disclaimer' => $p['disclaimer'] ?? 'Area kompetensi RFMO/RFB FAO.',
                'import_date' => now(),
                'geometry_geojson' => json_encode($geom),
                'bbox' => $feat['bbox'] ?? null,
                'crosses_antimeridian' => false,
            ]);

            if (DB::connection()->getDriverName() === 'pgsql') {
                try {
                    DB::statement(
                        "UPDATE fisheries_management_areas 
                         SET geom = ST_Multi(ST_CollectionExtract(ST_MakeValid(ST_GeomFromGeoJSON(?)), 3)) 
                         WHERE id = ?",
                        [json_encode($geom), $fma->id]
                    );
                } catch (\Throwable $e) {
                    $this->warn("Failed geom for {$fma->rfb_code}: " . $e->getMessage());
                }
            }
            $inserted++;
        }

        // Post-process bbox & antimeridian
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("
                UPDATE fisheries_management_areas
                SET 
                    bbox = json_build_array(
                        ROUND(ST_XMin(geom)::numeric, 5),
                        ROUND(ST_YMin(geom)::numeric, 5),
                        ROUND(ST_XMax(geom)::numeric, 5),
                        ROUND(ST_YMax(geom)::numeric, 5)
                    ),
                    crosses_antimeridian = (ST_XMin(geom) < -170 AND ST_XMax(geom) > 170)
                WHERE geom IS NOT NULL AND NOT ST_IsEmpty(geom);
            ");
        }

        $run->update([
            'status' => 'completed',
            'items_fetched' => $total,
            'items_inserted' => $inserted,
            'completed_at' => now(),
        ]);

        $this->info("Successfully imported {$inserted} Fisheries Management Areas.");
        return Command::SUCCESS;
    }
}
