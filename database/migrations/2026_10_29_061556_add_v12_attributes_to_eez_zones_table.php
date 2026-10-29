<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eez_zones', function (Blueprint $table) {
            $table->string('territory1', 120)->nullable()->after('sovereign_iso3');
            $table->string('iso_ter1', 10)->nullable()->after('territory1');
            $table->string('sovereign2', 120)->nullable()->after('iso_ter1');
            $table->string('sovereign3', 120)->nullable()->after('sovereign2');
            $table->decimal('x_1', 10, 5)->nullable()->after('area_sqkm');
            $table->decimal('y_1', 10, 5)->nullable()->after('x_1');
        });
    }

    public function down(): void
    {
        Schema::table('eez_zones', function (Blueprint $table) {
            $table->dropColumn(['territory1', 'iso_ter1', 'sovereign2', 'sovereign3', 'x_1', 'y_1']);
        });
    }
};
