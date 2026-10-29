# Global Fishing Vessel Tracking (GFVT)
## Production Operations & Incident Response Runbook

**Document ID:** `GFVIP-OPS-RUN-001`  
**Classification:** Standard Operating Procedure (SOP)  
**Version:** 2.0-GFVIP  
**Target:** Production Platform Administrators, Maritime Analysts, and DevSecOps Engineers  

---

## 1. NORMAL OPERATION (ROUTINE PROCEDURES)

### 1.1 Daily System Health Verification
Operators should inspect the `/settings` dashboard or query the automated probe daily:
```bash
curl -s http://127.0.0.1:8000/api/health | jq .
```
Expected output:
- `application`: `"ok"`
- `database`: `"ok"`
- `postgis`: `"ok"`
- `queue`: `"ok"`
- `scheduler`: `"active"`
- `ais`: `"ok"`
- `backup`: `"healthy"`
- `disaster_recovery`: `"verified"`

### 1.2 Automated Daily Backup Execution
- **Schedule:** Everyday at 02:00:00 AM local time via Windows Task Scheduler (`GFVIP_Automated_Backup`).
- **Target Dump:** `C:\backups\gfvip\database\gfvip_db_YYYYMMDD_HHMMSS.dump`
- **Retention:** 14 days rolling retention with automatic protection of the latest dump.
- **Manifest:** Verified at `C:\backups\gfvip\latest_backup_manifest.json`.

---

## 2. BACKUP FAILURE RESPONSE RUNBOOK

### 2.1 Symptoms & Detection
- Alert signal: `BACKUP_FAILED` or `BACKUP_AGE_WARNING` on `/settings` or `/api/health`.
- Task Scheduler exit code non-zero.

### 2.2 Triage & Recovery Steps
1. **Check Backup Logs:**
   Inspect `C:\backups\gfvip\logs\backup_YYYYMMDD.log`.
2. **Verify Disk Space:**
   Ensure Drive C: has > 10% free space.
3. **Manual Backup Execution:**
   Execute non-destructively:
   ```powershell
   powershell.exe -ExecutionPolicy Bypass -File "deployment\windows\backup-gfvip.ps1"
   ```
4. **Validate Exit Code:**
   Must return exit code `0`. Confirm `latest_backup_manifest.json` updates with `"status": "SUCCESS"`.

---

## 3. DISASTER RECOVERY & RESTORATION PROCEDURE

### 3.1 Weekly Automated Verification (Dry-Run on Isolated DB)
Verify recovery without affecting operational data:
```powershell
powershell.exe -ExecutionPolicy Bypass -File "deployment\windows\verify-recovery.ps1"
```
The script will:
- Create isolated temporary database `gfvip_isolated_recovery_test`.
- Restore the latest dump via `pg_restore.exe`.
- Verify 54 detections, 2 scenes, candidate `542e73f3-35ea-4ff6-bc6a-fa71d5d1fee7-0054`, and evidence crops.
- Safely drop `gfvip_isolated_recovery_test`.

### 3.2 Full Disaster Recovery (Primary Database Loss)
If operational database `global_fishing_vessel_intelligence` is corrupted or destroyed:
1. Stop running background services (`queue:work`, `schedule:work`, `serve`).
2. Re-create empty operational database:
   ```bash
   psql -U postgres -d postgres -c "DROP DATABASE IF EXISTS global_fishing_vessel_intelligence;"
   psql -U postgres -d postgres -c "CREATE DATABASE global_fishing_vessel_intelligence;"
   ```
3. Restore from latest verified dump:
   ```bash
   pg_restore -U postgres -d global_fishing_vessel_intelligence --no-owner --no-privileges "C:\backups\gfvip\database\<dump_name>.dump"
   ```
4. Restore SAR Storage files:
   ```powershell
   robocopy "C:\backups\gfvip\sar_storage\sar_products" "storage\app\sar_products" /E /XO
   robocopy "C:\backups\gfvip\sar_storage\sar_evidence" "storage\app\sar_evidence" /E /XO
   ```
5. Run test suite to verify platform integrity:
   ```bash
   php artisan test
   ```

---

## 4. AIS TELEMETRY STREAM DISCONNECTION

### 4.1 Symptoms
- Alert signal: `AIS_TELEMETRY_VERY_STALE` (> 6 hours without fresh positions).
- Status on `/providers` displays `stale` or `disconnected`.

### 4.2 Triage & Recovery
1. Check process watcher:
   ```powershell
   Get-Process -Name "php"
   ```
2. Verify outbound TLS connection to `stream.aisstream.io:443`:
   ```powershell
   Test-NetConnection -ComputerName stream.aisstream.io -Port 443
   ```
