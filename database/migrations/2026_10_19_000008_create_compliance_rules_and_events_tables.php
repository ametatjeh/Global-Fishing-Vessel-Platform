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

        Schema::create('maritime_zones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('zone_code')->unique()->index();
            $table->string('zone_name')->index();
            $table->string('zone_type')->index(); // mpa, closed_area, seasonal_closure, territorial_sea, eez, high_seas_pocket
            $table->string('jurisdiction')->index(); // International, Regional RFMO, Sovereign State
            $table->string('governing_authority')->nullable();
            $table->text('legal_basis')->nullable();
            $table->longText('boundary_geojson'); // Polygon/MultiPolygon
            $table->json('restrictions')->nullable(); // no_fishing, gear_prohibitions, license_required
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('vessel_authorizations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('vessel_id')->constrained('vessels')->cascadeOnDelete();
            $table->string('authorization_code')->unique()->index();
            $table->string('permit_type')->index(); // commercial_fishing, carrier_reefer, bunker_supply, scientific
            $table->string('issuing_authority')->index();
            $table->string('target_species')->nullable();
            $table->string('authorized_gear')->nullable();
            $table->timestamp('valid_from')->index();
            $table->timestamp('valid_to')->index();
            $table->string('status')->default('active')->index(); // active, expired, suspended, revoked
            $table->foreignUuid('maritime_zone_id')->nullable()->constrained('maritime_zones')->nullOnDelete();
            $table->json('conditions')->nullable();
            $table->timestamps();
        });

        Schema::create('compliance_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('rule_code')->unique()->index();
            $table->string('rule_name')->index();
            $table->string('jurisdiction')->index();
            $table->string('category')->index(); // license_validity, spatial_entry, seasonal_closure, gear_restriction, dark_vessel_gap, encounter_transshipment
            $table->string('severity')->default('medium')->index(); // low, medium, high, critical
            $table->json('rule_logic'); // criteria expressions & thresholds
            $table->string('legal_source')->nullable();
            $table->string('version')->default('1.0');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('compliance_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('event_code')->unique()->index();
            $table->foreignUuid('compliance_rule_id')->constrained('compliance_rules')->cascadeOnDelete();
            $table->foreignUuid('vessel_id')->constrained('vessels')->cascadeOnDelete();
            $table->timestamp('event_time')->index();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('severity')->index(); // low, medium, high, critical
            $table->string('status')->default('open')->index(); // open, under_review, resolved, dismissed
            $table->text('evidence_summary');
            $table->json('supporting_data');
            $table->foreignUuid('related_encounter_id')->nullable();
            $table->foreignUuid('related_detection_id')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();
        });

        if ($driver === 'pgsql') {
            try {
                DB::statement('ALTER TABLE maritime_zones ADD COLUMN geom geometry(MultiPolygon, 4326);');
                DB::statement('CREATE INDEX IF NOT EXISTS idx_maritime_zones_geom ON maritime_zones USING GIST (geom);');
            } catch (\Throwable $e) {
                // Ignore if postgis not enabled
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_events');
        Schema::dropIfExists('compliance_rules');
        Schema::dropIfExists('vessel_authorizations');
        Schema::dropIfExists('maritime_zones');
    }
};
