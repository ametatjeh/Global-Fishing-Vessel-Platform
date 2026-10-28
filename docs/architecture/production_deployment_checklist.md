# Global Fishing Vessel Intelligence Platform (GFVIP)
## Production Deployment Configuration Checklist

**Document ID:** `GFVIP-OPS-CHK-001`  
**Classification:** Standard Operating Procedure (SOP)  
**Target:** Production Server Environment (Linux / Windows Server)  

---

## 1. Environment & Framework Hardening

| Setting / Check | Target Production Value | Security Rationale |
|---|---|---|
| `APP_ENV` | `production` | Disables debug features, enables production caching optimizations. |
| `APP_DEBUG` | `false` | **CRITICAL:** Prevents exposure of stack traces, database credentials, server paths, and query arguments. |
| `APP_KEY` | 32-character base64 string | Encrypts session cookies, encrypted database columns, and signed URLs. Must be generated once via `php artisan key:generate` on production host. |
| `APP_URL` | `https://gfvip.domain.gov` | Enforces canonical HTTPS URL generation in emails, signed links, and API payloads. |
| `LOG_LEVEL` | `error` or `warning` | Minimizes log bloat and prevents sensitive payload logging in routine operations. |
| `SESSION_SECURE_COOKIE` | `true` | Ensures session cookies are transmitted exclusively over encrypted HTTPS connections. |

---

## 2. Web Server & Reverse Proxy Configuration

- **HTTPS / TLS Configuration:**
  - Enforce TLS 1.3 (or TLS 1.2+); disable SSLv3, TLS 1.0, TLS 1.1.
  - HSTS header: `Strict-Transport-Security: max-age=31536000; includeSubDomains; preload`.
- **Security Headers:**
  - `X-Frame-Options: SAMEORIGIN`
  - `X-Content-Type-Options: nosniff`
  - `Referrer-Policy: strict-origin-when-cross-origin`
  - Content Security Policy (CSP) tailored for MapLibre GL JS vector tiles and satellite tile layers.
- **Trusted Proxies (`bootstrap/app.php` or middleware):**
  - Configure `trustProxies(at: ['10.0.0.0/8', '172.16.0.0/12'])` if sitting behind Cloudflare, AWS ALB, or NGINX reverse proxy.

---

## 3. Filesystem Permissions & Directory Structure

Ensure the web server process (e.g. `www-data` or IIS `IUSR`) has least-privilege permissions:

- **Read-Only:**
  - Application code (`app/`, `bootstrap/`, `config/`, `routes/`, `resources/`, `vendor/`)
- **Read-Write:**
  - `storage/` (specifically `storage/app/sar_products`, `storage/app/sar_evidence`, `storage/framework/`, `storage/logs/`)
  - `bootstrap/cache/`
- **Ownership (Linux example):**
  ```bash
  chown -R root:www-data /var/www/gfvip
  chown -R www-data:www-data /var/www/gfvip/storage /var/www/gfvip/bootstrap/cache
  chmod -R 775 /var/www/gfvip/storage /var/www/gfvip/bootstrap/cache
  ```

---

## 4. Production Service Management (Systemd / Task Scheduler)

1. **Queue Worker Daemon:**
   - Must run continuously as a background service:
     `php artisan queue:work --queue=default,sar_processing,high --tries=3 --timeout=300 --stop-when-empty=false`
   - Managed via `systemd` (Linux) or Windows Service / NSSM.
2. **Scheduler (Cron / Scheduled Task):**
   - Must run every minute:
     `* * * * * cd /var/www/gfvip && php artisan schedule:run >> /dev/null 2>&1`
3. **Automated Daily Backup:**
   - Scheduled task or cron job running daily at 02:00:
     `deployment/windows/backup-gfvip.ps1` (Windows) or equivalent shell script.
4. **Disaster Recovery Integrity Test:**
   - Weekly automated dry-run restoration verifying dump integrity and record counts against manifests.

---

## 5. Deployment Pipeline & Cache Warming

During code release, execute in sequence:

```bash
# 1. Maintenance mode
php artisan down --secret="operator-maintenance-bypass-token"

# 2. Update dependencies
composer install --no-dev --optimize-autoloader

# 3. Apply database migrations (audit first!)
php artisan migrate --force

# 4. Optimize caches
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

## 6. Secrets & Provider Credentials Management

- Store CDSE credentials (`COPERNICUS_CLIENT_ID`, `COPERNICUS_CLIENT_SECRET`) and GFW tokens exclusively in the server environment or a secure vault (e.g., HashiCorp Vault, AWS Secrets Manager).
- Never commit `.env` or plain credential files to version control.
- Enforce periodic token rotation.
