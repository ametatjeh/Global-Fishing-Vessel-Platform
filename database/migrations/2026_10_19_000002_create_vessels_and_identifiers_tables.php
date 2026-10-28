<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vessels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name')->index();
            $table->string('flag_state', 3)->nullable()->index(); // ISO-3 or Flag code
            $table->string('vessel_type')->default('unknown')->index(); // fishing, carrier, bunker, support, cargo, tanker, passenger, patrol
            $table->decimal('length_meters', 8, 2)->nullable();
            $table->decimal('tonnage', 10, 2)->nullable();
            $table->string('callsign')->nullable()->index();
            $table->string('imo', 20)->nullable()->index();
            $table->string('mmsi', 20)->nullable()->index();
            $table->string('hull_code')->nullable()->index();
            $table->string('operator')->nullable();
            $table->string('owner')->nullable();
            $table->foreignId('source_provider_id')->nullable()->constrained('data_providers')->nullOnDelete();
            $table->string('verification_status')->default('unverified')->index(); // unverified, provider_reported, verified, disputed
            $table->timestamp('first_seen_at')->nullable()->index();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('vessel_identifiers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('vessel_id')->constrained('vessels')->cascadeOnDelete();
            $table->string('identifier_type')->index(); // mmsi, imo, callsign, hull_code, gfw_id, uvi
            $table->string('identifier_value')->index();
            $table->foreignId('source_provider_id')->nullable()->constrained('data_providers')->nullOnDelete();
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_to')->nullable();
            $table->boolean('is_current')->default(true)->index();
            $table->json('metadata_json')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vessel_identifiers');
        Schema::dropIfExists('vessels');
    }
};
