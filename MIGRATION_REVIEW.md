# GFVIP — DATABASE MIGRATION REVIEW & IMPACT ANALYSIS
**Target Database:** PostgreSQL 18 + PostGIS 3.6.2  
**Actual Production Database Identity:** `gfvip_1`  
**Staging Database Identity:** `gfvip_staging`  
**Execution Context:** `php artisan migrate --pretend` (Dry-run review only)  
**Strict Safety Rule:** Dilarang menjalankan `migrate:fresh`, `db:wipe`, `truncate`, `drop table`, atau destructive SQL.

---

## 1. Migration Inventory Overview

Terdapat **3 berkas migrasi baru** yang disiapkan pada siklus rilis ini:

1. `2026_11_07_043937_add_unique_constraint_to_vessel_positions.php`
2. `2026_11_07_045823_create_prohibited_vessels_table.php`
3. `2026_11_07_060000_add_enrichment_fields_to_vessels_table.php`

---

## 2. In-Depth Technical Audit per Migration

### Migrasi 1: `2026_11_07_043937_add_unique_constraint_to_vessel_positions.php`

- **Tabel Sasaran:** `vessel_positions`
- **SQL Aktual yang Dihasilkan Laravel:**
  ```sql
  ALTER TABLE "vessel_positions" ADD CONSTRAINT "vessel_pos_dedup_unique" UNIQUE ("vessel_id", "observed_at", "latitude", "longitude");
  ```
- **Constraint Definition:**
  Constraint bernama `vessel_pos_dedup_unique` yang memastikan kombinasi `(vessel_id, observed_at, latitude, longitude)` bersifat unik untuk mencegah data posisi ganda yang masuk akibat duplikasi paket WebSocket AIS.
- **Index yang Sudah Ada Sebelumnya di Tabel Ini:**
  - `vessel_positions_pkey` (PRIMARY KEY pada kolom `id` UUID)
  - `vessel_positions_vessel_id_foreign` (Foreign Key ke `vessels.id`)
  - `vessel_positions_latitude_index`
  - `vessel_positions_longitude_index`
  - `vessel_positions_observed_at_index`
  - `vessel_positions_received_at_index`
  - `vessel_positions_is_gap_indicator_index`
  - `vessel_positions_vessel_id_observed_at_index` (Composite Index)
- **Perilaku NULL (NULL Behavior):**
  Keempat kolom (`vessel_id`, `observed_at`, `latitude`, `longitude`) berstatus `NOT NULL`. Oleh karena itu, PostgreSQL akan memvalidasi keunikan secara deterministik tanpa ambiguitas penanganan nilai NULL.
- **Tingkat Risiko Kunci (Lock Risk) & Pertimbangan Ukuran Tabel:**
  - **RISIKO KUNCI TINGGI (`ACCESS EXCLUSIVE` LOCK):**
    Di PostgreSQL, penambahan constraint UNIQUE via sintaks standar `ALTER TABLE ... ADD CONSTRAINT` memerlukan kunci `ACCESS EXCLUSIVE`. Kunci ini memblokir **seluruh operasi SELECT, INSERT, UPDATE, dan DELETE** pada tabel `vessel_positions` selama PostgreSQL membangun indeks B-Tree dan memindai tabel untuk memvalidasi keunikan.
  - Jika tabel `vessel_positions` di produksi telah memiliki ratusan ribu atau jutaan baris, operasi ini dapat mengunci tabel selama beberapa puluh detik hingga menit, menyebabkan request timeout pada aplikasi web dan terputusnya koneksi worker AIS.
  - **RISIKO GAGAL BILA ADA DUPLIKAT:** Jika di database produksi historis terdapat baris dengan tuple identik, migrasi akan langsung gagal dengan error `SQLSTATE[23505]: Unique violation`.

#### Deteksi Duplikasi Pra-Migrasi (Query Audit Aman):
Jalankan query ini di PostgreSQL `gfvip_1` SEBELUM menjalankan migrasi:
```sql
SELECT vessel_id, observed_at, latitude, longitude, array_agg(id) AS position_ids, COUNT(*) AS count
FROM vessel_positions
GROUP BY vessel_id, observed_at, latitude, longitude
HAVING COUNT(*) > 1;
```
*(Pada database lokal, query ini menghasilkan 0 duplikat).*

