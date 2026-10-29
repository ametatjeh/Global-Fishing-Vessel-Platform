#!/usr/bin/env bash
# =============================================================================
# GFVIP - Production Automated Backup for PostgreSQL and SAR Storage (Linux)
# Compatible with Ubuntu 24.04 and aaPanel
# =============================================================================
# Usage:
#   bash deployment/linux/backup-gfvip.sh [/path/to/backup_root] [retention_days]
#
# Defaults:
#   BACKUP_ROOT: /www/backup/gfvip (or $BACKUP_ROOT_DIR env)
#   RETENTION_DAYS: 14 (or $BACKUP_RETENTION_DAYS env)
# =============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_PATH="$(cd "${SCRIPT_DIR}/../.." && pwd)"

# Resolve Backup Root
DEFAULT_ROOT="/www/backup/gfvip"
if [ ! -d "/www/backup" ] && [ ! -w "/www" ]; then
    DEFAULT_ROOT="${PROJECT_PATH}/storage/backups"
fi

BACKUP_ROOT="${1:-${BACKUP_ROOT_DIR:-$DEFAULT_ROOT}}"
RETENTION_DAYS="${2:-${BACKUP_RETENTION_DAYS:-14}}"

# Subdirectories
DB_BACKUP_DIR="${BACKUP_ROOT}/database"
SAR_BACKUP_DIR="${BACKUP_ROOT}/sar_storage"
LOG_DIR="${BACKUP_ROOT}/logs"

mkdir -p "${DB_BACKUP_DIR}" "${SAR_BACKUP_DIR}" "${LOG_DIR}"

TIMESTAMP="$(date +%Y%m%d_%H%M%S)"
DATE_DAY="$(date +%Y%m%d)"
LOG_FILE="${LOG_DIR}/backup_${DATE_DAY}.log"

log_msg() {
    local level="$1"
    local message="$2"
    local ts
    ts="$(date '+%Y-%m-%d %H:%M:%S.%3N' 2>/dev/null || date '+%Y-%m-%d %H:%M:%S')"
    local entry="[${ts}] [${level}] ${message}"
    echo "${entry}" >> "${LOG_FILE}"
    echo "${entry}"
}

log_msg "INFO" "=== Starting GFVIP Automated Backup Operation (Ubuntu/Linux) ==="
log_msg "INFO" "Project Directory: ${PROJECT_PATH}"
log_msg "INFO" "Backup Root: ${BACKUP_ROOT}"

# Validate pg_dump
if ! command -v pg_dump >/dev/null 2>&1; then
    log_msg "ERROR" "Binary 'pg_dump' is not found in PATH. Install postgresql-client."
    exit 1
fi
PG_DUMP_BIN="$(command -v pg_dump)"
log_msg "INFO" "Using pg_dump binary: ${PG_DUMP_BIN}"

cd "${PROJECT_PATH}"

# Extract DB connection credentials safely from Laravel
DB_JSON="$(php -r "
  require 'vendor/autoload.php';
  \$app = require_once 'bootstrap/app.php';
  \$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
  echo json_encode([
    'host' => config('database.connections.pgsql.host', '127.0.0.1'),
    'port' => config('database.connections.pgsql.port', '5432'),
    'database' => config('database.connections.pgsql.database', 'global_fishing_vessel_intelligence'),
    'username' => config('database.connections.pgsql.username', 'postgres'),
    'password' => config('database.connections.pgsql.password', '')
  ]);
")"

DB_HOST="$(echo "${DB_JSON}" | php -r "\$d=json_decode(file_get_contents('php://stdin'), true); echo \$d['host'] ?? '127.0.0.1';")"
DB_PORT="$(echo "${DB_JSON}" | php -r "\$d=json_decode(file_get_contents('php://stdin'), true); echo \$d['port'] ?? '5432';")"
DB_NAME="$(echo "${DB_JSON}" | php -r "\$d=json_decode(file_get_contents('php://stdin'), true); echo \$d['database'] ?? '';")"
DB_USER="$(echo "${DB_JSON}" | php -r "\$d=json_decode(file_get_contents('php://stdin'), true); echo \$d['username'] ?? '';")"
DB_PASS="$(echo "${DB_JSON}" | php -r "\$d=json_decode(file_get_contents('php://stdin'), true); echo \$d['password'] ?? '';")"

# 1. Execute PostgreSQL Backup
DUMP_FILENAME="gfvip_db_${TIMESTAMP}.dump"
DUMP_FILEPATH="${DB_BACKUP_DIR}/${DUMP_FILENAME}"

log_msg "INFO" "Dumping database '${DB_NAME}' on ${DB_HOST}:${DB_PORT} to ${DUMP_FILENAME}..."
START_SEC=$(date +%s)

export PGPASSWORD="${DB_PASS}"
"${PG_DUMP_BIN}" \
    -h "${DB_HOST}" \
    -p "${DB_PORT}" \
    -U "${DB_USER}" \
    -d "${DB_NAME}" \
    -F c \
    -b -v \
    -f "${DUMP_FILEPATH}" >> "${LOG_FILE}" 2>&1
unset PGPASSWORD

if [ ! -f "${DUMP_FILEPATH}" ] || [ ! -s "${DUMP_FILEPATH}" ]; then
    log_msg "ERROR" "Database dump failed or produced empty file: ${DUMP_FILEPATH}"
    exit 1
