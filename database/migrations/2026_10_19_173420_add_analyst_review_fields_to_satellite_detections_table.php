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
            if (!Schema::hasColumn('satellite_detections', 'review_status')) {
                $table->string('review_status')->default('pending_review')->index()->after('correlation_status');
            }
            if (!Schema::hasColumn('satellite_detections', 'reviewed_by_user_id')) {
                $table->foreignId('reviewed_by_user_id')->nullable()->after('review_status')->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('satellite_detections', 'reviewed_at')) {
                $table->timestamp('reviewed_at')->nullable()->after('reviewed_by_user_id')->index();
            }
            if (!Schema::hasColumn('satellite_detections', 'review_rationale')) {
                $table->text('review_rationale')->nullable()->after('reviewed_at');
            }
            if (!Schema::hasColumn('satellite_detections', 'tcr_db')) {
                $table->decimal('tcr_db', 6, 2)->nullable()->after('confidence_score')->index();
            }
            if (!Schema::hasColumn('satellite_detections', 'review_metadata')) {
                $table->json('review_metadata')->nullable()->after('review_rationale');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('satellite_detections', function (Blueprint $table) {
            if (Schema::hasColumn('satellite_detections', 'reviewed_by_user_id')) {
                $table->dropForeign(['reviewed_by_user_id']);
            }
            $cols = [
                'review_status',
                'reviewed_by_user_id',
                'reviewed_at',
                'review_rationale',
                'tcr_db',
                'review_metadata',
            ];
            $existing = array_filter($cols, fn ($c) => Schema::hasColumn('satellite_detections', $c));
            if (!empty($existing)) {
                $table->dropColumn($existing);
            }
        });
    }
};
