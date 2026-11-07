# LAPORAN TAHAP 4D FINAL: INTEGRITY, RETENTION VALIDATION & DEPLOYMENT READINESS
## Global Fishing Vessel Monitoring Platform (GFVIP)

**Project Path:** `C:\XPROJECT\global-fishing-vessel-intelligence`  
**Target Produksi:** `https://gfw.nagakecil.site`  
**Framework & Environment:** Laravel 13, PHP 8.4, PostgreSQL 18 + PostGIS, Node.js AIS Worker  
**Waktu Audit:** 2026-11-08 03:28 WIB  
**Status Evaluasi Akhir:** **PASS — READY FOR DEPLOYMENT REVIEW**

---

## A. EXECUTIVE SUMMARY

Laporan ini merupakan hasil evaluasi dan audit final menyeluruh (**Tahap 4D Final**) yang menggabungkan tiga pilar pengujian kedaulatan data maritim GFVIP:
1. **Classification Integrity Audit (Tahap 4D.3):** Memverifikasi integritas status klasifikasi, keabsahan bukti AIS ShipType, dan kepatuhan aturan non-konflik.
2. **Retention Safeguard Validation (Tahap 4D.4):** Memverifikasi perlindungan posisi permanen bagi kapal penangkap ikan (*Fishing*), perlindungan *Protective Hold* bagi kapal historis dan kapal bersengketa (*Disputed* / *Under Review*), serta jaminan integritas bukti operasional (SAR, Encounter, Dark Vessel, Investigasi).
3. **Full Regression Testing & Deployment Readiness (Tahap 4D.5):** Memvalidasi seluruh test suite proyek (424 test Laravel + 10 test Node.js), memeriksa aset frontend, serta menyusun rencana dan checklist deployment produksi yang aman.

### Status Akhir & Tingkat Risiko:
- **Tingkat Risiko:** **RENDAH (LOW RISK)**
- **Integritas Database:** **100% AMAN** — 0 baris data terhapus, 0 mutasi destruktif pada database lokal/operasional, simulasi dry-run mengonfirmasi 0 kandidat purge dan 21 posisi dalam Protective Hold.
- **Hasil Pengujian Penuh:** **424 / 424 Tests PASSED (2.708 assertions)** pada Laravel test suite, serta **10 / 10 Tests PASSED** pada Node.js AIS Worker hardening suite.
- **Kesiapan Sistem:** Sistem siap memasuki tahap review deployment administratif.

---

## B. CLASSIFICATION INTEGRITY AUDIT