#### Strategi Alternatif Zero-Downtime Tanpa Lock (Rekomendasi untuk Tabel Sangat Besar):
Jika tabel produksi sangat besar, rekomendasi PostgreSQL terbaik adalah membangun indeks unik secara konkuren terlebih dahulu tanpa mengunci pembacaan:
```sql
-- Langkah 1: Bangun indeks unik di latar belakang tanpa lock eksklusif (SHARE UPDATE EXCLUSIVE)
CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS vessel_pos_dedup_unique_idx
ON vessel_positions (vessel_id, observed_at, latitude, longitude);

-- Langkah 2: Pasang constraint menggunakan indeks yang sudah selesai dibangun (Instan / < 1ms)
ALTER TABLE vessel_positions
ADD CONSTRAINT vessel_pos_dedup_unique
UNIQUE USING INDEX vessel_pos_dedup_unique_idx;
```

#### Kandidat Strategi Deduplikasi Non-Destruktif (Jika Duplikat Ditemukan):
Jika ditemukan duplikasi di produksi, **JANGAN MENGHAPUS DATA**. Gunakan strategi pengarsipan forensik:
1. Buat tabel arsip: `vessel_positions_dedup_archive`.
2. Pindahkan baris duplikat sekunder (mempertahankan baris dengan telemetri terlengkap sebagai baris primer) ke dalam tabel arsip dengan mencatat referensi `primary_position_id` dan `archived_at`.
3. Seluruh raw payload, identitas UUID, dan timestamp historis tetap tersimpan 100% untuk audit forensik maritim.

- **Perilaku Rollback (`down`):**
  ```php
  $table->dropUnique('vessel_pos_dedup_unique');
  ```
  Melepas constraint unik secara instan tanpa menghapus baris data apa pun.

---

### Migrasi 2: `2026_11_07_045823_create_prohibited_vessels_table.php`

- **Tabel Sasaran:** `prohibited_vessels` (Tabel Baru)
- **Tipe Operasi:** `CREATE TABLE`
- **Kolom:** `id`, `mmsi` (UNIQUE), `reason`, `original_name`, `original_type`, `blocked_by_user_id` (FK `users.id` dengan `nullOnDelete`), timestamps.
- **Tingkat Risiko Kunci:** **NOL (NEGLIGIBLE)** — Tabel baru yang independen.
- **Dampak pada Data Lama:** Nol dampak.
- **Perilaku Rollback (`down`):** `Schema::dropIfExists('prohibited_vessels')`.

---

### Migrasi 3: `2026_11_07_060000_add_enrichment_fields_to_vessels_table.php`

- **Tabel Sasaran:** `vessels`
- **Tipe Operasi:** `ALTER TABLE ... ADD COLUMN` (Idempotent guarded dengan `Schema::hasColumn`)
- **Kolom Ditambahkan:** `beam_meters`, `engine_power_kw`, `build_year`, `gear_type`, `gfw_vessel_id` (idx), `enrichment_status` (idx), `last_enriched_at` (idx).
- **Tingkat Risiko Kunci:** **RENDAH (FAST METADATA UPDATE)** — Pada PostgreSQL 11+, penambahan kolom bernilai NULL atau konstanta hanya memperbarui katalog sistem tanpa menulis ulang tabel fisik.
- **Dampak pada Data Lama:** Seluruh data kapal lama tetap utuh.
- **Perilaku Rollback (`down`):**
  > [!WARNING]
  > **PERHATIAN ROLLBACK SKEMA:** Menjalankan rollback pada migrasi ini akan mengeksekusi `$table->dropColumn([...])`. Hal ini akan **MENGHAPUS SECARA PERMANEN** seluruh data spesifikasi teknis (beam, engine power, status pengayaan GFW) yang telah diisikan ke kolom-kolom tersebut sejak deployment! Oleh karena itu, migrasi ini tidak boleh di-rollback kecuali memang berniat membuang data pengayaan tersebut.

---

## 3. Protokol Verifikasi Pra-Migrasi

Sebelum Administrator mengonfirmasi eksekusi `php artisan migrate --force`, langkah verifikasi wajib dilakukan:
1. Pastikan backup database `gfvip_1` telah selesai dibuat dan berukuran wajar (> 0 byte).
2. Jalankan query pemeriksaan duplikasi pada `vessel_positions`.
3. Jalankan `php artisan migrate --pretend` dan pastikan ketiga migrasi di atas terdaftar tanpa error sintaks SQL.
