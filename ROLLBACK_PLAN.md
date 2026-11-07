# GFVIP — ROLLBACK PLAN & EMERGENCY DISASTER RECOVERY
**Platform:** Global Fishing Vessel Intelligence Platform  
**Target Environment:** Staging & Production (`https://gfw.nagakecil.site`)  
**Production Root Path:** `/www/wwwroot/gfw.nagakecil.site`  
**Safe Baseline Commit (Rollback Target):** `39ccd62` *(Commit sebelum siklus rilis GFW v3 / Map Rebuild)*  
**Current Release Commits:** `97f88ed`, `e09a57e`, `4ebb0bc`, `efd4509`

---

## 1. Rollback Triggers (Kriteria Pemicu)

Tim operasional WAJIB menginisiasi prosedur rollback jika salah satu kondisi berikut terjadi setelah deployment:

1. **Fatal HTTP 500 Spike:** Tingkat kegagalan request portal melebihi 2% dalam 15 menit pertama.
2. **Database Deadlock / Migration Failure:** Migrasi `vessel_pos_dedup_unique` terkunci atau menyebabkan query timeout pada sistem operasional.
3. **Peta Interaktif Blank:** MapLibre GL JS gagal memuat layer vektor atau library JavaScript crash di browser klien.
4. **AIS Pipeline Break:** Node.js worker gagal menulis telemetri kapal atau terjadi inkonsistensi data posisi.
5. **Autentikasi Lumpuh:** Pengguna tidak dapat login melalui sistem kata sandi lokal maupun Google SSO.

---

## 2. Multi-Level Rollback Procedures

```
[Level 1: Configuration & Cache] --> Reset config/view cache & restart workers (Zero downtime)
              │
[Level 2: Frontend & Code Revert]--> Revert commit via Git & re-run npm build (1-2 mins)
              │
[Level 3: Migration Reversal]    --> php artisan migrate:rollback --step=3 (Targeted DDL undo)
              │
[Level 4: Disaster DB Restore]   --> pg_restore / psql import from pre-deployment backup
```

---

### LEVEL 1: Quick Config & Worker Recovery (Ringan)

Gunakan jika terjadi error cache atau worker macet setelah deployment:

```bash
cd /www/wwwroot/gfw.nagakecil.site

# Bersihkan seluruh cache aplikasi
php artisan optimize:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear

# Restart worker background & web service
php artisan queue:restart
systemctl restart ais-node-worker
systemctl reload php8.4-fpm
systemctl reload nginx
```

---

### LEVEL 2: Git Code Rollback (Sedang)

Gunakan jika terdapat bug logika backend atau tampilan frontend rusak:

```bash
cd /www/wwwroot/gfw.nagakecil.site

# 1. Kembali ke commit baseline yang stabil
git checkout 39ccd62

# 2. Re-install dependensi composer
composer install --no-dev --optimize-autoloader

# 3. Rebuild aset frontend lama
npm ci
npm run build

# 4. Refresh cache aplikasi
php artisan optimize
systemctl reload php8.4-fpm
```

---

### LEVEL 3: Database Migration Rollback (Spesifik Skema)

Jika kode dikembalikan ke `39ccd62`, 3 migrasi baru dapat dibatalkan secara aman dan berurutan:

```bash
cd /www/wwwroot/gfw.nagakecil.site

# Periksa status migrasi saat ini
php artisan migrate:status

# Batalkan 3 migrasi terakhir secara terkontrol
php artisan migrate:rollback --step=3

# Verifikasi tabel dan kolom telah kembali ke skema awal:
# - Kolom beam_meters dkk pada tabel vessels terhapus.
# - Tabel prohibited_vessels terhapus.
# - Constraint vessel_pos_dedup_unique terlepas.
```

---

### LEVEL 4: Full Database Restoration (Kondisi Bencana Kritis)

Gunakan HANYA JIKA terjadi korupsi data serius dan backup pra-deployment harus dipulihkan:

> [!CAUTION]
> Tindakan ini akan mengembalikan seluruh database ke kondisi saat backup dibuat. Dapatkan konfirmasi Lead Administrator sebelum menjalankan perintah ini!

```bash
# 1. Aktifkan mode pemeliharaan darurat
php artisan down --secret="gfvip-emergency-bypass"

# 2. Hentikan ingestion AIS untuk mencegah penulisan data baru saat restore
systemctl stop ais-node-worker

# 3. Restore database dari file dump pra-deployment (contoh backup: gfvip_pre_deploy_20261107.dump)
PGPASSWORD="<DB_PASSWORD>" dropdb -h 127.0.0.1 -U postgres gfvip_production
PGPASSWORD="<DB_PASSWORD>" createdb -h 127.0.0.1 -U postgres gfvip_production
PGPASSWORD="<DB_PASSWORD>" pg_restore -h 127.0.0.1 -U postgres -d gfvip_production /backup/gfvip_pre_deploy_20261107.dump

# 4. Bersihkan cache aplikasi
php artisan optimize:clear

# 5. Hidupkan kembali worker dan matikan mode pemeliharaan
systemctl start ais-node-worker
php artisan up
```

---

## 3. Post-Rollback Sanity Verification

Setelah prosedur rollback diselesaikan, lakukan pemeriksaan:
- [ ] Buka `https://gfw.nagakecil.site/health` — pastikan status HTTP 200 OK.
- [ ] Buka `https://gfw.nagakecil.site/vessels` — pastikan daftar kapal tampil normal.
- [ ] Buka `https://gfw.nagakecil.site/map` — pastikan peta memuat koordinat kapal.
- [ ] Periksa log aplikasi di `storage/logs/laravel.log`.

Buat laporan insiden (**Post-Incident Report / PIR**) yang mendokumentasikan akar masalah, durasi downtime, dan rekomendasi perbaikan sebelum deployment ulang dijadwalkan.

