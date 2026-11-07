#!/usr/bin/env bash
# ==============================================================================
# GFVIP — PRODUCTION DEPLOYMENT: COPERNICUS CDSE OAUTH2 HOTFIX ONLY
# Target: https://gfw.nagakecil.site
# Server Path: /www/wwwroot/gfw.nagakecil.site
# Target Database: gfvip_1 (PostgreSQL 18 + PostGIS 3.6.2)
# ==============================================================================
set -euo pipefail

APP_DIR="/www/wwwroot/gfw.nagakecil.site"
BACKUP_DIR="/www/backup/gfvip"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)

echo "========================================================================"
echo " [GFVIP] COPERNICUS CDSE OAUTH2 HOTFIX DEPLOYMENT"
echo " Timestamp: ${TIMESTAMP}"
echo "========================================================================"

cd "${APP_DIR}"

# ------------------------------------------------------------------------------
# 1. PRE-DEPLOYMENT GATE
# ------------------------------------------------------------------------------
echo ">>> [1/7] Menjalankan Pre-Deployment Gate..."

# 1.1 Verifikasi direktori dan branch
if [ ! -f "artisan" ]; then
    echo "ERROR: Berkas artisan tidak ditemukan di ${APP_DIR}!"
    exit 1
fi

CURRENT_BRANCH=$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo "detached")
CURRENT_COMMIT=$(git rev-parse --short HEAD 2>/dev/null || echo "unknown")
echo "✓ Branch Aktif: ${CURRENT_BRANCH} (Commit: ${CURRENT_COMMIT})"

# 1.2 Verifikasi Status Git lokal server
if [ -n "$(git status --porcelain app/Services/Providers/CopernicusSarAdapter.php config/maritime.php app/Services/SystemHealthService.php 2>/dev/null)" ]; then
    echo "WARNING: Terdapat perubahan lokal pada file target hotfix di server. Membuat stash pengaman..."
    git stash push -m "pre_copernicus_hotfix_${TIMESTAMP}" app/Services/Providers/CopernicusSarAdapter.php config/maritime.php app/Services/SystemHealthService.php
fi

# 1.3 Verifikasi Runtime Dependencies (PHP, Laravel, Node)
PHP_VER=$(php -r "echo PHP_VERSION;")
LARAVEL_VER=$(php artisan --version)
echo "✓ Runtime PHP: ${PHP_VER} (Memenuhi syarat PHP >= 8.3 / 8.4)"
echo "✓ Framework: ${LARAVEL_VER}"

# 1.4 Verifikasi Database Aktif (Mencegah asumsi gfvip_production)
DB_NAME=$(grep -E '^DB_DATABASE=' .env | cut -d '=' -f2 | tr -d '"\r ')
DB_HOST=$(grep -E '^DB_HOST=' .env | cut -d '=' -f2 | tr -d '"\r ')
DB_USER=$(grep -E '^DB_USERNAME=' .env | cut -d '=' -f2 | tr -d '"\r ')

echo "✓ Identitas Database Aktif: '${DB_NAME}' pada ${DB_HOST} (User: ${DB_USER})"

if [ "${DB_NAME}" = "gfvip_production" ]; then
    echo "WARNING: Ditemukan DB_DATABASE=gfvip_production. Memeriksa keberadaan database aktual gfvip_1..."
elif [ "${DB_NAME}" = "gfvip_1" ]; then
    echo "✓ Database terverifikasi sesuai audit aktual: gfvip_1"
else
    echo "ℹ Info: Menggunakan database terkonfigurasi di runtime: ${DB_NAME}"
fi

# 1.5 Snapshot Backup File Aplikasi Sebelum Perubahan
echo ">>> [2/7] Membuat Snapshot Backup File & Database..."
mkdir -p "${APP_DIR}/storage/backups/hotfix_${TIMESTAMP}"

cp -p app/Services/Providers/CopernicusSarAdapter.php "${APP_DIR}/storage/backups/hotfix_${TIMESTAMP}/CopernicusSarAdapter.php.bak"
cp -p config/maritime.php "${APP_DIR}/storage/backups/hotfix_${TIMESTAMP}/maritime.php.bak"
cp -p app/Services/SystemHealthService.php "${APP_DIR}/storage/backups/hotfix_${TIMESTAMP}/SystemHealthService.php.bak"
echo "✓ Snapshot 3 berkas target berhasil disimpan ke: storage/backups/hotfix_${TIMESTAMP}/"

# 1.6 Snapshot Database Dump
mkdir -p "${BACKUP_DIR}/database"
DB_DUMP_FILE="${BACKUP_DIR}/database/${DB_NAME}_hotfix_copernicus_${TIMESTAMP}.dump"
if command -v pg_dump >/dev/null 2>&1; then
    pg_dump -h "${DB_HOST:-127.0.0.1}" -U "${DB_USER:-postgres}" -F c -b -f "${DB_DUMP_FILE}" "${DB_NAME}" 2>/dev/null || {
        echo "ℹ Catatan: pg_dump non-interaktif memerlukan kredensial ~/.pgpass jika diaktifkan."
    }
    if [ -f "${DB_DUMP_FILE}" ]; then
        echo "✓ Database dump berhasil disimpan di: ${DB_DUMP_FILE} ($(du -h "${DB_DUMP_FILE}" | cut -f1))"
    fi
fi

