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

        Schema::create('eez_zones', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('mrgid')->unique()->index(); // Marine Regions Geographic Identifier
            $table->string('geoname')->index(); // e.g. 'Indonesian Exclusive Economic Zone'
            $table->string('sovereign_state')->index();
            $table->string('sovereign_iso3', 3)->nullable()->index();
            $table->string('pol_type')->default('200NM'); // 200NM, Joint regime, Overlapping claim, Archipelagic
            $table->decimal('area_sqkm', 14, 2)->nullable();
            $table->string('version')->default('v12');
            $table->string('source_name')->default('Flanders Marine Institute (VLIZ) Marine Regions');
            $table->string('source_url')->default('https://www.marineregions.org/downloads.php');
            $table->string('license_name')->default('Creative Commons Attribution 4.0 International (CC BY 4.0)');
            $table->string('license_url')->default('https://creativecommons.org/licenses/by/4.0/');
            $table->string('attribution_notice')->default('Maritime Boundaries Geodatabase: Maritime Boundaries and Exclusive Economic Zone (200NM), version 12. Available online at https://www.marineregions.org/.');
            $table->timestamp('import_date')->nullable();
            $table->longText('geometry_geojson'); // MultiPolygon/Polygon
            $table->json('bbox')->nullable(); // [min_lng, min_lat, max_lng, max_lat]
            $table->boolean('crosses_antimeridian')->default(false);
            $table->timestamps();
        });

        if ($driver === 'pgsql') {
            try {
                DB::statement('ALTER TABLE eez_zones ADD COLUMN geom geometry(MultiPolygon, 4326);');
                DB::statement('CREATE INDEX IF NOT EXISTS idx_eez_zones_geom ON eez_zones USING GIST (geom);');
            } catch (\Throwable $e) {
                // Ignore if postgis not enabled
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('eez_zones');
    }
};
