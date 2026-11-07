# LAPORAN AUDIT IDENTITAS KAPAL & VESSEL TYPE UNKNOWN (TAHAP 1 — READ-ONLY)
## Global Fishing Vessel Monitoring Platform (GFVIP)

**Tanggal Audit:** 08 November 2026  
**Mode Audit:** READ-ONLY (Pemeriksaan Faktual Kode & Basis Data)  
**Status Audit:** Selesai — Tanpa modifikasi database, schema, worker, atau data historis.  
**Referensi:** Halaman Registry Kapal (`https://gfw.nagakecil.site/vessels` & Local DB)

---

## 1. RINGKASAN EKSEKUTIF & TEMUAN UTAMA

Audit teknis mendalam terhadap modul Vessel, alur data penyerapan telemetri AIS (AISStream $\rightarrow$ Node.js Worker $\rightarrow$ Laravel Ingestion API $\rightarrow$ Database $\rightarrow$ Registry Table), serta tabel identitas kapal menghasilkan kesimpulan faktual sebagai berikut:

1. **Status Basis Data Aktual:**
   - Total kapal terdaftar pada database lokal saat ini adalah **93 kapal**.
   - Distribusi klasifikasi: **Fishing (78 kapal / 83.87%)**, **Carrier (10 kapal / 10.75%)**, **Support (2 kapal / 2.15%)**, **Patrol (1 kapal / 1.08%)**, **Passenger (1 kapal / 1.08%)**, dan **Research (1 kapal / 1.08%)**.
   - Pada database saat ini, jumlah kapal berstatus `Unknown` adalah **0 kapal (0.00%)**.
   - Kelengkapan identitas: **100% kapal memiliki MMSI (93/93)**, **94.62% memiliki IMO (88/93)**, dan **94.62% memiliki Callsign (88/93)**.
   - Terdapat **5 kapal (5.38%)** yang memiliki MMSI tetapi tidak memiliki IMO/Callsign. Kelima kapal ini adalah kapal yang masuk melalui penyerapan feed live AIS (`raw_payload`) pada tanggal 07 November 2026.

2. **Duplikasi Nama & Konflik Identitas:**
   - **Duplikasi Nama:** **0 duplikasi**. Seluruh 93 kapal memiliki nama yang unik setelah dilakukan normalisasi (pembersihan tanda baca, penghapusan spasi berlebih, dan *case-insensitive*).
   - **Konflik Identitas:** **0 konflik**. Tidak ditemukan adanya satu MMSI, IMO, atau Callsign yang terhubung ke lebih dari satu `Vessel ID`. Pemetaan relasi pada tabel `vessels` dan `vessel_identifiers` berjalan 1:1 secara konsisten.

3. **Akar Masalah Teknis Munculnya "VESSEL TYPE: Unknown" pada Live Ingestion (Root Cause):**
   - **Faktor Protokol AIS:** Pesan posisi AIS (`PositionReport` / Type 1, 2, 3 dan `StandardClassBPositionReport` / Type 18) **tidak pernah memuat data ShipType, IMO, atau Callsign** sesuai standar ITU-R M.1371. Paket posisi hanya mentransmisikan MMSI, koordinat, SOG, COG, Heading, dan NavStatus.
   - **Inisialisasi Unknown:** Ketika kapal baru pertama kali terdeteksi melalui pesan posisi, aplikasi menginisialisasi record kapal dengan `vessel_type = 'unknown'`.
   - **Temuan Kritis pada Filter Worker (`ais/worker.mjs`):**
     Terdapat *race condition logic* pada arsitektur worker Node.js. Ketika kapal baru (misalnya kapal kargo) pertama kali mengirimkan `PositionReport`, worker meloloskannya ke Laravel karena belum diketahui tipenya, sehingga Laravel membuat record kapal berstatus `unknown`. Ketika beberapa menit kemudian kapal tersebut memancarkan `ShipStaticData` (Type 5) dengan ShipType = 70 (Cargo), `ais/worker.mjs` mendeteksi bahwa tipe tersebut dilarang (*prohibited*), memasukkannya ke daftar blokir, dan **langsung membuang (DROP) paket tersebut secara lokal**. Akibatnya, Laravel **tidak pernah menerima paket statis tersebut** untuk memperbarui klasifikasi atau memicu pembersihan. Kapal tersebut tertinggal di tabel `vessels` selamanya dengan status `vessel_type: 'unknown'`.
   - **Interval Emisi Statis yang Jarang:** Paket data statis (`ShipStaticData` / `StaticDataReport`) dipancarkan setiap 6 menit. Kapal yang hanya melintas sebentar di zona tangkapan radio/stream sebelum paket statis tiba akan tertahan sebagai `unknown`.

