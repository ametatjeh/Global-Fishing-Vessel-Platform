# GFVIP — DEPLOYMENT PLAN
**Target Environment:** Staging & Production (`https://gfw.nagakecil.site`)  
**Production Root Path:** `/www/wwwroot/gfw.nagakecil.site`  
**Framework:** Laravel 12 / PHP 8.4 / PostgreSQL 18 + PostGIS 3.6.2  
**Frontend Engine:** Vite v7 + MapLibre GL JS v5  
**Deployment Release:** GFW API v3 Rebuild, Phase 10 Fleet Type, Phase 11 Profile Enrichment, & Open Waters AIS Map  
**Document Version:** 1.0.0 (Production Release Candidate)

---

## 1. Executive Summary & Principles

Paket deployment ini merangkum pembaruan komprehensif pada platform GFVIP yang telah diverifikasi secara penuh di lingkungan lokal dengan **227 pengujian otomatis lulus (1,440 assertions)** dan build aset frontend Vite yang sukses.

### Prinsip Utama Operasi Deployment:
1. **Zero Data Loss:** Dilarang keras menjalankan perintah destruktif (`migrate:fresh`, `db:wipe`, `truncate`, `drop table`, `migrate:reset`).
2. **Strict Production Data Isolation:** Tidak ada data lokal (93 kapal lokal) yang diimpor atau disinkronkan ke database produksi.
3. **No Blind Mass Mutations:** Dilarang menjalankan `php artisan vessels:classify --apply` atau `php artisan gfw:vessels:enrich --apply` secara massal tanpa audit manual per kelompok target.
4. **Decoupled Deployment:** Proses penarikan kode Git, instalasi dependensi, migrasi database, dan build frontend dipisahkan menjadi tahapan terkontrol dengan verifikasi bertingkat.
5. **Fail-Safe Provider Resilience:** Kegagalan atau pembatasan kuota pada API eksternal (misalnya GFW Events HTTP 403/429) tidak boleh menghentikan operasional layer AIS, satelit SAR, atau peta utama.

---

## 2. Commit Release Inventory

Deployment ini mencakup 4 commit terverifikasi yang telah di-push dan tersinkronisasi pada branch `main`:

```
efd4509 feat(gfw,fleet): rebuild GFW v3 service and implement vessel fleet type classification
4ebb0bc feat(vessels,gfw): implement GFW vessel profile enrichment with multi-source reconciliation
e09a57e feat(map,ais): rebuild map interface following Open Waters AIS and synchronize AIS pipeline
97f88ed fix(telemetry): verify live AIS stream ingestion and update tracking status badges
```

### Cumulative File Changes (37 Files):
- **Core Maritime Services:**
  - `app/Services/Providers/GfwApiService.php` *(Baru — Klien resmi GFW API v3, Bearer token handling, sanitasi token, dataset capability tracker)*
  - `app/Services/Maritime/VesselClassificationService.php` *(Baru — Taksonomi 11 kategori, pemetaan ITU AIS ShipType, hirarki prioritas multi-sumber)*
  - `app/Services/Maritime/GfwVesselEnrichmentService.php` *(Baru — Rekonsiliasi profil GFW v3, proteksi data manual terverifikasi, pelacakan konflik & provenansi)*
  - `app/Services/VesselPurgeService.php` *(Baru — Kebijakan pencegahan & audit pembersihan kapal cargo/tanker)*
  - `app/Services/Providers/AisStreamAdapter.php` *(Update — Penanganan 5 pesan AIS ITU, deteksi stasioner, deduplikasi)*
  - `app/Services/Providers/CopernicusSarAdapter.php` *(Update — Pembaruan token OAuth2 & sinkronisasi OData)*
  - `app/Services/SpatialQueryService.php` *(Update — Filter koordinat antimeridian & batas EEZ)*
  - `app/Services/SystemHealthService.php` *(Update — Telemetri kesehatan provider transparan)*
