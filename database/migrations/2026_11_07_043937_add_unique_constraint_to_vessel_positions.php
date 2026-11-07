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
        Schema::table('vessel_positions', function (Blueprint $table) {
            $table->unique(['vessel_id', 'observed_at', 'latitude', 'longitude'], 'vessel_pos_dedup_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vessel_positions', function (Blueprint $table) {
            $table->dropUnique('vessel_pos_dedup_unique');
        });
    }
};