---

## 2. STATISTIK VESSEL TYPE & KELENGKAPAN IDENTITAS

Berdasarkan eksekusi kueri pada database aktual:

### A. Distribusi Klasifikasi Kapal
| Vessel Type | Jumlah Kapal | Persentase | Keterangan Mandat GFVIP |
|---|:---:|:---:|---|
| **Fishing** | 78 | 83.87% | Kapal penangkap ikan (Target Utama Platform) |
| **Carrier** | 10 | 10.75% | Kapal pengangkut ikan / reefer pendukung armada |
| **Support** | 2 | 2.15% | Kapal asistensi logistik & perikanan |
| **Patrol** | 1 | 1.08% | Kapal pengawas perikanan / penegak hukum laut |
| **Passenger** | 1 | 1.08% | Kapal penumpang komersial |
| **Research** | 1 | 1.08% | Kapal riset oseanografi / kelautan |
| **Unknown / NULL / Kosong** | 0 | 0.00% | Belum ada kapal berstatus unknown di DB lokal |
| **TOTAL** | **93** | **100.00%** | Basis data kapal operasional |

### B. Kelengkapan Identitas (MMSI, IMO, Callsign)
| Parameter Identitas | Jumlah Kapal | Persentase | Status Integritas |
|---|:---:|:---:|---|
| **Total Kapal Terdaftar** | 93 | 100.00% | Valid |
| **Memiliki MMSI** | 93 | 100.00% | Lengkap (100% kapal memiliki MMSI unik) |
| **Memiliki IMO** | 88 | 94.62% | Lengkap 7 digit IMO |
| **Memiliki Callsign** | 88 | 94.62% | Terdaftar pada callsign ITU |
| **Memiliki MMSI tetapi TIDAK Memiliki IMO** | 5 | 5.38% | 5 kapal hasil ingestion AIS live streaming |
| **Kapal Unknown yang Memiliki MMSI** | 0 | 0.00% | Tidak ada record unknown saat audit |
| **Kapal Unknown yang Memiliki IMO** | 0 | 0.00% | Tidak ada record unknown saat audit |
| **Kapal Unknown yang Memiliki Callsign** | 0 | 0.00% | Tidak ada record unknown saat audit |

### C. Rincian 5 Kapal yang Memiliki MMSI Tanpa IMO
Kelima kapal ini dibuat pada `2026-11-07 03:12:01` dari ingestion live stream:
1. `WESERLOTSE` — MMSI: `211233660` (IMO: NULL, Callsign: NULL, Vessel Type: `fishing`)
2. `XIN TAI YUAN` — MMSI: `311002359` (IMO: NULL, Callsign: NULL, Vessel Type: `fishing`)
3. `MAERSK MONTE VERDE` — MMSI: `563051800` (IMO: NULL, Callsign: NULL, Vessel Type: `fishing`)
4. `FRANKEN` — MMSI: `211443110` (IMO: NULL, Callsign: NULL, Vessel Type: `fishing`)
5. `HANS` — MMSI: `232056936` (IMO: NULL, Callsign: NULL, Vessel Type: `fishing`)

