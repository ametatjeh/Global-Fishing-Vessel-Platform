#!/usr/bin/env bash
# ==============================================================================
# GFVIP - READ-ONLY SERVER STORAGE, PGSQL LOG & LIVE AIS AUDIT SCRIPT
# Target: https://gfw.nagakecil.site (/www/wwwroot/gfw.nagakecil.site)
# Operating Environment: aaPanel / Ubuntu Linux / PostgreSQL 18 / PostGIS 3.6
# Mode: 100% READ-ONLY (No modifications, no deletion, no service restarts)
# ==============================================================================
set -e

APP_DIR="/www/wwwroot/gfw.nagakecil.site"
PG_LOG_DIR="/www/server/pgsql/logs"
PG_DATA_DIR="/www/server/pgsql/data"
TIMESTAMP=$(date '+%Y-%m-%d %H:%M:%S')

echo "=============================================================================="
echo " [GFVIP] PRODUCTION READ-ONLY STORAGE, PGSQL & NETWORK AUDIT"
echo " Timestamp: ${TIMESTAMP}"
echo " Host: $(hostname) | Kernel: $(uname -r)"
echo "=============================================================================="

cd "${APP_DIR}"

# 1. DISK & STORAGE TELEMETRY
echo ""
echo ">>> [1/7] STORAGE AUDIT: FILESYSTEM & DIRECTORY USAGE"
echo "------------------------------------------------------------------------------"
echo "--- Filesystem Usage (df -h) ---"
df -h / /www 2>/dev/null || df -h /

echo ""
echo "--- PostgreSQL Logs Directory (${PG_LOG_DIR}) ---"
if [ -d "${PG_LOG_DIR}" ]; then
    du -sh "${PG_LOG_DIR}"
    echo "Top 15 Largest Log Files:"
    ls -lhS "${PG_LOG_DIR}" 2>/dev/null | head -n 16
else
    echo "Directory ${PG_LOG_DIR} not found. Searching alternative PostgreSQL log dirs:"
    find /www/server -type d -name "logs" 2>/dev/null
fi

echo ""
echo "--- PostgreSQL Data Directory (${PG_DATA_DIR}) ---"
if [ -d "${PG_DATA_DIR}" ]; then
    du -sh "${PG_DATA_DIR}" 2>/dev/null || echo "Permission denied to read pgsql/data size"
    echo "WAL Files Directory Size:"
    du -sh "${PG_DATA_DIR}/pg_wal" 2>/dev/null || echo "WAL dir checked via SQL"
fi

echo ""
echo "--- Application Storage (${APP_DIR}/storage) ---"
du -sh "${APP_DIR}/storage" 2>/dev/null
echo "Detailed Breakdown:"
du -sh "${APP_DIR}/storage/logs" "${APP_DIR}/storage/app" "${APP_DIR}/storage/framework" 2>/dev/null || true

# 2. POSTGRESQL LIVE RUNTIME CONFIGURATION
echo ""
echo ">>> [2/7] POSTGRESQL RUNTIME AUDIT (READ-ONLY SQL)"
echo "------------------------------------------------------------------------------"
DB_NAME=$(grep -E '^DB_DATABASE=' .env | cut -d '=' -f2 | tr -d '"\r ')
DB_HOST=$(grep -E '^DB_HOST=' .env | cut -d '=' -f2 | tr -d '"\r ')
DB_PORT=$(grep -E '^DB_PORT=' .env | cut -d '=' -f2 | tr -d '"\r ')
DB_USER=$(grep -E '^DB_USERNAME=' .env | cut -d '=' -f2 | tr -d '"\r ')
DB_PASS=$(grep -E '^DB_PASSWORD=' .env | cut -d '=' -f2 | tr -d '"\r ')

export PGPASSWORD="${DB_PASS}"
PSQL_CMD="psql -h ${DB_HOST:-127.0.0.1} -p ${DB_PORT:-5432} -U ${DB_USER:-postgres} -d ${DB_NAME:-gfvip_1} -t -A -c"

echo "Database Identity: ${DB_NAME} on ${DB_HOST}:${DB_PORT} (User: ${DB_USER})"