3. Restart AIS ingestion daemon:
   ```powershell
   powershell.exe -ExecutionPolicy Bypass -File "deployment\windows\run-ais-daemon.ps1"
   ```
4. Verify organic live positions resume in `vessel_positions`.

---

## 5. COPERNICUS CDSE AUTHENTICATION FAILURE

### 5.1 Symptoms
- Status on `/providers` or `/api/health` indicates `error` or `not_configured`.
- Log entries: `Copernicus CDSE OAuth2 Token Error`.

### 5.2 Resolution
1. Verify credentials in `.env`: `COPERNICUS_CLIENT_ID` and `COPERNICUS_CLIENT_SECRET`.
2. Clear cached bearer token:
   ```bash
   php artisan cache:forget copernicus_cdse_oauth_token
   ```
3. Test OAuth2 handshake manually:
   ```bash
   php artisan tinker --execute="var_dump(app(App\Services\Providers\CopernicusSarAdapter::class)->healthCheck());"
   ```

---

## 6. GLOBAL FISHING WATCH (GFW) HTTP 403 HANDLING

### 6.1 Status & Scientific Policy
- Endpoint `/datasets` returns HTTP 200 OK.
- Endpoint `/events` returns HTTP 403 `ACCESS NOT AUTHORIZED`.
- **STRICT DIRECTIVE:** Do NOT create synthetic encounter records. Keep status transparently visible as `ACCESS NOT AUTHORIZED`.
- To enable `/events`: Operator must apply for an academic/non-commercial research tier license at `globalfishingwatch.org`. Once granted, update `GFW_API_TOKEN` in the production environment.

---

## 7. QUEUE WORKER & SCHEDULER FAILURE

### 7.1 Symptoms
- `failed_jobs` > 0 or pending jobs accumulating without progress.
- Alert: `QUEUE_FAILED_JOBS`.

### 7.2 Resolution
1. View failed job details:
   ```bash
   php artisan queue:failed
   ```
2. Restart queue worker daemon:
   ```bash
   php artisan queue:restart
   ```
3. Retry failed jobs after fixing root cause:
   ```bash
   php artisan queue:retry all
   ```

---

## 8. DISK SPACE WARNING / STORAGE EXHAUSTION

### 8.1 Thresholds
- `> 20% Free`: **HEALTHY**
- `10% – 20% Free`: **WARNING**
- `< 10% Free`: **CRITICAL**

### 8.2 Triage
1. Check Drive C: utilization:
   ```powershell
   Get-Volume -DriveLetter C
   ```
2. Remove expired temporary files from `storage/logs/` or run dump pruning:
   ```powershell
   powershell.exe -ExecutionPolicy Bypass -File "deployment\windows\backup-gfvip.ps1" -RetentionDays 7
   ```
3. **DO NOT** delete SAR evidence crops (`storage/app/sar_evidence`). These contain scientific forensic crops supporting detection validation.

---

## 9. OFFSITE BACKUP ACTIVATION PROCEDURE (SUPER ADMIN ONLY)

Offsite backup is governed by the **Offsite Backup Activation Gate**:
1. Log into GFVIP as `super_admin`.
2. Navigate to `/settings` &bull; **Offsite Backup Activation Gate**.
3. Select target provider (e.g., `S3_COMPATIBLE` or `AWS_S3`).
4. Enter target bucket URI (e.g., `s3://my-gfvip-vault/backups`).
5. Select status:
   - `DRY_RUN`: Test network paths without file upload.
   - `ACTIVE`: Authorize production backup replication.
6. Check the confirmation box and click **Simpan Perubahan Gerbang**.
7. The operation is immutably recorded in `audit_logs` with the administrator's identity and timestamp.

---

## 10. PRODUCTION DEPLOYMENT & ZERO-DOWNTIME RELEASE

Follow the 6-step deployment pipeline:
```bash
# 1. Enter maintenance mode with bypass secret
php artisan down --secret="gfvip-ops-maintenance-bypass"

# 2. Update dependencies
composer install --no-dev --optimize-autoloader

# 3. Apply audited database migrations (NEVER use migrate:fresh)
php artisan migrate --force

# 4. Cache configurations and routes
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# 5. Restart background workers
php artisan queue:restart

# 6. Exit maintenance mode
php artisan up
```

---

## 11. ROLLBACK PROCEDURE

If a fatal application defect is detected post-deployment:
1. Revert to previous Git release tag:
   ```bash
   git checkout <previous_release_tag>
   ```
2. Re-cache configurations:
   ```bash
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   ```
3. Restart worker daemons:
   ```bash
   php artisan queue:restart
   ```
4. Verify health via `/api/health`.
