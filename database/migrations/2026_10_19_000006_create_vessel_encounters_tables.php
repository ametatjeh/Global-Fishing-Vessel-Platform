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

        Schema::create('vessel_encounters', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('encounter_code')->unique()->index();
            $table->foreignUuid('vessel_1_id')->constrained('vessels')->cascadeOnDelete();
            $table->foreignUuid('vessel_2_id')->constrained('vessels')->cascadeOnDelete();
            $table->string('category')->default('fishing_fishing')->index(); // fishing_carrier, fishing_support, fishing_bunker, fishing_fishing, other
            $table->string('status')->default('candidate')->index(); // candidate, needs_review, verified, rejected
            $table->timestamp('start_time')->index();
            $table->timestamp('end_time')->index();
            $table->integer('duration_minutes')->default(0)->index();
            $table->decimal('min_distance_meters', 10, 2)->index();
            $table->decimal('median_distance_meters', 10, 2)->nullable();
            $table->decimal('latitude', 10, 7)->index();
            $table->decimal('longitude', 10, 7)->index();
            $table->string('detection_method')->default('native_ais')->index(); // native_ais, gfw_events, external_api
            $table->string('algorithm_version')->default('v1.0.0');
            $table->json('parameters_used'); // max_distance_meters, min_duration_minutes, timestamp_tolerance_minutes
            $table->foreignId('source_provider_id')->nullable()->constrained('data_providers')->nullOnDelete();
            $table->string('external_event_id')->nullable()->index();
            $table->text('analyst_notes')->nullable();
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->string('disclaimer')->default('Encounter is an observational proximity detection and does not constitute conclusive evidence of transshipment, illegal activity, or maritime violation.');
            $table->timestamps();

            $table->index(['vessel_1_id', 'vessel_2_id', 'start_time']);
        });

        Schema::create('encounter_vessels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('encounter_id')->constrained('vessel_encounters')->cascadeOnDelete();
            $table->foreignUuid('vessel_id')->constrained('vessels')->cascadeOnDelete();
            $table->string('role')->default('participant'); // primary, secondary, carrier, bunker, donor, receiver
            $table->string('flag_state', 3)->nullable();
            $table->string('vessel_type')->nullable();
            $table->timestamps();
        });

        if ($driver === 'pgsql') {
            try {
                DB::statement('ALTER TABLE vessel_encounters ADD COLUMN geom geometry(Point, 4326);');
                DB::statement('CREATE INDEX IF NOT EXISTS idx_vessel_encounters_geom ON vessel_encounters USING GIST (geom);');
            } catch (\Throwable $e) {
                // Ignore if postgis not enabled
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('encounter_vessels');
        Schema::dropIfExists('vessel_encounters');
    }
};