if command -v psql >/dev/null 2>&1; then
    echo ""
    echo "--- Active PostgreSQL Logging Parameters ---"
    ${PSQL_CMD} "
        SELECT name || ' = ' || setting || ' (' || source || ')'
        FROM pg_settings 
        WHERE name IN (
            'log_statement', 'log_duration', 'log_min_duration_statement',
            'log_connections', 'log_disconnections', 'log_min_messages',
            'log_min_error_statement', 'log_checkpoints', 'logging_collector',
            'log_directory', 'log_filename', 'log_rotation_age', 'log_rotation_size',
            'log_truncate_on_rotation', 'log_line_prefix', 'archive_mode',
            'max_wal_size', 'min_wal_size', 'wal_keep_size'
        ) ORDER BY name;
    " || echo "Failed to query pg_settings via psql"

    echo ""
    echo "--- Database & Table Disk Footprint ---"
    ${PSQL_CMD} "
        SELECT 'Database ' || current_database() || ' size: ' || pg_size_pretty(pg_database_size(current_database()));
    " || true
    echo "Top 10 Largest Tables in ${DB_NAME}:"
    ${PSQL_CMD} "
        SELECT rpad(relname, 28) || ' | Size: ' || rpad(pg_size_pretty(pg_total_relation_size(c.oid)), 10) || ' | Live Tuples: ' || n_live_tup
        FROM pg_class c
        JOIN pg_namespace n ON n.oid = c.relnamespace
        JOIN pg_stat_user_tables s ON s.relid = c.oid
        WHERE n.nspname = 'public' AND c.relkind = 'r'
        ORDER BY pg_total_relation_size(c.oid) DESC
        LIMIT 10;
    " || true

    echo ""
    echo "--- PostgreSQL WAL & Replication Slots ---"
    ${PSQL_CMD} "SELECT 'Active Replication Slots: ' || count(*) FROM pg_replication_slots;" || true
