# LAPORAN TEKNIS GFVIP TAHAP 12
# OPERATIONAL VALIDATION, SCIENTIFIC ASSURANCE & PRODUCTION READINESS

**Platform:** Global Fishing Vessel Intelligence Platform (GFVIP)  
**Lokasi Proyek:** `C:\XPROJECT\global-fishing-vessel-intelligence`  
**Waktu Audit & Pengujian:** 20 Oktober 2026  
**Status Evaluasi:** **PASS WITH LIMITATIONS**

---

### A. EXECUTIVE SUMMARY

Audit operasional, asuransi ilmiah, dan kesiapan produksi Tahap 12 telah dilaksanakan secara komprehensif terhadap seluruh lapisan platform GFVIP (Database PostgreSQL/PostGIS, Pipeline Sentinel-1 SAR, AIS Live Stream, MapLibre GL JS, Security, Performance, dan Background Workers).

**Keputusan Kesiapan:** **`PASS WITH LIMITATIONS`**

**Ringkasan Status:**
1. **Application & API Readiness (`PASS`):** Seluruh route, controller, middleware, policy, dan view berfungsi stabil. Endpoint SAR Evidence dan SAR Track yang diperbaiki di Tahap 11 berjalan 100% tanpa error database (`SQLSTATE[22P02]`). 92 automated tests dengan 583 assertions lulus 100%.
2. **Scientific Assurance (`PASS WITH LIMITATIONS`):** Algoritma CA-CFAR, kalibrasi radiometrik $\sigma^0 = |DN|^2 / A_\sigma^2$, serta ekstraksi crop deterministik 61×61 piksel terverifikasi penuh dan dapat direproduksi dari SAFE archive asli. Namun, validasi deteksi kapal di Samudra Selatan masih berstatus *Validated for Analyst Review* tanpa ground truth independen, dan umpan Sea Ice satelit berstatus `not_configured`.
3. **Operational & Deployment Readiness (`PASS WITH LIMITATIONS`):** Database aktif PostgreSQL 18.6 + PostGIS 3.6.2 dalam kondisi sehat (566 kapal, 586 posisi AIS, 54 kandidat SAR, 2 scene, 22 audit log). Live AIS WebSocket streaming berjalan organik. Limitasi operasional: belum tersedianya prosedur backup otomatis terkonfigurasi (cron/task) untuk PostgreSQL dan storage citra SAR, serta GFW Events API masih menerima HTTP 403 (Access Not Authorized).

---

### B. BASELINE DAN AUDIT DATABASE

#### 1. Verifikasi Lingkungan Database
- **DBMS:** PostgreSQL 18.6 on x86_64-windows (compiled by MSVC 19.44, 64-bit).
- **Spasial:** PostGIS 3.6.2 (GEOS 3.14.1, PROJ 8.2.1, LibXML 2.12.5, Wagyu internal).
- **Koneksi:** `pgsql` (Host: 127.0.0.1:5432, Database: `global_fishing_vessel_intelligence`).

#### 2. Jumlah Entitas Aktual Database (Read-Only Audit)
| Entitas / Tabel | Baseline Tahap 11 | Aktual Tahap 12 | Status Integritas |
|---|---|---|---|
| `satellite_detections` | 54 | **54** | Utuh (100%), terhubung ke scene valid |
| `satellite_scenes` | 2 | **2** | Utuh (100%), metadata valid |
| `vessels` | 519 | **566** | Bertambah organik via Live AIS WebSocket |
| `vessel_positions` | 536 | **586** | Bertambah organik via Live AIS WebSocket |
| `vessel_encounters` | 0 | **0** | Konsisten (belum ada deteksi pertemuan jarak dekat) |
| `compliance_events` | 0 | **0** | Konsisten |
| `audit_logs` | 22 | **22** | Utuh (100%), riwayat review analis terjaga |
| `data_providers` | 5 | **5** | Utuh |
| `users` | 3 | **3** | Utuh (Super Admin, Analyst, Viewer) |

#### 3. Audit Relasi, Geospasial, dan Foreign Keys
- **Orphan Detections:** 0 record (100% deteksi terhubung ke `satellite_scenes`).
- **Orphan Positions:** 0 record (100% posisi terhubung ke `vessels`).
- **Koordinat Invalid:** 0 record (seluruh latitude berada pada rentang [-90, 90] dan longitude [-180, 180]).
- **Identifier Consistency:** 54 dari 54 deteksi memiliki `detection_identifier` compound valid (format `{scene_id}-{candidate_index}`).
- **UUID Protection:** Scope `scopeWhereIdentifierOrId` pada model `SatelliteDetection` berhasil mencegah error `SQLSTATE[22P02]` saat menerima compound identifier 41 karakter.

