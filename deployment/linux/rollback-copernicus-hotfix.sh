#!/usr/bin/env bash
# ==============================================================================
# GFVIP — ROLLBACK SCRIPT: COPERNICUS CDSE OAUTH2 HOTFIX
# Target: https://gfw.nagakecil.site
# Server Path: /www/wwwroot/gfw.nagakecil.site
# ==============================================================================
set -euo pipefail

APP_DIR="/www/wwwroot/gfw.nagakecil.site"
cd "${APP_DIR}"

SNAPSHOT_ID="${1:-}"

if [ -z "${SNAPSHOT_ID}" ]; then
    # Cari snapshot cadangan terakhir
    LATEST_SNAPSHOT_DIR=$(ls -td storage/backups/hotfix_* 2>/dev/null | head -n 1 || true)
    if [ -z "${LATEST_SNAPSHOT_DIR}" ]; then
        echo "ERROR: Tidak ditemukan snapshot backup di storage/backups/!"
        exit 1
    fi
    BACKUP_PATH="${LATEST_SNAPSHOT_DIR}"
else
    BACKUP_PATH="storage/backups/hotfix_${SNAPSHOT_ID}"
fi

if [ ! -d "${BACKUP_PATH}" ]; then
    echo "ERROR: Direktori snapshot '${BACKUP_PATH}' tidak ditemukan!"
    exit 1
fi

echo "========================================================================"
echo " [GFVIP] MEMULIHKAN BERKAS KE SNAPSHOT: ${BACKUP_PATH}"
echo "========================================================================"

if [ -f "${BACKUP_PATH}/CopernicusSarAdapter.php.bak" ]; then
    cp -p "${BACKUP_PATH}/CopernicusSarAdapter.php.bak" app/Services/Providers/CopernicusSarAdapter.php
    echo "✓ CopernicusSarAdapter.php dipulihkan."
fi

if [ -f "${BACKUP_PATH}/maritime.php.bak" ]; then
    cp -p "${BACKUP_PATH}/maritime.php.bak" config/maritime.php
    echo "✓ config/maritime.php dipulihkan."
fi

if [ -f "${BACKUP_PATH}/SystemHealthService.php.bak" ]; then
    cp -p "${BACKUP_PATH}/SystemHealthService.php.bak" app/Services/SystemHealthService.php
    echo "✓ SystemHealthService.php dipulihkan."
fi

echo ">>> Merefresh cache konfigurasi..."
php artisan config:clear
php artisan config:cache

if command -v systemctl >/dev/null 2>&1; then
    if systemctl is-active --quiet php-fpm-84 2>/dev/null; then
        systemctl reload php-fpm-84
    elif systemctl is-active --quiet php-fpm 2>/dev/null; then
        systemctl reload php-fpm
    fi
fi

echo "========================================================================"
echo "✓ ROLLBACK BERHASIL. File dikembalikan ke versi sebelum hotfix."
echo "========================================================================"
