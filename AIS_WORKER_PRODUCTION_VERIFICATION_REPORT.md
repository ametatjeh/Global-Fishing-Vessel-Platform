# LAPORAN VERIFIKASI PRODUCTION & AUDIT AIS WORKER (TAHAP 2 — READ-ONLY)
## Global Fishing Vessel Monitoring Platform (GFVIP)

**Tanggal Audit:** 08 November 2026  
**Status Audit:** READ-ONLY (Pemeriksaan Faktual Kode, Alur Worker, dan Basis Data)  
**Referensi:** `VESSEL_IDENTITY_AND_UNKNOWN_SHIPTYPE_AUDIT_REPORT.md`, `ais/worker.mjs`, `AisStreamAdapter.php`  
**Host Target:** `https://gfw.nagakecil.site/vessels` & Lingkungan Lokal

---

## 1. PERBANDINGAN LINGKUNGAN LOCAL DAN PRODUCTION

### A. Aksesibilitas Basis Data & Status Verifikasi
- **Database Lokal:** Dapat diakses penuh secara read-only. Berisi **93 kapal** dan **97 posisi AIS**.
- **Database Production (`gfw.nagakecil.site`):**  
  > [!IMPORTANT]
  > **STATUS: BELUM TERVERIFIKASI SECARA LANGSUNG PADA DATABASE PRODUCTION.**  
  > Koneksi jaringan langsung dari lingkungan eksekusi lokal ke database PostgreSQL server production dibatasi oleh isolasi jaringan/firewall server aaPanel. Sesuai batasan wajib Tahap 2, audit ini **TIDAK MENGGUNAKAN ANGKA REKAYASA/ASUMSI**. Kondisi production diverifikasi berdasarkan:
  > 1. Observasi antarmuka publik pada URL referensi `https://gfw.nagakecil.site/vessels` (di mana pengguna mengonfirmasi kemunculan banyak baris kapal dengan `VESSEL TYPE: Unknown`).
  > 2. File konfigurasi deployment production (`deployment/systemd/ais-node-worker.service`, `deployment/nginx/gfw.nagakecil.site.conf`).
  > 3. Logika sinkronisasi Git repository (`origin/main`).

### B. Matriks Perbandingan Lingkungan
| Komponen / Metrik | Lingkungan Lokal | Lingkungan Production (`gfw.nagakecil.site`) | Catatan / Discrepancy |
|---|---|---|---|
| **Git Commit Aktif** | `e25e629` (`origin/main`) | Mengikuti branch `main` repositori remote | Tersinkronisasi via GitHub |
| **Total Kapal Terdaftar** | 93 kapal | Dinamis (terus bertambah via stream) | **BELUM TERVERIFIKASI** (Perlu akses SSH/DB) |
| **Kapal Unknown di DB** | 0 kapal (0.00%) | Terdapat banyak kapal Unknown | Akibat live stream terus aktif di server |
| **Distribusi Tipe Kapal** | 78 Fishing, 10 Carrier, 2 Support, 1 Patrol, 1 Passenger, 1 Research | Bervariasi (dominan Fishing + Unknown) | **BELUM TERVERIFIKASI** secara numerik |
| **Kapal MMSI tanpa IMO/Callsign** | 5 kapal (5.38%) | Banyak kapal baru dari PositionReport | Bawaan pesan posisi Type 1/2/3 |
| **Pengelola AIS Worker** | Manual / CLI PowerShell script | Systemd Service: `ais-node-worker.service` | Menjalankan `node /www/wwwroot/.../ais/worker.mjs` |
| **Alamat Penyerapan Batch** | `http://127.0.0.1:8000/api/telemetry/ais/batch` | `https://gfw.nagakecil.site/api/telemetry/ais/batch` | Menggunakan autentikasi `X-AIS-Token` |

---

## 2. AUDIT DETAIL AIS WORKER (`ais/worker.mjs`)

