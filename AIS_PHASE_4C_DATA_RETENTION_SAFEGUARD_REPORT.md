# LAPORAN IMPLEMENTASI TAHAP 4C: DATA RETENTION SAFEGUARD
## Global Fishing Vessel Tracking (GFVT)

**Tanggal:** 8 November 2026  
**Status Tahap 4C:** **IMPLEMENTASI SELESAI & SELURUH TEST BERHASIL (14/14 PASS)**  
**Repositori:** `C:\XPROJECT\global-fishing-vessel-intelligence`

---

## 1. RINGKASAN EKSEKUTIF

Tahap 4C berhasil mengimplementasikan pengamanan tingkat lanjut (*Data Retention Safeguard Engine*) pada sistem retensi telemetri AIS historis GFVIP.

Implementasi ini mengintegrasikan perlindungan mutlak bagi data perikanan, menerapkan isolasi proteksi (*Protective Hold*) untuk klasifikasi yang belum terbukti, mengunci data 5 kapal historis dari pembersihan spekulatif, dan menjamin seluruh rekaman telemetri yang memiliki tautan bukti operasional maritim (**SAR Detections, Encounters, Dark Vessel Gap Indicators, Investigations, Compliance Events, dan Audit Logs**) **tidak dapat dihapus** oleh mekanisme retensi otomatis apa pun.

Seluruh 14 skenario pengujian perlindungan data telah lulus 100% (*76 assertions*) pada test suite terisolasi tanpa menyentuh data produksi operasional.

---

## 2. AUDIT KODE SEBELUM PERUBAHAN (BASELINE AUDIT)

Sebelum modifikasi dilakukan, audit kode menemukan sejumlah celah potensial pada sistem retensi lama:

1. **Definisi Fishing Terlalu Longgar:**  
   Pada `DataRetentionPolicyService.php` lama, klausul proteksi hanya memeriksa `vessels.vessel_type != 'fishing'`. Ini berarti kapal apa pun yang menyandang label teks `fishing` di database (meskipun tanpa bukti kode AIS atau verifikasi analis) otomatis mendapatkan perlindungan permanen seolah-olah kapal tersebut terverifikasi.
2. **Ketiadaan Konsep Protective Hold untuk Kapal Unverified:**  
   Tidak ada pemisahan antara kapal perikanan terverifikasi (*Verified Fishing*) dan kapal yang belum terbukti (*Unverified Fishing* / *Unverified Historical*). Kapal historis yang masuk akibat anomali masa lalu dapat terancam terhapus jika diubah statusnya, atau sebaliknya mendapatkan status permanen tanpa justifikasi bukti yang sah.
3. **Cakupan Bukti Operasional Belum Lengkap:**  
   Proteksi bukti operasional pada sistem lama belum mencakup indikator posisi anomali *Dark Vessel* (`is_gap_indicator = true`) serta catatan verifikasi entitas kapal pada `audit_logs`.
4. **Verifikasi Batch Purge Bersifat Optimistik:**  
   Proses pembersihan lama menghapus data batch tanpa pra-validasi (*pre-flight safeguard check*) di dalam transaksi database untuk menjamin bahwa tidak ada satupun ID dalam batch yang terikat pada kapal yang dilindungi.

---

## 3. FILE YANG DIUBAH & DIIMPLEMENTASIKAN

| File | Status | Peran & Perubahan Kunci |
|---|---|---|
| `app/Models/Vessel.php` | **MODIFIED** | Menambahkan konstanta `HISTORICAL_PROTECTED_MMSIS`, method helper: `isVerifiedFishing()`, `isUnverifiedFishing()`, `isHistoricalProtected()`, `isUnderProtectiveHold()`, `isVerifiedNonFishing()`. |
| `app/Models/VesselPosition.php` | **MODIFIED** | Menambahkan method helper `isDarkVesselIndicator()` untuk mendeteksi rekaman posisi indikator gap transmisi AIS. |
| `app/Services/DataRetentionPolicyService.php` | **MODIFIED** | Merestrukturisasi engine retensi: `getProtectiveHoldVesselIds()`, `getVerifiedFishingVesselIds()`, `getAllProtectedVesselIds()`, audit bukti operasional 6-layer, simulasi dry-run kaya metrik, dan pre-flight safe batch deletion ber-transaksi. |
| `app/Console/Commands/PruneAisTelemetryCommand.php` | **MODIFIED** | Memperbarui antarmuka CLI `--dry-run` untuk menampilkan detail metrik: Verified Fishing vs Unverified Hold, perlindungan 5 kapal historis, proteksi Dark Vessel, dan rentang tanggal terdampak. |
| `tests/Feature/AisRetentionSafeguardTest.php` | **NEW** | Test suite komprehensif menguji ke-14 kriteria wajib pengamanan retensi data maritim (14 test, 76 assertions). |
| `tests/Feature/DataRetentionPolicyTest.php` | **VERIFIED** | Memverifikasi kompatibilitas mundur (9 test, 42 assertions, 100% PASS). |

