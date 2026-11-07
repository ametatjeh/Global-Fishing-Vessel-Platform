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
        Schema::table('investigations', function (Blueprint $table) {
            if (!Schema::hasColumn('investigations', 'confidentiality_level')) {
                $table->string('confidentiality_level')->default('private')->after('status')->index(); // private, restricted, strictly_confidential
            }
            if (!Schema::hasColumn('investigations', 'lead_user_id')) {
                $table->foreignId('lead_user_id')->nullable()->after('assigned_to_user_id')->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('investigations', 'organization')) {
                $table->string('organization')->nullable()->after('lead_user_id')->index();
            }
            if (!Schema::hasColumn('investigations', 'collaborator_ids')) {
                $table->json('collaborator_ids')->nullable()->after('organization');
            }
            if (!Schema::hasColumn('investigations', 'focus_areas')) {
                $table->json('focus_areas')->nullable()->after('collaborator_ids');
            }
            if (!Schema::hasColumn('investigations', 'verdict_status')) {
                $table->string('verdict_status')->default('under_review')->after('focus_areas')->index(); // confirmed, suspected, potential, under_review, inconclusive
            }
            if (!Schema::hasColumn('investigations', 'evidence_matrix')) {
                $table->json('evidence_matrix')->nullable()->after('recommendation');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('investigations', function (Blueprint $table) {
            $columnsToDrop = [];
            foreach (['confidentiality_level', 'lead_user_id', 'organization', 'collaborator_ids', 'focus_areas', 'verdict_status', 'evidence_matrix'] as $col) {
                if (Schema::hasColumn('investigations', $col)) {
                    $columnsToDrop[] = $col;
                }
            }
            if (!empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};

