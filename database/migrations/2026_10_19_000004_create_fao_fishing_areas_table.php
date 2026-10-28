<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        Schema::create('fao_fishing_areas', function (Blueprint $table) {
            $table->id();
            $table->string('area_code', 20)->unique()->index(); // e.g. '57', '71', '27'
            $table->string('name_en')->index();
            $table->string('name_fr')->nullable();
            $table->string('name_es')->nullable();
            $table->string('ocean_basin')->nullable(); // Atlantic, Indian Ocean, Pacific, etc.
            $table->string('area_type')->default('major')->index(); // major, subarea, division, subunit
            $table->decimal('surface_water_sqkm', 14, 2)->nullable();
            $table->string('version')->default('2024.1');
            $table->string('source_dataset_url')->default('https://www.fao.org/fishery/docs/maps/Default.htm');
            $table->string('license_name')->default('FAO Open Access Terms / CC-BY-NC-SA 3.0 IGO');
            $table->timestamp('import_date')->nullable();
            $table->longText('geometry_geojson'); // MultiPolygon/Polygon GeoJSON
            $table->json('bbox')->nullable(); // [min_lng, min_lat, max_lng, max_lat]
            $table->timestamps();
        });

        if ($driver === 'pgsql') {
            try {
                DB::statement('ALTER TABLE fao_fishing_areas ADD COLUMN geom geometry(MultiPolygon, 4326);');
                DB::statement('CREATE INDEX IF NOT EXISTS idx_fao_fishing_areas_geom ON fao_fishing_areas USING GIST (geom);');
            } catch (\Throwable $e) {
                // Ignore if postgis not enabled
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fao_fishing_areas');
    }
};
