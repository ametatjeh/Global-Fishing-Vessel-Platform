# GFVIP — POST-DEPLOYMENT SMOKE TEST SOP
**Platform:** Global Fishing Vessel Intelligence Platform (GFVIP)  
**Target URL:** `https://gfw.nagakecil.site`  
**Execution Timeframe:** 0 - 30 Menit Setelah Rilis  
**Evaluator:** QA / Maritime System Administrator / Lead Engineer

---

## 1. Automated Smoke Test Script (Command Line)

Jalankan perintah ini langsung di terminal server setelah deployment:

```bash
cd /www/wwwroot/gfw.nagakecil.site

# 1. Jalankan subset feature test kritis
php artisan test --filter=VesselClassificationTest
php artisan test --filter=GfwVesselEnrichmentTest
php artisan test --filter=ProviderHealthTest
php artisan test --filter=AisStreamIngestionRebuildTest
php artisan test --filter=OpenWatersMapInterfaceTest

# 2. Uji probe konektivitas GFW API v3
php artisan tinker --execute="
\$gfw = app(App\Services\Providers\GfwApiService::class);
echo 'GFW Auth Probe: ' . json_encode(\$gfw->probeAuthentication()) . PHP_EOL;
echo 'GFW Capabilities: ' . json_encode(\$gfw->checkDatasetCapabilities()) . PHP_EOL;
"
```

---

## 2. Manual UI & End-to-End Verification Checklist

Lakukan checklist interaktif melalui browser (Mode Desktop & Incognito):

### A. Homepage & Portal Overview
- [ ] Buka `https://gfw.nagakecil.site/` — Halaman landing page memuat dalam < 1.5 detik.
- [ ] Kartu ringkasan metrik (Total Kapal, Deteksi Terkini, Status AIS) menampilkan angka valid.
- [ ] Tidak ada error 500 atau pesan unhandled exception di layar.

### B. Otentikasi & Hak Akses (RBAC)
- [ ] Buka `/login` — Form login lokal memuat dengan token CSRF yang valid.
- [ ] Login sebagai pengguna berwenang berhasil diarahkan ke dashboard.
- [ ] Tombol "Login dengan Google" mengarahkan ke OAuth Google (`accounts.google.com`).
- [ ] Pengguna tanpa hak akses super admin tidak dapat mengakses `/admin/users`.
- [ ] Sesi logout bekerja sempurna dan membersihkan cookie sesi.

### C. Registri Kapal (`/vessels`)
- [ ] Buka `/vessels` — Daftar kapal terdaftar memuat dengan penomoran berurutan.
- [ ] Dropdown filter **Fleet Type** berfungsi menyaring kategori (`fishing`, `carrier`, `support`, `patrol`, dll).
- [ ] Badge status klasifikasi (`Verified`, `Provider Reported`, `Unverified`) tampil dengan warna yang sesuai.
- [ ] Pencarian instan (nama kapal, MMSI, IMO) mengembalikan hasil secara akurat.

### D. Profil Detail Kapal (`/vessels/{id}`)
- [ ] Buka halaman salah satu kapal (misal target kapal 4 ROSES atau kapal terverifikasi).
- [ ] **Seksi 1 (Identity Header):** Nama, MMSI, IMO, Callsign, dan Bendera negara tertera rapi.
- [ ] **Seksi 2 (Classification & Fleet Type):** Sumber data dan confidence level tercantum transparan.
- [ ] **Seksi 3 (Spesifikasi Teknis):** Panjang LOA, Lebar (Beam), Gross Tonnage, dan Daya Mesin (kW).
- [ ] **Seksi 4 (Operator & Kepemilikan):** Nama operator dan pemilik legal ditampilkan (atau tanda strip jika tidak tersedia).
- [ ] **Seksi 5 (Riwayat Event GFW):** Rekapitulasi Port Visits, Loitering, Fishing, dan Encounters.
- [ ] **Seksi 6 (Jejak Audit & Provenansi):** Riwayat perubahan dan pelacakan konflik tersimpan rapi.

### E. Peta Interaktif Open Waters AIS (`/map`)
- [ ] Peta MapLibre GL JS memuat gaya vektor nautical dark theme (OpenFreeMap).
- [ ] Fallback peta offline ESRI berfungsi jika koneksi vector tiles eksternal terhambat.
- [ ] Marker kapal AIS berbentuk simbol arah kapal dan berotasi sesuai Heading / COG.
- [ ] Warna marker kapal membedakan kategori (Fishing: Cyan/Green, Carrier: Blue, Patrol: Red/Amber).
- [ ] Klik marker membuka **sliding panel detail kapal di sisi kanan**.
- [ ] Hash URL di bilah alamat browser tersinkronisasi otomatis (misal `#map=12.5/1.234/104.567&vessel=525801421`).
- [ ] Tombol kontrol zoom, kompas orientasi, skala nautika, dan koordinat kursor bekerja responsif.

### F. Telemetri AIS Real-Time & Daemon Service
- [ ] Buka `/tracking` — Status AIS Stream aktif dengan indikator live streaming hijau.
- [ ] Periksa daemon Node.js di server: `systemctl status ais-node-worker`.
- [ ] Endpoint webhook micro-batch `/api/telemetry/ais/batch` menerima paket AIS dengan header `X-AIS-Token` yang valid.

### G. Layer Satelit Sentinel-1 SAR & Dark Vessel Candidates
- [ ] Pada peta `/map`, aktifkan layer **SAR Detections**.
- [ ] Deteksi citra SAR menampilkan poligon footprint scene dan marker deteksi target non-AIS.
- [ ] Filter TCR (Target-to-Clutter Ratio) dan platform satelit Sentinel-1A berfungsi baik.

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
Hasil Akhir     : [ ] PASSED  /  [ ] FAILED  /  [ ] PASSED WITH NOTES

Catatan Tambahan:
- Semua 227 pengujian PHPUnit lulus: YA / TIDAK
- Peta Open Waters AIS responsif: YA / TIDAK
- GFW API v3 terotentikasi: YA / TIDAK
- AIS Stream menerima posisi baru: YA / TIDAK

Tanda Tangan Penguji: ________________________
```