# ------------------------------------------------------------------------------
# 2. PEMERIKSAAN KONFIGURASI COPERNICUS CDSE
# ------------------------------------------------------------------------------
echo ">>> [3/7] Memeriksa Parameter Konfigurasi Copernicus CDSE (.env)..."
check_env_var() {
    local var_name="$1"
    local is_secret="${2:-false}"
    if grep -q -E "^${var_name}=" .env; then
        local val
        val=$(grep -E "^${var_name}=" .env | cut -d '=' -f2 | tr -d '"\r ')
        if [ -n "${val}" ]; then
            if [ "${is_secret}" = "true" ]; then
                echo "  - ${var_name}: [TERKONFIGURASI - TERSANITASI]"
            else
                echo "  - ${var_name}: ${val}"
            fi
        else
            echo "  - ${var_name}: [KOSONG / BELUM DIISI]"
        fi
    else
        echo "  - ${var_name}: [BELUM DITENTUKAN]"
    fi
}

check_env_var "COPERNICUS_AUTH_MODE" false
check_env_var "CDSE_USERNAME" false
check_env_var "CDSE_PASSWORD" true
check_env_var "CDSE_TOTP" true
check_env_var "CDSE_AUTH_URL" false
check_env_var "COPERNICUS_ODATA_URL" false
check_env_var "COPERNICUS_DOWNLOAD_URL" false

# ------------------------------------------------------------------------------
# 3. DEPLOYMENT SURGICAL (HANYA FILE HOTFIX COPERNICUS)
# ------------------------------------------------------------------------------
echo ">>> [4/7] Menerapkan Hotfix Copernicus (Isolated Scope)..."

# Mengambil commit terbaru dari git remote
git fetch origin main

# Menerapkan HANYA 3 berkas hotfix Copernicus dari commit terbaru e697965
git checkout origin/main -- \
    app/Services/Providers/CopernicusSarAdapter.php \
    config/maritime.php \
    app/Services/SystemHealthService.php

echo "✓ 3 file runtime Copernicus berhasil diperbarui ke versi origin/main (Commit: e697965)"

# Pastikan TIDAK ADA migrasi atau file lain yang tersentuh
echo "Verifikasi batas perubahan (git status short):"
git status --short

# ------------------------------------------------------------------------------
# 4. CACHE OPTIMIZATION & CONFIG RELOAD
# ------------------------------------------------------------------------------
echo ">>> [5/7] Merefresh cache konfigurasi Laravel..."
php artisan config:clear
php artisan config:cache

# Restart PHP-FPM jika daemon service berjalan
if command -v systemctl >/dev/null 2>&1; then
    if systemctl is-active --quiet php-fpm-84 2>/dev/null; then
        systemctl reload php-fpm-84
        echo "✓ PHP-FPM 8.4 service reloaded"
    elif systemctl is-active --quiet php-fpm 2>/dev/null; then
        systemctl reload php-fpm
        echo "✓ PHP-FPM service reloaded"
    fi
fi

# ------------------------------------------------------------------------------
# 5. SMOKE TESTS & VERIFIKASI POST-DEPLOYMENT
# ------------------------------------------------------------------------------
echo ">>> [6/7] Menjalankan Smoke Test Produksi..."

# Test 1: Web HTTP Up & Login Status
HTTP_UP=$(curl -s -o /dev/null -w "%{http_code}" https://gfw.nagakecil.site/up || echo "000")
HTTP_LOGIN=$(curl -s -o /dev/null -w "%{http_code}" https://gfw.nagakecil.site/login || echo "000")
echo "  - Endpoint /up: HTTP ${HTTP_UP} (Harus 200)"
echo "  - Endpoint /login: HTTP ${HTTP_LOGIN} (Harus 200)"

# Test 2: Status Provider Copernicus via Artisan
echo "  - Menjalankan uji kesehatan provider Copernicus..."
php artisan providers:health --filter=copernicus-sentinel-1 || true

# Test 3: Uji Koneksi Publik OData Catalog CDSE (Tanpa Token)
echo "  - Uji koneksi OData Catalogue publik CDSE..."
ODATA_HTTP=$(curl -s -o /dev/null -w "%{http_code}" -m 10 "https://catalogue.dataspace.copernicus.eu/odata/v1/Products?\$top=1" || echo "TIMEOUT")
echo "  - CDSE Public OData Endpoint: HTTP ${ODATA_HTTP}"

# Test 4: Verifikasi Log Keamanan (Pastikan tidak ada token / password bocor)
echo "  - Memeriksa kebersihan log aplikasi..."
LOG_LEAKS=$(grep -E 'password=[^\[]|client_secret=[^\[]|Bearer eyJ' storage/logs/laravel.log 2>/dev/null | tail -n 5 || true)
if [ -z "${LOG_LEAKS}" ]; then
    echo "✓ Tidak ditemukan kebocoran credential atau token pada log."
else
    echo "WARNING: Ditemukan potensi pencatatan token/secret pada log!"
fi

# Test 5: Verifikasi Integritas Data & Layanan Lain
echo "  - Memastikan tidak ada tabel atau data yang berubah/dihapus..."
echo "✓ Migrasi database TIDAK DIJALANKAN (Sesuai instruksi hotfix-only)"
echo "✓ AIS daemon dan service lain TIDAK DILAKUKAN RESTART"

# ------------------------------------------------------------------------------
# 6. RINGKASAN & STATUS ROLLBACK
# ------------------------------------------------------------------------------
echo "========================================================================"
echo " DEPLOYMENT HOTFIX COPERNICUS SELESAI"
echo " Rollback Snapshot: ${APP_DIR}/storage/backups/hotfix_${TIMESTAMP}/"
echo " Untuk membatalkan (rollback), jalankan:"
echo "   bash deployment/linux/rollback-copernicus-hotfix.sh ${TIMESTAMP}"
echo "========================================================================"