> [!NOTE]
> Kelima kapal di atas terklasifikasi sebagai `fishing` pada database lokal karena di-ingest sebelum perbaikan commit `e09a57e` diterapkan (di mana versi sebelumnya memiliki kode *fallback* otomatis: `'vessel_type' => $message['ship_type'] ?? 'fishing'`). Nama seperti `MAERSK MONTE VERDE` (lini kargo Maersk) dan `WESERLOTSE` (kapal pandu/pilot) mengindikasikan bahwa tanpa paket statis, kapal non-fishing sempat terlabeli fishing akibat bug fallback versi lama.

---

## 3. AUDIT DUPLIKASI NAMA KAPAL

Pengujian dilakukan dengan algoritma normalisasi:
1. Mengubah seluruh karakter ke huruf kecil (*lowercase*).
2. Menghapus seluruh tanda baca, garis miring, strip, dan karakter non-alfanumerik (`preg_replace('/[^\p{L}\p{N}\s]/u', '', $name)`).
3. Menggabungkan spasi ganda menjadi spasi tunggal dan memotong spasi ujung (*trim*).

### Hasil Pengelompokan:
- **Total Nama Unik Setelah Normalisasi:** 93 nama dari 93 kapal.
- **Nama sama, MMSI berbeda:** **0 kasus (NIHIL)**.
- **Nama sama, IMO berbeda:** **0 kasus (NIHIL)**.
- **Nama sama, MMSI dan IMO sama:** **0 kasus (NIHIL)**.
- **Nama sama tetapi identitas lainnya tidak tersedia:** **0 kasus (NIHIL)**.

**Kesimpulan:** Tidak terdapat duplikasi nama kapal pada database platform. Seluruh kapal memiliki nama yang unik dan tidak terdapat indikasi kerancuan nama ganda antar kapal.

---

## 4. AUDIT DUPLIKASI IDENTITAS (MMSI, IMO, CALLSIGN)

Audit memeriksa integritas identitas pada tabel master `vessels` dan tabel riwayat `vessel_identifiers`.

| Pengujian Konflik | Hasil Faktual | Status |
|---|:---:|:---:|
| Satu MMSI terhubung ke > 1 Vessel ID di `vessels` | **0 kasus** | **SEHAT** (Unique 1:1) |
| Satu IMO terhubung ke > 1 Vessel ID di `vessels` | **0 kasus** | **SEHAT** (Unique 1:1) |
| Satu Callsign terhubung ke > 1 Vessel ID di `vessels` | **0 kasus** | **SEHAT** (Unique 1:1) |
| Nilai MMSI terhubung ke > 1 Vessel ID di `vessel_identifiers` | **0 kasus** | **SEHAT** (Unique 1:1) |
| Nilai IMO terhubung ke > 1 Vessel ID di `vessel_identifiers` | **0 kasus** | **SEHAT** (Unique 1:1) |
| Nilai Callsign terhubung ke > 1 Vessel ID di `vessel_identifiers` | **0 kasus** | **SEHAT** (Unique 1:1) |
| Kapal dengan riwayat multi-MMSI di `vessel_identifiers` | **0 kasus** | **TIDAK ADA PERGANTIAN** |
| Kapal dengan riwayat multi-IMO di `vessel_identifiers` | **0 kasus** | **TIDAK ADA PERGANTIAN** |

**Kesimpulan Integritas Identitas:**  
Tabel identitas beroperasi dengan integritas tinggi. Tidak ada insiden benturan identitas (*identifier collision*) atau penggunaan ulang MMSI/IMO antar kapal yang berbeda.

---

## 5. HASIL PENELUSURAN ALUR SUMBER DATA AIS

Penelusuran dilakukan mengikuti alur end-to-end:  
`AISStream WebSocket` $\rightarrow$ `ais/worker.mjs` $\rightarrow$ `routes/api.php` $\rightarrow$ `AisStreamAdapter.php` $\rightarrow$ `Database (vessels & vessel_positions)` $\rightarrow$ `VesselController & Blade Table`.

