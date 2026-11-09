<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Additive columns on vessels table
        Schema::table('vessels', function (Blueprint $table) {
            if (!Schema::hasColumn('vessels', 'draught')) {
                $table->decimal('draught', 5, 2)->nullable()->after('beam_meters');
            }
            if (!Schema::hasColumn('vessels', 'deadweight_tonnage')) {
                $table->decimal('deadweight_tonnage', 10, 2)->nullable()->after('tonnage');
            }
            if (!Schema::hasColumn('vessels', 'ais_class')) {
                $table->string('ais_class', 20)->nullable()->after('vessel_type');
            }
            if (!Schema::hasColumn('vessels', 'ship_builder')) {
                $table->string('ship_builder', 255)->nullable()->after('build_year');
            }
            if (!Schema::hasColumn('vessels', 'home_port')) {
                $table->string('home_port', 255)->nullable()->after('operator');
            }
            if (!Schema::hasColumn('vessels', 'registry_port')) {
                $table->string('registry_port', 255)->nullable()->after('home_port');
            }
            if (!Schema::hasColumn('vessels', 'vessel_category')) {
                $table->string('vessel_category', 50)->nullable()->after('vessel_type');
            }
        });

        // 2. Multi-source observations table
        if (!Schema::hasTable('vessel_attribute_observations')) {
            Schema::create('vessel_attribute_observations', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('vessel_id');
                $table->string('source_name', 50); // e.g. aisstream, gfw, fao, flag_registry, internal
                $table->string('external_record_id', 255)->nullable();
                $table->string('attribute_name', 50); // e.g. name, mmsi, imo, callsign, vessel_type, etc.
                $table->text('attribute_value')->nullable();
                $table->decimal('confidence_score', 4, 2)->nullable(); // e.g. 0.95
                $table->boolean('is_conflicting')->default(false);
                $table->text('conflict_reason')->nullable();
                $table->json('raw_payload')->nullable();
                $table->timestamp('retrieved_at')->useCurrent();
                $table->timestamps();

                $table->foreign('vessel_id')->references('id')->on('vessels')->onDelete('cascade');
                $table->index(['vessel_id', 'attribute_name']);
                $table->index(['vessel_id', 'source_name']);
                $table->index('source_name');
                $table->index('is_conflicting');
            });
        }

        // 3. Enrichment jobs audit and background execution table
        if (!Schema::hasTable('enrichment_jobs')) {
            Schema::create('enrichment_jobs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('vessel_id');
                $table->string('status', 30)->default('pending'); // pending, processing, completed, failed, cancelled
                $table->json('sources_attempted')->nullable();
                $table->integer('matched_attributes_count')->default(0);
                $table->integer('conflicts_count')->default(0);
                $table->text('error_message')->nullable();
                $table->timestamp('scheduled_at')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->foreign('vessel_id')->references('id')->on('vessels')->onDelete('cascade');
                $table->index('vessel_id');
                $table->index('status');
                $table->index('created_at');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('enrichment_jobs');
        Schema::dropIfExists('vessel_attribute_observations');

        Schema::table('vessels', function (Blueprint $table) {
            $cols = ['draught', 'deadweight_tonnage', 'ais_class', 'ship_builder', 'home_port', 'registry_port', 'vessel_category'];
            foreach ($cols as $col) {
                if (Schema::hasColumn('vessels', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};

