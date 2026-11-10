# GFVIP — TAHAP 14: PRODUCTION READINESS PREFLIGHT REPORT

**Document ID:** `GFVIP-PREFLIGHT-014`  
**Platform:** Global Fishing Vessel Tracking (GFVT)  
**Location:** `C:\XPROJECT\global-fishing-vessel-intelligence`  
**Stack:** Laravel 13.37.0, PHP 8.4.16, PostgreSQL 18.6, PostGIS 3.6.2, MapLibre GL JS  
**Audit Date:** 2026-10-22  
**Preflight Status:** **READY FOR DEPLOYMENT WITH POLICY GATES**  

---

## 1. APPLICATION RUNTIME & FRAMEWORK AUDIT

| Component | Current Value (Workstation) | Production Target Spec | Preflight Evaluation |
|---|---|---|---|
| **Framework** | Laravel 13.37.0 | Laravel 13.x LTS / Latest | **PASS** — Core framework is up-to-date and robust. |
| **PHP Runtime** | PHP 8.4.16 (cli/fpm) | PHP 8.4+ | **PASS** — 64-bit with OPcache, PDO PostgreSQL, OpenSSL, GD, Zip. |
| **APP_ENV** | `local` | `production` | **COMPLIANT WORKSTATION GATE** — Workstation intentionally retained as `local`. Target checklist mandates `production`. |
| **APP_DEBUG** | `true` | `false` | **COMPLIANT WORKSTATION GATE** — Workstation intentionally retained as `true`. Production checklist mandates `false` to hide stack traces. |
| **APP_URL** | `http://localhost:8000` | `https://gfvip.domain.gov` | **CONFIGURED** — Target checklist requires canonical HTTPS URL. |
| **Cache Driver** | `database` (table `cache`) | `database` / `redis` | **PASS** — Supports atomic locks (`cache_locks`) and provider token caching. |
| **Queue Driver** | `database` (table `jobs`) | `database` / `redis` | **PASS** — Supervised worker running with timeout 120s and retry limit 3. |
| **Session Driver** | `database` (table `sessions`)| `database` / `redis` | **PASS** — Secure session storage with encryption support. |
| **Filesystem Disk**| `local` (`storage/app`) | `local` with NVMe / SAN | **PASS** — Separate subdirectories for `sar_products` and `sar_evidence`. |
| **Logging** | `stack` (`single` daily) | `stack` (daily + Papertrail/Syslog) | **PASS** — Zero secrets logged in audit trail or application logs. |
| **Mail Config** | `log` | `smtp` / `mailgun` | **PASS** — Outbound email decoupled from routine telemetry alerts. |

---

## 2. DATABASE & SPATIAL ENGINE AUDIT

- **PostgreSQL Version:** `PostgreSQL 18.6 on x86_64-windows, compiled by msvc-19.44.35229, 64-bit`.
- **PostGIS Extension Version:** `POSTGIS="3.6.2 3.6.2" [EXTENSION] PGSQL="180" GEOS="3.14.1dev" PROJ="8.2.1"`.
- **Connection Latency:** ~59 – 75 ms (Windows local TCP socket).
- **Spatial Functions Verification:**
  - Query: `SELECT ST_AsText(ST_Point(106.8456, -6.2088, 4326)) AS geom;`
  - Result: `POINT(106.8456 -6.2088)` — Valid WGS-84 geometry.
- **Entity Consistency & Snapshot Counts:**
  - `satellite_detections`: **54** (Deterministic CA-CFAR candidates).
  - `satellite_scenes`: **2** (Scene metadata records).
  - `vessels`: **3,497** (Organic live AIS vessels).
  - `vessel_positions`: **4,227** (Organic live AIS positions).
  - `audit_logs`: **42** (Forensic audit events recorded).
  - `vessel_encounters`: 0 (No synthetic encounters created).
- **UUID Integrity:** Compound identifiers (e.g. `542e73f3-35ea-4ff6-bc6a-fa71d5d1fee7-0054`) parsed safely in API controllers without causing `SQLSTATE[22P02]` UUID format exceptions.

---

## 3. STORAGE & EVIDENCE ARTIFACTS AUDIT

| Directory / Artifact Path | Item Count / Size | Permissions & Integrity | Status |
|---|---|---|---|
| `storage/app/sar_products` | 22 files (1 zip SAFE 86.9 MB) | SHA256 verified: `0177FF5FB29F051F06291392453DAC0FC522D059FE5AB07ECD244DFC9654DC23` | **HEALTHY** |
| `storage/app/sar_evidence` | 216 files (54 candidate folders $\times$ 4 files) | `raw.png`, `sigma0-linear.png`, `sigma0-db.png`, `metadata.json` intact | **HEALTHY** |
| `C:\backups\gfvip` | 1 dump (1.12 MB), 22 SAFE, 216 crops | Backup manifest and recovery report verified | **HEALTHY** |
| `Drive C: Capacity` | Free: 260.68 GB / Total: 500.35 GB | Free space ratio: 52.1% (> 20% healthy threshold) | **HEALTHY** |
| `public/build` (Vite) | Manifest, CSS bundle, JS bundle | MapLibre GL JS assets compiled | **HEALTHY** |

---

## 4. ACTIVE BACKGROUND SERVICES AUDIT

1. **Queue Worker:**
   - Command: `php artisan queue:work --tries=3 --timeout=120`
   - State: Active running daemon.
   - Pending Jobs: 0. Failed Jobs: 0.
2. **Scheduler:**
   - Command: `php artisan schedule:work`
   - State: Active running daemon.
   - Cron Schedule: Registered.
3. **HTTP Server:**
   - Command: `php artisan serve` on `http://127.0.0.1:8000`.
   - State: Active running daemon.
4. **AIS Live Ingestion:**
   - Live stream receiver: Active organically ingesting telemetry.
5. **Windows Task Scheduler:**
   - Task: `GFVIP_Automated_Backup`.
   - State: `Ready`, Daily 02:00:00 AM.
   - Note: Requires `NT AUTHORITY\SYSTEM` configuration for unattended headless servers.

---

## 5. PROVIDER CAPABILITY & DATA INTEGRITY SUMMARY

- **Copernicus CDSE:** OAuth2 authentication verified with Keycloak; OData catalog endpoint responsive (HTTP 200). Recurring automated product downloads subject to API quota constraints.
- **Global Fishing Watch (GFW):** Gateway datasets endpoint accessible; events endpoint returns HTTP 403 `ACCESS NOT AUTHORIZED` due to commercial/research tier restrictions. No synthetic encounter records are injected into the database.
- **SAR Detections:** 54 candidates detected from real radar imagery retained strictly as unconfirmed candidates with `insufficient_data` correlation status.
