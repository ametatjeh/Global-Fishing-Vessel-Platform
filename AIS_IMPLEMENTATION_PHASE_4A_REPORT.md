# LAPORAN IMPLEMENTASI TAHAP 4A: BACKEND FOUNDATION, PROVENANCE, & API KONTRAK
## Global Fishing Vessel Monitoring Platform (GFVIP)

**Status:** SELESAI DENGAN SUKSES (100% PASS)  
**Tanggal Eksekusi:** 8 November 2026  
**Cakupan:** TAHAP 4A SAJA (Backend Foundation, Schema Migration, Ingestion Types 19/27, API Prohibited MMSI)

---

## 1. FILE YANG BERUBAH DAN DITAMBAHKAN

### A. File Baru
1. `database/migrations/2026_11_08_020000_add_classification_provenance_to_vessels_table.php`
   - Migrasi aditif pada tabel `vessels` untuk menambahkan 3 kolom fisik berindeks:
     - `classification_source` (`string`, nullable, indexed)
     - `ais_ship_type_code` (`integer`, nullable, indexed)
     - `classified_at` (`timestamp`, nullable, indexed)
   - **Kepatuhan PostgreSQL:** Secara eksplisit menghindari klausul `->after()` yang tidak kompatibel dengan mesin database PostgreSQL.
2. `tests/Feature/AisPhase4aIngestionAndSyncTest.php`
   - Test suite komprehensif berisi 9 test case (76 assertions) yang memvalidasi seluruh fungsionalitas baru Tahap 4A.

### B. File yang Dimodifikasi
3. `app/Models/Vessel.php`
   - Menambahkan casting model Eloquent:
     - `'classified_at' => 'datetime'`
     - `'ais_ship_type_code' => 'integer'`
4. `app/Services/Providers/AisStreamAdapter.php`
   - **Pemetaan ShipType 58 & 59:**
     - Kode 58 dipetakan ke `'medical'` (Geneva Convention transport).
     - Kode 59 dikoreksi dari sebelumnya `'unknown'` menjadi `'non_combatant'` (States not parties to armed conflict).
     - Menghindari fallback spekulatif ke `fishing`.
   - **Perbaikan Penyimpanan Provenance & Out-of-Order Guard:**
     - Mengisi inisialisasi variabel `$classificationSource = 'AIS_STATIC_MESSAGE_SHIPTYPE'` dan `$aisTypeCode = (int) $rawShipType` (sebelumnya berstatus bug bernilai `null`).
     - Menyimpan provenance pada kolom fisik `vessels` dan mereplikasikannya ke JSON `metadata` demi kompatibilitas penuh.
     - Melindungi data klasifikasi: menolak pembaruan klasifikasi jika `$observedAt` paket masuk lebih lama daripada `$vessel->classified_at` yang sudah tercatat.
   - **Penanganan AIS Message Type 19 (`ExtendedClassBPositionReport`):**
     - Menerapkan *Dual Ingestion*: metadata statis (`Name`, `ShipType`, `Dimension`) selalu diekstrak dan disimpan terlebih dahulu melalui `ingestStaticMessage()`, baru kemudian posisi navigasi diproses secara terpisah.
     - Metadata kapal tetap tersimpan utuh meskipun paket posisi ditolak akibat aturan *prohibited* atau koordinat di luar batas.
   - **Penanganan AIS Message Type 27 (`LongRangeBroadcastMessage`):**
     - Dipetakan secara khusus sebagai posisi navigasi murni (`ingestPositionMessage` dengan `ship_type => null`).
     - Tidak pernah membuat atau mengubah tipe kapal menjadi `fishing`.
5. `routes/api.php`
   - Menambahkan endpoint `GET /api/telemetry/ais/prohibited-mmsis` untuk sinkronisasi worker.
   - Menggunakan otentikasi header `X-AIS-Token` atau Bearer Token yang konsisten dengan batch ingestion.
   - Menyediakan `snapshot_version`, `total_count`, `returned_count`, `next_cursor`, dan `is_last_page`.
   - Menerapkan pagination deterministik berbasis ID cursor (`?cursor=...&limit=...`).
   - Menyediakan field eksplisit `action: 'block' | 'unblock'`, sehingga worker tidak menghapus entri cache berdasarkan pembacaan snapshot parsial.
6. `tests/Feature/AisStreamIngestionRebuildTest.php`
   - Menyelaraskan asersi test normalizer untuk kode 59 yang kini terpetakan ke `'non_combatant'`.

---

## 2. STATUS MIGRATION POSTGRESQL

Migrasi aditif telah berhasil dieksekusi pada database PostgreSQL lokal:

```
INFO  Running migrations.
2026_11_08_020000_add_classification_provenance_to_vessels_table ..... 59.95ms DONE
```

### Verifikasi Skema Kolom Fisik `vessels`
- `classification_source`: `character varying`, nullable: YES, index: active
- `ais_ship_type_code`: `integer`, nullable: YES, index: active
- `classified_at`: `timestamp without time zone`, nullable: YES, index: active

