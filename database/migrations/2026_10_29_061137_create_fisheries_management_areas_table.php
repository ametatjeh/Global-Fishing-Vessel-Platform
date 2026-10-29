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

        Schema::create('fisheries_management_areas', function (Blueprint $table) {
            $table->id();
            $table->string('rfb_code', 30)->index(); // e.g. 'WCPFC', 'IOTC', 'ICCAT'
            $table->string('name', 255)->index(); // e.g. 'Western and Central Pacific Fisheries Commission'
            $table->string('mandate_type', 80)->default('RFMO'); // RFMO (Regulatory), RFB (Advisory)
            $table->string('ocean_basin', 80)->nullable()->index(); // Pacific, Indian, Atlantic, Global
            $table->text('competence_summary')->nullable(); // Target species, geographical scope
            $table->string('established_year', 10)->nullable();
            $table->string('legal_instrument', 255)->nullable(); // Convention/Agreement title
            $table->string('version')->default('2024.1');
            $table->string('source_dataset_url')->default('https://www.fao.org/fishery/geoserver/fifao/ows');
            $table->string('license_name')->default('FAO Open Access Terms / CC-BY-NC-SA 3.0 IGO');
            $table->text('disclaimer')->nullable();
            $table->timestamp('import_date')->nullable();
            $table->longText('geometry_geojson');
            $table->json('bbox')->nullable();
            $table->boolean('crosses_antimeridian')->default(false);
            $table->timestamps();
        });

        if ($driver === 'pgsql') {
            try {
                DB::statement('ALTER TABLE fisheries_management_areas ADD COLUMN geom geometry(MultiPolygon, 4326);');
                DB::statement('CREATE INDEX IF NOT EXISTS idx_fma_geom ON fisheries_management_areas USING GIST (geom);');
            } catch (\Throwable $e) {
                // Ignore if postgis not enabled
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fisheries_management_areas');
    }
};
