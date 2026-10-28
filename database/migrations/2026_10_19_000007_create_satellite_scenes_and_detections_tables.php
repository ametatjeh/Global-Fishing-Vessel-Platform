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

        Schema::create('satellite_scenes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('scene_id')->unique()->index(); // e.g. Sentinel-1 Product ID
            $table->string('platform_name')->default('Sentinel-1'); // Sentinel-1A, Sentinel-1B, RADARSAT, etc.
            $table->string('instrument')->default('SAR-C');
            $table->string('sensor_mode')->default('IW'); // IW, EW, SM, WV
            $table->string('polarization')->default('VV+VH');
            $table->string('orbit_direction')->nullable(); // ASCENDING, DESCENDING
            $table->timestamp('acquisition_start')->index();
            $table->timestamp('acquisition_end')->index();
            $table->longText('footprint_geojson'); // Polygon GeoJSON
            $table->decimal('cloud_cover_percent', 5, 2)->nullable();
            $table->string('provider_url')->nullable();
            $table->string('processing_status')->default('cataloged'); // cataloged, processing, analyzed, failed
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('satellite_detections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('satellite_scene_id')->constrained('satellite_scenes')->cascadeOnDelete();
            $table->string('detection_identifier')->unique()->index();
            $table->decimal('latitude', 10, 7)->index();
            $table->decimal('longitude', 10, 7)->index();
            $table->timestamp('detected_at')->index();
            $table->timestamp('received_at')->nullable();
            $table->decimal('estimated_length_meters', 8, 2)->nullable();
            $table->decimal('estimated_width_meters', 8, 2)->nullable();
            $table->decimal('estimated_heading', 6, 2)->nullable();
            $table->decimal('confidence_score', 4, 2)->default(0.80); // 0.00 - 1.00
            $table->foreignUuid('correlated_vessel_id')->nullable()->constrained('vessels')->nullOnDelete();
            $table->string('correlation_method')->nullable(); // nearest_neighbor, kalman_filter, dead_reckoning, manual_analyst
            $table->string('correlation_status')->default('candidate')->index(); // candidate, matched, dark_vessel_candidate, false_positive
            $table->integer('ais_gap_duration_minutes')->nullable();
            $table->decimal('distance_to_nearest_ais_meters', 10, 2)->nullable();
            $table->text('analyst_notes')->nullable();
            $table->string('disclaimer')->default('AIS gap and SAR detection indicate potential non-broadcasting vessel, but does not definitively prove intentional AIS disabling or illegal activity without multi-source verification.');
            $table->timestamps();
        });

        if ($driver === 'pgsql') {
            try {
                DB::statement('ALTER TABLE satellite_detections ADD COLUMN geom geometry(Point, 4326);');
                DB::statement('CREATE INDEX IF NOT EXISTS idx_satellite_detections_geom ON satellite_detections USING GIST (geom);');
            } catch (\Throwable $e) {
                // Ignore if postgis not enabled
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('satellite_detections');
        Schema::dropIfExists('satellite_scenes');
    }
};
