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

        if (!Schema::hasTable('eez_boundaries')) {
            Schema::create('eez_boundaries', function (Blueprint $table) {
                $table->id();
                $table->integer('line_id')->unique();
                $table->string('line_name', 255)->nullable();
                $table->string('line_type', 100)->nullable()->index();
                $table->string('sovereign1', 150)->nullable()->index();
                $table->string('sovereign2', 150)->nullable()->index();
                $table->string('territory1', 150)->nullable();
                $table->string('territory2', 150)->nullable();
                $table->string('eez1', 255)->nullable();
                $table->string('eez2', 255)->nullable();
                $table->string('source1', 255)->nullable();
                $table->text('url1')->nullable();
                $table->string('doc_date', 50)->nullable();
                $table->decimal('length_km', 12, 2)->nullable();
                $table->json('geometry_geojson')->nullable();
                $table->timestamps();
            });

            if ($driver === 'pgsql') {
                try {
                    DB::statement('ALTER TABLE eez_boundaries ADD COLUMN IF NOT EXISTS geom geometry(MultiLineString, 4326);');
                    DB::statement('CREATE INDEX IF NOT EXISTS eez_boundaries_geom_gist ON eez_boundaries USING GIST (geom);');
                } catch (\Throwable $e) {
                    // Ignore if PostGIS is not present
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('eez_boundaries');
    }
};
