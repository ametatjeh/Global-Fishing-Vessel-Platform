# LAPORAN AUDIT INTEGRITAS RETENSI AIS TAHAP 4C.1
## Global Fishing Vessel Intelligence Platform (GFVIP)

**Tanggal:** 8 November 2026  
**Mode Audit:** **STRICT READ-ONLY — ZERO DESTRUCTIVE ACTIONS**  
**Repositori:** `C:\XPROJECT\global-fishing-vessel-intelligence`

---

## 1. REKONSILIASI MATEMATIS SELURUH POSISI AIS (97 POSISI)

Berdasarkan audit langsung terhadap database operasional PostgreSQL, seluruh 97 rekaman posisi AIS pada tabel `vessel_positions` telah direkonsiliasi secara **saling eksklusif (mutually exclusive)** tanpa ada posisi yang dihitung ganda:

$$\text{Total Posisi} = \text{Verified Fishing (57)} + \text{Unverified Fishing / Protective Hold (21)} + \text{Non-Fishing Aktif (19)} = 97$$

### Tabel Rekonsiliasi Kategori Posisi Saling Eksklusif

| No | Kategori Klasifikasi Retensi | Status Verifikasi | Kode AIS ShipType | Jumlah Kapal | Jumlah Posisi | % terhadap Total | Status Kebijakan Retensi |
|---|---|---|---|:---:|:---:|:---:|---|
| **1** | **Verified Fishing** | `verified` | *null / 30* | 57 | **57** | 58.8% | **PERMANEN (TIDAK BOLEH DIHAPUS)** |
| **2** | **Historical Protected Vessels (5 Kapal)** | `provider_reported` | *null* | 5 | **5** | 5.2% | **PROTECTIVE HOLD (ISOLASI MUTLAK)** |
| **3** | **Unverified Fishing (Provider Reported)** | `provider_reported` | *null* | 9 | **9** | 9.3% | **PROTECTIVE HOLD (MENUNGGU REVIEW)** |
| **4** | **Unverified Fishing (Unverified)** | `unverified` | *null* | 7 | **7** | 7.2% | **PROTECTIVE HOLD (MENUNGGU REVIEW)** |
| **5** | **Verified Non-Fishing (Carrier)** | `verified` | *null* | 8 | **8** | 8.2% | Aktif dalam masa retensi 90 hari |
| **6** | **Unverified Non-Fishing (Carrier)** | `provider_reported` | *null* | 2 | **2** | 2.1% | Aktif dalam masa retensi 90 hari |
| **7** | **Verified Non-Fishing (Passenger)** | `provider_reported` | *null* | 1 | **5** | 5.2% | Aktif dalam masa retensi 60 hari |
| **8** | **Verified Non-Fishing (Support)** | `verified` | *null* | 1 | **1** | 1.0% | Aktif dalam masa retensi 90 hari |
| **9** | **Unverified Non-Fishing (Support)** | `provider_reported` | *null* | 1 | **1** | 1.0% | Aktif dalam masa retensi 90 hari |
| **10** | **Verified Non-Fishing (Research)** | `verified` | *null* | 1 | **1** | 1.0% | Aktif dalam masa retensi 90 hari |
| **11** | **Verified Non-Fishing (Patrol)** | `verified` | *null* | 1 | **1** | 1.0% | Aktif dalam masa retensi 90 hari |
| **12** | **Unknown / Belum Terklasifikasi** | — | — | 0 | **0** | 0.0% | Kebijakan aktif 30 hari |
| **13** | **Orphan Positions (Tanpa Relasi Kapal)** | — | — | 0 | **0** | 0.0% | **0 (Integritas Foreign Key Sempurna)** |
| **TOTAL** | **SELURUH POSISI AIS TERSIMPAN** | — | — | **93 Kapal** | **97 Posisi** | **100.0%** | **KANDIDAT PURGE = 0 (AMAN)** |

---

## 2. PENJELASAN TUNTAS 19 POSISI NON-FISHING

Pada laporan dry-run Tahap 4C sebelumnya, dilaporkan:
- Total Posisi: **97**
- Fishing Terverifikasi (Permanen): **57**
- Fishing Belum Terverifikasi (Protective Hold): **21**
- **Selisih: $97 - (57 + 21) = 19 \text{ posisi}$.**

Audit mendalam terhadap database membuktikan secara definitif bahwa ke-19 posisi tersebut adalah milik **15 kapal non-fishing yang sah dan aktif**:

### Rincian 15 Kapal Pemilik 19 Posisi Non-Fishing:

