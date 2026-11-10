# Global Fishing Vessel Tracking (GFVT)

Global Fishing Vessel Tracking platform built on Laravel 13, PostgreSQL/PostGIS, and MapLibre GL JS for worldwide fishing fleet surveillance, maritime spatial analysis, and vessel intelligence with three core operational pillars:

1. **Live Vessel Monitoring** (Real-time AIS position telemetry and tracking).
2. **Maritime Compliance Monitoring** (Rule-based compliance engine grounded in international maritime law, authorizations, and conservation zones).
3. **Dark Vessel Detection** (Sentinel-1 SAR radar satellite correlation, transponder gap analysis, and unclassified craft detection).

The platform integrates official spatial layers:

- **ASEAN Exclusive Economic Zones (EEZ)** (Flanders Marine Institute / Marine Regions official maritime boundaries).
- **Vessel Encounter Analysis** (Spatio-temporal rendezvous detection and transshipment monitoring in international and domestic waters).

---

## 1. Prasyarat Sistem Windows (Windows 10 / 11)

Sebelum memulai, pastikan perangkat lunak berikut terpasang pada komputer Windows Anda:

| Perangkat Lunak | Versi yang Didukung | Keterangan |
| --- | --- | --- |
| **PHP** | 8.4+ (atau 8.3+) | Ekstensi aktif: `pdo_pgsql`, `pgsql`, `pdo_sqlite`, `sqlite3`, `curl`, `mbstring`, `fileinfo`, `openssl`, `sodium` |
| **Composer** | 2.8+ / 2.10+ | Dependency manager PHP |
| **Node.js & npm** | Node v20+ / v22+, npm 10+ | Kompilasi aset Vite & MapLibre GL JS |
| **PostgreSQL** | PostgreSQL 15 / 16 / 17 | Database target dengan ekstensi spasial PostGIS |
| **PostGIS** | PostGIS 3.3+ / 3.4+ | Ekstensi spasial PostgreSQL |
| **PowerShell** | Windows PowerShell 5.1 / PowerShell 7+ | Terminal eksekusi perintah |

---

## 2. Langkah Instalasi Cepat pada Komputer Windows

Buka **PowerShell** (Run as Administrator jika perlu menginstal dependensi baru) dan jalankan tahapan berikut:

### A. Clone / Masuk ke Direktori Proyek

```powershell
cd C:\XPROJECT\global-fishing-vessel-intelligence
```

### B. Salin Konfigurasi Environment

```powershell
Copy-Item .env.example .env
```

### C. Pasang Dependensi Backend & Frontend

```powershell
composer install
npm install
npm run build
```

### D. Generate Application Key

```powershell
php artisan key:generate
```

---

## 3. Pembuatan Database PostgreSQL & Aktivasi PostGIS di Windows

### A. Instalasi PostgreSQL & PostGIS (Jika Belum Terpasang)

Anda dapat menginstal PostgreSQL dan PostGIS melalui Windows Package Manager (Winget):

```powershell
winget install PostgreSQL.PostgreSQL.16 --accept-source-agreements --accept-package-agreements
```

Setelah instalasi selesai, buka **Stack Builder** yang disertakan oleh PostgreSQL installer dan centang **Spatial Extensions -> PostGIS 3.4 Bundle**, lalu selesaikan wizard instalasi.

### B. Buat Database & Aktifkan Ekstensi PostGIS

Masuk ke terminal PostgreSQL (`psql`) dengan pengguna `postgres`:

```powershell
psql -U postgres
```

Jalankan perintah SQL berikut di terminal `psql`:

```sql
CREATE DATABASE gfvip_production;
\c gfvip_production
CREATE EXTENSION postgis;
CREATE EXTENSION "uuid-ossp";
\q
```

### C. Verifikasi Kesiapan Database & PostGIS via Artisan Command

Aplikasi menyediakan Artisan Command diagnostik otomatis untuk memeriksa koneksi dan versi PostGIS:

```powershell
php artisan db:check-postgis
```

---

## 4. Konfigurasi Database pada `.env`