```
┌───────────────────────────────────────────────────────────────────────────────┐
│                           ALUR DATA TELEMETRI AIS                             │
└───────────────────────────────────────────────────────────────────────────────┘

 [AISStream WebSocket]
        │
        │ Subscribes to 5 ITU types
        ▼
 [ais/worker.mjs] (Node.js)
        ├── If msgType == 'PositionReport' (95%+ of traffic):
        │     - TIDAK MEMILIKI SHIPTYPE
        │     - Lolos ke antrean batch (karena mmsi belum ada di blockedMmsis)
        │
        ├── If msgType == 'ShipStaticData' / 'StaticDataReport':
        │     - Memiliki ShipType
        │     - JIKA Cargo/Tanker/Pleasure:
        │         -> blockedMmsis.add(mmsi)
        │         -> DROP LOCALLY (TIDAK DIKIRIM KE LARAVEL!)  <-- [BUG ARSITEKTUR]
        │     - JIKA Fishing/Lainnya:
        │         -> Lolos ke antrean batch
        ▼
 [POST /api/telemetry/ais/batch] (Laravel API)
        │
        ▼
 [AisStreamAdapter::parseAndIngestRawFrame]
        ├── Case 'PositionReport':
        │     -> ingestPositionMessage()
        │     -> ship_type = null
        │     -> vesselType = 'unknown'
        │     -> Vessel::firstOrCreate(['mmsi' => $mmsi], ['vessel_type' => 'unknown'])
        │        (Kapal baru tercatat sebagai 'Unknown'!)
        │
        ├── Case 'ShipStaticData':
        │     -> ingestStaticMessage()
        │     -> Jika kapal sudah ada & bertipe 'unknown':
        │          -> $updates['vessel_type'] = $vesselType; (Diperbarui!)
        │
        └── JIKA ShipStaticData tadi di-drop oleh worker.mjs:
              -> Laravel tidak pernah menerima pembaruan!
              -> Kapal selamanya menggantung sebagai 'Unknown' di /vessels!
```

### Jawaban Terhadap 8 Pertanyaan Teknis:
1. **Apakah AIS Position Report diterima tanpa ShipType?**
   **YA**. Sesuai standar ITU-R M.1371, pesan Type 1, 2, 3, dan 18 tidak memiliki field `ShipType`. Hal ini terkonfirmasi dari inspeksi langsung raw payload di database (`vessel_positions.raw_payload`).
2. **Apakah ShipStaticData atau StaticDataReport diterima?**
   Didaftarkan dalam subscription, namun frekuensi transmisinya sangat rendah (setiap 6 menit vs 2-10 detik untuk posisi).
3. **Apakah ShipType tersedia di raw AIS payload?**
   Pada paket `PositionReport`: **TIDAK ADA**. Pada paket `ShipStaticData`: **ADA** di path `Message.ShipStaticData.Type`. Pada `StaticDataReport`: **ADA** di `Message.StaticDataReport.ReportB.ShipType`.
4. **Jika tersedia, apakah ShipType berhasil disimpan ke tabel master vessels?**
   **YA**, jika paket statis sampai ke Laravel, `ingestStaticMessage()` memperbarui field `vessels.vessel_type`. Namun variabel metadata `$classificationSource` dan `$aisTypeCode` tidak tersimpan karena didefinisikan null pada baris 893-894.
5. **Apakah pemetaan AIS ShipType ke Vessel Type sudah benar?**
   **YA**. Pemetaan di `AisStreamAdapter::normalizeVesselType()` dan `VesselClassificationService::mapAisCodeToTaxonomy()` sudah 100% mematuhi standar ITU-R M.1371 / IMO (30: Fishing, 70-79: Cargo, 80-89: Tanker, 60-69: Passenger, dll).
6. **Apakah pesan posisi yang tidak memiliki ShipType berpotensi menimpa klasifikasi sebelumnya dengan NULL?**
   **TIDAK**. Baris 1172 pada `AisStreamAdapter.php` memiliki guard clause:
   `if ($vesselType !== 'unknown' && ($vessel->vessel_type === 'unknown' || empty($vessel->vessel_type)))`
   Pesan posisi yang tidak memiliki ShipType menghasilkan `$vesselType = 'unknown'`, sehingga guard clause ini bernilai false dan tidak pernah menimpa klasifikasi valid yang sudah tersimpan.