else
    echo "psql binary not in PATH. Running read-only SQL via artisan tinker:"
    php artisan tinker --execute="
        \$settings = DB::select(\"SELECT name, setting, source FROM pg_settings WHERE name LIKE 'log_%' OR name LIKE '%wal%'\");
        foreach (\$settings as \$s) { echo \$s->name . ' = ' . \$s->setting . ' (' . \$s->source . ')' . PHP_EOL; }
    " || true
fi

# 3. NETWORK TRAFFIC & SOCKETS AUDIT
echo ""
echo ">>> [3/7] NETWORK DIAGNOSIS (ACTIVE PROCESSES & CONNECTIONS)"
echo "------------------------------------------------------------------------------"
echo "--- Listening Ports & Sockets (ss -tulpn) ---"
ss -tulpn 2>/dev/null | grep -E 'node|php|postgres|nginx|cloudflared' || netstat -tulpn 2>/dev/null || true

echo ""
echo "--- Active Established Connections by Process ---"
ss -tanp 2>/dev/null | grep ESTAB | awk '{print $NF}' | sort | uniq -c | sort -nr | head -n 15 || true

echo ""
echo "--- Network Interfaces IO Summary ---"
ip -s link 2>/dev/null || ifconfig 2>/dev/null || true

# 4. AIS INGESTION WORKER STATUS
echo ""
echo ">>> [4/7] LIVE AIS INGESTION WORKER STATUS"
echo "------------------------------------------------------------------------------"
if systemctl is-active --quiet ais-node-worker 2>/dev/null; then
    echo "Systemd Service (ais-node-worker): ACTIVE (RUNNING)"
    systemctl status ais-node-worker --no-pager 2>/dev/null | head -n 12
elif [ -f "/etc/supervisor/conf.d/ais-stream.conf" ]; then
    supervisorctl status ais-stream 2>/dev/null || echo "Supervisor check skipped"
else
    echo "Checking running node or ais processes:"
    ps aux | grep -E 'ais/worker|ais:listen' | grep -v grep || echo "No active AIS listener process found"
fi

echo ""
echo "Recent AIS Worker Logs (Last 10 lines):"
tail -n 10 "${APP_DIR}/storage/logs/ais-worker.log" 2>/dev/null || echo "ais-worker.log not populated"

# 5. CARGO, TANKER, PLEASURE & FISHING VESSEL AUDIT
echo ""
echo ">>> [5/7] VESSEL INVENTORY AUDIT (READ-ONLY STATS)"
echo "------------------------------------------------------------------------------"
php artisan tinker --execute="
    echo '--- Breakdown by Vessel Type ---' . PHP_EOL;
    \$types = DB::table('vessels')->select('vessel_type', DB::raw('count(*) as count'))->groupBy('vessel_type')->orderByDesc('count')->get();
    foreach (\$types as \$t) {
        echo sprintf('%-18s: %d vessels', \$t->vessel_type, \$t->count) . PHP_EOL;
    }

    echo PHP_EOL . '--- Non-Fishing (Cargo, Tanker, Pleasure) & Protected Assessment ---' . PHP_EOL;
    \$cargoIds = DB::table('vessels')->where('vessel_type', 'cargo')->pluck('id')->toArray();
    \$tankerIds = DB::table('vessels')->where('vessel_type', 'tanker')->pluck('id')->toArray();
    \$pleasureIds = DB::table('vessels')->where('vessel_type', 'pleasure')->pluck('id')->toArray();
    \$fishingIds = DB::table('vessels')->where('vessel_type', 'fishing')->pluck('id')->toArray();

    \$cargoPos = !empty(\$cargoIds) ? DB::table('vessel_positions')->whereIn('vessel_id', \$cargoIds)->count() : 0;
    \$tankerPos = !empty(\$tankerIds) ? DB::table('vessel_positions')->whereIn('vessel_id', \$tankerIds)->count() : 0;
    \$pleasurePos = !empty(\$pleasureIds) ? DB::table('vessel_positions')->whereIn('vessel_id', \$pleasureIds)->count() : 0;
    \$fishingPos = !empty(\$fishingIds) ? DB::table('vessel_positions')->whereIn('vessel_id', \$fishingIds)->count() : 0;
    \$totalPos = DB::table('vessel_positions')->count();

    echo sprintf('Total Vessels in DB          : %d', DB::table('vessels')->count()) . PHP_EOL;
    echo sprintf('Total Positions in DB        : %d', \$totalPos) . PHP_EOL;
    echo sprintf('Cargo Vessels Count          : %d (Positions: %d)', count(\$cargoIds), \$cargoPos) . PHP_EOL;
    echo sprintf('Tanker Vessels Count         : %d (Positions: %d)', count(\$tankerIds), \$tankerPos) . PHP_EOL;
    echo sprintf('Pleasure Vessels Count       : %d (Positions: %d)', count(\$pleasureIds), \$pleasurePos) . PHP_EOL;
    echo sprintf('Fishing Vessels Count        : %d (Positions: %d)', count(\$fishingIds), \$fishingPos) . PHP_EOL;

    \$targetIds = array_merge(\$cargoIds, \$tankerIds, \$pleasureIds);
    \$encCount = 0;
    \$sarCount = 0;
    \$invCount = 0;
    if (!empty(\$targetIds)) {
        \$encCount = DB::table('vessel_encounters')->whereIn('vessel_1_id', \$targetIds)->orWhereIn('vessel_2_id', \$targetIds)->count();
        \$sarCount = DB::table('satellite_detections')->whereIn('correlated_vessel_id', \$targetIds)->count();
        if (Schema::hasTable('investigations')) {
            \$invCount = DB::table('investigations')->whereIn('target_vessel_id', \$targetIds)->count();
        }
    }
    echo sprintf('Targets in Encounters        : %d (Protected from cascade loss)', \$encCount) . PHP_EOL;
    echo sprintf('Targets in SAR Correlation   : %d (Protected from cascade loss)', \$sarCount) . PHP_EOL;
    echo sprintf('Targets in Investigations    : %d (Protected from cascade loss)', \$invCount) . PHP_EOL;
"

# 6. SCHEDULER & QUEUE AUDIT
echo ""
echo ">>> [6/7] SCHEDULER & QUEUE AUDIT"
echo "------------------------------------------------------------------------------"
echo "Pending Jobs in Queue Table:"
php artisan tinker --execute="echo 'Jobs count: ' . DB::table('jobs')->count() . ' | Failed jobs: ' . DB::table('failed_jobs')->count() . PHP_EOL;" 2>/dev/null || true

echo "System Crontab:"
crontab -l 2>/dev/null || echo "No crontab for current user"

# 7. SUMMARY & COMPLETION
echo ""
echo "=============================================================================="
echo " [AUDIT COMPLETE] GFVIP Server Storage, PgSQL Log & AIS Audit Finished"
echo " Output generated in safe read-only mode. No data or processes were modified."
echo "=============================================================================="

