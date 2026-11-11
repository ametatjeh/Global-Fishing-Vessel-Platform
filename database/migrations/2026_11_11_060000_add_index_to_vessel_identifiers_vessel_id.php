<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vessel_identifiers', function (Blueprint $table) {
            $table->index('vessel_id', 'vessel_identifiers_vessel_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('vessel_identifiers', function (Blueprint $table) {
            $table->dropIndex('vessel_identifiers_vessel_id_index');
        });
    }
};