---

## 4. ATURAN KLASIFIKASI & RETENSI YANG DITERAPKAN

### A. Verified Fishing (Penyimpanan Permanen Mutlak)
- **Kriteria Bukti:** Kapal dengan `vessel_type === 'fishing'` **DAN** memenuhi salah satu dari dua bukti otentik:
  1. Memiliki bukti kode transmisi AIS ITU: `ais_ship_type_code === 30`.
  2. Telah diverifikasi secara manual oleh analis berwenang: `verification_status === 'verified'`.
- **Kebijakan:** Seluruh rekaman posisi dan lintasan disimpan **permanen**. Dikecualikan 100% dari kandidat purge (`candidates = 0`, status `PROTECTED_PERMANENT`).

### B. Unverified Fishing & Unverified Historical (Protective Hold)
- **Kriteria Bukti:** Kapal dengan `vessel_type === 'fishing'`, namun **tidak** memiliki bukti AIS code 30 dan **belum** diverifikasi analis (`verification_status !== 'verified'`).
- **Kebijakan:**
  - **TIDAK** otomatis dianggap sebagai Fishing.
  - **TIDAK** diberikan status Fishing Permanent.
  - Otomatis dimasukkan ke **Protective Hold** (`PROTECTIVE_HOLD`).
  - Posisi historis **DILARANG DIHAPUS** sampai proses review identitas atau verifikasi administrasi selesai.

### C. Provider Reported
- Label `verification_status === 'provider_reported'` **tidak disamakan** dengan `verified`.
- Sistem memeriksa kombinasi `classification_source`, `ais_ship_type_code`, dan `classified_at`.
- Hanya kapal berstatus `provider_reported` yang membawa bukti kode AIS sah (misal code 30 untuk fishing, code 70 untuk cargo, code 80 untuk tanker) yang diakui klasifikasinya. Jika membawa label fishing tanpa code 30, wajib masuk *Protective Hold*.

### D. Verified Non-Fishing
- Menggunakan kode AIS ShipType yang sah (Cargo 70-79, Tanker 80-89, Passenger 60-69, Tug 31-32/52, Pleasure 36-37, Medical 58, Non-Combatant 59).
- Mengikuti konfigurasi durasi retensi aktif per jenis kapal (misal 90 hari, 60 hari, 30 hari).
- Dilarang mengklasifikasikan kapal berdasarkan tebakan nama, MMSI, atau kecepatan.

### E. Unknown / Belum Terklasifikasi
- Merupakan kategori tersendiri (`vessel_type === 'unknown'` atau NULL).
- **Dilarang keras dipetakan menjadi Fishing**.
- Mengikuti kebijakan durasi retensi Unknown yang dikonfigurasi administrator.
- Kapal Unknown yang belum memiliki bukti klasifikasi namun terhubung dengan investigasi atau anomali tetap dilindungi di bawah *Protective Hold*.

---

## 5. PERLINDUNGAN LIMA KAPAL HISTORIS

Lima kapal historis hasil temuan audit sebelumnya:
1. **WESERLOTSE** — MMSI `211233660`
2. **MAERSK MONTE VERDE** — MMSI `563051800`
3. **XIN TAI YUAN** — MMSI `311002359`
4. **FRANKEN** — MMSI `211443110`
5. **HANS** — MMSI `232056936`

### Status & Penanganan Tahap 4C:
- **Identitas Database Utuh:** Tidak ada klasifikasi, nama, MMSI, atau kolom apa pun yang diubah pada database operasional.
- **Isolasi Proteksi:** Terdaftar eksplisit pada `Vessel::HISTORICAL_PROTECTED_MMSIS` dan otomatis terjaring oleh `getProtectiveHoldVesselIds()`.
- **Hasil Audit Retensi:** Seluruh 5 posisi dari kelima kapal historis ini berstatus **TERLINDUNGI 100% DALAM PROTECTIVE HOLD**. Tidak ada satupun posisi yang masuk ke daftar kandidat purge.

---

## 6. PROTEKSI RELASI OPERASIONAL (6-LAYER OPERATIONAL EVIDENCE)

