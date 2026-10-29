# Global Fishing Vessel Tracking (GFVT)
## Offsite Disaster Recovery & Backup Architecture Design

**Document ID:** `GFVT-ARCH-BCP-001`  
**Classification:** Internal Operational Architecture  
**Status:** DRAFT / PENDING OPERATOR APPROVAL  
**Target Environment:** Production / Multi-Region Secondary Vault  

---

## 1. Executive Summary & The 3-2-1 Backup Strategy

The Global Fishing Vessel Tracking platform processes sensitive maritime spatial data, live AIS telemetry, Copernicus Sentinel-1 SAR imagery products, and forensic investigation audit trails. To ensure operational resilience against site failures, hardware disasters, and ransomware, the platform adopts the industry-standard **3-2-1 Backup Strategy**:

1. **3 Copies of Data:**
   - Primary Operational: Live PostgreSQL 18 + PostGIS database and active storage (`storage/app/sar_*`).
   - Local Secondary: Automated daily local snapshots (`C:\backups\gfvip`).
   - Offsite Tertiary: Geographically separated, immutable offsite vault.
2. **2 Different Media Types:**
   - High-performance local NVMe/SSD for low-latency operational access and rapid daily recovery.
   - Cloud Object Storage (or isolated ZFS NAS) featuring Write-Once-Read-Many (WORM) immutability.
3. **1 Offsite Copy:**
   - Stored in a distinct fault domain / cloud region, air-gapped from production administrative credentials.

---

## 2. Target Directory & Object Hierarchy

The offsite repository will maintain a clean, deterministic hierarchy matching local backup manifests:

```
s3://<gfvip-offsite-bucket>/
│
├── manifests/
│   ├── backup_manifest_20261020_025106.json
│   ├── backup_manifest_20261020_025106.json.sha256
│   └── latest_backup_manifest.json
│
├── database/
│   ├── gfvip_db_20261020_025106.dump
│   └── gfvip_db_20261020_025106.dump.sha256
│
├── sar_products/
│   └── 542e73f3-35ea-4ff6-bc6a-fa71d5d1fee7.zip
│
└── sar_evidence/
    └── 542e73f3-35ea-4ff6-bc6a-fa71d5d1fee7/
        └── 542e73f3-35ea-4ff6-bc6a-fa71d5d1fee7-0054/
            ├── raw.png
            ├── sigma0-linear.png
            ├── sigma0-db.png
            └── metadata.json
```

---

## 3. Supported Storage Providers & Comparative Evaluation

| Provider Option | Primary Advantages | Immutability / WORM Support | Transfer Tool | Estimated Cost / Month (100 GB) |
|---|---|---|---|---|
| **S3-Compatible (Wasabi / MinIO / Backblaze B2)** | Lowest egress & storage cost; S3 API standard; no vendor lock-in | Yes (Object Lock Compliance Mode) | `aws-cli` / `rclone` | ~$0.60 – $0.70 |
| **AWS S3 (Standard + Glacier Instant Retrieval)** | Highest durability (99.999999999%); AWS KMS integration | Yes (S3 Object Lock in Compliance Mode) | `aws s3 cp` / AWS CLI v2 | ~$2.30 (Standard) / ~$0.40 (Glacier) |
| **Azure Blob Storage (Cool / Cold Tier)** | Enterprise AD / Entra ID integration; Managed Identities | Yes (Immutable Blob Storage with time-based retention) | `azcopy` | ~$1.00 – $1.80 |
| **Google Cloud Storage (Nearline / Coldline)** | Strong global networking; Customer-Managed Encryption Keys | Yes (Bucket Lock / Retention Policies) | `gcloud storage` | ~$1.20 – $2.00 |
| **Dedicated On-Premise NAS (ZFS / TrueNAS)** | Zero external cloud billing; 100% data sovereignty | Yes (ZFS read-only snapshots with air-gap) | `robocopy /XO` (SMB) or `rsync` | Capex only (hardware maintenance) |

---

## 4. Security & Cryptographic Controls

### 4.1 In-Transit Encryption
- All transfers to cloud endpoints must strictly mandate **TLS 1.3** (minimum TLS 1.2 with secure cipher suites like `ECDHE-ECDSA-AES256-GCM-SHA384`).
- Plaintext HTTP connections are unconditionally blocked at bucket policy level (`aws:SecureTransport: true`).

### 4.2 At-Rest Encryption
- Cloud Object Storage: Mandate server-side encryption with customer-managed keys (SSE-KMS) or standard SSE-S3 AES-256.
- Dedicated NAS: Encrypted ZFS pools (AES-256-GCM) with key unlocking decoupled from production host boot.

### 4.3 Secure Credential Architecture
- **Zero Plaintext Secrets:** API keys, IAM access keys, and storage connection strings must **never** be placed in `.env`, git repositories, or plain scripts.
- **Credential Storage:**
  - On Windows: Windows Credential Manager or DPAPI-encrypted PowerShell credentials.
  - On Cloud: AWS IAM Roles / Azure Managed Identity (if GFVIP moves to cloud VM).