Buka file `.env` dan sesuaikan parameter koneksi database PostgreSQL Anda:

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=gfvip_production
DB_USERNAME=postgres
DB_PASSWORD=your_postgres_password
```

*(Catatan: Proyek juga memiliki kompatibilitas dual-driver dengan SQLite untuk kemudahan pengujian otomatis dan demo lokal).*

---

## 5. Menjalankan Migrasi & Seeder Database

Jalankan perintah migrasi skema database dan pemuatan data awal:

```powershell
php artisan migrate --seed
```

### Akun Bawaan Sistem (Default Credentials)

- **Super Administrator**: `admin@gfw.nagakecil.site` (Kata Sandi: `Password123!`)
- **Senior Maritime Analyst**: `analyst@gfw.nagakecil.site` (Kata Sandi: `Password123!`)

---

## 6. Mengimpor Dataset Geospasial Resmi (FAO & EEZ)

Aplikasi telah dilengkapi file data resmi GeoJSON di direktori `database/seeders/data/`:

- `fao_major_fishing_areas.geojson` (FAO Major Fishing Areas)
- `marine_regions_eez_sample.geojson` (Marine Regions EEZ Boundaries)

Jalankan perintah Artisan berikut untuk mengimpor atau memperbarui data:

### A. Impor Dataset FAO Major Fishing Areas

```powershell
# Jalankan dry-run untuk verifikasi jumlah fitur tanpa menyentuh database:
php artisan fao:import --dry-run

# Eksekusi impor penuh ke PostGIS:
php artisan fao:import
```

### B. Impor Dataset Marine Regions EEZ Boundaries

```powershell
# Jalankan dry-run terlebih dahulu:
php artisan eez:import --dry-run

# Eksekusi impor penuh ke PostGIS:
php artisan eez:import
```

---

## 7. Menjalankan Aplikasi di Lingkungan Lokal Windows

Buka **3 tab PowerShell terpisah** untuk menjalankan layanan operasional:

### Tab 1 — Web Server Laravel

```powershell
cd C:\XPROJECT\global-fishing-vessel-intelligence
php artisan serve
```

Akses platform di peramban: **`http://localhost:8000`**

### Tab 2 — Queue Worker (Pemrosesan Asinkron Encounter & Satelit)

```powershell
cd C:\XPROJECT\global-fishing-vessel-intelligence
php artisan queue:work --tries=3 --timeout=120
```

### Tab 3 — Scheduler (Sinkronisasi & Health Check Berkala)

```powershell
cd C:\XPROJECT\global-fishing-vessel-intelligence
php artisan schedule:work
```

*(Jika sedang mengembangkan antarmuka, Anda dapat menjalankan `npm run dev` untuk Vite hot-reload).*

---

## 8. Menjalankan Encounter Detection Manual

Untuk menjalankan analisis kedekatan kapal spasio-temporal (default: jarak maks 500 meter, durasi min 30 menit, toleransi 10 menit):

```powershell
php artisan encounters:detect --hours=24 --distance=500 --duration=30
```

---

## 9. Konfigurasi Kredensial Provider Eksternal (Data Sources)

Aplikasi menjunjung tinggi **integritas data dan prinsip anti-data dummy**. Jika kredensial API belum dimasukkan, status provider akan secara jujur dilaporkan sebagai `Not Configured`, dan peta tidak akan menampilkan data buatan atau fiktif.

Untuk menghubungkan data live sungguhan:

### 1. Global Fishing Watch (GFW Events API v3)