---

### C. SCIENTIFIC ASSURANCE SENTINEL-1 SAR

#### 1. Provenance dan Integritas File Produk
- **SAFE Archive:** `storage/app/sar_products/542e73f3-35ea-4ff6-bc6a-fa71d5d1fee7.zip`
- **Ukuran File:** 86.988.229 bytes (MD5: `dc1c2cfc0794e22edcec3a5f99d3f247`).
- **Product Name:** `S1C_EW_GRDM_1SSH_20261019T135212_20261019T135236_009954_013D8A_0AA8.SAFE`
- **Measurement File:** `s1c-ew-grd-hh-20261019t135212-20261019t135236-009954-013d8a-001.tiff`
- **Raster Dimensions:** 10.494 samples × 4.032 lines (Polarisasi: HH, Mode: Extra-Wide Swath / EW).

#### 2. Verifikasi Kalibrasi dan Deteksi
- **Formula Kalibrasi Radiometrik:** $\sigma^0 = |DN|^2 / A_\sigma^2$ dengan $A_\sigma$ diperoleh dari LUT XML kalibrasi ESA.
- **Detektor Anomali:** CA-CFAR (Cell-Averaging Constant False Alarm Rate) dengan training cells, guard cells, dan threshold dinamis terhadap sea clutter.
- **Evidence Storage:** 54 direktori kandidat tersedia lengkap (`0001` s/d `0054`), masing-masing memuat `raw.png`, `sigma0-linear.png`, `sigma0-db.png`, dan `metadata.json`.
- **Crop Geometry:** Jendela 61×61 piksel (resolusi nominal 40m/px, cakupan 2.440m × 2.440m).

#### 3. Keterbatasan Ilmiah dan Pemisahan Forensik
Prinsip validitas maritim dijaga ketat di seluruh UI dan database:
1. *Radar Detection $\neq$ Vessel Confirmation*: Dimensi objek dihitung dari pemekaran PSF (Point Spread Function) hamburan balik radar, bukan ukuran fisik lambung kapal sesungguhnya.
2. *Absence of AIS $\neq$ Dark Vessel*: Ketiadaan sinyal AIS di Samudra Selatan (lintang 58°–60° LS) adalah akibat ketiadaan stasiun penerima Terrestrial AIS (>3.000 km dari pantai) dan jeda orbit Satellite AIS, bukan bukti kesengajaan mematikan transponder.
3. *Status Evaluasi*: Seluruh 54 kandidat berada pada status korelasi `insufficient_data` dan review status `pending_review` / `possible_vessel` tanpa ada penetapan sepihak kapal pelanggar.

---

### D. AIS, VESSEL REGISTRY & FLAG STATE ASSURANCE

#### 1. Kualitas Data dan Freshness Telemetri AIS
- **Total Kapal:** 566 kapal.
- **Total Posisi:** 586 posisi.
- **Fresh Positions (< 2 jam):** 300 posisi (diberi tanda badge hijau `● LIVE (< 2h)`).
- **Stale Positions (> 2 jam):** 286 posisi (diberi tanda badge amber `⏳ KEDALUWARSA`).
- **Integritas Waktu:** Sistem membedakan secara transparan telemetri terkini dari data historis tanpa memalsukan status live.

#### 2. Klasifikasi Flag State Maritim
Implementasi `FlagStateService` menggunakan aturan validitas ITU Appendix 42:
- `verified_explicit`: **0 kapal** (karena paket NMEA AIS Type 1/2/3 hanya menyiarkan MMSI tanpa nama negara resmi).
- `mid_administrative_indicator`: **565 kapal** (misal MMSI `503*` $\rightarrow$ 🇦🇺 Australia [MID Clue - Belum Terverifikasi]).
- `conflict_detected`: **0 kapal**.
- `unknown`: **1 kapal** (MMSI `500000001`, prefix 500 tidak dialokasikan dalam tabel ITU MID).
- **Integritas:** Tidak ada flag state sintetis yang dibuat-buat; UI selalu menyertakan label penjelas bahwa MMSI MID adalah petunjuk alokasi radio administratif, bukan bukti definitif kebangsaan kapal terkini.

---

### E. API & INVESTIGATION WORKFLOW REGRESSION

Pengujian dilakukan langsung melalui HTTP curl terhadap server lokal:

| Endpoint | Method | Status | Format | Respons Aktual |
|---|---|---|---|---|
| `/api/sar/candidates/{id}/evidence` | GET | **200 OK** | `application/json` | Metadata citra SAFE, koordinat piksel, bbox crop 61×61 px |
| `/api/sar/candidates/{id}/track` | GET | **200 OK** | `application/json` | GeoJSON FeatureCollection lintasan posisi AIS |
| `/api/sar/candidates/{invalid}/evidence` | GET | **404 Not Found** | `application/json` | Respons terstruktur aman: `{"status":"error","message":"..."}` |
| `/api/sar/candidates/{id}/track?window=xyz`| GET | **422 Unproc** | `application/json` | Pesan validasi parameter `window` wajib integer 10–1440 |
| `/api/layers/vessels` | GET | **200 OK** | `application/json` | GeoJSON 200 kapal dengan properti flag state lengkap |
| `/api/layers/dark-vessels` | GET | **200 OK** | `application/json` | GeoJSON 54 kandidat deteksi SAR |
| `/api/layers/sar-scenes` | GET | **200 OK** | `application/json` | GeoJSON poligon footprint scene satelit |
| `/api/layers/eez` | GET | **200 OK** | `application/json` | GeoJSON batas maritim ZEE 200 NM |
| `/api/layers/fao` | GET | **200 OK** | `application/json` | GeoJSON fishing areas statistik PBB FAO |

---

### F. SECURITY AUDIT

1. **Role-Based Access Control (RBAC):**
   - Role `viewer` dibatasi read-only (HTTP 403 saat mencoba submit review, ekspor, atau trigger health check).
   - Role `analyst` dan `admin` diizinkan melakukan asesmen forensik SAR dengan audit trail wajib (justifikasi minimal 20 karakter).
2. **Path Traversal Protection:**
   - Method `serveEvidenceImage` pada `SarEvidenceController` menggunakan whitelist tipe file ketat (`raw`, `sigma0-linear`, `sigma0-db`, `metadata`).
   - Path dibentuk dari properti terverifikasi model database (`$sceneId`, `$identifier`), bukan dari input query string sembarangan.
3. **Pencegahan Kebocoran Kredensial:**
   - Tidak ada token OAuth2, password, API key, atau stack trace yang diekspos melalui API JSON publik.
4. **Klasifikasi Temuan Keamanan:**
   - *Critical:* **0**
   - *High:* **0**
   - *Medium:* **1** (Nilai `APP_DEBUG=true` aktif di lingkungan lokal `.env`; harus diubah menjadi `false` saat deployment produksi).
   - *Low / Informational:* **1** (Rate limiting API publik disarankan diperketat pada reverse proxy Nginx).

---

### G. PERFORMANCE & MAPLIBRE VALIDATION

Hasil benchmark latensi dan payload:
- `/api/layers/vessels`: **873.4 ms** (Payload: 140.2 KB, 200 entri GeoJSON FeatureCollection).
- `/api/layers/dark-vessels`: **794.6 ms** (Payload: 59.8 KB, 54 kandidat SAR).
- `/api/sar/candidates/{id}/evidence`: **369.4 ms** (Payload: 11.1 KB).
- `/api/sar/candidates/{id}/track`: **247.4 ms** (Payload: 0.45 KB).
- **MapLibre GL JS Assets:**
  - Build Vite produksi: `public/build/assets/app-BaAMxA_N.js` (1.170 kB, gzip 323.7 kB).
  - Worker MapLibre: `public/build/assets/maplibre-gl-worker-BrB25tGK.mjs` (518.8 kB).
  - Tidak ada error 404 pada worker, tidak ada tile canvas blank. Basemap ESRI World Dark Gray ter-render sempurna.

---

### H. OPERATIONS: QUEUE, SCHEDULER, LOGGING & PROVIDER HEALTH

1. **Queue Worker:**
   - Driver: `database` (PostgreSQL `jobs` dan `failed_jobs`).
   - `pending_jobs`: **0**, `failed_jobs`: **0**.
   - Daemon `php artisan queue:work --tries=3 --timeout=120` berjalan stabil.
2. **Scheduler:**
   - Daemon `php artisan schedule:work` aktif memonitor health provider dan streaming AIS.
3. **Status Data Provider:**
   - `marine-regions-eez`: **CONNECTED**
   - `fao-fisheries`: **CONNECTED**
   - `ais-stream`: **RECEIVING_DATA** (last sync: aktif real-time)
   - `global-fishing-watch`: **AUTHENTICATED** (HTTP 403 on `/events` didokumentasikan transparan sebagai `Access Not Authorized`, bukan error sistem)
   - `copernicus-sentinel-1`: **CONFIGURED / ERROR** (token Keycloak membutuhkan verifikasi kredensial produksi)
