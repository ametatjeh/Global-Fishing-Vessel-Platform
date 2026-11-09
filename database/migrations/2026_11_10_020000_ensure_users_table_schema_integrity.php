<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Ensures comprehensive schema integrity for users table on PostgreSQL / SQLite / MySQL.
     * Guarantees all required columns exist even on existing partially migrated databases.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'role')) {
                $table->string('role')->default('analyst')->index();
            }
            if (!Schema::hasColumn('users', 'organization')) {
                $table->string('organization')->nullable();
            }
            if (!Schema::hasColumn('users', 'is_active')) {
                $table->boolean('is_active')->default(true)->index();
            }
            if (!Schema::hasColumn('users', 'avatar_path')) {
                $table->string('avatar_path')->nullable();
            }
            if (!Schema::hasColumn('users', 'google_id')) {
                $table->string('google_id')->nullable()->unique();
            }
            if (!Schema::hasColumn('users', 'password_set_at')) {
                $table->timestamp('password_set_at')->nullable();
            }
            if (!Schema::hasColumn('users', 'last_login_at')) {
                $table->timestamp('last_login_at')->nullable();
            }
        });

        // Safe data backfill for existing rows with NULL role or is_active
        if (Schema::hasColumn('users', 'role')) {
            DB::table('users')
                ->whereNull('role')
                ->orWhere('role', '')
                ->update(['role' => 'analyst']);
        }

        if (Schema::hasColumn('users', 'is_active')) {
            DB::table('users')
                ->whereNull('is_active')
                ->update(['is_active' => true]);
        }
    }

    /**
     * Reverse the migrations.
     * Non-destructive rollback to prevent accidental data loss of user identities.
     */
    public function down(): void
    {
        // Preserves user table columns to prevent accidental data loss in production.
    }
};