Sebelum sebuah posisi non-fishing dinyatakan memenuhi syarat penghapusan (*eligible for purge*), sistem memeriksa keterkaitan dengan 6 lapisan bukti operasional maritim:

1. **SAR Satellite Detections (`satellite_detections`):**  
   Posisi dari kapal yang berkorelasi dengan deteksi satelit radar aperture sintetis (SAR) dilindungi secara permanen.
2. **Vessel Encounters (`vessel_encounters` & `encounter_vessels`):**  
   Kapal yang terlibat dalam peristiwa *rendezvous*, *transshipment*, atau pertemuan laut (baik sebagai `vessel_1` maupun `vessel_2`) dikecualikan dari pembersihan.
3. **Dark Vessel Gap Indicators (`vessel_positions.is_gap_indicator`):**  
   Titik telemetri yang menandai anomali pemadaman transponder AIS (`is_gap_indicator = true`) atau deteksi satelit gap (`ais_gap_duration_minutes > 0`) dilindungi mutlak sebagai barang bukti dugaan aktivitas ilegal.
4. **Analyst Investigations (`investigations`):**  
   Kapal target yang terdaftar pada kasus investigasi aktif maupun arsip investigasi (`target_vessel_id`) tidak dapat dibersihkan riwayat posisinya.
5. **Compliance Events (`compliance_events`):**  
   Kapal yang memiliki insiden pelanggaran kepatuhan atau peringatan perbatasan maritim dilindungi dari retensi.
6. **Audit Logs & Master Records (`audit_logs` & `vessel_identifiers`):**  
   Data master kapal, relasi identifier (MMSI, IMO, Callsign), dan log audit **tidak pernah dihapus** meskipun seluruh posisi kadaluwarsa.

---

## 7. HASIL PENGUJIAN FEATURE TEST (14 KRITERIA WAJIB)

Pengujian dijalankan pada database testing terisolasi (`sqlite :memory:`) menggunakan perintah:
```powershell
php artisan test --filter=AisRetentionSafeguardTest
```

### Output Pengujian Aktual:
```text
   PASS  Tests\Feature\AisRetentionSafeguardTest
  ✓ 01 verified fishing is never candidate for purge                                 0.45s  
  ✓ 02 unverified fishing enters protective hold                                     0.03s  
  ✓ 03 five historical vessels are strictly protected                                0.03s  
  ✓ 04 unknown is never treated as fishing                                           0.03s  
  ✓ 05 provider reported does not automatically become verified                      0.02s  
  ✓ 06 verified non fishing follows retention period                                 0.03s  
  ✓ 07 positions referenced by sar are not deleted                                   0.03s  
  ✓ 08 positions referenced by encounter are not deleted                             0.03s  
  ✓ 09 dark vessel and investigations are protected                                  0.03s  
  ✓ 10 audit log and classification history remain available                         0.03s  
  ✓ 11 dry run does not modify database                                              0.04s  
  ✓ 12 batch cleanup does not exceed eligible data limit                             0.03s  
  ✓ 13 disabled retention policy does not execute deletion                           0.03s  
  ✓ 14 transaction failure does not cause partial unsafe deletion                    0.02s  

  Tests:    14 passed (76 assertions)
  Duration: 1.28s
```

### Verifikasi Uji Regresi Lainnya:
- `Tests\Feature\DataRetentionPolicyTest`: **9 passed (42 assertions)**
- `Tests\Feature\AisPhase4aIngestionAndSyncTest`: **9 passed (76 assertions)**
- `node ais/test_worker_hardening.mjs`: **10 / 10 passed**

---

## 8. HASIL DRY-RUN ENGINE RETENSI (READ-ONLY)

Simulasi dry-run dijalankan pada CLI untuk memverifikasi akurasi klasifikasi proteksi terhadap data riil di database PostgreSQL:
```powershell
php artisan ais:prune-telemetry --dry-run
```

