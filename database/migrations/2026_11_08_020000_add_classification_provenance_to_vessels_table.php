<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Add additive classification provenance fields to vessels table.
     * Explicitly avoids ->after() for full PostgreSQL compatibility.
     */
    public function up(): void
    {
        Schema::table('vessels', function (Blueprint $table) {
            if (!Schema::hasColumn('vessels', 'classification_source')) {
                $table->string('classification_source')->nullable()->index();
            }
            if (!Schema::hasColumn('vessels', 'ais_ship_type_code')) {
                $table->integer('ais_ship_type_code')->nullable()->index();
            }
            if (!Schema::hasColumn('vessels', 'classified_at')) {
                $table->timestamp('classified_at')->nullable()->index();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vessels', function (Blueprint $table) {
            $columnsToDrop = [];
            if (Schema::hasColumn('vessels', 'classification_source')) {
                $table->dropIndex(['classification_source']);
                $columnsToDrop[] = 'classification_source';
            }
            if (Schema::hasColumn('vessels', 'ais_ship_type_code')) {
                $table->dropIndex(['ais_ship_type_code']);
                $columnsToDrop[] = 'ais_ship_type_code';
            }
            if (Schema::hasColumn('vessels', 'classified_at')) {
                $table->dropIndex(['classified_at']);
                $columnsToDrop[] = 'classified_at';
            }
            if (!empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};

