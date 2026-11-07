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
        Schema::create('prohibited_vessels', function (Blueprint $table) {
            $table->id();
            $table->string('mmsi', 20)->unique()->index();
            $table->string('reason')->default('cargo_tanker_policy')->index();
            $table->string('original_name')->nullable();
            $table->string('original_type')->nullable()->index(); // cargo, tanker
            $table->foreignId('blocked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('exception_granted_at')->nullable();
            $table->text('exception_notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prohibited_vessels');
    }
};
