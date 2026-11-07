# GFVIP — POST-DEPLOYMENT SMOKE TEST SOP
**Platform:** Global Fishing Vessel Intelligence Platform (GFVIP)  
**Target URL:** `https://gfw.nagakecil.site`  
**Execution Timeframe:** 0 - 30 Menit Setelah Rilis  
**Evaluator:** QA / Maritime System Administrator / Lead Engineer  
**Database Identity:** `gfvip_1` (PostgreSQL 18 + PostGIS 3.6.2)

---

## 1. Automated Smoke Test Script (Command Line)

Jalankan perintah ini di server produksi untuk memverifikasi fungsionalitas inti:

```bash
cd /www/wwwroot/gfw.nagakecil.site

# 1. Jalankan subset feature test kritis
php artisan test --filter=ProviderHealthTest
php artisan test --filter=OpenWatersMapInterfaceTest

# 2. Uji probe konektivitas GFW API v3 resmi
php artisan tinker --execute="
\$gfw = app(App\Services\Providers\GfwApiService::class);
echo 'GFW Auth Probe: ' . json_encode(\$gfw->probeAuthentication()) . PHP_EOL;
echo 'GFW Capabilities: ' . json_encode(\$gfw->checkDatasetCapabilities()) . PHP_EOL;
"

# 3. Uji status daemon ingestion Node.js AIS
systemctl status ais-node-worker --no-pager
```

> [!NOTE]
> **JAMINAN ISOLASI DATABASE PADA PENGUJIAN (`php artisan test`):**
> File `phpunit.xml` mengonfigurasi `DB_CONNECTION=sqlite` dan `DB_DATABASE=:memory:`. Setiap eksekusi pengujian berjalan 100% pada memori RAM sementara dan secara fisik **TIDAK BISA** membaca atau mengubah database PostgreSQL produksi (`gfvip_1`).

---

## 2. Manual UI & End-to-End Verification Checklist

Lakukan verifikasi interaktif melalui browser (Desktop & Mode Incognito):

### A. Portal Overview & Otentikasi
- [ ] Buka `https://gfw.nagakecil.site/` — Halaman memuat dalam < 1.5 detik tanpa error 500.
- [ ] Buka `/login` — Form login lokal memuat dengan CSRF token valid.
- [ ] Login pengguna berwenang berhasil diarahkan ke dashboard.
- [ ] Tombol Google SSO mengarahkan ke halaman persetujuan Google Accounts.
- [ ] Hak akses RBAC terproteksi: Pengguna non-admin menerima HTTP 403 pada rute administratif.

### B. Katalog Kapal (`/vessels`) & Filter Fleet Type
- [ ] Buka `/vessels` — Daftar kapal memuat dengan penomoran berurutan (custom pagination).
- [ ] Dropdown filter **Fleet Type** berfungsi menyaring kategori (`fishing`, `carrier`, `support`, `patrol`, dll).
- [ ] Badge status klasifikasi (`Verified`, `Provider Reported`, `Unverified`) tampil dengan warna styling nautical.
- [ ] Pencarian instan (nama kapal, MMSI, IMO) mengembalikan hasil akurat tanpa reload halaman.

### C. Profil Detail Kapal (`/vessels/{id}`) & Rekonsiliasi GFW
- [ ] Buka halaman salah satu kapal terdaftar.
- [ ] **Seksi 1 (Identity):** Menampilkan nama kapal, MMSI, IMO, Callsign, dan Bendera negara.
- [ ] **Seksi 2 (Classification):** Menampilkan taksonomi Fleet Type dan tingkat keyakinan (confidence).
- [ ] **Seksi 3 (Spesifikasi Teknis):** Menampilkan panjang LOA, Gross Tonnage, dan Daya Mesin (kW).
- [ ] **Seksi 4 (Kepemilikan):** Menampilkan nama pemilik terdaftar (Owner / Operator).
- [ ] **Seksi 5 (Riwayat Event GFW):** Menampilkan Port Visits, Loitering, Fishing, dan Encounters.
- [ ] **Seksi 6 (Jejak Provenansi):** Menampilkan rekonsiliasi sumber data dan pelacakan konflik.

### D. Peta Interaktif Open Waters AIS (`/map`)
- [ ] Base map MapLibre GL JS memuat gaya vektor nautical dark theme (OpenFreeMap).
- [ ] Fallback peta offline ESRI berfungsi jika koneksi vector tiles eksternal terhambat.
- [ ] Marker kapal AIS berbentuk simbol arah kapal dan berotasi sesuai Heading / COG.
- [ ] Warna marker membedakan kategori (Fishing: Hijau/Cyan, Carrier: Biru, Patrol: Merah).
- [ ] Klik marker membuka **sliding panel detail kapal di sisi kanan**.
- [ ] Hash URL di bilah browser tersinkronisasi otomatis (misal `#map=12.5/1.234/104.567&vessel=525801421`).
- [ ] Kontrol zoom, kompas orientasi, skala nautika, dan info koordinat kursor bekerja responsif.

### E. Telemetri AIS Real-Time & Node Worker
- [ ] Buka `/tracking` — Status AIS Stream aktif dengan indikator live streaming hijau.
- [ ] Log worker menunjukkan aliran ingest micro-batch: `tail -n 20 storage/logs/ais-worker.log`.

### F. Layer Satelit Sentinel-1 SAR & Dark Vessel Candidates
- [ ] Pada peta `/map`, aktifkan layer **SAR Detections**.
- [ ] Poligon footprint citra satelit dan kandidat dark vessel SAR ter-render rapi.

---

## 3. Provider Fail-Safe Verification (Uji Ketahanan Sistem)

Platform GFVIP dirancang dengan prinsip **Fault Tolerance**:

1. **Skenario GFW Events Quota Exceeded (HTTP 429) atau Forbidden (HTTP 403):**
   - Halaman `/vessels/{id}` **TIDAK BOLEH** menghasilkan error 500.
   - Seksi intelijen GFW harus menampilkan pesan anggun: *"Data event GFW tidak tersedia untuk dataset ini atau memerlukan lisensi riset."*
   - Layer AIS, peta interaktif, dan pencarian kapal tetap **100% beroperasi normal**.
2. **Skenario Base Map CDN Lambat / Timeout:**
   - MapLibre GL JS otomatis beralih ke layer satelit/vektor cadangan tanpa merusak render marker kapal.
3. **Skenario Token Kadaluarsa:**
   - Token rahasia **TIDAK PERNAH** dicetak ke layar pengguna atau log publik (`[REDACTED]`).

---

## 4. Smoke Test Sign-Off Template

```
Waktu Pengujian : YYYY-MM-DD HH:MM WIB
Penguji         : [Nama Penguji / Role]
Database Target : gfvip_1
Hasil Akhir     : [ ] PASSED  /  [ ] FAILED  /  [ ] PASSED WITH NOTES

Catatan Evaluasi:
- Pengujian PHPUnit lulus: YA / TIDAK
- Peta Open Waters AIS responsif: YA / TIDAK
- GFW API v3 terotentikasi: YA / TIDAK
- AIS Stream menerima posisi baru: YA / TIDAK

Tanda Tangan Penguji: ________________________
```
