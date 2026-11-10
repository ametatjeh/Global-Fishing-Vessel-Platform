# LAPORAN TEKNIS GFVIP
# PRODUCTION HARDENING & BACKUP AUTOMATION REPORT

**Platform:** Global Fishing Vessel Tracking (GFVT)  
**Direktori Proyek:** `C:\XPROJECT\global-fishing-vessel-intelligence`  
**Waktu Eksekusi:** 20 Oktober 2026  
**Status Evaluasi:** **PASS WITH LIMITATIONS**

---

### 1. KONDISI ENVIRONMENT SEBELUM DAN SESUDAH PERUBAHAN

#### Kondisi Awal (Sebelum Perubahan)
- `APP_ENV`: `local`
- `APP_DEBUG`: `true`
- `APP_KEY`: Tersedia (AES-256-CBC)
- Database: PostgreSQL 18.6 + PostGIS 3.6.2 aktif pada `127.0.0.1:5432` (database: `global_fishing_vessel_intelligence`).
- Backup Otomatis: Belum ada script terjadwal maupun manifest pencatatan backup.
- Uji Pemulihan (Disaster Recovery): Belum pernah diuji di lingkungan terisolasi (`NOT VERIFIED`).
- Status Copernicus CDSE: Tercatat `error` pada database akibat belum diverifikasinya token OAuth2 Keycloak terkini.
- Status GFW Events API: Tercatat `authenticated` pada `/datasets`, namun mengembalikan HTTP 403 Forbidden pada `/events` (hak akses dibatasi untuk akun non-research).

#### Kondisi Akhir (Sesudah Perubahan)
- Environment Lokal Terjaga: `APP_ENV=local` dan `APP_DEBUG=true` dipertahankan untuk kebutuhan development lokal aktif, namun panduan hardening produksi telah diintegrasikan langsung pada menu **System Settings** (`/settings`) dengan rekomendasi eksplisit `APP_DEBUG=false` untuk deployment rilis.
- Script Backup Otomatis: Tersedia di `deployment/windows/backup-gfvip.ps1` (dump PostgreSQL custom format `pg_dump -F c` + robocopy non-destruktif SAR storage + rotasi retensi 14 hari).
- Task Scheduler Windows: Terdaftar aktif sebagai task `GFVIP_Automated_Backup` dengan jadwal harian pukul 02:00 AM (Status: `Ready`).
- Uji Pemulihan (Disaster Recovery Test): Teruji 100% pada database terisolasi `gfvip_isolated_recovery_test` via `deployment/windows/verify-recovery.ps1` (Status: **`PASS`**).
- Monitoring Backup di UI: Halaman `/settings` sekarang menampilkan kartu pemantauan *Status Backup Otomatis & Disaster Recovery* yang membaca `latest_backup_manifest.json` dan `latest_recovery_test_report.json` secara real-time.
- Status Copernicus CDSE: Berhasil diverifikasi dan terotentikasi penuh (`authenticated`, latensi: 2.572 ms, OAuth2 Bearer token aktif dan katalog OData merespons valid).
- Status GFW Events API: HTTP 403 tetap dipertahankan secara transparan dan jujur sebagai `ACCESS NOT AUTHORIZED` tanpa mengubahnya menjadi sukses palsu.

---

### 2. PERUBAHAN KONFIGURASI YANG DILAKUKAN

1. **Pembuatan Script Backup Windows:**
   - File: `deployment/windows/backup-gfvip.ps1`
   - Fitur: Membaca kredensial database via PHP runtime (tidak ada password yang ditulis mentah di dalam file script), mengeksekusi `pg_dump -F c -b -v`, menyalin folder `storage/app/sar_products` dan `storage/app/sar_evidence` secara non-destruktif (tanpa flag `/MIR` yang berisiko), membersihkan dump yang berumur lebih dari 14 hari, dan menulis manifest JSON mesin (`latest_backup_manifest.json`).
2. **Pembuatan Script Uji Pemulihan Terisolasi:**
   - File: `deployment/windows/verify-recovery.ps1`
   - Fitur: Membuka database isolasi sementara `gfvip_isolated_recovery_test`, mengeksekusi `pg_restore`, memvalidasi keberadaan 54 kandidat deteksi SAR, 2 scene, kapal, posisi, dan audit log, memverifikasi file crop bukti SAR, mencatat laporan ke `latest_recovery_test_report.json`, lalu menghapus database isolasi tersebut secara aman.
3. **Pendaftaran Windows Task Scheduler:**
   - File: `deployment/windows/register-backup-task.ps1`
   - Task Name: `GFVIP_Automated_Backup` (Daily @ 02:00 AM).
