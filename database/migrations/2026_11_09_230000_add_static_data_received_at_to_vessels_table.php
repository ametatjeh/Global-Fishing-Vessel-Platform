<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Add static_data_received_at tracking column to vessels table.
     */
    public function up(): void
    {
        Schema::table('vessels', function (Blueprint $table) {
            if (!Schema::hasColumn('vessels', 'static_data_received_at')) {
                $table->timestamp('static_data_received_at')->nullable()->index();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vessels', function (Blueprint $table) {
            if (Schema::hasColumn('vessels', 'static_data_received_at')) {
                $table->dropIndex(['static_data_received_at']);
                $table->dropColumn('static_data_received_at');
            }
        });
    }
};
