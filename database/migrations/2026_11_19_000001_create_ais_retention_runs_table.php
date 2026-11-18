<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ais_retention_runs', function (Blueprint $table) {
            $table->id();
            $table->string('run_type')->default('manual_purge')->index(); // manual_purge, scheduled_cleanup, simulation, recalculate_stats
            $table->string('status')->default('pending')->index(); // pending, running, completed, failed, cancelled
            $table->boolean('is_dry_run')->default(false);
            $table->integer('chunk_size')->default(1000);
            $table->integer('batch_count')->default(0);
            $table->integer('total_records_purged')->default(0);
            $table->json('purged_by_type')->nullable();
            $table->json('target_policies')->nullable();
            $table->json('summary')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->decimal('duration_seconds', 8, 2)->nullable();
            $table->text('error_message')->nullable();
            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ais_retention_runs');
    }
};