7. **Apakah pesan statis lama dapat menimpa data klasifikasi yang lebih baru?**
   Secara logika terdapat perbandingan `$observedAt->lt($existingClassifiedAt)` (baris 970), tetapi karena `classified_at` belum tersimpan pada record kapal lama (0 dari 93 kapal memilikinya), proteksi ini belum aktif secara efektif untuk kapal eksisting.
8. **Apakah data statis berhasil dihubungkan dengan MMSI dan Vessel ID yang tepat?**
   **YA**. Keduanya menggunakan pencarian berbasis `mmsi` tunggal melalui `Vessel::firstOrCreate(['mmsi' => $cleanMmsi])`, sehingga selalu terhubung ke baris database yang sama.

---

## 6. PENYEBAB TEKNIS MENGAPA BANYAK KAPAL "UNKNOWN" PADA PRODUCTION

Berdasarkan bukti kode dan alur data:

1. **Efek Asinkronisasi Emisi AIS (Timing Gap):**
   Kapal yang baru muncul selalu memancarkan posisi terlebih dahulu sebelum paket statis. Selama selang waktu 6 menit sebelum `ShipStaticData` tiba, kapal tersebut secara sah berstatus `Unknown`.
2. **Celah Drop Lokal pada `ais/worker.mjs`:**
   Ini adalah penyebab utama kapal non-fishing (kargo/tanker) menggantung sebagai `Unknown` di production. Ketika kapal kargo terdeteksi:
   - Posisi pertamanya membuat baris kapal baru dengan status `Unknown`.
   - Ketika paket statis Type 5 tiba dengan tipe Kargo, worker memblokir MMSI dan **membuang paket statis** agar tidak diteruskan ke backend.
   - Karena backend tidak menerima paket statis, backend **tidak pernah tahu bahwa kapal tersebut adalah Kargo**.
   - Akibatnya, baris kapal berstatus `Unknown` tersebut tidak pernah diperbarui dan tidak pernah dibersihkan.
3. **Transponder Class B Tanpa Laporan Statis:**
   Kapal-kapal nelayan tradisional atau berukuran sedang sering kali menggunakan transponder AIS Class B yang tidak memancarkan Report B (Part B) atau memancarkan ShipType bernilai `0` (*Not available*). Berdasarkan aturan mandat maritim, kode 0 wajib dipertahankan sebagai `Unknown` dan tidak boleh ditebak menjadi `Fishing` tanpa bukti sah.

---

## 7. FILE KODE DAN LOKASI BARIS YANG RELEVAN

| File | Baris | Komponen / Logika |
|---|:---:|---|
| `ais/worker.mjs` | 268–296 | Logika drop lokal paket statis Cargo/Tanker/Pleasure yang menyebabkan kapal tertinggal sebagai `Unknown` di backend |
| `ais/worker.mjs` | 231–237 | Konfigurasi subscription 5 tipe pesan AISStream |
| `app/Services/Providers/AisStreamAdapter.php` | 504–534 | Parsing `PositionReport` — `$shipType` selalu bernilai `null` |
| `app/Services/Providers/AisStreamAdapter.php` | 537–565 | Parsing `ShipStaticData` (Type 5) — mengekstrak ShipType, IMO, Callsign |
| `app/Services/Providers/AisStreamAdapter.php` | 568–604 | Parsing `StaticDataReport` (Type 24 Part B) |
| `app/Services/Providers/AisStreamAdapter.php` | 703–867 | `normalizeVesselType()` — mapping standar ITU-R M.1371 / IMO |
| `app/Services/Providers/AisStreamAdapter.php` | 872–1031 | `ingestStaticMessage()` — pembaruan kapal berdasarkan paket statis |
| `app/Services/Providers/AisStreamAdapter.php` | 893–894 | Bug variabel `$classificationSource = null; $aisTypeCode = null;` tidak terisi |
| `app/Services/Providers/AisStreamAdapter.php` | 1039–1244 | `ingestPositionMessage()` — inisialisasi kapal baru dengan tipe `unknown` |
| `app/Services/Providers/AisStreamAdapter.php` | 1172–1174 | Guard clause mencegah posisi tanpa ShipType menimpa klasifikasi valid |
| `app/Models/Vessel.php` | 213–227 | Accessor `getDisplayVesselTypeAttribute()` |
| `app/Services/Maritime/VesselClassificationService.php` | 71–150 | `mapAisCodeToTaxonomy()` |
| `resources/views/vessels/index.blade.php` | 211–235 | Rendering badge kolom `VESSEL TYPE` |

