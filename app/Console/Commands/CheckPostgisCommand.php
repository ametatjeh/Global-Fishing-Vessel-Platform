<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CheckPostgisCommand extends Command
{
    protected $signature = 'db:check-postgis';
    protected $description = 'Check database connection, PostGIS spatial extension readiness, and Windows setup';

    public function handle(): int
    {
        $this->info("=== GLOBAL FISHING VESSEL INTELLIGENCE PLATFORM ===");
        $this->info("Pemeriksaan Database Geospasial & PostGIS");
        $this->newLine();

        $connection = config('database.default');
        $this->line("Default Connection Driver: <fg=cyan>{$connection}</>");

        try {
            DB::connection()->getPdo();
            $this->info("✓ Koneksi database berhasil!");
        } catch (\Throwable $e) {
            $this->error("✗ Gagal terhubung ke database: " . $e->getMessage());
            $this->newLine();
            $this->renderPostgresInstallGuide();
            return self::FAILURE;
        }

        $driver = DB::connection()->getDriverName();
        $this->line("RDBMS Driver: <fg=yellow>{$driver}</>");

        if ($driver === 'pgsql') {
            try {
                $postgisVersion = DB::selectOne("SELECT PostGIS_Full_Version() as ver");
                if ($postgisVersion) {
                    $this->info("✓ PostGIS Extension TERSEDIA & AKTIF!");
                    $this->line("<fg=green>{$postgisVersion->ver}</>");
                    return self::SUCCESS;
                }
            } catch (\Throwable $e) {
                $this->warn("! PostgreSQL terhubung, tetapi ekstensi PostGIS belum diaktifkan pada database ini.");
                $this->line("Mencoba mengaktifkan PostGIS (CREATE EXTENSION IF NOT EXISTS postgis)...");

                try {
                    DB::statement("CREATE EXTENSION IF NOT EXISTS postgis;");
                    $this->info("✓ Berhasil mengaktifkan ekstensi PostGIS!");
                    return self::SUCCESS;
                } catch (\Throwable $err) {
                    $this->error("✗ Gagal mengaktifkan PostGIS: " . $err->getMessage());
                    $this->renderPostgisInstallGuide();
                    return self::FAILURE;
                }
            }
        } else {
            $this->warn("Database saat ini menggunakan driver [{$driver}].");
            $this->line("Platform siap beroperasi dengan format GeoJSON dan spatial filtering.");
            $this->line("Untuk performa spatial index global tingkat lanjut dengan PostGIS, ikuti panduan berikut:");
            $this->renderPostgresInstallGuide();
            return self::SUCCESS;
        }

        return self::SUCCESS;
    }

    protected function renderPostgresInstallGuide(): void
    {
        $this->newLine();
        $this->comment("--- PANDUAN INSTALASI POSTGRESQL + POSTGIS DI WINDOWS ---");
        $this->line("1. Jalankan perintah instalasi melalui PowerShell (Run as Administrator):");
        $this->line("   <fg=yellow>winget install PostgreSQL.PostgreSQL --version 16</>");
        $this->line("2. Pada akhir instalasi PostgreSQL, jalankan Application Stack Builder untuk mencentang:");
        $this->line("   <fg=yellow>Spatial Extensions -> PostGIS 3.4+ Bundle</>");
        $this->line("3. Buat database untuk platform:");
        $this->line("   <fg=yellow>psql -U postgres -c \"CREATE DATABASE gfvip_db;\"</>");
        $this->line("   <fg=yellow>psql -U postgres -d gfvip_db -c \"CREATE EXTENSION postgis;\"</>");
        $this->line("4. Sesuaikan kredensial di file .env:");
        $this->line("   DB_CONNECTION=pgsql");
        $this->line("   DB_HOST=127.0.0.1");
        $this->line("   DB_PORT=5432");
        $this->line("   DB_DATABASE=gfvip_db");
        $this->line("   DB_USERNAME=postgres");
        $this->line("   DB_PASSWORD=your_password");
        $this->newLine();
    }

    protected function renderPostgisInstallGuide(): void
    {
        $this->newLine();
        $this->comment("Instal PostGIS melalui Stack Builder pada PostgreSQL Anda di Windows, lalu jalankan:");
        $this->line("   <fg=yellow>psql -U postgres -d gfvip_db -c \"CREATE EXTENSION postgis;\"</>");
        $this->newLine();
    }
}
