<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investigations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('case_number')->unique()->index();
            $table->string('title')->index();
            $table->string('priority')->default('medium')->index(); // low, medium, high, urgent
            $table->string('status')->default('open')->index(); // open, in_progress, under_review, closed, archived
            $table->foreignUuid('target_vessel_id')->nullable()->constrained('vessels')->nullOnDelete();
            $table->foreignUuid('related_encounter_id')->nullable()->constrained('vessel_encounters')->nullOnDelete();
            $table->foreignUuid('related_detection_id')->nullable()->constrained('satellite_detections')->nullOnDelete();
            $table->foreignUuid('related_compliance_event_id')->nullable()->constrained('compliance_events')->nullOnDelete();
            $table->text('summary');
            $table->text('findings')->nullable();
            $table->text('recommendation')->nullable();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action')->index(); // create, update, delete, review, export, login
            $table->string('entity_type')->index();
            $table->string('entity_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('details')->nullable();
            $table->timestamp('created_at')->index();
        });

        // Add role & organization to users table if not already present
        if (!Schema::hasColumn('users', 'role')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('role')->default('analyst')->index();
            });
        }
        if (!Schema::hasColumn('users', 'organization')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('organization')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('investigations');
    }
};