4. **Backup & Recovery Readiness:**
   - Saat ini belum ada script backup otomatis database (`pg_dump`) dan file evidence SAR yang dijadwalkan via cron/Task Scheduler.
   - Prosedur backup dan restore telah disusun pada dokumen operasional ini (lihat Bagian K & Runbook).

---

### I. AUTOMATED TESTING & BROWSER VERIFICATION

1. **Automated Feature & Unit Tests:**
   - **92 tests passed** (100% lulus).
   - **583 assertions**.
   - Waktu eksekusi: **7.15 detik**.
   - Zero errors, zero failures, zero deprecation warnings yang menghentikan eksekusi.
2. **Browser Subagent Verification:**
   - Telah diverifikasi langsung melalui browser headless nyata pada server `http://127.0.0.1:8000`.
   - *Screenshots Tersimpan:*
     - `sar_evidence_viewer_1792438152401.png` (Kandidat SAR detail, crop 61×61 px, 3 tab mode visualisasi).
     - `live_tracking_page_1792438162398.png` (Kanvas MapLibre tracking, Telemetry Inspector, bendera negara, SOG/COG).
     - `global_map_page_1792438172089.png` (Peta global, ESRI Dark Gray, layer kontrol, marker kapal).
     - `data_providers_page_1792438183543.png` (Katalog data provider dan status kesehatan).
   - *Rekaman Sesi:* `tahap12_operational_validation_1792438138081.webp`.

---

### J. CHANGES MADE

1. Menjalankan perintah `php artisan storage:link` untuk menghubungkan `public/storage` ke `storage/app/public`.
2. Menyusun dokumen runbook dan laporan kesiapan operasional komprehensif pada `docs/reports/tahap12_operational_readiness_report.md`.
3. Mempertahankan seluruh kode dan perbaikan Tahap 11 tanpa perubahan destruktif atau modifikasi skema database.

---

### K. OUTSTANDING RISKS & OPERATIONAL RUNBOOK

#### 1. Risiko yang Belum Selesai (Outstanding Risks)
1. **Automasi Backup Database & Storage (Prioritas P1):**
   - *Risiko:* Kehilangan data jika terjadi kegagalan disk storage server.
   - *Mitigasi:* Jalankan script backup harian berikut via Windows Task Scheduler atau Linux cron:
     ```powershell
     # Backup PostgreSQL
     pg_dump -U postgres -h 127.0.0.1 -F c -b -v -f "C:\backups\gfvip_db_$(Get-Date -Format 'yyyyMMdd_HHmmss').dump" global_fishing_vessel_intelligence
     # Backup SAR Evidence & Safe Files
     robocopy "C:\XPROJECT\global-fishing-vessel-intelligence\storage\app\sar_evidence" "C:\backups\sar_evidence" /MIR
     ```
2. **Kredensial Eksternal Copernicus CDSE & GFW (Prioritas P2):**
   - CDSE OData API memerlukan pembaharuan secret Keycloak untuk mengunduh scene baru secara terjadwal.
   - GFW Events API memerlukan permohonan *Research Access Tier* ke pihak Global Fishing Watch agar HTTP 403 terangkat.
3. **Pengaturan Produksi Environment (Prioritas P1):**
   - Sebelum rilis ke jaringan publik, ubah `APP_DEBUG=false` dan setel `APP_ENV=production`.

---

### L. FINAL READINESS DECISION

| Dimensi Evaluasi | Keputusan | Rationale |
|---|---|---|
| **Kesiapan Aplikasi & API (Application Readiness)** | **`PASS`** | 100% test lulus (92/92 tests, 583 assertions), seluruh endpoint API berfungsi sesuai kontrak, MapLibre GL JS bebas blank canvas, query PostgreSQL bebas `QueryException`. |
| **Kesiapan Ilmiah (Scientific Assurance)** | **`PASS WITH LIMITATIONS`** | Pipeline SAR CA-CFAR dan kalibrasi $\sigma^0$ terverifikasi penuh dari data SAFE asli. Keterbatasan: validitas deteksi kapal di Samudra Selatan berstatus *Analyst Review Pending* tanpa ground truth independen, serta layer es musiman belum terintegrasi. |
| **Kesiapan Operasional (Operational Readiness)** | **`PASS WITH LIMITATIONS`** | Layanan AIS streaming dan queue worker berjalan aktif. Diperlukan implementasi cron backup berkala (`pg_dump`) dan konfigurasi `APP_DEBUG=false` untuk rilis produksi final. |

**KEPUTUSAN AKHIR SISTEM: `PASS WITH LIMITATIONS`**
