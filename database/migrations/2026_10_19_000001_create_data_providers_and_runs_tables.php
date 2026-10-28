<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_providers', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('type'); // ais_stream, gfw_events, copernicus_sar, marine_regions, fao
            $table->string('api_url')->nullable();
            $table->string('auth_type')->default('bearer_token'); // bearer_token, basic, oauth2, none
            $table->boolean('is_enabled')->default(false);
            $table->string('health_status')->default('not_configured'); // connected, not_configured, delayed, error
            $table->timestamp('last_health_check_at')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->text('last_error_message')->nullable();
            $table->json('rate_limit_info')->nullable();
            $table->string('license_type')->nullable(); // CC-BY-4.0, Non-Commercial, Commercial, Public Domain
            $table->string('license_url')->nullable();
            $table->boolean('commercial_use_allowed')->default(false);
            $table->boolean('retention_allowed')->default(true);
            $table->boolean('redistribution_allowed')->default(false);
            $table->json('config_meta')->nullable();
            $table->timestamps();
        });

        Schema::create('provider_ingestion_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('data_provider_id')->constrained('data_providers')->cascadeOnDelete();
            $table->string('run_type'); // health_check, sync_fao, sync_eez, sync_ais, sync_encounters, sync_sar
            $table->string('status')->default('pending'); // pending, running, completed, failed
            $table->integer('items_fetched')->default(0);
            $table->integer('items_inserted')->default(0);
            $table->integer('items_skipped')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_ingestion_runs');
        Schema::dropIfExists('data_providers');
    }
};