- **Principal Privilege Separation:**
  - **Backup Uploader Role (`gfvip-backup-agent`):** Permissions restricted strictly to `s3:PutObject`, `s3:PutObjectLegalHold`, and `s3:PutObjectRetention`. The role has **NO** permissions to `s3:DeleteObject`, `s3:DeleteBucket`, or `s3:AbortMultipartUpload`.
  - **Recovery Reader Role (`gfvip-restore-agent`):** Read-only permissions (`s3:GetObject`, `s3:ListBucket`) used strictly during disaster recovery events.

---

## 5. Anti-Ransomware & Immutability Architecture

Ransomware attacks frequently target network backups to prevent victim recovery. GFVIP protects offsite archives using:

1. **Write-Once-Read-Many (WORM):**
   - Enabling **S3 Object Lock** in **Compliance Mode** for 30 days. In this mode, no user—including the root account—can overwrite or delete a backup object until the retention duration expires.
2. **Non-Destructive Synchronization:**
   - Mirroring (`/MIR`) or destructive sync commands that propagate source deletions are strictly prohibited. The offsite script only performs append-only copies.
3. **Integrity Sidecars (SHA-256):**
   - Before uploading any dump or SAR product, the backup engine computes its SHA-256 checksum and uploads `<file>.sha256`. The recovery agent recalculates and validates the hash before executing `pg_restore`.

---

## 6. Retention & Lifecycle Architecture

| Tier | Location | Retention Period | Action upon Expiry |
|---|---|---|---|
| **Tier 1 (Hot Local)** | `C:\backups\gfvip` | 14 Days | Local script prunes dumps older than 14 days, always retaining at least the newest dump. |
| **Tier 2 (Offsite Warm)** | Cloud Object Storage (Standard/Hot) | 30 Days | Retained under Object Lock for immediate single-click recovery. |
| **Tier 3 (Offsite Cold)** | Cloud Archive (Glacier / Coldline / Cool) | 90 Days | Automatically transitioned via Lifecycle Rules; deleted after 90 days. |
| **Tier 4 (Quarterly Golden Snapshot)** | Immutable Vault | 365 Days | End-of-quarter snapshots flagged with legal hold for compliance auditing. |

---

## 7. Cost Model & Configuration Prerequisites for Operator Approval

Prior to activating offsite synchronization, the platform operator must confirm:

1. **Provider Selection:** Choose between S3-compatible (e.g. Wasabi), AWS S3, Azure Blob, GCS, or secondary NAS.
2. **Budget Allocation:** Monthly budget estimated at **$2.00 – $5.00 USD/month** for ~100 GB storage with egress reserves.
3. **Domain & Egress Allowlist:** Firewall rules must permit outbound HTTPS on port 443 to the designated storage endpoint.
4. **Credential Setup:** Setup isolated IAM credentials with `PutObject` only and store in Windows Credential Manager.

---

## 8. Disaster Recovery Runbook (From Offsite Archive)

In the event of complete local storage loss:

1. **Provision Target Host:**
   - Install PostgreSQL 18 with PostGIS 3.6.2.
   - Checkout GFVIP repository and install PHP 8.4 dependencies.
2. **Download Verified Archive:**
   ```powershell
   aws s3 cp s3://<gfvip-offsite-bucket>/manifests/latest_backup_manifest.json C:\backups\gfvip\latest_backup_manifest.json
   aws s3 cp s3://<gfvip-offsite-bucket>/database/<dump_name>.dump C:\backups\gfvip\database\<dump_name>.dump
   aws s3 cp s3://<gfvip-offsite-bucket>/database/<dump_name>.dump.sha256 C:\backups\gfvip\database\<dump_name>.dump.sha256
   ```
3. **Verify Integrity Checksum:**
   ```powershell
   $expectedHash = (Get-Content C:\backups\gfvip\database\<dump_name>.dump.sha256).Split(" ")[0]
   $actualHash = (Get-FileHash C:\backups\gfvip\database\<dump_name>.dump -Algorithm SHA256).Hash
   if ($expectedHash -ne $actualHash) { throw "Integrity check failed!" }
   ```
4. **Restore Database:**
   ```powershell
   & "C:\Program Files\PostgreSQL\18\bin\pg_restore.exe" -h 127.0.0.1 -p 5432 -U postgres -d global_fishing_vessel_intelligence --clean --if-exists "C:\backups\gfvip\database\<dump_name>.dump"
   ```
5. **Download SAR Evidence & SAFE Products:**
   ```powershell
   aws s3 sync s3://<gfvip-offsite-bucket>/sar_evidence/ C:\XPROJECT\global-fishing-vessel-intelligence\storage\app\sar_evidence/
   aws s3 sync s3://<gfvip-offsite-bucket>/sar_products/ C:\XPROJECT\global-fishing-vessel-intelligence\storage\app\sar_products/
   ```
6. **Validate Application State:**
   ```powershell
   php artisan test
   ```
