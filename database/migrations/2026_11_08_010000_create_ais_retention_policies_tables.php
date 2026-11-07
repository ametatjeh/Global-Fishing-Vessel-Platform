<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ais_retention_policies', function (Blueprint $table) {
            $table->id();
            $table->string('vessel_type')->unique();
            $table->string('label');
            $table->integer('retention_days')->nullable(); // null = permanent
            $table->boolean('is_immutable')->default(false);
            $table->boolean('is_enabled')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('ais_retention_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('auto_cleanup_enabled')->default(false);
            $table->string('cleanup_schedule')->default('daily'); // daily, weekly, monthly
            $table->string('cleanup_time')->default('03:00');
            $table->integer('chunk_size')->default(1000);
            $table->timestamp('last_cleanup_at')->nullable();
            $table->integer('last_cleanup_records_purged')->default(0);
            $table->string('last_cleanup_status')->nullable();
            $table->json('last_cleanup_summary')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Seed default policies with Fishing strictly immutable & permanent
        $now = now();
        DB::table('ais_retention_policies')->insert([
            [
                'vessel_type' => 'fishing',
                'label' => 'Fishing / Kapal Penangkap Ikan',
                'retention_days' => null,
                'is_immutable' => true,
                'is_enabled' => true,
                'description' => 'Seluruh posisi historis disimpan permanen sesuai mandat platform pemantauan maritim GFVIP. Kebijakan ini bersifat tetap (immutable) dan terlindungi dari automatic cleanup.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'vessel_type' => 'cargo',
                'label' => 'Cargo / Kapal Kargo',
                'retention_days' => 90,
                'is_immutable' => false,
                'is_enabled' => true,
                'description' => 'Retensi posisi kapal pengangkut kargo niaga.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'vessel_type' => 'tanker',
                'label' => 'Tanker / Kapal Tangki',
                'retention_days' => 90,
                'is_immutable' => false,
                'is_enabled' => true,
                'description' => 'Retensi posisi kapal pengangkut cairan/tangki minyak/gas.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'vessel_type' => 'passenger',
                'label' => 'Passenger / Kapal Penumpang',
                'retention_days' => 60,
                'is_immutable' => false,
                'is_enabled' => true,
                'description' => 'Retensi posisi kapal penumpang, feri, dan kapal pesiar niaga.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'vessel_type' => 'tug',
                'label' => 'Tug / Kapal Tunda',
                'retention_days' => 60,
                'is_immutable' => false,
                'is_enabled' => true,
                'description' => 'Retensi posisi kapal tunda (tugboat) dan asistensi pelabuhan.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'vessel_type' => 'pleasure',
                'label' => 'Pleasure Craft / Kapal Pesiar Rekreasi',
                'retention_days' => 30,
                'is_immutable' => false,
                'is_enabled' => true,
                'description' => 'Retensi posisi kapal pesiar rekreasi dan yacht privat.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'vessel_type' => 'other',
                'label' => 'Other / Tipe Kapal Lainnya',
                'retention_days' => 90,
                'is_immutable' => false,
                'is_enabled' => true,
                'description' => 'Retensi posisi kapal bertipe carrier, patrol, research, support, dan tipe lainnya.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'vessel_type' => 'unknown',
                'label' => 'Unknown / Belum Terklasifikasi',
                'retention_days' => 30,
                'is_immutable' => false,
                'is_enabled' => true,
                'description' => 'Retensi posisi kapal dengan AIS ShipType tidak dikenal atau belum diverifikasi. Tidak otomatis dianggap kapal fishing.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        // Seed initial single-row settings (Auto cleanup DISABLED by default)
        DB::table('ais_retention_settings')->insert([
            'id' => 1,
            'auto_cleanup_enabled' => false,
            'cleanup_schedule' => 'daily',
            'cleanup_time' => '03:00',
            'chunk_size' => 1000,
            'last_cleanup_at' => null,
            'last_cleanup_records_purged' => 0,
            'last_cleanup_status' => null,
            'last_cleanup_summary' => null,
            'updated_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('ais_retention_settings');
        Schema::dropIfExists('ais_retention_policies');
    }
};