1. **Kapal Passenger (5 Posisi):**
   - **MMSI:** `538070201` | **Nama:** `4 ROSES` | **Tipe:** `passenger`
   - **Status:** `provider_reported`
   - **Rentang Waktu Observasi:** `2026-11-07 04:32:54` s.d. `2026-11-07 05:32:52` UTC.
   - **Analisis Retensi:** Posisi baru berumur **~1 hari**. Batas waktu (*cutoff*) kebijakan Passenger (60 hari) adalah `2026-09-08`. Posisi ini berada jauh di dalam batas retensi aman sehingga **TIDAK memenuhi syarat penghapusan**.

2. **Kapal Carrier (10 Posisi pada 10 Kapal Berbeda):**
   - `412001003` (`F/V SHUN XING 8`) — 1 posisi (`2026-10-28 13:41:41`)
   - `351001002` (`F/V CORONA BOREALIS`) — 1 posisi (`2026-10-27 19:41:41`)
   - `525001039` (`KM MUARA BARU 19`) — 1 posisi (`2026-10-28 04:41:41`)
   - `525001011` (`KM NUSANTARA JAYA 02`) — 1 posisi (`2026-10-28 07:41:41`)
   - `351001001` (`F/V ATLANTIC SPIRIT`) — 1 posisi (`2026-10-27 06:41:41`)
   - `636001002` (`F/V OCEANIC FORTUNE`) — 1 posisi (`2026-10-28 12:41:41`)
   - `525001007` (`KM SURYA KENCANA 22`) — 1 posisi (`2026-10-27 15:41:41`)
   - `525001025` (`KM SORONG MAKMUR 05`) — 1 posisi (`2026-10-28 17:41:41`)
   - `636001001` (`F/V SILVER FJORD`) — 1 posisi (`2026-10-29 04:41:41`)
   - `412001004` (`F/V HAI FENG 688`) — 1 posisi (`2026-10-27 13:41:41`)
   - **Analisis Retensi:** Tipe `carrier` dipetakan ke kebijakan retensi kategori `other` (**90 hari**, cutoff: `2026-08-09`). Karena seluruh posisi tercatat pada akhir Oktober 2026 (berumur **~11-12 hari**), semuanya berada jauh di dalam durasi retensi aktif dan **TIDAK memenuhi syarat purge**.

3. **Kapal Support (2 Posisi pada 2 Kapal):**
   - `525001016` (`KM MAJU BERSAMA 06`) — 1 posisi (`2026-10-27 19:41:41`)
   - `645001001` (`R/V MAURITIUS PRIDE`) — 1 posisi (`2026-10-27 22:41:41`)
   - **Analisis Retensi:** Kategori `other` (90 hari). Umur data ~12 hari. **TIDAK memenuhi syarat purge**.

4. **Kapal Riset & Patroli (2 Posisi pada 2 Kapal):**
   - `525001004` (`KM BARUNA JAYA IV` — Research) — 1 posisi (`2026-10-27 15:41:41`)
   - `525001023` (`KM NATUNA INDAH 03` — Patrol) — 1 posisi (`2026-10-29 00:41:41`)
   - **Analisis Retensi:** Kategori `other` (90 hari). Umur data ~10-12 hari. **TIDAK memenuhi syarat purge**.

**Kesimpulan:** 19 posisi non-fishing tersebut bukan data anomali atau data hilang, melainkan posisi kapal non-fishing resmi yang **usianya masih sangat segar (1 s.d. 12 hari) sehingga secara sah dilindungi oleh konfigurasi kebijakan retensinya masing-masing**.

---

## 3. VALIDASI ATURAN VERIFIED FISHING & DETEKSI KONFLIK BUKTI

Audit langsung terhadap method `isVerifiedFishing()` pada `app/Models/Vessel.php` (baris 253–268) dan query pada `DataRetentionPolicyService.php` (baris 365–375).

### Matriks Pengujian Skenario Klasifikasi:

| Skenario | `vessel_type` | `verification_status` | `ais_ship_type_code` | Hasil Evaluasi `isVerifiedFishing()` | Status Retensi Aktual | Kesimpulan Integritas |
|---|---|---|---|:---:|---|---|
| **A** | `fishing` | *any* | `30` | **TRUE** | Verified Fishing (Permanen) | **VALID** (Sesuai ITU-R M.1371) |
| **B** | `fishing` | `verified` | *null* | **TRUE** | Verified Fishing (Permanen) | **VALID** (Legacy verified tanpa konflik) |
| **C** | `fishing` | `provider_reported` | *null* | **FALSE** | Protective Hold (Unverified) | **VALID** (5 kapal historis terlindungi) |
| **D** | `fishing` | `unverified` | *null* | **FALSE** | Protective Hold (Unverified) | **VALID** (7 kapal unverified terlindungi) |
| **E** | `cargo` | `verified` | `70` | **FALSE** | Verified Non-Fishing (90 hari) | **VALID** (Mengikuti retensi cargo) |
| **F** | `tanker` | `verified` | `80` | **FALSE** | Verified Non-Fishing (90 hari) | **VALID** (Mengikuti retensi tanker) |
| **G (KONFLIK)** | `fishing` | `verified` | `70` *(Cargo)* | **TRUE (POTENSI RESIKO)** | **PERMANEN (SALAH)** | **TEMUAN AUDIT: KONFLIK BUKTI DIABAIKAN** |
| **H (KONFLIK)** | `fishing` | `verified` | `80` *(Tanker)* | **TRUE (POTENSI RESIKO)** | **PERMANEN (SALAH)** | **TEMUAN AUDIT: KONFLIK BUKTI DIABAIKAN** |