### 1. Konsistensi Status Klasifikasi
Audit memverifikasi implementasi status klasifikasi pada Model [`Vessel.php`](file:///c:/XPROJECT/global-fishing-vessel-intelligence/app/Models/Vessel.php), [`AisStreamAdapter.php`](file:///c:/XPROJECT/global-fishing-vessel-intelligence/app/Services/Providers/AisStreamAdapter.php), dan [`VesselClassificationReviewController.php`](file:///c:/XPROJECT/global-fishing-vessel-intelligence/app/Http/Controllers/VesselClassificationReviewController.php):

| Status Klasifikasi | Definisi & Perilaku Sistem | Status Retensi Posisi |
|:---|:---|:---:|
| `verified` (Fishing) | Kapal penangkap ikan dengan bukti sah AIS ShipType 30 atau verifikasi manual analis tanpa konflik telemetri. | **Permanen (100% Dilindungi)** |
| `verified` (Non-Fishing) | Kapal kargo, tangki, tunda, rekreasi, atau penumpang dengan bukti sah AIS atau penetapan analis. | Sesuai Kebijakan Tipe (30–90 Hari) |
| `under_review` | Kapal yang mengalami konflik data baru atau memerlukan peninjauan ulang analis. | **Protective Hold** |
| `disputed` (Kategori) | Konflik aktif antara klaim Fishing dengan kode AIS non-fishing (`ais_ship_type_code > 0 && != 30`). | **Protective Hold** |
| `unverified_historical` | Lima MMSI historis terproteksi (`211233660`, `563051800`, `311002359`, `211443110`, `232056936`). | **Protective Hold** |
| `provider_reported` | Status awal dari feed penyedia eksternal, tidak otomatis menjadi verified analis. | **Protective Hold** (jika fishing) |
| `unverified` | Kapal tanpa bukti klasifikasi yang memadai, dilarang otomatis dianggap fishing. | **Protective Hold** (jika fishing) |
| `reclassified` | Status kapal yang klasifikasinya telah diperbaiki secara sah oleh analis. | Sesuai Tipe Baru |
| `rejected` | Kapal dengan bukti perikanan yang ditolak analis karena tidak memadai. | **Protective Hold** (Status Aman) |

### 2. Validasi Konflik Telemetri AIS (Non-Conflict Rule)
1. **ShipType 30:** Merupakan bukti telemetri objektif ITU untuk kapal penangkap ikan (*Fishing*).
2. **ShipType Valid Non-30 (70 Cargo, 80 Tanker, dll):** Dilarang keras dianggap sebagai bukti Fishing.
3. **Konflik Disputed:** Kapal dengan label/tipe `fishing` tetapi memancarkan ShipType non-30 secara mutlak ditolak masuk ke dalam `isVerifiedFishing()`.
4. **Unknown:** Diperlakukan secara independen dengan retensi 30 hari, tidak pernah diasumsikan sebagai Fishing.
5. **Provider Reported:** Dilarang otomatis memperoleh retensi permanen tanpa bukti AIS 30 atau persetujuan analis.

### 3. Validasi Timestamp dan Provenance
- `classification_source`: Menyimpan asal penetapan (`AIS_TYPE_5`, `AIS_TYPE_19`, `AIS_TYPE_24B`, `ANALYST_VERIFICATION`, `ANALYST_RECLASSIFICATION`).
- `ais_ship_type_code`: Menyimpan kode angka asli dari telemetri statis AIS.
- `classified_at`: Timestamp penetapan klasifikasi saat ini.
- `metadata['classification_history']`: Array append-only yang menyimpan seluruh riwayat transisi status, alasan (*reason*), bukti (*evidence*), ID analis, dan timestamp keputusan.
- **Perlindungan Out-of-Order:** Metadata AIS lampau (`$observedAt < $existingClassifiedAt`) diabaikan sehingga tidak dapat meregresikan keputusan analis yang lebih baru.
- **Penanganan Sinyal Kontradiktif:** Jika pesan AIS baru membawa kode non-fishing untuk kapal fishing terverifikasi, sistem tidak menghapus riwayat analis, melainkan memicu status `under_review`, memperbarui `ais_ship_type_code`, dan menahan posisi kapal dalam *Protective Hold*.

---

## C. ADMIN CLASSIFICATION REVIEW AUDIT

### 1. Antarmuka dan Routing
- **Route:** `/vessels/classification-review`, `/vessels/classification-review/{id}`, dan `/vessels/classification-review/{id}/decision` didefinisikan secara eksplisit sebelum wildcard `{id}`.
- **Middleware:** Dilindungi oleh `role:super_admin,admin,analyst`. Pengguna tanpa izin (termasuk akun dinonaktifkan atau peran `viewer`) secara konsisten menerima respons HTTP 403 atau dialihkan ke login.
- **Navigasi:** Navigasi sidebar terintegrasi pada [`resources/views/layouts/app.blade.php`](file:///c:/XPROJECT/global-fishing-vessel-intelligence/resources/views/layouts/app.blade.php) dengan badge jumlah kapal berkonflik (*disputed*).
- **Fitur Form & Filter:**
  - Pencarian fleksibel: Nama kapal, MMSI, IMO, dan Callsign.
  - Filter multi-dimensi: `verification_status`, `vessel_type`, `conflict_status` (*disputed only*), dan `protective_hold`.
  - Pagination server-side dengan retensi parameter query string (`withQueryString()`).

### 2. Integritas Keputusan Analis & Audit Log
- **Validasi Server-Side:**
  - Alasan keputusan (`reason`) wajib diisi dan bermakna (minimal 5 karakter).
  - Bukti pendukung (`evidence`) wajib dicantumkan untuk verifikasi atau reklasifikasi.
  - Percobaan melakukan "Verify Fishing" pada kapal dengan kode AIS non-fishing (70/80) secara mutlak ditolak oleh validasi backend dengan pesan kesalahan jelas.
- **Transaksi Atomik (`DB::transaction`):**
  - Pembaruan model `Vessel` dan pembuatan entri pada tabel `audit_logs` dieksekusi dalam satu transaksi database.
  - Teruji pada Test 19: jika terjadi kegagalan penyimpanan pada tabel audit log, seluruh pembaruan klasifikasi kapal dibatalkan (*rollback*).
- **Sifat Append-Only:**
  - Tabel `audit_logs` dan `metadata['classification_history']` bersifat murni append-only. Keputusan historis tidak pernah dihapus atau di-overwrite.

---

## D. RETENTION SAFEGUARDS AUDIT

### 1. Validasi Aturan Retensi Posisi
Evaluasi pada [`DataRetentionPolicyService.php`](file:///c:/XPROJECT/global-fishing-vessel-intelligence/app/Services/DataRetentionPolicyService.php):
- **Fishing Permanen:** Posisi kapal *Verified Fishing* dikecualikan secara mutlak dari query kandidat purge (`candidates = 0`).
- **Protective Hold:** Seluruh posisi kapal berstatus *Disputed*, *Under Review*, *Unverified Historical*, dan *Unverified Fishing* ditahan dalam `total_held` dan tidak pernah disentuh oleh operasi retensi.
- **Pembersihan Otomatis (Automatic Cleanup):** Tetap berstatus **NONAKTIF** (`is_automatic_purge_enabled = false`).

### 2. Perlindungan Data Operasional & Bukti Forensik
Pengujian memverifikasi bahwa posisi dan kapal yang memiliki relasi operasional berikut tidak dapat dihapus:
- Deteksi satelit SAR ([`SatelliteDetection`](file:///c:/XPROJECT/global-fishing-vessel-intelligence/app/Models/SatelliteDetection.php))
- Pertemuan kapal di laut ([`VesselEncounter`](file:///c:/XPROJECT/global-fishing-vessel-intelligence/app/Models/VesselEncounter.php))
- Anomali AIS mati / Dark Vessel gap indicators
- Berkas investigasi analis ([`Investigation`](file:///c:/XPROJECT/global-fishing-vessel-intelligence/app/Models/Investigation.php))
- Peristiwa kepatuhan ([`ComplianceEvent`](file:///c:/XPROJECT/global-fishing-vessel-intelligence/app/Models/ComplianceEvent.php))
- Rekaman audit log

### 3. Verifikasi Lima Kapal Historis
Lima MMSI historis terproteksi:
- `211233660` (WESERLOTSE)
- `563051800` (MAERSK MONTE VERDE)
- `311002359` (XIN TAI YUAN)
- `211443110` (FRANKEN)
- `232056936` (HANS)

Kelima kapal terverifikasi 100% berada dalam Protective Hold (`isHistoricalProtected() = true`), dan seluruh posisinya terlindungi dari siklus retensi.

### 4. Hasil Audit Simulasi Dry-Run (PostgreSQL Lokal Operasional)
Perintah: `php artisan ais:prune-telemetry --dry-run`

```
====================================================================
 GFVIP - AIS HISTORICAL TELEMETRY RETENTION ENGINE
 Perlindungan Permanen Kapal Penangkap Ikan (Fishing 100% Protected)
====================================================================
[MODE SIMULASI / DRY-RUN] Menghitung kandidat retensi tanpa mengubah database...

Total Posisi AIS Tersimpan: 97
Posisi Fishing Terverifikasi (Permanent): 57 (0 dihapus / 100% aman)
Posisi Fishing Belum Terverifikasi (Protective Hold): 21 (0 dihapus / ditahan)
Lima Kapal Historis Terlindungi: 5 kapal (5 posisi aman)
Kapal Bukti Operasional Terlindungi (SAR / Encounter / Dark Vessel / Investigasi): 0
Posisi Terkait Dark Vessel Gap Indicator Terlindungi: 0

+----------------------------------------+-----------+----------------------+----------------+----------------+--------------------+
| Tipe Kapal                             | Kebijakan | Batas Waktu (Cutoff) | Kandidat Purge | Ditahan (Hold) | Status Keamanan    |
+----------------------------------------+-----------+----------------------+----------------+----------------+--------------------+
| Fishing / Kapal Penangkap Ikan         | Permanen  | —                    | 0              | 21             | PERMANEN (FISHING) |
| Cargo / Kapal Kargo                    | 90 Hari   | 2026-08-09 20:27:14  | 0              | 0              | Tidak Ada Data     |
| Tanker / Kapal Tangki                  | 90 Hari   | 2026-08-09 20:27:14  | 0              | 0              | Tidak Ada Data     |
| Passenger / Kapal Penumpang            | 60 Hari   | 2026-09-08 20:27:14  | 0              | 0              | Tidak Ada Data     |
| Tug / Kapal Tunda                      | 60 Hari   | 2026-09-08 20:27:14  | 0              | 0              | Tidak Ada Data     |
| Pleasure Craft / Kapal Pesiar Rekreasi | 30 Hari   | 2026-10-08 20:27:14  | 0              | 0              | Tidak Ada Data     |
| Other / Tipe Kapal Lainnya             | 90 Hari   | 2026-08-09 20:27:14  | 0              | 0              | Tidak Ada Data     |
| Unknown / Belum Terklasifikasi         | 30 Hari   | 2026-10-08 20:27:14  | 0              | 0              | Tidak Ada Data     |
+----------------------------------------+-----------+----------------------+----------------+----------------+--------------------+

Total Posisi Memenuhi Syarat Purge: 0
Total Posisi Ditahan (Protective Hold & Evidence): 21
Estimasi Kapasitas Disk Dibebaskan: 0 B

Simulasi selesai. Tidak ada data yang dihapus dari database.
====================================================================
```

---

## E. AIS WORKER & INGESTION AUDIT

Audit pada [`ais/worker.mjs`](file:///c:/XPROJECT/global-fishing-vessel-intelligence/ais/worker.mjs) dan pipeline backend:
1. **Dukungan Format Pesan AIS:**
   - **Type 5 (ShipStaticData):** Diteruskan sebagai metadata statis ke backend.
   - **Type 19 (Extended Class B Position Report):** Dual ingestion terverifikasi — metadata diteruskan ke backend meskipun posisi berada dalam batas wilayah yang diblokir.
   - **Type 24 Part B:** Membawa metadata `ship_type` dan diterjemahkan secara akurat.
   - **Type 27 (Long-Range Position):** Mengingesti posisi tanpa mengasumsikan kapal sebagai fishing.
2. **Hardening & Ketahanan Gangguan Jaringan:**
   - **In-Flight Batch Buffer:** Pesan antrean tidak dihapus sebelum menerima konfirmasi HTTP 200 dengan payload konfirmasi yang valid dari backend.
   - **Exponential Backoff:** Diterapkan pada kegagalan koneksi, HTTP 429 (rate limited), dan HTTP 500.
   - **Disseksi HTTP 422:** Membedah batch pesan untuk mengisolasi frame rusak tanpa membuang pesan valid lainnya.
   - **True LRU Cache:** Manajemen memori cache lokal mengeliminasi entri berdasarkan frekuensi akses terakhir, bukan sekadar durasi waktu.
   - **Perlindungan Snapshot Prohibited MMSI:** Mencegah penurunan versi (*stale snapshot rollback*), serta mendukung pagination kursor dan pembatalan blokir (*revocation*).
   - **Persistensi Disk Antrean:** Antrean pesan disimpan ke disk saat sinyal shutdown (SIGTERM/SIGINT) dan dipulihkan kembali saat proses restart.

---

## F. DATABASE & SECURITY AUDIT

1. **Migration & Struktur Database:**
   - Migration `2026_11_08_020000_add_classification_provenance_to_vessels_table.php` bersifat murni aditif (*additive-only*), tanpa menggunakan sintaks `after()` yang tidak didukung PostgreSQL.
   - Tidak ada operasi `DROP`, `TRUNCATE`, atau penghapusan kolom historis.
   - Seluruh indeks foreign key dan unique constraint terpasang secara tepat.
2. **Keamanan & Otorisasi:**
   - Perlindungan CSRF aktif pada seluruh formulir POST/PATCH.
   - Akses meja review klasifikasi dibatasi hanya untuk role `super_admin`, `admin`, dan `analyst`.
   - Token internal AIS Ingestion (`X-AIS-Token`) tervalidasi ketat pada endpoint `/api/telemetry/ais/batch` dan `/api/telemetry/ais/prohibited-mmsis`.
   - Tidak ada kebocoran token atau kredensial rahasia pada error log maupun antarmuka pengguna.
   - Seluruh input teks (alasan, bukti, nama kapal) disanitasi terhadap risiko Cross-Site Scripting (XSS) dan SQL Injection melalui Eloquent ORM parameterized queries.

---

## G. REGRESSION TEST RESULTS

### 1. Ringkasan Eksekusi Seluruh Test
Audit mengeksekusi 100% test suite pada repositori lokal:

| Test Suite / Perintah | Sub-sistem yang Diuji | Status | Test / Assertion | Durasi |
|:---|:---|:---:|:---:|:---:|
| `php artisan test --filter=AisClassificationReviewTest` | Fitur Review Klasifikasi & 20 Skenario Wajib | **PASS** | 20 / 89 | 2.20s |
| `php artisan test --filter=AisRetentionSafeguardTest` | 24 Safeguard Retensi & Konflik Kapal | **PASS** | 24 / 119 | 1.56s |
| `php artisan test --filter=DataRetentionPolicyTest` | Kebijakan Retensi & Proteksi Fishing | **PASS** | 9 / 42 | 1.25s |
| `php artisan test --filter=AisPhase4aIngestionAndSyncTest` | Ingestion Provenance & Sync Prohibited | **PASS** | 9 / 76 | 1.10s |
| `node ais/test_worker_hardening.mjs` | Node.js AIS Worker Hardening (10 Skenario) | **PASS** | 10 / 10 | 1.48s |
| `npm run build` | Kompilasi Aset Frontend Vite v7.3.7 | **PASS** | 63 modules | 3.86s |
| **`php artisan test` (SELURUH SISTEM)** | **Seluruh Modul Aplikasi Laravel GFVIP** | **PASS** | **424 / 2.708** | **32.33s** |

### 2. Analisis & Resolusi Temuan Uji Regresi
Selama eksekusi awal test suite menyeluruh, ditemukan 2 ketidaksesuaian minor yang telah diperbaiki secara tuntas:
- **Temuan 1 (Pembedaan Ingestion Kapal Baru vs Kapal Eksisting):** Pada `AisStreamAdapter.php`, logika penolakan kapal non-fishing diperjelas: pesan statis untuk kapal baru yang membawa tipe kargo/tangki/rekreasi ditolak (`return null`) dan didaftarkan ke `ProhibitedVessel`, sedangkan kapal yang **telah terdaftar sebelumnya** tetap diperbarui metadata statisnya agar konflik klasifikasi dapat terdeteksi dan masuk ke *Under Review*.
- **Temuan 2 (String Label Legenda Peta ZEE ASEAN):** Teks legenda pada `resources/views/map/index.blade.php` diselaraskan agar memuat teks standar `Undelimited / Unresolved (Median Line)` sehingga memenuhi asersi uji peta dan tampilan pengguna.

---

## H. DEPLOYMENT READINESS AUDIT

Target Produksi: `https://gfw.nagakecil.site`

### Checklist Rencana Deployment Produksi:
> [!IMPORTANT]
> Seluruh langkah di bawah ini merupakan **dokumen rencana administratif** dan **BELUM DIJALANKAN**. Tidak ada perubahan yang dieksekusi pada server produksi.

1. **Pemeriksaan Pra-Deployment:**
   - [ ] Verifikasi ketersediaan cadangan (*backup*) penuh database PostgreSQL produksi (`pg_dump`).
   - [ ] Verifikasi cadangan direktori aplikasi dan file konfigurasi `.env`.
   - [ ] Pastikan `APP_ENV=production` dan `APP_DEBUG=false` pada `.env` produksi.
2. **Penerapan Kode & Migrasi:**
   - [ ] Pull branch perubahan yang telah disetujui pengguna.
   - [ ] Jalankan migration database aditif: `php artisan migrate --force`.
   - [ ] Kompilasi aset frontend di server atau transfer build: `npm run build`.
   - [ ] Optimalkan cache Laravel:
     ```bash
     php artisan config:cache
     php artisan route:cache
     php artisan view:cache
     ```
3. **Penyelarasan Worker AIS Daemon:**
   - [ ] Restart worker AIS systemd daemon: `systemctl restart ais-worker` (atau `pm2 restart ais-worker`).
   - [ ] Verifikasi file log daemon: pastikan disk queue pulih tanpa pesan hilang dan sinkronisasi prohibited MMSI berhasil.
4. **Smoke Testing Pasca-Deployment:**
   - [ ] Buka halaman `/vessels/classification-review` dan pastikan metrik serta tabel termuat sempurna.
   - [ ] Buka detail salah satu kapal berkonflik dan pastikan tombol aksi review berfungsi.
   - [ ] Verifikasi endpoint `/api/telemetry/ais/prohibited-mmsis` merespons dengan header otorisasi yang valid.
   - [ ] Jalankan audit retensi mode simulasi: `php artisan ais:prune-telemetry --dry-run` (pastikan kandidat purge tetap 0).
5. **Prosedur Rollback Darurat:**
   - Jika terjadi kendala operasional, kembalikan kode ke commit sebelumnya (`git checkout <previous_commit>`).
   - Rollback migration jika diperlukan: `php artisan migrate:rollback --step=1`.
   - Jalankan `php artisan config:clear && php artisan cache:clear`.
   - Restart worker daemon.

---

## I. DAFTAR FILE YANG DIBUAT DAN DIUBAH

### File Baru:
1. `app/Http/Controllers/VesselClassificationReviewController.php`
2. `resources/views/vessels/classification_review/index.blade.php`
3. `resources/views/vessels/classification_review/show.blade.php`
4. `tests/Feature/AisClassificationReviewTest.php`
5. `tests/Feature/AisPhase4aIngestionAndSyncTest.php`
6. `tests/Feature/AisRetentionSafeguardTest.php`
7. `ais/test_worker_hardening.mjs`
8. `database/migrations/2026_11_08_020000_add_classification_provenance_to_vessels_table.php`
9. Dokumen Laporan Arsitektur & Audit (`AIS_PHASE_4D_INITIAL_AUDIT_REPORT.md`, `AIS_PHASE_4D_CLASSIFICATION_REVIEW_REPORT.md`, dll.)

### File Diubah:
1. `app/Models/Vessel.php` (menambahkan scope disputed, relasi auditLogs, accessor konflik)
2. `app/Services/Providers/AisStreamAdapter.php` (safeguard pesan kontradiktif, dual ingestion)
3. `app/Services/DataRetentionPolicyService.php` (pendelegasian scope model untuk non-konflik)
4. `routes/web.php` (mendaftarkan route classification-review)
5. `resources/views/layouts/app.blade.php` (navigasi sidebar classification review)
6. `resources/views/map/index.blade.php` (penyelarasan teks legenda ZEE ASEAN)
7. `ais/worker.mjs` (in-flight batch, snapshot sync, disk queue)

---

## J. FINAL DECISION

```
========================================================================================
                   STATUS KELAYAKAN AKHIR TAHAP 4D FINAL:
                  PASS — READY FOR DEPLOYMENT REVIEW
========================================================================================
```

**Justifikasi Evaluasi:**
1. Seluruh 20 skenario uji meja review analis dan 24 skenario perlindungan retensi maritim lulus 100%.
2. Seluruh 424 pengujian pada suite Laravel (`php artisan test`) lulus tanpa ada kegagalan maupun peringatan (2.708 assertions).
3. 10 skenario ketahanan AIS Worker Node.js lulus dengan status hijau.
4. Simulasi dry-run mengonfirmasi bahwa 0 data terhapus dan 5 kapal historis 100% aman.
5. Pembersihan otomatis (*Automatic Cleanup*) tetap dinonaktifkan secara aman.
6. Kode terisolasi di repositori lokal tanpa perubahan di server produksi.

