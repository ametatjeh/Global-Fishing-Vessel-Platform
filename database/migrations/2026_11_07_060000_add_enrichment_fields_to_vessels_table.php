<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Additive and reversible enrichment fields for vessels.
     */
    public function up(): void
    {
        Schema::table('vessels', function (Blueprint $table) {
            if (!Schema::hasColumn('vessels', 'beam_meters')) {
                $table->decimal('beam_meters', 8, 2)->nullable()->after('length_meters');
            }
            if (!Schema::hasColumn('vessels', 'engine_power_kw')) {
                $table->decimal('engine_power_kw', 10, 2)->nullable()->after('tonnage');
            }
            if (!Schema::hasColumn('vessels', 'build_year')) {
                $table->unsignedSmallInteger('build_year')->nullable()->after('engine_power_kw');
            }
            if (!Schema::hasColumn('vessels', 'gear_type')) {
                $table->string('gear_type')->nullable()->after('vessel_type');
            }
            if (!Schema::hasColumn('vessels', 'gfw_vessel_id')) {
                $table->string('gfw_vessel_id')->nullable()->index()->after('source_provider_id');
            }
            if (!Schema::hasColumn('vessels', 'enrichment_status')) {
                $table->string('enrichment_status')->default('not_enriched')->index()->after('verification_status');
            }
            if (!Schema::hasColumn('vessels', 'last_enriched_at')) {
                $table->timestamp('last_enriched_at')->nullable()->index()->after('last_seen_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vessels', function (Blueprint $table) {
            $cols = [
                'beam_meters',
                'engine_power_kw',
                'build_year',
                'gear_type',
                'gfw_vessel_id',
                'enrichment_status',
                'last_enriched_at',
            ];
            foreach ($cols as $col) {
                if (Schema::hasColumn('vessels', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};