Data historis yang sudah ada pada database tetap 100% utuh tanpa reset (`migrate:fresh` tidak dijalankan).

---

## 3. HASIL PENGUJIAN (TEST SUITE EXECUTION)

Seluruh test suite terkait Tahap 4A dan regresi AIS berhasil dijalankan dengan hasil **100% PASS**:

### A. Test Suite Baru: `AisPhase4aIngestionAndSyncTest`
```
PASS  Tests\Feature\AisPhase4aIngestionAndSyncTest
✓ vessels table has provenance columns                             0.50s  
✓ shiptype 58 and 59 mapping                                       0.02s  
✓ provenance is stored in dedicated columns and metadata           0.08s  
✓ out of order static messages do not regress classification       0.03s  
✓ type 19 preserves metadata when position is rejected             0.03s  
✓ type 19 with valid position ingests both                         0.03s  
✓ type 27 ingests position without classifying as fishing          0.03s  
✓ prohibited mmsi api requires valid token                         0.07s  
✓ prohibited mmsi api pagination and revocation                    0.03s  

Tests:    9 passed (76 assertions)
Duration: 1.57s
```

### B. Test Suite Regresi: `AisStreamIngestionRebuildTest`
```
PASS  Tests\Feature\AisStreamIngestionRebuildTest
✓ vessel type normalizer distinguishes fishing from cargo/unknown  0.45s  
✓ position report does not force unknown vessel to fishing         0.07s  
✓ position report filters sentinel values                          0.04s  
✓ ship static data updates vessel identity and type                0.03s  
✓ position deduplication is idempotent                             0.03s  
✓ static data report type 24 part b updates vessel                 0.03s  
✓ type 24 part a and part b sequential merge                       0.03s  
✓ batch endpoint requires valid x ais token                        0.05s  
✓ batch endpoint ingests messages with valid x ais token           0.04s  
✓ ais shiptype 70 to 79 classifies as cargo and records provenance 0.03s  
✓ ais shiptype 80 to 89 classifies as tanker and records provenance 0.03s 
✓ no external http or api called during classification             0.03s  
✓ message without shiptype results in unknown without guessing     0.04s  
✓ raw payload preserved unaltered as source evidence               0.03s  
✓ external api failure does not alter local ais cargo/tanker       0.03s  

Tests:    15 passed (113 assertions)
Duration: 1.48s
```

### C. Test Suite Kebijakan Retensi: `DataRetentionPolicyTest`
```
PASS  Tests\Feature\DataRetentionPolicyTest
Tests:    9 passed (42 assertions)
Duration: 1.72s
```

---

## 4. PEMENUHAN BATASAN KERJA

- [x] **Kebijakan retensi tidak diubah:** File `app/Services/DataRetentionPolicyService.php` tidak disentuh pada Tahap 4A.
- [x] **AIS Worker tidak diubah:** File `ais/worker.mjs` tetap utuh pada kondisi aslinya.
- [x] **Tidak mereklasifikasi 5 kapal historis:** Data 5 kapal legasi tidak disentuh pada tahap ini.
- [x] **Tidak menjalankan purge atau hapus data:** Seluruh record `vessel_positions` dan `vessels` terjaga 100%.
- [x] **Tidak mengubah konfigurasi produksi:** `.env` dan konfigurasi environment tidak diubah.
- [x] **Tidak ada commit atau push Git:** Perubahan tetap berada di working directory lokal.

---

## 5. RISIKO TERSISA & MITIGASI UNTUK TAHAP BERIKUTNYA

| Risiko Tersisa | Mitigasi yang Telah Disiapkan di Tahap 4A | Rencana Penanganan di Tahap 4B / 4C |
| :--- | :--- | :--- |
| **Worker masih memotong antrean pesan dini (`splice`)** | Kontrak API batch dan prohibited telah siap menerima koneksi resilien. | Tahap 4B akan memasang *Two-Phase In-Flight Buffer* pada `ais/worker.mjs`. |
| **Cache lokal worker belum melakukan sinkronisasi unblock** | Endpoint `GET /api/telemetry/ais/prohibited-mmsis` telah menyediakan payload `action: 'unblock'`. | Tahap 4B akan mengintegrasikan siklus sinkronisasi berkala pada worker. |
| **5 kapal legasi masih berlabel fishing `provider_reported`** | Kolom provenance dan proteksi timestamp telah siap menampung data verifikasi baru. | Tahap 4D akan mengeksekusi rekonsiliasi via command `ais:reconcile-unverified-vessels`. |

---

## 6. STATUS PEKERJAAN & LANGKAH SELANJUTNYA

- **Tahap 4A:** **SELESAI DENGAN SUKSES (100% COMPLETED)**.
- **Langkah Berikutnya:** Menunggu persetujuan pengguna untuk melanjutkan ke **Tahap 4B (Worker Hardening pada `ais/worker.mjs`)**.