### Analisis Temuan Konflik Klasifikasi (Skenario G & H):
Pada baris 258–261 `app/Models/Vessel.php`:
```php
// Analyst verified
if ($this->verification_status === 'verified') {
    return true; // <-- EVALUASI DILAKUKAN SEBELUM MENGECEK KODE AIS
}

// Legitimate AIS ShipType 30 (Fishing)
return (int) $this->ais_ship_type_code === 30;
```
**Dampak:**  
Jika sebuah kapal di masa lalu diberi label manual `verified`, namun transmisi AIS aktualnya membawa kode `70` (Cargo) atau `80` (Tanker), sistem saat ini **mengabaikan bukti transmisi AIS** dan tetap menganggapnya sebagai *Verified Fishing*.

Meskipun pada 97 posisi saat ini tidak ditemukan konflik tersebut (`ais_ship_type_code` pada data historis bernilai NULL), untuk keamanan jangka panjang sebelum modul verifikasi admin dibuka, aturan ini harus disempurnakan.

---

## 4. AUDIT PROTECTIVE HOLD DAN BUKTI OPERASIONAL

### A. Perlindungan Lima Kapal Historis
Kelima kapal historis:
- **WESERLOTSE** (`211233660`)
- **MAERSK MONTE VERDE** (`563051800`)
- **XIN TAI YUAN** (`311002359`)
- **FRANKEN** (`211443110`)
- **HANS** (`232056936`)

**Hasil Audit:**
1. Terdaftar permanen pada `Vessel::HISTORICAL_PROTECTED_MMSIS` (`app/Models/Vessel.php#L236-L242`).
2. Masuk ke daftar ID yang dikecualikan mutlak melalui `getProtectiveHoldVesselIds()` (`DataRetentionPolicyService.php#L330-L336`).
3. Seluruh 5 posisinya berstatus `PROTECTIVE_HOLD` dan tidak ada satupun yang dapat dihapus.

### B. Granularitas Proteksi: Level Posisi vs Level Kapal

Audit membedakan secara tegas mekanisme penahanan data:

1. **Proteksi Level Seluruh Kapal (Vessel-Level Hold):**
   - Diterapkan pada:
     - Kapal berstatus *Protective Hold* (5 kapal historis, unverified fishing).
     - Kapal yang memiliki keterkaitan bukti operasional: **SAR Detections**, **Vessel Encounters**, **Investigasi**, **Compliance Events**, dan **Audit Logs**.
   - **Mekanisme:** Query kandidat purge mengecualikan ID kapal secara penuh (`whereNotIn('vessel_id', $allProtectedVesselIds)`).
   - **Dampak:** **Seluruh lintasan posisi kapal tersebut (100% histori masa lalu, sekarang, dan masa depan) DITAHAN UTUH**.
   - **Justifikasi Maritim:** Dalam investigasi penegakan hukum IUU Fishing dan pembuktian di peradilan maritim internasional, rekonstruksi lintasan kapal (*track continuity*) memerlukan kesinambungan titik awal berangkat, rute jelajah, hingga pelabuhan tujuan. Memotong titik-titik di luar jendela pertemuan akan merusak integritas interpolasi kecepatan dan geometri lintasan.

2. **Proteksi Level Rekaman Posisi (Position-Level Protection):**
   - Diterapkan pada: **Dark Vessel / AIS Gap Indicators** (`vessel_positions.is_gap_indicator = true`).
   - **Mekanisme:** Query kandidat purge secara eksplisit menambahkan klausul `where('vessel_positions.is_gap_indicator', false)`.
   - **Dampak:** Jika sebuah kapal non-fishing biasa (misal kapal kargo umum tanpa kasus) telah melewati masa retensi 90 hari, posisi-posisi regulernya dapat dibersihkan, **namun rekaman posisi yang memiliki tanda pemadaman transponder (`is_gap_indicator = true`) TETAP DIPERTAHANKAN** sebagai bukti anomali telemetri.

---

## 5. AUDIT SIMULASI DRY-RUN

Pemeriksaan terhadap implementasi `simulateRetentionPurge()`:
1. **Sifat Read-Only Mutlak:**  
   Metode ini hanya mengeksekusi fungsi agregasi `count()`, `select()`, dan `selectRaw('min(), max()')`. Tidak ada satupun query `INSERT`, `UPDATE`, `DELETE`, atau `ALTER` yang dipanggil.