- **Console Artisan Commands:**
  - `app/Console/Commands/ClassifyVesselsCommand.php` (`php artisan vessels:classify` — dry-run & apply mode)
  - `app/Console/Commands/EnrichVesselsGfwCommand.php` (`php artisan gfw:vessels:enrich` — dry-run & apply mode)
  - `app/Console/Commands/PurgeCargoTankerVesselsCommand.php` (`php artisan vessels:purge-cargo-tanker` — audit & purge policy)
  - `app/Console/Commands/AisStreamListenCommand.php`
  - `app/Console/Commands/SyncProvidersCommand.php`
- **Models & Migrations:**
  - `app/Models/ProhibitedVessel.php`
  - `app/Models/Vessel.php` *(Update casting & relasi)*
  - `database/migrations/2026_11_07_043937_add_unique_constraint_to_vessel_positions.php`
  - `database/migrations/2026_11_07_045823_create_prohibited_vessels_table.php`
  - `database/migrations/2026_11_07_060000_add_enrichment_fields_to_vessels_table.php`
- **Frontend Views & Assets:**
  - `resources/views/map/index.blade.php` *(Rebuild full-screen Open Waters style nautical dark theme)*
  - `resources/js/map/global-map.js` *(MapLibre GL JS vector styles, directional markers, URL hash state)*
  - `resources/views/vessels/index.blade.php` *(Dropdown filter Fleet Type & status badges)*
  - `resources/views/vessels/show.blade.php` *(6 seksi detail profil, provenansi data, riwayat event GFW)*
  - `resources/views/providers/index.blade.php` & `tracking/index.blade.php`
- **Node.js High-Performance Ingestion Daemon:**
  - `ais/package.json`
  - `ais/worker.mjs`
  - `deployment/systemd/ais-node-worker.service`
- **Feature Test Suites:**
  - `tests/Feature/VesselClassificationTest.php`
  - `tests/Feature/GfwVesselEnrichmentTest.php`
  - `tests/Feature/AisStreamIngestionRebuildTest.php`
  - `tests/Feature/OpenWatersMapInterfaceTest.php`
  - `tests/Feature/PurgeCargoTankerVesselsTest.php`

---

## 3. Deployment Workflow & Phase Sequences

```
[Phase 1: Pre-Flight]   --> Backup Database & Verifikasi Git Clean
        │
[Phase 2: Code Pull]    --> git pull origin main
        │
[Phase 3: Dependencies] --> composer install --no-dev & npm ci
        │
[Phase 4: Asset Build]  --> npm run build (Vite Production Bundling)
        │
[Phase 5: Migration]    --> php artisan migrate --pretend (Review)
                            php artisan migrate --force
        │
[Phase 6: Optimize]     --> php artisan config:cache && route:cache && view:cache
        │
[Phase 7: Workers]      --> systemctl restart ais-node-worker supervisor
        │
[Phase 8: Validation]   --> Post-Deployment Smoke Tests & API Probe
```

---

## 4. Staging vs Production Separation

| Kriteria | Lingkungan Staging | Lingkungan Produksi (`gfw.nagakecil.site`) |
| :--- | :--- | :--- |
| **Domain** | Staging Subdomain / Localhost IP | `https://gfw.nagakecil.site` |
| **Path Server** | `/www/wwwroot/staging.nagakecil.site` | `/www/wwwroot/gfw.nagakecil.site` |
| **Database** | `gfvip_staging` | `gfvip_production` (PostgreSQL + PostGIS) |
| **Data Ingestion** | Simulasi micro-batch & dataset uji | Live AIS WebSocket stream & live SAR imagery |
| **Eksekusi Artisan** | Boleh menggunakan `--apply` pada sampel | **Dry-run only** secara default; apply manual terisolasi |
| **Rollback Window** | Kapan saja | Terjadwal dalam maintenance window (02:00 - 04:00 WIB) |

---

## 5. Deployment Readiness Sign-Off

- [x] Kode terverifikasi lulus 227 pengujian PHPUnit lokal.
- [x] Kompilasi frontend Vite v7 teruji bersih tanpa error (4.10 detik).
- [x] Repository Git bersih dan tersinkronisasi dengan remote origin.
- [x] Rencana rollback lengkap terdokumentasi.
- [x] SOP verifikasi post-deployment tersedia.

**STATUS:** **READY FOR STAGING DEPLOYMENT & READY FOR PRODUCTION REVIEW**  
*(Deployment produksi menunggu persetujuan eksplisit dari Administrator).*