fi

chmod 640 "${DUMP_FILEPATH}"
DUMP_SIZE=$(stat -c%s "${DUMP_FILEPATH}" 2>/dev/null || wc -c < "${DUMP_FILEPATH}")
DUMP_SIZE_MB=$(php -r "echo round(${DUMP_SIZE} / 1048576, 2);")
log_msg "SUCCESS" "PostgreSQL dump completed: ${DUMP_FILENAME} (${DUMP_SIZE_MB} MB)"

# 2. Archive SAR Storage Evidence
SAR_PRODUCTS_DIR="${PROJECT_PATH}/storage/app/sar_products"
SAR_EVIDENCE_DIR="${PROJECT_PATH}/storage/app/sar_evidence"
SAR_PRODUCTS_COUNT=0
SAR_EVIDENCE_COUNT=0

if [ -d "${SAR_PRODUCTS_DIR}" ]; then
    SAR_PRODUCTS_COUNT=$(find "${SAR_PRODUCTS_DIR}" -type f | wc -l)
    mkdir -p "${SAR_BACKUP_DIR}/products"
    cp -u -r "${SAR_PRODUCTS_DIR}/." "${SAR_BACKUP_DIR}/products/" 2>/dev/null || true
fi

if [ -d "${SAR_EVIDENCE_DIR}" ]; then
    SAR_EVIDENCE_COUNT=$(find "${SAR_EVIDENCE_DIR}" -type f | wc -l)
    mkdir -p "${SAR_BACKUP_DIR}/evidence"
    cp -u -r "${SAR_EVIDENCE_DIR}/." "${SAR_BACKUP_DIR}/evidence/" 2>/dev/null || true
fi

log_msg "INFO" "SAR evidence synchronized: ${SAR_PRODUCTS_COUNT} products, ${SAR_EVIDENCE_COUNT} evidence crops."

# 3. Retention Enforcement (Prune old dumps safely)
log_msg "INFO" "Enforcing retention policy: keeping database dumps newer than ${RETENTION_DAYS} days..."
DUMP_COUNT=$(find "${DB_BACKUP_DIR}" -maxdepth 1 -name "gfvip_db_*.dump" | wc -l)

if [ "${DUMP_COUNT}" -gt 1 ]; then
    # Delete files older than retention days, but ensure newest is retained
    find "${DB_BACKUP_DIR}" -maxdepth 1 -name "gfvip_db_*.dump" -mtime +"${RETENTION_DAYS}" -exec rm -f {} + 2>/dev/null || true
fi

# 4. Capture Live Snapshot Metadata for DR Validation
COUNTS_JSON="$(php -r "
  require 'vendor/autoload.php';
  \$app = require_once 'bootstrap/app.php';
  \$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
  try {
    echo json_encode([
      'satellite_detections' => (int)\Illuminate\Support\Facades\DB::table('satellite_detections')->count(),
      'satellite_scenes' => (int)\Illuminate\Support\Facades\DB::table('satellite_scenes')->count(),
      'vessels' => (int)\Illuminate\Support\Facades\DB::table('vessels')->count(),
      'vessel_positions' => (int)\Illuminate\Support\Facades\DB::table('vessel_positions')->count(),
      'audit_logs' => (int)\Illuminate\Support\Facades\DB::table('audit_logs')->count()
    ]);
  } catch (\Throwable \$e) {
    echo json_encode([]);
  }
")"

END_SEC=$(date +%s)
DURATION_SECONDS=$(( END_SEC - START_SEC ))
TIMESTAMP_UTC="$(date -u +"%Y-%m-%dT%H:%M:%SZ")"

# 5. Generate Machine-Readable Manifest
MANIFEST_FILE="${BACKUP_ROOT}/latest_backup_manifest.json"
MANIFEST_TEMP="${MANIFEST_FILE}.tmp.$$"

php -r "
  \$manifest = [
    'status' => 'SUCCESS',
    'timestamp_utc' => '${TIMESTAMP_UTC}',
    'database' => [
      'database_name' => '${DB_NAME}',
      'dump_file' => '${DUMP_FILENAME}',
      'dump_path' => '${DUMP_FILEPATH}',
      'size_bytes' => (int)'${DUMP_SIZE}',
      'size_mb' => (float)'${DUMP_SIZE_MB}'
    ],
    'snapshot_records' => json_decode('${COUNTS_JSON}', true) ?: [],
    'sar_storage' => [
      'products_file_count' => (int)'${SAR_PRODUCTS_COUNT}',
      'evidence_file_count' => (int)'${SAR_EVIDENCE_COUNT}'
    ],
    'retention_days' => (int)'${RETENTION_DAYS}',
    'duration_seconds' => (int)'${DURATION_SECONDS}',
    'gfvip_version' => '2.0-GFVIP',
    'os' => 'Linux'
  ];
  file_put_contents('${MANIFEST_TEMP}', json_encode(\$manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
"

mv -f "${MANIFEST_TEMP}" "${MANIFEST_FILE}"
chmod 644 "${MANIFEST_FILE}"

log_msg "SUCCESS" "=== GFVIP Automated Backup Operation Completed Successfully in ${DURATION_SECONDS} s ==="
exit 0

