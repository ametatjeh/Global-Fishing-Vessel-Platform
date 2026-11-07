# GFVIP — DEPLOYMENT PLAN & PRE-PRODUCTION SAFETY PROTOCOL
**Target Production:** `https://gfw.nagakecil.site`  
**Production Server Path:** `/www/wwwroot/gfw.nagakecil.site`  
**Actual Production Database Identity:** `gfvip_1` (PostgreSQL 18 + PostGIS 3.6.2)  
**Staging Database Identity:** `gfvip_staging`  
**Runtime Environment:** Laravel Framework 13.37.0 / PHP 8.4.x (PHP ^8.3 required) / Node.js v22.x LTS / Vite v7.3.7  
**Document Version:** 2.0.0 (Pre-Production Safety Review Edition)  
**Execution Mode:** STAGED & DECOUPLED (Code, Assets, and Migrations are completely isolated)

---

## 1. Executive Summary & Core Safety Mandates

Paket deployment ini merangkum pembaruan komprehensif platform GFVIP yang mencakup:
- Integrasi resmi **Global Fishing Watch (GFW) API v3** dengan sanitasi token dan isolasi kegagalan provider.
- **Phase 10 Fleet Type Classification Service** (Taksonomi 11 kategori standar, pemetaan ITU AIS, proteksi verifikasi manual).
- **Phase 11 Vessel Profile Enrichment Service** (Pencocokan IMO/MMSI, rekonsiliasi multi-sumber, pelacakan konflik & provenansi).
- **Open Waters AIS Map Interface Rebuild** (MapLibre GL JS nautical dark theme, rotasi marker sesuai Heading/COG, sliding panel telemetri kanan, URL hash sync).
- **Live AIS Ingestion Daemon Rebuild** (Node.js ESM WebSocket daemon + endpoint batch terproteksi token).
- **227 pengujian otomatis lulus (1,440 assertions)** dan build aset Vite produksi yang stabil.

### Mandat Keselamatan Produksi yang Tidak Boleh Dilanggar:
1. **Dilarang Menjalankan Operasi Destruktif:** Dilarang keras menjalankan `migrate:fresh`, `db:wipe`, `truncate`, `drop table`, atau destructive SQL.
2. **Isolasi Mutlak Data Produksi:** Dilarang mengimpor atau menyinkronkan 93 data lokal ke database produksi. Data produksi yang ada adalah data asli dan sakral.
3. **Pemisahan Total Deployment Kode dari Migrasi Database:** Eksekusi kode baru tidak boleh secara otomatis menjalankan migrasi skema. Migrasi membutuhkan ulasan dan persetujuan terpisah.
4. **Tidak Ada Eksekusi Purge atau Mass Mutation Otomatis:** Perintah `vessels:purge-cargo-tanker`, `vessels:classify --apply`, dan `gfw:vessels:enrich --apply` **TIDAK DIJALANKAN** saat deployment.
5. **Dynamic Database Identity Resolution:** Seluruh skrip wajib membaca nama database secara dinamis dari berkas `.env` server target (`gfvip_1`), bukan menggunakan nama database yang di-hardcode.

---

## 2. Release Inventory

### Commit Terverifikasi (Branch `main`):
```
b3fb26f docs(deploy): add staging and production deployment package documents
efd4509 feat(gfw,fleet): rebuild GFW v3 service and implement vessel fleet type classification
4ebb0bc feat(vessels,gfw): implement GFW vessel profile enrichment with multi-source reconciliation
e09a57e feat(map,ais): rebuild map interface following Open Waters AIS and synchronize AIS pipeline
97f88ed fix(telemetry): verify live AIS stream ingestion and update tracking status badges
```

### Runtime Requirements Audit:
- **PHP:** PHP 8.4.x aktif (Persyaratan `composer.json`: `"php": "^8.3"`).
- **Laravel Framework:** `laravel/framework: 13.37.0` (Laravel 13, BUKAN Laravel 12).
- **Node.js:** Node.js v22.x LTS (v22.22.3 terverifikasi / minimum Node.js >= 20.x untuk Vite 7 dan Node ESM).
- **PostgreSQL / PostGIS:** PostgreSQL 18 dengan PostGIS 3.6.2 dan ekstensi `uuid-ossp`.

---

## 3. Decoupled 6-Stage Deployment Procedure

Deployment dipisahkan secara tegas menjadi 6 tahap terpisah untuk mencegah eksekusi tanpa persetujuan:

```
┌─────────────────────────────────────────────────────────────┐
│ TAHAP A: Code Deployment & Working Directory Sync           │
│ TAHAP B: Dependency Installation (Composer & NPM)           │
│ TAHAP C: Frontend Production Asset Compilation (Vite v7)     │
│ TAHAP D: Migration Safety Review (Read-Only Dry-Run)        │
│ ─── GERBANG PERSETUJUAN EKSPLISIT ADMINISTRATOR ─────────── │
│ TAHAP E: Migration Execution (Standalone Script)            │
│ TAHAP F: Post-Deployment Verification & Smoke Tests         │
└─────────────────────────────────────────────────────────────┘
```

---

### TAHAP A: Code Deployment & Backup Snapshot (Skrip A)

Jalankan di server produksi (`/www/wwwroot/gfw.nagakecil.site`):