---

## 8. QUERY SQL YANG DIGUNAKAN DALAM AUDIT

Berikut daftar kueri SQL yang dijalankan untuk mengumpulkan bukti audit secara read-only:

```sql
-- 1. Distribusi Vessel Type
SELECT 
    CASE 
        WHEN vessel_type IS NULL OR TRIM(vessel_type) = '' THEN 'NULL/Empty' 
        ELSE LOWER(TRIM(vessel_type)) 
    END AS v_type,
    COUNT(*) AS total,
    ROUND((COUNT(*) * 100.0 / (SELECT COUNT(*) FROM vessels)), 2) AS percentage
FROM vessels
GROUP BY v_type
ORDER BY total DESC;

-- 2. Jumlah Kapal dengan Identitas Parsial
SELECT 
    COUNT(*) AS total_vessels,
    COUNT(CASE WHEN mmsi IS NOT NULL AND TRIM(mmsi) != '' THEN 1 END) AS with_mmsi,
    COUNT(CASE WHEN imo IS NOT NULL AND TRIM(imo) != '' THEN 1 END) AS with_imo,
    COUNT(CASE WHEN callsign IS NOT NULL AND TRIM(callsign) != '' THEN 1 END) AS with_callsign,
    COUNT(CASE WHEN (mmsi IS NOT NULL AND TRIM(mmsi) != '') AND (imo IS NULL OR TRIM(imo) = '') THEN 1 END) AS with_mmsi_no_imo,
    COUNT(CASE WHEN vessel_type IS NULL OR LOWER(TRIM(vessel_type)) = 'unknown' OR TRIM(vessel_type) = '' THEN 1 END) AS total_unknown
FROM vessels;

-- 3. Pemeriksaan Duplikasi MMSI pada Tabel Vessels
SELECT mmsi, COUNT(*) AS count_vessels
FROM vessels
WHERE mmsi IS NOT NULL AND TRIM(mmsi) != ''
GROUP BY mmsi
HAVING COUNT(*) > 1;

-- 4. Pemeriksaan Duplikasi IMO pada Tabel Vessels
SELECT imo, COUNT(*) AS count_vessels
FROM vessels
WHERE imo IS NOT NULL AND TRIM(imo) != ''
GROUP BY imo
HAVING COUNT(*) > 1;

-- 5. Pemeriksaan Konflik pada Tabel Identifiers
SELECT identifier_type, identifier_value, COUNT(DISTINCT vessel_id) AS distinct_vessels
FROM vessel_identifiers
GROUP BY identifier_type, identifier_value
HAVING COUNT(DISTINCT vessel_id) > 1;

-- 6. Pemeriksaan Normalisasi Nama Duplikat
SELECT 
    LOWER(REGEXP_REPLACE(REGEXP_REPLACE(TRIM(name), '[^a-zA-Z0-9 ]', '', 'g'), '\s+', ' ', 'g')) AS normalized_name,
    COUNT(*) AS total,
    ARRAY_AGG(mmsi) AS mmsis,
    ARRAY_AGG(id) AS vessel_ids
FROM vessels
GROUP BY normalized_name
HAVING COUNT(*) > 1;

-- 7. Pemeriksaan Raw Payload Ingestion AIS
SELECT id, vessel_id, raw_payload->>'MessageType' AS msg_type, observed_at
FROM vessel_positions
WHERE raw_payload IS NOT NULL
ORDER BY observed_at DESC
LIMIT 10;
```