2. **Bebas Penghitungan Ganda (No Double-Counting):**  
   Pengelompokan menggunakan klausa `whereNotIn` yang terpusat pada `$allProtectedVesselIds`. Tidak ada kapal atau posisi yang masuk ke dalam kategori kandidat sekaligus kategori terlindungi.
3. **Transparansi Alasan Penahanan:**  
   Setiap baris keluaran pada tabel dry-run menyertakan kolom alasan (`hold_reasons`) yang menjelaskan mengapa posisi ditahan (misal: *Fishing Permanent*, *Protective Hold*, atau *Operational Evidence*).
4. **Perhitungan Estimasi Ruang Realistis:**  
   Estimasi ruang disk yang dibebaskan dihitung dari:
   $$\text{Estimasi Dihapus} = \text{Jumlah Kandidat} \times \text{Ukuran Rata-rata Baris PostgreSQL (termasuk Indeks)}$$
   Karena saat ini kandidat purge adalah 0, hasil estimasi adalah **0 B**, yang mencerminkan kondisi riil database saat ini tanpa spekulasi.

---

## 6. DAFTAR RISIKO YANG TERIDENTIFIKASI

1. **Risiko Konflik Manual vs AIS (Severity: MEDIUM):**  
   Jika di masa depan seorang analis menandai kapal sebagai `verified` secara manual, namun kapal tersebut terus memancarkan kode AIS `70` (Cargo) atau `80` (Tanker), `isVerifiedFishing()` saat ini akan memenangkan status manual. Hal ini berpotensi memberikan perlindungan retensi permanen pada kapal niaga.
2. **Ketergantungan Join Query Skala Besar (Severity: LOW saat ini, MEDIUM jangka panjang):**  
   Fungsi `getAllProtectedVesselIds()` saat ini mengumpulkan ID dari 6 tabel operasional secara dinamis. Untuk 97 posisi saat ini, eksekusi berlangsung instan (< 15 ms). Namun, jika tabel posisi mencapai jutaan baris, pengumpulan ribuan ID array pada `whereNotIn` perlu dioptimalkan menggunakan subquery indeks atau kolom flag terdenormalisasi.

---

## 7. REKOMENDASI PERBAIKAN MINIMAL (READY FOR NEXT PHASE)

Tanpa mengubah database atau kode saat ini (sesuai batasan READ-ONLY), berikut adalah perbaikan paling minimal yang direkomendasikan untuk diterapkan sebelum aktivasi retensi:

### Rekomendasi 1: Sempurnakan `isVerifiedFishing()` pada `app/Models/Vessel.php`
Prioritaskan bukti transmisi AIS di atas verifikasi manual jika terjadi pertentangan data:
```php
public function isVerifiedFishing(): bool
{
    if (strtolower((string) $this->vessel_type) !== 'fishing') {
        return false;
    }

    // Jika telemetri AIS membuktikan tipe non-fishing (contoh 70 Cargo, 80 Tanker),
    // status manual verified TIDAK BOLEH mengabaikan bukti objektif ini.
    if ($this->ais_ship_type_code !== null && (int) $this->ais_ship_type_code !== 30) {
        return false; // Masuk ke Protective Hold / Disputed
    }

    // Kode AIS 30 adalah bukti sah Fishing
    if ((int) $this->ais_ship_type_code === 30) {
        return true;
    }

    // Status manual verified sah selama tidak ada bukti kode AIS yang bertentangan
    return $this->verification_status === 'verified';
}
```

### Rekomendasi 2: Sinkronisasi Filter pada `getVerifiedFishingVesselIds()`
Pada `DataRetentionPolicyService.php`:
```php
public function getVerifiedFishingVesselIds(): array
{
    return DB::table('vessels')
        ->where('vessel_type', 'fishing')
        ->where(function ($q) {
            $q->where('ais_ship_type_code', 30)
              ->orWhere(function ($sub) {
                  $sub->where('verification_status', 'verified')
                      ->where(function ($codeQ) {
                          $codeQ->whereNull('ais_ship_type_code')
                                ->orWhere('ais_ship_type_code', 30);
                      });
              });
        })
        ->pluck('id')
        ->toArray();
}
```

---

## 8. KONFIRMASI BATASAN AUDIT

- Audit ini dilakukan secara **STRICT READ-ONLY**.
- Tidak ada perubahan kode yang diterapkan pada giliran ini.
- Tidak ada penghapusan (*purge*) aktual atau automatic cleanup yang dijalankan.
- Database operasional, kelima kapal historis, migrasi, dan file `.env` tidak disentuh sama sekali.
- Tidak ada commit atau push Git.

Audit selesai. Menunggu persetujuan pengguna untuk langkah implementasi rekomendasi berikutnya.
