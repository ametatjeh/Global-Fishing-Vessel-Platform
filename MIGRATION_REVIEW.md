# GFVIP — DATABASE MIGRATION REVIEW & IMPACT ANALYSIS
**Target Database:** PostgreSQL 18 + PostGIS 3.6.2  
**Database Name (Production):** `gfvip_production`  
**Execution Context:** `php artisan migrate --pretend` (Dry-run review)  
**Strict Safety Rule:** Dilarang menjalankan `migrate:fresh`, `db:wipe`, `truncate`, `drop table`, atau destructive SQL.

---

## 1. Migration Inventory Overview

Terdapat **3 berkas migrasi baru** yang disiapkan pada siklus rilis ini:

1. `2026_11_07_043937_add_unique_constraint_to_vessel_positions.php`
2. `2026_11_07_045823_create_prohibited_vessels_table.php`
3. `2026_11_07_060000_add_enrichment_fields_to_vessels_table.php`

Seluruh migrasi bersifat **inkremental (additive)** dan **dapat dibalikkan (reversible)** tanpa merusak relasi integritas data yang telah ada.

---

## 2. Detailed Technical Audit per Migration

### Migrasi 1: `2026_11_07_043937_add_unique_constraint_to_vessel_positions.php`

- **Tabel Sasaran:** `vessel_positions`
- **Tipe Operasi:** `ALTER TABLE ... ADD CONSTRAINT`
- **Constraint / Index yang Ditambahkan:**
  - `vessel_pos_dedup_unique` UNIQUE CONSTRAINT pada kombinasi kolom:
    `['vessel_id', 'observed_at', 'latitude', 'longitude']`
- **Foreign Key:** Tidak ada foreign key baru.
- **Tingkat Risiko Kunci (Lock Risk):** **TINGGI (HIGH LOCK RISK)**
  - *Analisis PostgreSQL:* Penambahan unique constraint standar membutuhkan `ACCESS EXCLUSIVE` lock pada tabel `vessel_positions`. Pada tabel berukuran jutaan baris, operasi ini dapat memblokir operasi pembacaan (SELECT) dan penulisan (INSERT) selama indeks unik dibangun.
  - *Risiko Kegagalan:* Jika data produksi historis telah memiliki duplikasi titik telemetri pada 4 tuple tersebut, migrasi **AKAN GAGAL** dengan error `unique constraint violation`.
- **Mitigasi & Prosedur Pra-Migrasi yang Wajib Dijalankan:**
  Sebelum menjalankan migrasi di produksi, lakukan audit duplikasi berikut via psql:
  ```sql
  SELECT vessel_id, observed_at, latitude, longitude, COUNT(*)
  FROM vessel_positions
  GROUP BY vessel_id, observed_at, latitude, longitude
  HAVING COUNT(*) > 1;
  ```
  *Jika ada baris duplikat, lakukan deduplikasi aman terlebih dahulu dengan mempertahankan baris dengan ID terendah.*
- **Dampak pada Data Lama:** Data posisi yang valid tidak terhapus. Menjamin deduplikasi idempotensi untuk ingest real-time ke depan.
- **Perilaku Rollback (`down`):**
  ```php
  $table->dropUnique('vessel_pos_dedup_unique');
  ```
  Melepas constraint unik secara instan tanpa menghapus baris posisi apa pun.

---

### Migrasi 2: `2026_11_07_045823_create_prohibited_vessels_table.php`

- **Tabel Sasaran:** `prohibited_vessels` (Tabel Baru)
- **Tipe Operasi:** `CREATE TABLE`
- **Kolom yang Dibuat:**
  - `id` (bigserial, primary key)
  - `mmsi` (varchar 20, UNIQUE, INDEX)
  - `reason` (varchar, default 'cargo_tanker_policy', INDEX)
  - `original_name` (varchar nullable)
  - `original_type` (varchar nullable, INDEX)
  - `blocked_by_user_id` (bigint unsigned nullable, Foreign Key)
  - `exception_granted_at` (timestamp nullable)
  - `exception_notes` (text nullable)
  - `created_at`, `updated_at` (timestamps)
- **Foreign Key:**
  - `blocked_by_user_id` mereferensikan `users.id` dengan relasi `ON DELETE SET NULL`.
- **Tingkat Risiko Kunci (Lock Risk):** **SANGAT RENDAH (NONE / NEGLIGIBLE)**
  - Pembuatan tabel baru tidak memengaruhi kinerja tabel operasional mana pun.
- **Dampak pada Data Lama:** Nol (0) dampak.
- **Perilaku Rollback (`down`):**
  ```php
  Schema::dropIfExists('prohibited_vessels');
  ```
  Menghapus tabel log pencegahan tanpa memengaruhi data kapal.

---

### Migrasi 3: `2026_11_07_060000_add_enrichment_fields_to_vessels_table.php`

- **Tabel Sasaran:** `vessels`
- **Tipe Operasi:** `ALTER TABLE ... ADD COLUMN` (Idempotent guarded with `Schema::hasColumn`)
- **Kolom yang Ditambahkan:**
  - `beam_meters` (`decimal(8,2)`, nullable)
  - `engine_power_kw` (`decimal(10,2)`, nullable)
  - `build_year` (`smallint unsigned`, nullable)
  - `gear_type` (`string`, nullable)
  - `gfw_vessel_id` (`string`, nullable, INDEX)
  - `enrichment_status` (`string`, default `'not_enriched'`, INDEX)
  - `last_enriched_at` (`timestamp`, nullable, INDEX)
- **Foreign Key:** Tidak ada foreign key baru.
- **Tingkat Risiko Kunci (Lock Risk):** **RENDAH (LOW / FAST METADATA UPDATE)**
  - Pada PostgreSQL 11+, menambahkan kolom dengan default konstanta atau nilai `NULL` tidak menulis ulang seluruh tabel (hanya modifikasi katalog sistem `pg_attribute`). Kunci `ACCESS EXCLUSIVE` hanya berlangsung dalam hitungan milidetik.
- **Dampak pada Data Lama:**
  - Seluruh 93 kapal atau ribuan kapal di produksi tetap utuh dengan data aslinya.
  - Kolom baru bernilai `NULL` secara aman, sementara `enrichment_status` terisi otomatis `'not_enriched'`.
- **Perilaku Rollback (`down`):**
  ```php
  $table->dropColumn([...]);
  ```
  Menghapus 7 kolom spesifikasi teknis tambahan secara aman jika rollback diinstruksikan.

---

## 3. Safe Execution Protocol

Sebelum menjalankan migrasi, selalu lakukan simulasi SQL mentah:

```bash
# 1. Periksa simulasi query yang akan dieksekusi (DRY RUN)
php artisan migrate --pretend

# 2. Jika query SQL terverifikasi aman, eksekusi migrasi formal:
php artisan migrate --force
```

> [!CAUTION]
> DILARANG KERAS menggunakan opsi `--fresh`, `--seed`, atau `--step` yang memicu penghapusan skema!

