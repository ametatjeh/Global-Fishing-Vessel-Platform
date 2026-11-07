# PRODUCTION ENVIRONMENT CHECKLIST
**Platform:** Global Fishing Vessel Intelligence Platform (GFVIP)  
**Host Target:** `https://gfw.nagakecil.site`  
**Root Path:** `/www/wwwroot/gfw.nagakecil.site`  
**File Target:** `/www/wwwroot/gfw.nagakecil.site/.env`  
**Security Rule:** Dokumen ini merupakan template dan panduan audit. Dilarang menuliskan nilai token riil, password plaintext, atau kunci privat ke dalam repositori.

---

## 1. Application & Security Configuration

| Parameter | Nilai Produksi yang Wajib | Status Audit | Catatan Keamanan |
| :--- | :--- | :---: | :--- |
| `APP_NAME` | `"Global Fishing Vessel Tracking"` | [ ] | Nama platform maritim resmi |
| `APP_ENV` | `production` | [ ] | Menjamin logging dan pelaporan error dalam mode aman |
| `APP_KEY` | `base64:...` *(Wajib unik)* | [ ] | Harus sudah digenerate via `php artisan key:generate` |
| `APP_DEBUG` | `false` | [ ] | **KRITIKAL:** Wajib `false` di produksi untuk mencegah kebocoran stack trace |
| `APP_URL` | `https://gfw.nagakecil.site` | [ ] | Wajib menggunakan HTTPS penuh |
| `APP_LOCALE` | `id` | [ ] | Default Bahasa Indonesia untuk portal maritim |
| `APP_FALLBACK_LOCALE` | `en` | [ ] | Fallback Bahasa Inggris |
| `BCRYPT_ROUNDS` | `12` | [ ] | Keamanan hashing kata sandi pengguna |

---

## 2. Database Connection (PostgreSQL + PostGIS)

| Parameter | Nilai Produksi yang Disarankan | Status Audit | Catatan |
| :--- | :--- | :---: | :--- |
| `DB_CONNECTION` | `pgsql` | [ ] | Wajib PostgreSQL (driver SQLite dilarang untuk produksi) |
| `DB_HOST` | `127.0.0.1` | [ ] | Gunakan localhost atau private VPC socket |
| `DB_PORT` | `5432` | [ ] | Port standar PostgreSQL |
| `DB_DATABASE` | `gfvip_production` | [ ] | Database produksi PostGIS terpisah |
| `DB_USERNAME` | `gfvip_user` | [ ] | User database non-superuser dengan hak akses terbatas |
| `DB_PASSWORD` | `<secure_random_db_password>` | [ ] | Minimal 24 karakter alfanumerik acak |

> [!IMPORTANT]
> Pastikan ekstensi `postgis` dan `uuid-ossp` sudah terpasang pada database produksi:
> ```sql
> CREATE EXTENSION IF NOT EXISTS postgis;
> CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
> ```

---

## 3. High-Performance Session, Cache & Queue

| Parameter | Nilai Produksi yang Disarankan | Status Audit | Catatan |
| :--- | :--- | :---: | :--- |
| `CACHE_STORE` | `database` atau `redis` | [ ] | Hindari file cache pada multi-worker cluster |
| `QUEUE_CONNECTION` | `database` atau `redis` | [ ] | Mengelola asynchronous background jobs |
| `SESSION_DRIVER` | `database` | [ ] | Memastikan session persist dan kompatibel load-balancer |
| `SESSION_LIFETIME` | `120` | [ ] | Timeout sesi aktif pengguna (dalam menit) |
| `SESSION_SECURE_COOKIE` | `true` | [ ] | Hanya kirim cookie melalui HTTPS |

---

## 4. Provider 1: Global Fishing Watch (GFW API v3)

| Parameter | Nilai Produksi | Status Audit | Catatan |
| :--- | :--- | :---: | :--- |
| `GFW_API_BASE_URL` | `https://gateway.api.globalfishingwatch.org/v3` | [ ] | **WAJIB V3:** Versi v1/v2 sudah didegradasi |
| `GFW_API_TOKEN` | `<Bearer Token Resmi GFW>` | [ ] | Panjang token ~797 karakter, didapatkan dari research portal GFW |
| `GFW_API_TIMEOUT` | `30` | [ ] | Timeout toleransi jaringan internasional (detik) |
| `GFW_API_CACHE_TTL` | `3600` | [ ] | Cache respons API selama 1 jam untuk efisiensi kuota |

---

## 5. Provider 2: Live AIS Stream & Telemetry Pipeline

| Parameter | Nilai Produksi | Status Audit | Catatan |
| :--- | :--- | :---: | :--- |
| `AIS_STREAM_API_KEY` | `<API Key aisstream.io>` | [ ] | Digunakan oleh Node.js daemon (`ais/worker.mjs`) |
| `AIS_STREAM_URL` | `wss://stream.aisstream.io/v0/stream` | [ ] | Endpoint secure WebSocket feed |
| `AIS_INGEST_TOKEN` | `<random_sha256_shared_secret>` | [ ] | Token autentikasi micro-batch `X-AIS-Token` |
| `AIS_BACKEND_URL` | `https://gfw.nagakecil.site/api/telemetry/ais/batch` | [ ] | Target internal webhook pipeline micro-batch |

---

## 6. Provider 3: Copernicus Sentinel-1 SAR (Satellite Imagery)

| Parameter | Nilai Produksi | Status Audit | Catatan |
| :--- | :--- | :---: | :--- |
| `COPERNICUS_CLIENT_ID` | `<OAuth2 Client ID>` | [ ] | Pendaftaran via Copernicus Data Space Ecosystem |
| `COPERNICUS_CLIENT_SECRET` | `<OAuth2 Client Secret>` | [ ] | Kunci otentikasi rahasia |
| `COPERNICUS_AUTH_URL` | `https://identity.dataspace.copernicus.eu/auth/realms/CDSE/protocol/openid-connect/token` | [ ] | Identity server resmi CDSE |
| `COPERNICUS_ODATA_URL` | `https://catalogue.dataspace.copernicus.eu/odata/v1` | [ ] | OData API katalog citra Sentinel-1 |
| `SAR_STORAGE_DIR` | `/www/wwwroot/gfw.nagakecil.site/storage/app/sar_products` | [ ] | Direktori penyimpanan citra SAR |
| `SAR_MIN_FREE_DISK_GB` | `10.0` | [ ] | Ambang batas kapasitas disk sebelum download produk |

---

## 7. Google Single Sign-On (SSO OAuth 2.0)

| Parameter | Nilai Produksi | Status Audit | Catatan |
| :--- | :--- | :---: | :--- |
| `GOOGLE_CLIENT_ID` | `<Google OAuth Client ID>` | [ ] | Konfigurasi Google Cloud Console |
| `GOOGLE_CLIENT_SECRET` | `<Google OAuth Secret>` | [ ] | Konfigurasi Google Cloud Console |
| `GOOGLE_REDIRECT_URI` | `https://gfw.nagakecil.site/auth/google/callback` | [ ] | Wajib terdaftar di Authorized Redirect URIs |

---

## 8. Verifikasi Pra-Aktivasi File `.env`

Jalankan perintah ini di server produksi untuk memvalidasi bahwa konfigurasi telah terbaca tanpa error sintaks:

```bash
# Uji pemuatan konfigurasi Laravel secara aman tanpa mencetak rahasia
php artisan config:clear
php artisan about
```

Jika status terbaca `Environment: production` dan `Debug Mode: false`, lanjutkan ke proses deployment migrasi.