```bash
#!/usr/bin/env bash
set -e
cd /www/wwwroot/gfw.nagakecil.site

echo "=== [TAHAP A1] Membaca Identitas Database Aktual dari .env ==="
DB_NAME=$(grep '^DB_DATABASE=' .env | cut -d '=' -f2 | tr -d '"\r ')
DB_HOST=$(grep '^DB_HOST=' .env | cut -d '=' -f2 | tr -d '"\r ')
DB_USER=$(grep '^DB_USERNAME=' .env | cut -d '=' -f2 | tr -d '"\r ')
echo "Target Database: ${DB_NAME}@${DB_HOST} (User: ${DB_USER})"

if [ -z "${DB_NAME}" ]; then
    echo "ERROR: DB_DATABASE tidak ditemukan di .env! Deployment dihentikan."
    exit 1
fi

echo "=== [TAHAP A2] Membuat Backup Database Pra-Deployment (pg_dump) ==="
BACKUP_FILE="/backup/${DB_NAME}_pre_deploy_$(date +%Y%m%d_%H%M%S).dump"
mkdir -p /backup
pg_dump -h "${DB_HOST:-127.0.0.1}" -U "${DB_USER:-postgres}" -F c -b -v -f "${BACKUP_FILE}" "${DB_NAME}"
echo "✓ Backup berhasil disimpan di: ${BACKUP_FILE}"

echo "=== [TAHAP A3] Mengambil Pembaruan Kode Git ==="
git fetch origin main
git status --short
git merge origin/main --ff-only
echo "✓ Kode berhasil diperbarui ke commit: $(git rev-parse --short HEAD)"
```

---

### TAHAP B & C: Dependency Installation & Asset Build (Skrip B)

```bash
#!/usr/bin/env bash
set -e
cd /www/wwwroot/gfw.nagakecil.site

echo "=== [TAHAP B] Menginstal Dependensi PHP & Node.js ==="
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# Instal dependensi subproject AIS Worker
cd ais
npm install --production
cd ..

# Instal dependensi frontend root
npm ci

echo "=== [TAHAP C] Membangun Aset Frontend Produksi (Vite v7) ==="
npm run build
echo "✓ Kompilasi Vite selesai."

echo "=== [TAHAP C2] Menyegarkan Cache Konfigurasi & Rute ==="
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

---

### TAHAP D: Migration Safety Review (Read-Only Dry-Run)

> [!IMPORTANT]
> Tahap ini HANYA menampilkan query SQL yang akan dijalankan. **TIDAK MENGUBAH BASIS DATA.**

```bash
cd /www/wwwroot/gfw.nagakecil.site
echo "=== [TAHAP D] Simulasi SQL Migrasi Baru (Pretend Mode) ==="
php artisan migrate --pretend
```

**Output harus ditinjau oleh Administrator maritim dan Database Administrator sebelum melangkah ke Tahap E.**

---

### TAHAP E: Standalone Migration Execution (Skrip E)

> [!CAUTION]
> **EKSEKUSI TERPISAH.** Skrip ini TIDAK PERNAH dijalankan secara otomatis oleh skrip deployment. Memerlukan pengetikan konfirmasi interaktif `CONFIRM_MIGRATE`.

```bash
#!/usr/bin/env bash
# ==============================================================================
# GFVIP STANDALONE MIGRATION EXECUTION SCRIPT
# Path: /www/wwwroot/gfw.nagakecil.site/deploy_execute_migrations.sh
# ==============================================================================
set -e
cd /www/wwwroot/gfw.nagakecil.site

DB_NAME=$(grep '^DB_DATABASE=' .env | cut -d '=' -f2 | tr -d '"\r ')
echo "===================================================================="
echo " PERINGATAN: ANDA AKAN MENJALANKAN MIGRASI PADA DATABASE: [${DB_NAME}]"
echo "===================================================================="
echo "Daftar migrasi yang akan dieksekusi:"
php artisan migrate:status | grep -E "Pending|2026_11_07" || true
echo ""
read -p "Ketik 'CONFIRM_MIGRATE' untuk melanjutkan eksekusi skema: " CONFIRMATION

if [ "${CONFIRMATION}" != "CONFIRM_MIGRATE" ]; then
    echo "Eksekusi dibatalkan oleh pengguna. Basis data tidak diubah."
    exit 0
fi

echo "Menjalankan migrasi database..."
php artisan migrate --force

echo "✓ Migrasi berhasil dieksekusi."
php artisan migrate:status
```

---

### TAHAP F: Post-Deployment Verification & Services Restart

```bash
#!/usr/bin/env bash
set -e
cd /www/wwwroot/gfw.nagakecil.site

echo "=== [TAHAP F1] Memuat Ulang Service Daemon ==="
php artisan queue:restart
systemctl reload php8.4-fpm
systemctl reload nginx
systemctl restart ais-node-worker

echo "=== [TAHAP F2] Menjalankan Smoke Test Otomatis ==="
php artisan test --filter=ProviderHealthTest
php artisan test --filter=OpenWatersMapInterfaceTest

echo "✓ Deployment selesai secara aman. Lanjutkan ke manual checklist di POST_DEPLOYMENT_SMOKE_TEST.md."
```

---

## 4. Audit Penonaktifan Purge Command

Perintah `php artisan vessels:purge-cargo-tanker` dan service `VesselPurgeService`:
- **STATUS:** **NON-AKTIF UNTUK PRODUKSI (DEACTIVATED)**.
- **ALASAN:** Menghapus entitas kapal atau posisi telemetri melanggar integritas forensik data maritim GFVIP dan berisiko merusak integritas referensial foreign key (`vessel_encounters`, `satellite_detections`, `investigations`).
- **KEBIJAKAN RILIS:** Command ini tidak didaftarkan pada scheduler, tidak dipanggil dalam skrip deployment apa pun, dan dilindungi dengan flag safety check. Pencegahan kapal cargo dan tanker masuk ke database di masa depan ditangani secara preventif di pintu gerbang ingress (`AisStreamAdapter.php`), BUKAN dengan penghapusan destruktif di basis data.
