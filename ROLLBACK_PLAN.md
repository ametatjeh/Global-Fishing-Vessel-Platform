# GFVIP — ROLLBACK PLAN & EMERGENCY RECOVERY PROTOCOL
**Platform:** Global Fishing Vessel Intelligence Platform  
**Target Environment:** Staging & Production (`https://gfw.nagakecil.site`)  
**Production Server Path:** `/www/wwwroot/gfw.nagakecil.site`  
**Actual Production Database Identity:** `gfvip_1` (PostgreSQL 18 + PostGIS 3.6.2)  
**Safe Baseline Commit (Rollback Target):** `39ccd62`  
**Current Release Commits:** `97f88ed`, `e09a57e`, `4ebb0bc`, `efd4509`, `b3fb26f`

---

## 1. Prinsip Fundamental Rollback: Code vs Database

> [!CRITICAL]
> **PENTING: JANGAN MENCAMPURADUKKAN ROLLBACK KODE DENGAN ROLLBACK DATABASE!**
> - **Code Rollback (Aman & Non-Destruktif):** Mengembalikan kode Git ke commit `39ccd62` dan mem-rebuild aset Vite. Operasi ini **TIDAK MENGHAPUS DATA** apa pun di basis data dan aman dilakukan jika terjadi bug antarmuka atau error logika PHP.
> - **Migration Rollback (`migrate:rollback`) (Destruktif terhadap Kolom Baru):** Membatalkan migrasi akan mengeksekusi `dropColumn` pada tabel `vessels` (menghapus `beam_meters`, `engine_power_kw`, `gfw_vessel_id`, dll) dan `dropTable` pada `prohibited_vessels`. **Seluruh data baru yang telah diisi sejak deployment akan hilang permanen!** Jangan menjalankan migration rollback jika masalahnya hanya terletak pada tampilan frontend atau worker script.

---

## 2. Kriteria Pemicu Rollback (Rollback Triggers)

Prosedur rollback diaktifkan jika:
1. Terjadi lonjakan HTTP 500 fatal (> 2% request) yang tidak terselesaikan dengan pembersihan cache.
2. Peta interaktif MapLibre GL JS blank total di browser klien.
3. Node.js AIS worker gagal memproses stream dan memicu deadlock di basis data.
4. Terjadi kegagalan integritas data posisi atau korupsi skema.

---

## 3. Prosedur Bertingkat (Multi-Level Rollback)

### LEVEL 1: Quick Config & Worker Recovery (Zero Downtime)

Gunakan jika terjadi error cache atau worker macet setelah deployment:

```bash
cd /www/wwwroot/gfw.nagakecil.site

echo "=== [LEVEL 1] Membersihkan Seluruh Cache Aplikasi ==="
php artisan optimize:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear

echo "=== [LEVEL 1] Memuat Ulang Daemon Services ==="
php artisan queue:restart
systemctl restart ais-node-worker
systemctl reload php8.4-fpm
systemctl reload nginx
```

---

### LEVEL 2: Git Code Rollback (Aman / Non-Destruktif terhadap Data)

Gunakan jika terdapat bug backend PHP atau tampilan peta rusak, **tanpa menyentuh skema database**:

```bash
cd /www/wwwroot/gfw.nagakecil.site

echo "=== [LEVEL 2] Mengembalikan Kode ke Commit Stabil: 39ccd62 ==="
git checkout 39ccd62

echo "=== [LEVEL 2] Re-install Dependensi Composer ==="
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

echo "=== [LEVEL 2] Rebuild Aset Frontend Lama ==="
npm ci
npm run build

echo "=== [LEVEL 2] Refresh Cache Aplikasi ==="
php artisan optimize
systemctl reload php8.4-fpm
```

*Catatan: Kolom baru di database tetap ada, namun kode lama akan mengabaikannya secara aman.*

---

### LEVEL 3: Targeted Schema Rollback (Hanya Jika Skema Rusak)

Jalankan HANYA JIKA migrasi baru terbukti menyebabkan kerusakan struktur data:

```bash
cd /www/wwwroot/gfw.nagakecil.site

# Periksa status migrasi aktif
php artisan migrate:status

# Batalkan tepat 3 migrasi terakhir
php artisan migrate:rollback --step=3
```

---

### LEVEL 4: Full Disaster Database Restoration (Pemulihan Bencana Kritis)

Gunakan HANYA JIKA terjadi korupsi data menyeluruh dan database harus dikembalikan ke snapshot pra-deployment:

> [!CAUTION]
> Memulihkan dump akan menghapus seluruh perubahan data yang terjadi sejak waktu backup dibuat! Wajib mendapat persetujuan Lead Administrator.

```bash
#!/usr/bin/env bash
set -e
cd /www/wwwroot/gfw.nagakecil.site

DB_NAME=$(grep '^DB_DATABASE=' .env | cut -d '=' -f2 | tr -d '"\r ')
DB_HOST=$(grep '^DB_HOST=' .env | cut -d '=' -f2 | tr -d '"\r ')
DB_USER=$(grep '^DB_USERNAME=' .env | cut -d '=' -f2 | tr -d '"\r ')

# 1. Aktifkan mode pemeliharaan
php artisan down --secret="gfvip-emergency-bypass"

# 2. Hentikan ingestion AIS untuk mencegah penulisan data baru
systemctl stop ais-node-worker

# 3. Cari berkas dump pra-deployment terbaru
LATEST_BACKUP=$(ls -t /backup/${DB_NAME}_pre_deploy_*.dump | head -n1)
echo "Menggunakan berkas cadangan: ${LATEST_BACKUP}"

if [ ! -f "${LATEST_BACKUP}" ]; then
    echo "ERROR: Berkas backup tidak ditemukan di /backup!"
    exit 1
fi

# 4. Restore database PostgreSQL secara bersih
echo "Memulihkan database [${DB_NAME}]..."
dropdb -h "${DB_HOST:-127.0.0.1}" -U "${DB_USER:-postgres}" "${DB_NAME}"
createdb -h "${DB_HOST:-127.0.0.1}" -U "${DB_USER:-postgres}" "${DB_NAME}"
pg_restore -h "${DB_HOST:-127.0.0.1}" -U "${DB_USER:-postgres}" -d "${DB_NAME}" "${LATEST_BACKUP}"

# 5. Aktifkan PostGIS kembali jika belum ada di template
psql -h "${DB_HOST:-127.0.0.1}" -U "${DB_USER:-postgres}" -d "${DB_NAME}" -c "CREATE EXTENSION IF NOT EXISTS postgis;"

# 6. Bersihkan cache dan hidupkan aplikasi kembali
php artisan optimize:clear
systemctl start ais-node-worker
php artisan up

echo "✓ Pemulihan database darurat selesai."
```
