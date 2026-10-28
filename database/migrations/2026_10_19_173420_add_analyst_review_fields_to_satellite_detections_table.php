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
        Schema::table('satellite_detections', function (Blueprint $table) {
            $table->string('review_status')->default('pending_review')->index()->after('correlation_status');
            $table->foreignId('reviewed_by_user_id')->nullable()->after('review_status')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by_user_id')->index();
            $table->text('review_rationale')->nullable()->after('reviewed_at');
            $table->decimal('tcr_db', 6, 2)->nullable()->after('confidence_score')->index();
            $table->json('review_metadata')->nullable()->after('review_rationale');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('satellite_detections', function (Blueprint $table) {
            $table->dropForeign(['reviewed_by_user_id']);
            $table->dropColumn([
                'review_status',
                'reviewed_by_user_id',
                'reviewed_at',
                'review_rationale',
                'tcr_db',
                'review_metadata',
            ]);
        });
    }
};