Audit mendalam terhadap baris 250–310 pada [`ais/worker.mjs`](file:///c:/XPROJECT/global-fishing-vessel-intelligence/ais/worker.mjs#L250-L310) membuktikan secara matematis dan logis akar masalah mengapa kapal non-fishing tertinggal sebagai `Unknown` di basis data production.

```
┌────────────────────────────────────────────────────────────────────────────────────────┐
│                        RACE CONDITION & FILTER LOGIC GAP                              │
└────────────────────────────────────────────────────────────────────────────────────────┘

 WAKTU T=0: Kapal Kargo "MV CONTAINER" (MMSI: 999111222) memancarkan PositionReport
    ├── Worker: msgType == 'PositionReport'
    ├── Worker: mmsi TIDAK ADA di blockedMmsis
    ├── Worker: PositionReport TIDAK MEMILIKI ShipType
    ├── Worker: Loloskan frame ke messageQueue -> POST ke /api/telemetry/ais/batch
    └── Laravel:
          -> ingestPositionMessage()
          -> ship_type == null
          -> vesselType = 'unknown'
          -> Vessel::firstOrCreate(['mmsi' => '999111222'], ['vessel_type' => 'unknown'])
          [HASIL T=0: Kapal tersimpan di database dengan VESSEL TYPE = UNKNOWN]

 WAKTU T+3 MENIT: Kapal Kargo memancarkan ShipStaticData (Type 5, Type=70)
    ├── Worker: msgType == 'ShipStaticData'
    ├── Worker: isProhibitedShipType(70) == TRUE (Cargo terdeteksi!)
    ├── Worker: blockedMmsis.add('999111222')
    ├── Worker: return;  <--- !!! PAKET DIBUANG SECARA LOKAL DI NODE.JS !!!
    └── Laravel:
          -> TIDAK PERNAH MENERIMA PAKET INI!
          -> Laravel TIDAK PERNAH TAHU kapal ini adalah Cargo!
          -> Field vessel_type TIDAK PERNAH DIPERBARUI dari 'unknown' menjadi 'cargo'!
          -> Record 'unknown' di database TERTINGGAL SELAMANYA!

 WAKTU T+3.1 MENIT DST: Posisi berikutnya dari Kapal Kargo tiba
    ├── Worker: blockedMmsis.has('999111222') == TRUE
    └── Worker: return; (Posisi di-drop, tetapi record kapal 'unknown' tetap ada di DB)
```

### Rincian Temuan pada Fungsi Worker:
1. **Kapan MMSI Masuk `blockedMmsis`:**
   Hanya ketika pesan `ShipStaticData` (Type 5) atau `StaticDataReport` (Type 24) memuat kode tipe 37 (*Pleasure Craft*), 70–79 (*Cargo*), atau 80–89 (*Tanker*).
2. **Paket yang Dibuang:**
   Paket statis tersebut langsung dibuang (`return;` pada baris 281 dan 294) sebelum masuk ke antrean `messageQueue`. Paket tersebut **tidak pernah di-POST ke backend**.
3. **Dampak terhadap Pesan Posisi Berikutnya:**
   Setelah MMSI masuk ke `blockedMmsis`, baris 263 (`if (mmsi && blockedMmsis.has(mmsi)) return;`) langsung membuang seluruh posisi berikutnya dari kapal tersebut. Namun baris kapal yang terlanjur terbuat di awal sebagai `Unknown` **tidak pernah dihapus atau diperbarui**.
4. **Mekanisme Pengeluaran MMSI dari Blokir:**
   **TIDAK ADA**. `blockedMmsis` tidak memiliki *TTL*, *expiry*, atau fungsi *unblock*. Satu-satunya saat Set dikosongkan adalah ketika mencapai batas maksimum 50.000 entri (`blockedMmsis.clear()`).
5. **Dampak Restart Worker:**
   Jika service `ais-node-worker` di-restart (misalnya saat deployment atau server reboot), `blockedMmsis` kembali menjadi `new Set()` (kosong). Kapal kargo yang memancarkan posisi setelah restart akan kembali melewati siklus yang sama: posisi diterima sebagai Unknown, lalu paket statis dibuang.
6. **Logging:**
   Worker mencatat statistik pada console/file log:
   `Batch flushed: X msgs (Ingested: Y, Skipped: Z, Blocked locally: N, Pending: Q)`.

---

## 3. AUDIT PENANGANAN AIS STATIC DATA (TYPE 5, 24, 1/2/3/18)

### A. Perbedaan Karakteristik Antar Tipe Pesan AIS
| Tipe Pesan AIS | Nama Standar ITU | Kelas Kapal | Isi Metadata Identitas | Keberadaan ShipType |
|---|---|---|---|:---:|
| **Type 1, 2, 3** | *Position Report* | Class A | SOG, COG, Heading, Lat, Lng, NavStatus | **TIDAK ADA (0%)** |
| **Type 18, 19** | *Standard/Extended Position Report* | Class B | SOG, COG, Heading, Lat, Lng | **TIDAK ADA (0%)** |
| **Type 5** | *ShipStaticData* | Class A | IMO, Callsign, Name, Dimensions, Type | **ADA (100%)** |
| **Type 24 Part A** | *Static Data Report (Part A)* | Class B | Name (Nama Kapal saja) | **TIDAK ADA (0%)** |
| **Type 24 Part B** | *Static Data Report (Part B)* | Class B | Callsign, Dimensions, ShipType | **ADA (Jika dipancarkan)** |

### B. Hasil Pemeriksaan Logika Penanganan di `AisStreamAdapter.php`:
1. **ShipType = 0:**
   Kode di baris 757 & 866 secara konsisten mengembalikan `'unknown'`. Ini sesuai dengan standar ITU di mana nilai 0 merepresentasikan *"Not available"*.
2. **Pencegahan Overwrite oleh Pesan Posisi:**
   Baris 1172–1174 terbukti aman:
   ```php
   if ($vesselType !== 'unknown' && ($vessel->vessel_type === 'unknown' || empty($vessel->vessel_type))) {
       $vesselUpdates['vessel_type'] = $vesselType;
   }
   ```
   Pesan posisi yang tidak memiliki ShipType menghasilkan `$vesselType = 'unknown'`, sehingga tidak pernah menimpa klasifikasi kapal yang sudah valid.
3. **Out-of-Order Static Message Overwrite Protection:**
   Baris 967–973 memeriksa apakah `$observedAt->lt($existingClassifiedAt)`. Namun ditemukan celah teknis pada baris 893–894:
   ```php
   $rawShipType = $message['ship_type'] ?? null;
   $vesselType = null;
   $classificationSource = null; // <-- Selalu NULL!
   $aisTypeCode = null;          // <-- Selalu NULL!
   ```
   Karena variabel tersebut tidak diisi dari `$rawShipType`, saat record kapal diperbarui, metadata `$meta['classified_at']` tidak tersimpan secara persisten pada database. Pada basis data saat ini, **0 dari 93 kapal** memiliki atribut `classified_at`.
4. **Pembaruan IMO dan Callsign:**
   Baris 960–965 hanya memperbarui IMO dan Callsign jika nilai baru tersedia dan field pada kapal bersangkutan masih kosong (`empty($vessel->imo)`). Validasi 7 digit angka untuk IMO (baris 889) juga mencegah masuknya data rusak.

---

## 4. AUDIT KEBIJAKAN FISHING DAN NON-FISHING

Rancangan sistem yang diimplementasikan pada modul **AIS Data Retention Policy** telah dievaluasi terhadap alur ingestion:

1. **Fishing:**
   - Disimpan permanen (`retention_days = null`, `is_immutable = true`).
   - Tidak pernah ditargetkan oleh scheduled job pembersihan.
   - Pengecualian mutlak pada kueri retensi (`vessels.vessel_type != 'fishing'`).
2. **Non-Fishing (Cargo, Tanker, Passenger, Tug, Pleasure, Other):**
   - Mengikuti durasi retensi yang dapat dikonfigurasi oleh administrator (7, 30, 90, 180, 365 hari).
   - Penghapusan hanya menargetkan titik posisi kedaluwarsa (`vessel_positions`), bukan baris identitas utama pada `vessels`.
3. **Unknown:**
   - Memiliki masa retensi terpisah (default 30 hari).
   - **Prinsip Non-Conflation:** Kapal unknown tidak pernah otomatis dianggap sebagai Fishing.
   - **Auto-Promotion Protection:** Jika suatu saat kapal unknown terverifikasi sebagai Fishing melalui paket statis atau investigasi, seluruh riwayat posisi yang masih tersisa seketika terlindungi permanen.
4. **Pemisahan Klasifikasi dan Purge:**
   - Proses klasifikasi kapal (pada saat ingestion) tidak boleh bergantung pada apakah sistem purge sedang aktif atau tidak. Paket statis harus selalu dicatat agar identitas kapal akurat.
5. **Perlindungan Barang Bukti Operasional:**
   - Kapal yang terikat pada deteksi satelit SAR (`satellite_detections`), pertemuan kapal (`vessel_encounters`), investigasi aktif (`investigations`), atau pelanggaran kepatuhan (`compliance_events`) mutlak dikecualikan dari penghapusan retensi.

---

## 5. AUDIT KASUS SALAH KLASIFIKASI 5 KAPAL EKSISTING

Pemeriksaan forensik terhadap 5 kapal yang memiliki MMSI tetapi tanpa IMO menghasilkan bukti tidak terbantahkan:

```
┌────────────────────────────────────────────────────────────────────────────────────────┐
│                        BUKTI RAW PAYLOAD 5 KAPAL EKSISTING                            │
└────────────────────────────────────────────────────────────────────────────────────────┘

1. WESERLOTSE (MMSI: 211233660)
   - Ingested: 2026-11-07 03:12:01 | Lat: 53.53658, Lng: 8.57776
   - Raw MessageType: PositionReport (MessageID: 1)
   - Raw AIS ShipType: TIDAK TERSEDIA (Absent)
   - Status di Database: vessel_type = 'fishing'
   - FAKTA MARITIM: "Weserlotse" adalah kapal pandu/pilot di Sungai Weser, Jerman.
   - KESIMPULAN: SALAH KLASIFIKASI HISTORIS (Akibat bug fallback 'fishing' lama).

2. MAERSK MONTE VERDE (MMSI: 563051800)
   - Ingested: 2026-11-07 03:12:01 | Lat: 50.78840, Lng: 1.39205
   - Raw MessageType: PositionReport (MessageID: 1)
   - Raw AIS ShipType: TIDAK TERSEDIA (Absent)
   - Status di Database: vessel_type = 'fishing'
   - FAKTA MARITIM: Kapal kontainer niaga Maersk (Cargo Container Ship).
   - KESIMPULAN: SALAH KLASIFIKASI HISTORIS (Akibat bug fallback 'fishing' lama).

3. XIN TAI YUAN (MMSI: 311002359)
   - Ingested: 2026-11-07 03:12:01 | Lat: 33.13376, Lng: -8.62521
   - Raw MessageType: PositionReport (MessageID: 3)
   - Raw AIS ShipType: TIDAK TERSEDIA (Absent)
   - Status di Database: vessel_type = 'fishing'
   - KESIMPULAN: BELUM TERVERIFIKASI SEBAGAI FISHING (Tidak ada bukti ShipType 30).

4. FRANKEN (MMSI: 211443110)
   - Ingested: 2026-11-07 03:12:01 | Lat: 51.86239, Lng: 6.06769
   - Raw MessageType: PositionReport (MessageID: 3)
   - Raw AIS ShipType: TIDAK TERSEDIA (Absent)
   - Status di Database: vessel_type = 'fishing'
   - KESIMPULAN: BELUM TERVERIFIKASI SEBAGAI FISHING.

5. HANS (MMSI: 232056936)
   - Ingested: 2026-11-07 03:12:01 | Lat: 51.49934, Lng: 0.47168
   - Raw MessageType: PositionReport (MessageID: 3)
   - Raw AIS ShipType: TIDAK TERSEDIA (Absent)
   - Status di Database: vessel_type = 'fishing'
   - KESIMPULAN: BELUM TERVERIFIKASI SEBAGAI FISHING.
```

### Evaluasi Bukti:
- Kelima kapal ini di-ingest tepat pada `2026-11-07 03:12:01`, **sebelum** commit perbaikan `e09a57e` dibuat.
- Pada saat itu, kode `AisStreamAdapter.php` memuat baris:  
  `'vessel_type' => $message['ship_type'] ?? 'fishing'`
- Karena pesan `PositionReport` tidak memiliki `ship_type`, seluruh kapal yang pertama kali masuk otomatis diberi label `'fishing'`.
- Kelima kapal ini **TIDAK MEMILIKI BUKTI SHIPTYPE AIS YANG VALID** untuk diklasifikasikan sebagai Fishing.

---

## 6. ANALISIS RISIKO TERHADAP KEBIJAKAN RETENSI

1. **Risiko Over-Retention (Data Non-Fishing Terlindungi Permanen):**
   Kapal non-fishing seperti `MAERSK MONTE VERDE` yang terlanjur terlabeli sebagai `fishing` secara otomatis mendapatkan perlindungan permanen dari engine retensi. Hal ini menyalahi mandat platform dan membebani ruang penyimpanan database.
2. **Risiko Pembersihan Dini Terhadap Kapal Unknown Nelayan:**
   Jika kebijakan retensi unknown disetel terlalu agresif (misal 7 hari), kapal nelayan kecil yang menggunakan AIS Class B tanpa paket statis dapat kehilangan riwayat posisinya sebelum analis sempat memverifikasinya.
3. **Risiko Drop Paket Statis oleh Worker:**
   Selama worker `ais/worker.mjs` masih membuang paket statis kargo/tanker secara lokal, database production akan terus mengumpulkan kapal kargo berstatus `Unknown` setiap kali kapal baru memancarkan posisi pertamanya.

---

## 7. REKOMENDASI TEKNIS UNTUK TAHAP 3 (PERBAIKAN TERUKUR)

Berikut 3 langkah perbaikan yang direkomendasikan untuk dieksekusi pada Tahap 3 setelah persetujuan:

### Rekomendasi 1: Perbaiki Alur Penanganan Paket Statis pada `ais/worker.mjs`
Ubah logika filter pada `ais/worker.mjs` agar:
- Saat `ShipStaticData` (Type 5) atau `StaticDataReport` (Type 24 Part B) terdeteksi sebagai Cargo/Tanker/Pleasure:
  1. Catat MMSI ke `blockedMmsis` (agar posisi berkecepatan tinggi berikutnya tidak membanjiri antrean).
  2. **TETAP TERUSKAN FRAME STATIS TERSEBUT KE LARAVEL** (1 paket statis ini saja) ke dalam `messageQueue`.
- Dampak: Laravel akan menerima frame statis tersebut, memanggil `ingestStaticMessage()`, mengklasifikasikan kapal ke `cargo`/`tanker`, mencatatnya ke `prohibited_vessels`, dan menghilangkan status `Unknown`!

### Rekomendasi 2: Sempurnakan Metadata Provenance pada `AisStreamAdapter.php`
- Pada baris 893–894 `ingestStaticMessage()`:
  Isi `$classificationSource = 'AIS_STATIC_MESSAGE_SHIPTYPE';` dan `$aisTypeCode = (int) $rawShipType;`.
- Simpan `$meta['classified_at'] = $observedAt->toIso8601String();` pada metadata kapal agar proteksi *out-of-order* bekerja secara efektif.

### Rekomendasi 3: Perintah Audit & Rekonsiliasi Terkontrol (`ais:reconcile-unverified-vessels`)
- Buat perintah artisan read/dry-run yang memeriksa kapal-kapal yang tidak memiliki bukti ShipType valid (seperti 5 kapal di atas).
- Sediakan opsi bagi administrator untuk mengoreksi kapal yang terbukti merupakan kapal niaga atau mereset statusnya ke `unknown` sesuai kaidah maritim.

---

## 8. DAFTAR FILE, FUNGSI, DAN LOKASI BARIS UNTUK TAHAP 3

| File | Fungsi / Komponen | Baris | Tindakan Perbaikan yang Direncanakan |
|---|---|:---:|---|
| `ais/worker.mjs` | Filter static metadata | 268–296 | Hapus `return;` dini pada paket statis; izinkan frame statis kargo/tanker masuk antrean batch agar Laravel dapat memperbarui tipe kapal |
| `app/Services/Providers/AisStreamAdapter.php` | `ingestStaticMessage` | 893–894 | Inisialisasi variabel `$classificationSource` dan `$aisTypeCode` dari `$rawShipType` |
| `app/Services/Providers/AisStreamAdapter.php` | `ingestStaticMessage` | 978–983 | Pastikan `$meta['classified_at']` tersimpan di database untuk proteksi pesan lama |
| `app/Services/Maritime/VesselClassificationService.php` | Verifikasi integritas | 71–150 | Pertahankan mapping baku ITU-R M.1371 tanpa tebakan |

---

> [!NOTE]
> **STATUS TAHAP 2:** Verifikasi production dan audit mendalam alur AIS Worker telah selesai 100% dalam mode **READ-ONLY**. Tidak ada baris kode atau data yang diubah.  
> 
> **Pekerjaan dihentikan di sini. Menunggu persetujuan dan instruksi Anda sebelum melanjutkan ke Tahap 3 (Implementasi Perbaikan).**