---

## 9. REKOMENDASI PERBAIKAN BERDASARKAN PRIORITAS (UNTUK TAHAP 2)

Setelah mendapat persetujuan, perbaikan teknis berikut direkomendasikan secara berurutan:

1. **Prioritas 1 (Kritis — Perbaikan Alur Worker Node.js `ais/worker.mjs`):**
   - Jangan membuang (`drop`) paket `ShipStaticData` kapal kargo/tanker sebelum dikirim ke backend.
   - Izinkan paket statis terkirim ke Laravel agar backend dapat memperbarui `vessel_type` kapal dari `unknown` menjadi `cargo`/`tanker`, lalu secara terkoordinasi memicu isolasi atau registrasi ke `prohibited_vessels` dan eksekusi retensi/purge.
   - Dengan demikian, kapal kargo tidak akan pernah tertinggal berstatus `unknown` di basis data.

2. **Prioritas 2 (Penyempurnaan `AisStreamAdapter.php`):**
   - Lengkapi pengisian variabel `$classificationSource = 'AIS_STATIC_MESSAGE_SHIPTYPE';` dan `$aisTypeCode = $rawShipType;` pada `ingestStaticMessage()`, serta simpan timestamp `metadata.classified_at = now()->toIso8601String()`.
   - Ini memastikan pelacakan provenance dan proteksi terhadap pesan statis lama yang datang terlambat (*out-of-order protection*).

3. **Prioritas 3 (Mekanisme Reklasifikasi Otomatis Melalui Paket Statis Tertunda):**
   - Buat artisan background job ringan (`ais:reconcile-unknown-types`) yang memeriksa apakah ada posisi AIS atau paket statis tertunda yang memuat data klasifikasi untuk kapal-kapal berstatus `unknown`.
   - Tetap patuhi aturan ketat: jika tidak ada ShipType valid dari AISStream, kapal tetap dipertahankan `Unknown` (tidak ditebak).

---

## 10. DAFTAR RISIKO APABILA PERBAIKAN DITERAPKAN

1. **Risiko Conflation (Pencampuran Identitas):**
   - Jika kapal `Unknown` dipaksa menjadi `Fishing` tanpa bukti ShipType, platform akan salah sasaran dalam menerapkan aturan kepatuhan (*compliance rules* seperti perizinan tangkap) dan menghasilkan false-positive pada modul pemantauan IUU.
   - **Mitigasi:** Tetap pertahankan status `Unknown` jika ShipType tidak tersedia.
2. **Risiko Beban Jaringan & Database pada Worker Ingestion:**
   - Mengizinkan paket statis kapal kargo/tanker lewat ke Laravel akan menambah sedikit volume payload pada `/api/telemetry/ais/batch`.
   - **Mitigasi:** Paket statis hanya muncul sekali tiap 6 menit per kapal, sehingga lonjakan volume sangat minimal (<1% dari volume pesan posisi).
3. **Risiko Penghapusan Terlalu Dini:**
   - Menghapus kapal `Unknown` secara gegabah dapat menghilangkan bukti jika kapal tersebut sebenarnya adalah kapal nelayan yang belum sempat memancarkan paket statis.
   - **Mitigasi:** Terapkan masa tenggang retensi (*grace period*) 30 hari sesuai modul *AIS Data Retention Policy* yang baru dibangun.

---

> [!IMPORTANT]
> **STATUS TAHAP 1:** Audit dan investigasi teknis read-only telah selesai 100%. Tidak ada perubahan basis data atau kode yang dilakukan. Menunggu persetujuan pengguna sebelum melanjutkan ke Tahap 2 (Implementasi Perbaikan).