4. **Integrasi Pemantauan di UI:**
   - [SystemController.php](file:///c:/XPROJECT/global-fishing-vessel-intelligence/app/Http/Controllers/SystemController.php): Membaca manifest backup dan laporan recovery untuk disajikan kepada pengguna berwenang (Super Admin).
   - [settings.blade.php](file:///c:/XPROJECT/global-fishing-vessel-intelligence/resources/views/system/settings.blade.php): Menampilkan status backup, timestamp, ukuran dump, jumlah bukti SAR, dan panduan hardening environment produksi.

---

### 3. JADWAL BACKUP DAN STATUS IMPLEMENTASI

- **Nama Tugas:** `GFVIP_Automated_Backup`
- **Mekanisme:** Windows Task Scheduler
- **Pemicu (Trigger):** Harian setiap pukul 02:00 AM lokal (`2026-10-20T02:00:00+07:00`)
- **Status Registrasi:** **`Ready`**
- **Executable:** `powershell.exe -ExecutionPolicy Bypass -WindowStyle Hidden -File "C:\XPROJECT\global-fishing-vessel-intelligence\deployment\windows\backup-gfvip.ps1"`
- **Batas Waktu Eksekusi (Timeout):** 2 jam
- **Status Tindakan:** Berhasil diuji coba eksekusi manual dan menghasilkan file dump valid.

---

### 4. HASIL PEMERIKSAAN BACKUP POSTGRESQL

- **Executable:** `C:\Program Files\PostgreSQL\18\bin\pg_dump.exe` (PostgreSQL 18.6)
- **Format:** Custom Archive PostgreSQL (`-F c`)
- **File Dump yang Dihasilkan:** `C:\backups\gfvip\database\gfvip_db_20261020_023753.dump`
- **Ukuran File Dump:** **283.080 bytes (0.27 MB)**
- **Waktu Eksekusi:** 2.07 detik
- **Exit Code:** `0` (Sukses tanpa error)
- **Cakupan Tabel yang Di-dump:**
  - `satellite_detections` (54 record deteksi SAR lengkap)
  - `satellite_scenes` (2 scene Sentinel-1 lengkap)
  - `vessels` (590 kapal aktif terdaftar)
  - `vessel_positions` (611 posisi telemetri AIS)
  - `audit_logs` (27 log asesmen forensik dan review analis)
  - Seluruh skema PostGIS, tipe data spasial, migration, user, cache, dan data provider.

---

### 5. HASIL BACKUP STORAGE SAR

- **Lokasi Sumber:**
  - `storage/app/sar_products`
  - `storage/app/sar_evidence`
- **Lokasi Tujuan Backup:**
  - `C:\backups\gfvip\sar_storage\sar_products`
  - `C:\backups\gfvip\sar_storage\sar_evidence`
- **Strategi Penyalinan:** `robocopy /E /XO /R:2 /W:3` (Non-destruktif; menyalin file baru/berubah tanpa menghapus file apa pun di tujuan).
- **Jumlah File SAFE Archive di Backup:** **22 file** (termasuk `542e73f3-35ea-4ff6-bc6a-fa71d5d1fee7.zip` berukuran 86.988.229 bytes).
- **Jumlah File Bukti SAR di Backup:** **216 file** (54 direktori kandidat `0001` s/d `0054`, masing-masing lengkap memuat `raw.png`, `sigma0-linear.png`, `sigma0-db.png`, dan `metadata.json`).

---

### 6. HASIL UJI RESTORE (ISOLATED DISASTER RECOVERY TEST)

Pengujian restore dilakukan menggunakan script `deployment/windows/verify-recovery.ps1` pada database terisolasi:

- **Database Uji Terisolasi:** `gfvip_isolated_recovery_test` (dibuat khusus untuk pengujian dan langsung dihapus setelah verifikasi; **database operasional `global_fishing_vessel_intelligence` sama sekali tidak disentuh**).
- **Tool Pemulihan:** `C:\Program Files\PostgreSQL\18\bin\pg_restore.exe` (PostgreSQL 18.6).
- **Exit Code Pemulihan:** `0` (Sukses).
- **Hasil Verifikasi Data di Database Terisolasi:**
  - `satellite_detections`: **54 record** (100% cocok).
  - `satellite_scenes`: **2 record** (100% cocok).
  - `vessels`: **590 kapal** (100% cocok).
  - `vessel_positions`: **611 posisi** (100% cocok).
  - `audit_logs`: **27 record** (100% cocok).
  - Kandidat `542e73f3-35ea-4ff6-bc6a-fa71d5d1fee7-0054`: **Ditemukan dan utuh**.
  - File Crop Bukti SAR (`raw.png`, `sigma0-linear.png`, `sigma0-db.png`, `metadata.json`): **100% terverifikasi di backup**.
- **Pembersihan (Teardown):** Database `gfvip_isolated_recovery_test` di-drop dengan aman setelah verifikasi selesai.
- **Status Disaster Recovery:** **`PASS` (100% VERIFIED)**.

---

### 7. STATUS AUTENTIKASI DAN AKSES CDSE (COPERNICUS)

- **Provider:** Copernicus Data Space Ecosystem (Sentinel-1 SAR)
- **Token Endpoint:** `https://identity.dataspace.copernicus.eu/auth/realms/CDSE/protocol/openid-connect/token`
- **Katalog OData:** `https://catalogue.dataspace.copernicus.eu/odata/v1`
- **Hasil Health Check:**
  - Status: **`authenticated`**
  - Latensi: **2.572,65 ms**
  - Respons: *"Koneksi ke Copernicus Data Space Ecosystem terverifikasi (OAuth2 Bearer token valid dan OData catalog aktif)."*
  - Cache Token: Disimpan aman di cache Laravel selama 1.500 detik.
  - Secret & Kredensial: Tidak diekspos ke log publik atau API response.

---

### 8. STATUS GLOBAL FISHING WATCH (GFW)

- **Provider:** Global Fishing Watch API v3
- **Hasil Health Check:**
  - Endpoint `/datasets`: **HTTP 200 OK** (Autentikasi token berhasil).
  - Endpoint `/events`: **HTTP 403 Forbidden** (Hak akses akun terbatas pada API publik standar; memerlukan persetujuan *Research Access Tier* dari pihak GFW).
  - Status Provider yang Ditampilkan: **`authenticated`** dengan pesan penjelas: *"HTTP 403: Endpoint /events memerlukan Research Access permission"*.
  - Pemisahan Forensik: Status 403 tidak disamarkan sebagai keberhasilan palsu, dan ketiadaan event tidak diinterpretasikan sebagai ketiadaan aktivitas kapal ilegal.

---

### 9. HASIL REGRESSION TESTS

Pengujian otomatis dijalankan dengan `php artisan test`:

- **Total Tests:** **92 passed** (100% lulus).
- **Total Assertions:** **583 assertions**.
- **Waktu Eksekusi:** **5.75 detik**.
- **Cakupan Pengujian:**
  - Auth, Role & Security: 4 tests (viewer di-block dari review/settings, super_admin full access).
  - SAR Evidence Viewer API & Track API: 10 tests.
  - Multi-source SAR-AIS Correlation: 14 tests.
  - Flag State ITU MID Intelligence: 8 tests.
  - Provider Health & Concurrency Lock: 8 tests.
  - Spatial Query Service & GeoJSON layers: 6 tests.
  - Registry & Vessel Search: 3 tests.

---

### 10. KONFIRMASI INTEGRITAS DATA OPERASIONAL

- `satellite_detections`: **54 record** (100% utuh).
- `satellite_scenes`: **2 record** (100% utuh).
- `vessels`: **590 kapal** (bertambah organik dari 566 via live WebSocket AIS).
- `vessel_positions`: **611 posisi** (bertambah organik via live AIS).
- `audit_logs`: **27 log** (100% utuh).
- Larangan Terpenuhi: Tidak ada perintah `migrate:fresh`, `db:wipe`, atau `truncate` yang dieksekusi. Tidak ada data operasional yang dimanipulasi atau dihapus.

---

### 11. DAFTAR RISIKO YANG MASIH TERSISA

1. **Pengaturan Produksi (P1):**
   - Nilai `APP_DEBUG=true` saat ini aktif di lingkungan workstation lokal. Pada saat deployment ke server produksi Ubuntu/Windows Server publik, operator wajib menyetel `APP_DEBUG=false` dan `APP_ENV=production`.
2. **Izin Research Tier GFW Events API (P2):**
   - Endpoint `/events` memerlukan permohonan lisensi penelitian resmi kepada Global Fishing Watch untuk membuka akses data inter-vessel encounters global.
3. **Penyimpanan Backup Offsite (P2):**
   - Saat ini backup disimpan lokal pada partisi `C:\backups\gfvip`. Disarankan untuk mengonfigurasi replikasi otomatis ke penyimpanan terpisah (Network Attached Storage / AWS S3 / Cloud Cold Storage) guna mengantisipasi kegagalan perangkat keras server.

---

### 12. BUKTI EKSEKUSI TINDAKAN

1. **Log Eksekusi Backup:** `C:\backups\gfvip\logs\backup_20261020.log`
2. **Manifest Backup Terkini:** `C:\backups\gfvip\latest_backup_manifest.json`
3. **Log Uji Pemulihan:** `C:\backups\gfvip\logs\recovery_test_20261020.log`
4. **Laporan Uji Pemulihan Terkini:** `C:\backups\gfvip\latest_recovery_test_report.json`
5. **Pendaftaran Task Scheduler:** Terkonfirmasi via `Get-ScheduledTask -TaskName GFVIP_Automated_Backup`.
6. **Tampilan UI:** Terekam pada halaman `/settings` dengan status backup dan panduan hardening.

---

### KEPUTUSAN KESIAPAN AKHIR: `PASS WITH LIMITATIONS`

- **Aplikasi, API & Visualisasi:** **`PASS`** (Bebas error, 92 tests lulus, MapLibre GL JS responsif).
- **Otomasi Backup & Disaster Recovery:** **`PASS`** (Script backup teruji, terjadwal di Windows Task Scheduler, dan verifikasi restore 100% berhasil di database terisolasi).
- **Asuransi Ilmiah & Integrasi Eksternal:** **`PASS WITH LIMITATIONS`** (Kandidat SAR di Samudra Selatan berstatus *Analyst Review Pending* tanpa ground truth independen; GFW Events API menunggu persetujuan Research Access Tier dari provider).
