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

        if ($driver === 'pgsql') {
            try {
                DB::statement('CREATE EXTENSION IF NOT EXISTS postgis;');
            } catch (\Throwable $e) {
                // PostGIS might be restricted or already present
            }
        }

        Schema::create('vessel_positions', function (Blueprint $table) use ($driver) {
            $table->uuid('id')->primary();
            $table->foreignUuid('vessel_id')->constrained('vessels')->cascadeOnDelete();
            $table->decimal('latitude', 10, 7)->index();
            $table->decimal('longitude', 10, 7)->index();
            $table->decimal('speed_knots', 6, 2)->nullable();
            $table->decimal('course_degrees', 6, 2)->nullable();
            $table->decimal('heading_degrees', 6, 2)->nullable();
            $table->string('nav_status')->nullable(); // under way using engine, at anchor, fishing, restricted manoeuvrability
            $table->timestamp('observed_at')->index();
            $table->timestamp('received_at')->index();
            $table->foreignId('source_provider_id')->nullable()->constrained('data_providers')->nullOnDelete();
            $table->decimal('data_quality_score', 4, 2)->default(1.0);
            $table->boolean('is_gap_indicator')->default(false)->index();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->index(['vessel_id', 'observed_at']);
        });

        Schema::create('vessel_tracks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('vessel_id')->constrained('vessels')->cascadeOnDelete();
            $table->timestamp('start_time')->index();
            $table->timestamp('end_time')->index();
            $table->integer('point_count')->default(0);
            $table->json('track_geojson'); // GeoJSON LineString
            $table->timestamps();
        });

        if ($driver === 'pgsql') {
            try {
                DB::statement('ALTER TABLE vessel_positions ADD COLUMN geom geometry(Point, 4326);');
                DB::statement('CREATE INDEX IF NOT EXISTS idx_vessel_positions_geom ON vessel_positions USING GIST (geom);');
            } catch (\Throwable $e) {
                // Ignore if postgis extension is not loaded in this session
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vessel_tracks');
        Schema::dropIfExists('vessel_positions');
    }
};
