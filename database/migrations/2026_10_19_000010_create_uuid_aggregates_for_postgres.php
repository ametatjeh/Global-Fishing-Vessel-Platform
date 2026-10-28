<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Enables MAX(uuid) and MIN(uuid) aggregate functions in PostgreSQL
     * required by Eloquent's latestOfMany() / ofMany() on UUID primary keys.
     */
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::unprepared(<<<SQL
                CREATE OR REPLACE FUNCTION pg_uuid_max(uuid, uuid)
                RETURNS uuid AS $$
                    SELECT CASE WHEN $1 IS NULL THEN $2
                                WHEN $2 IS NULL THEN $1
                                WHEN $1 > $2 THEN $1
                                ELSE $2 END;
                $$ LANGUAGE sql IMMUTABLE PARALLEL SAFE;

                CREATE OR REPLACE AGGREGATE max(uuid) (
                    SFUNC = pg_uuid_max,
                    STYPE = uuid,
                    COMBINEFUNC = pg_uuid_max,
                    PARALLEL = SAFE,
                    SORTOP = >
                );

                CREATE OR REPLACE FUNCTION pg_uuid_min(uuid, uuid)
                RETURNS uuid AS $$
                    SELECT CASE WHEN $1 IS NULL THEN $2
                                WHEN $2 IS NULL THEN $1
                                WHEN $1 < $2 THEN $1
                                ELSE $2 END;
                $$ LANGUAGE sql IMMUTABLE PARALLEL SAFE;

                CREATE OR REPLACE AGGREGATE min(uuid) (
                    SFUNC = pg_uuid_min,
                    STYPE = uuid,
                    COMBINEFUNC = pg_uuid_min,
                    PARALLEL = SAFE,
                    SORTOP = <
                );
            SQL);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::unprepared(<<<SQL
                DROP AGGREGATE IF EXISTS max(uuid);
                DROP AGGREGATE IF EXISTS min(uuid);
                DROP FUNCTION IF EXISTS pg_uuid_max(uuid, uuid);
                DROP FUNCTION IF EXISTS pg_uuid_min(uuid, uuid);
            SQL);
        }
    }
};