- Daftarkan akun di [Global Fishing Watch Research Portal](https://globalfishingwatch.org).
- Dapatkan API Token dan masukkan ke `.env`:

  ```env
  GFW_API_TOKEN=your_gfw_bearer_token_here
  ```

- *Catatan Lisensi*: Penggunaan data GFW terbatas untuk riset dan tujuan non-komersial.

### 2. Copernicus Data Space Ecosystem (Sentinel-1 SAR Radar)

- Daftarkan akun di [Copernicus Data Space Ecosystem](https://dataspace.copernicus.eu).
- Buat OAuth2 Client ID & Secret di portal CDSE, lalu masukkan ke `.env`:

  ```env
  COPERNICUS_CLIENT_ID=your_cdse_client_id
  COPERNICUS_CLIENT_SECRET=your_cdse_client_secret
  ```

### 3. Live AIS Feed (AisStream / Komersial)

- Dapatkan API Key dari penyedia AIS (misal [AisStream.io](https://aisstream.io)):

  ```env
  AIS_STREAM_API_KEY=your_ais_api_key
  ```

### Cek Kesehatan Provider Secara Menyeluruh

```powershell
php artisan providers:health
```

---

## 10. Menjalankan Otomatisasi Pengujian (Automated Tests)

Semua pengujian unit dan fitur telah dirancang untuk berjalan cepat dan independen menggunakan test runner bawaan:

```powershell
vendor/bin/phpunit
```

atau menggunakan perintah Artisan:

```powershell
php artisan test
```

### Cakupan Pengujian (24 Tests, 120 Assertions)

1. `AuthTest`: Otentikasi sesi, pembatasan akses guest, proteksi brute-force kata sandi, logout.
2. `MapLayerApiTest`: Format GeoJSON, bounding-box viewport filtering, metadata lisensi FAO/EEZ, integritas ingest AIS, penolakan koordinat invalid.
3. `EncounterDetectionTest`: Algoritma kedekatan Haversine, window temporal (jarak < 500m & durasi > 30 menit), isolasi kapal jauh, dan deduplikasi insiden.
4. `ComplianceRuleTest`: Evaluasi rule engine terhadap diskontinuitas AIS gap dan kesesuaian izin kapal.
5. `ProviderHealthTest`: Status jujur `Not Configured` tanpa kredensial, verifikasi konektivitas eksternal.
6. `VesselRegistryTest`: Registrasi identitas kapal (MMSI, IMO, Callsign), penyaringan pencarian, dan profil armada.

---

## 11. Troubleshooting Masalah Umum di Windows

### 1. Ekstensi `pdo_pgsql` atau `pgsql` Belum Aktif

**Gejala**: Error `Driver [pgsql] not supported` saat migrasi.  
**Solusi**: Buka file `php.ini` Anda (misal `C:\CodesEasy\DevKit\services\php\php.ini`), hapus tanda titik koma (`;`) pada baris berikut, lalu simpan:

```ini
extension=pdo_pgsql
extension=pgsql
```

### 2. PostGIS Function Error (`ST_MakeEnvelope`, `ST_Intersects`)

**Gejala**: Error `function st_makeenvelope does not exist`.  
**Solusi**: Pastikan ekstensi PostGIS telah dibuat pada database yang sedang aktif:

```sql
psql -U postgres -d gfvip_production -c "CREATE EXTENSION IF NOT EXISTS postgis;"
```

### 3. Port 8000 Sedang Digunakan

**Solusi**: Jalankan `php artisan serve` pada port alternatif:

```powershell
php artisan serve --port=8080
```

### 4. Cache Konfigurasi & View Setelah Perubahan `.env`

Jika Anda mengubah nilai `.env`, bersihkan cache aplikasi dengan:

```powershell
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
```

---

## 12. Disclaimer Hukum & Metodologi Pemantauan Maritim

1. **ASEAN Exclusive Economic Zones (EEZ)**: Batas Zona Ekonomi Eksklusif (EEZ) 200 mil laut merupakan yurisdiksi hukum maritim negara pantai berdasarkan UNCLOS 1982, bukan secara otomatis merupakan zona terlarang untuk pelayaran atau penangkapan ikan tanpa verifikasi izin dan regulasi terkait.
2. **Vessel Encounters**: Peristiwa kedekatan spasio-temporal dua kapal merupakan anomali observasional yang memerlukan telaah analis (*Needs Review*) dan tidak dapat serta-merta disimpulkan sebagai tindak transshipment ilegal tanpa verifikasi bukti pendukung.
3. **Dark Vessel Detection**: Diskontinuitas sinyal AIS dan korelasi deteksi SAR merupakan indikasi investigatif; hilangnya transmisi AIS dapat disebabkan oleh hambatan propagasi propagasi sinyal satelit, gangguan atmosferik, atau kegagalan perangkat, bukan bukti mutlak tindak kriminal di laut.