### Output Aktual:
```text
====================================================================
 GFVIP - AIS HISTORICAL TELEMETRY RETENTION ENGINE
 Perlindungan Permanen Kapal Penangkap Ikan (Fishing 100% Protected)
====================================================================
[MODE SIMULASI / DRY-RUN] Menghitung kandidat retensi tanpa mengubah database...

Total Posisi AIS Tersimpan: 97
Posisi Fishing Terverifikasi (Permanent): 57 (0 dihapus / 100% aman)
Posisi Fishing Belum Terverifikasi (Protective Hold): 21 (0 dihapus / ditahan)
Lima Kapal Historis Terlindungi: 5 kapal (5 posisi aman)
Kapal Bukti Operasional Terlindungi (SAR / Encounter / Dark Vessel / Investigasi): 0
Posisi Terkait Dark Vessel Gap Indicator Terlindungi: 0

+----------------------------------------+-----------+----------------------+----------------+----------------+--------------------+
| Tipe Kapal                             | Kebijakan | Batas Waktu (Cutoff) | Kandidat Purge | Ditahan (Hold) | Status Keamanan    |
+----------------------------------------+-----------+----------------------+----------------+----------------+--------------------+
| Fishing / Kapal Penangkap Ikan         | Permanen  | —                    | 0              | 21             | PERMANEN (FISHING) |
| Cargo / Kapal Kargo                    | 90 Hari   | 2026-08-09 19:39:45  | 0              | 0              | Tidak Ada Data     |
| Tanker / Kapal Tangki                  | 90 Hari   | 2026-08-09 19:39:45  | 0              | 0              | Tidak Ada Data     |
| Passenger / Kapal Penumpang            | 60 Hari   | 2026-09-08 19:39:45  | 0              | 0              | Tidak Ada Data     |
| Tug / Kapal Tunda                      | 60 Hari   | 2026-09-08 19:39:45  | 0              | 0              | Tidak Ada Data     |
| Pleasure Craft / Kapal Pesiar Rekreasi | 30 Hari   | 2026-10-08 19:39:45  | 0              | 0              | Tidak Ada Data     |
| Other / Tipe Kapal Lainnya             | 90 Hari   | 2026-08-09 19:39:45  | 0              | 0              | Tidak Ada Data     |
| Unknown / Belum Terklasifikasi         | 30 Hari   | 2026-10-08 19:39:45  | 0              | 0              | Tidak Ada Data     |
+----------------------------------------+-----------+----------------------+----------------+----------------+--------------------+

Total Posisi Memenuhi Syarat Purge: 0
Total Posisi Ditahan (Protective Hold & Evidence): 21
Estimasi Kapasitas Disk Dibebaskan: 0 B

Simulasi selesai. Tidak ada data yang dihapus dari database.
====================================================================
```

**Temuan Validasi:**
- Sebanyak **57 posisi** fishing terverifikasi langsung dilindungi permanen.
- Sebanyak **21 posisi** fishing yang belum memiliki kode AIS (termasuk 5 posisi dari 5 kapal historis) berhasil diamankan di dalam **Protective Hold**.
- **Kandidat purge riil adalah 0**. Database operasional tetap utuh 100%.

---

## 9. RISIKO YANG BELUM TERSELESAIKAN & MITIGASI

1. **Review Administratif untuk 5 Kapal Historis:**  
   Kelima kapal historis saat ini aman di dalam *Protective Hold*. Namun, pada tahap berikutnya (misal modul verifikasi identitas admin / Tahap 5), kapal-kapal ini memerlukan keputusan administratif analis (apakah disahkan menjadi kapal non-fishing berdasarkan data registri maritim eksternal, atau tetap dalam pengawasan).
2. **Kapasitas Cache Bounded vs Arus Data Tinggi:**  
   Sistem penahanan *Protective Hold* saat ini bekerja pada level database join. Jika di masa mendatang volume kapal unverified melonjak hingga ratusan ribu, disarankan menambahkan kolom indeks boolean terdenormalisasi `is_under_protective_hold` pada tabel `vessels` untuk akselerasi query subdetik.

---

## 10. KONFIRMASI BATASAN OPERASIONAL & PERLINDUNGAN LINGKUNGAN

Dengan ini dikonfirmasikan bahwa selama pengerjaan Tahap 4C:
1. **TIDAK ADA PENGHAPUSAN (PURGE) AKTUAL** yang dieksekusi terhadap database operasional.
2. **AUTOMATIC CLEANUP TETAP NONAKTIF (DISABLED)** pada System Settings.
3. **LIMA KAPAL HISTORIS DAN HISTORI POSISINYA 100% UTUH** tanpa perubahan nilai atau klasifikasi.
4. **TIDAK ADA COMMAND DESTRUKTIF** (`migrate:fresh`, `db:wipe`, `TRUNCATE`, atau `DROP TABLE`).
5. **AIS WORKER TAHAP 4B & ENDPOINT TAHAP 4A TIDAK DIUBAH**.
6. **DATABASE TESTING TERISOLASI** digunakan untuk seluruh pengujian otomatis.
7. **TIDAK ADA COMMIT ATAU PUSH GIT** yang dilakukan.

Pekerjaan Tahap 4C telah tuntas. Sistem berhenti dan menunggu arahan persetujuan dari pengguna untuk langkah selanjutnya.
