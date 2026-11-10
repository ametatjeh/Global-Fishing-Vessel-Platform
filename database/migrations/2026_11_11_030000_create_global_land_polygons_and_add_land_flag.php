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

        // 1. Create global_land_polygons table
        if (!Schema::hasTable('global_land_polygons')) {
            Schema::create('global_land_polygons', function (Blueprint $table) {
                $table->id();
                $table->string('feature_name')->nullable()->index();
                $table->string('source')->default('natural_earth')->index();
                $table->string('scale', 10)->default('50m')->index();
                $table->decimal('min_lat', 10, 7)->nullable();
                $table->decimal('max_lat', 10, 7)->nullable();
                $table->decimal('min_lng', 10, 7)->nullable();
                $table->decimal('max_lng', 10, 7)->nullable();
                $table->longText('geometry_geojson')->nullable();
                $table->timestamps();

                $table->index(['min_lng', 'min_lat', 'max_lng', 'max_lat'], 'idx_land_polygons_bbox');
            });

            if ($driver === 'pgsql') {
                try {
                    DB::statement('ALTER TABLE global_land_polygons ADD COLUMN geom geometry(MultiPolygon, 4326);');
                    DB::statement('CREATE INDEX IF NOT EXISTS idx_global_land_polygons_geom ON global_land_polygons USING GIST (geom);');
                } catch (\Throwable $e) {
                    // PostGIS might be configured differently or already present
                }
            }
        }

        // 2. Add is_on_land flag to vessel_positions
        if (Schema::hasTable('vessel_positions') && !Schema::hasColumn('vessel_positions', 'is_on_land')) {
            Schema::table('vessel_positions', function (Blueprint $table) {
                $table->boolean('is_on_land')->default(false)->index()->after('is_gap_indicator');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('vessel_positions') && Schema::hasColumn('vessel_positions', 'is_on_land')) {
            Schema::table('vessel_positions', function (Blueprint $table) {
                $table->dropColumn('is_on_land');
            });
        }

        Schema::dropIfExists('global_land_polygons');
    }
};

