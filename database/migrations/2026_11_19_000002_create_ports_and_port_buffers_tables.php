<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (!Schema::hasTable('ports')) {
            Schema::create('ports', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('wpi_port_id')->nullable()->index();
                $table->string('port_code', 50)->nullable()->index();
                $table->string('name')->index();
                $table->string('country')->index();
                $table->text('state')->nullable();
                $table->decimal('latitude', 10, 7)->index();
                $table->decimal('longitude', 10, 7)->index();
                $table->string('port_size', 50)->default('Small')->index(); // Major, Large, Medium, Small, Very Small
                $table->unsignedInteger('buffer_radius_meters')->default(5000)->index();
                $table->string('harbor_type', 100)->nullable();
                $table->string('max_vessel_size', 100)->nullable();
                $table->text('point_of_interest')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['latitude', 'longitude'], 'idx_ports_lat_lng');
            });

            if ($driver === 'pgsql') {
                try {
                    DB::statement('ALTER TABLE ports ADD COLUMN geom geometry(Point, 4326);');
                    DB::statement('CREATE INDEX IF NOT EXISTS idx_ports_geom ON ports USING GIST (geom);');
                } catch (\Throwable $e) {
                    // PostGIS might be absent in non-spatial testing pgsql or already set
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ports');
    }
};
