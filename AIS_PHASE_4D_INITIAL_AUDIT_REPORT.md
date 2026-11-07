# LAPORAN AUDIT READ-ONLY AWAL — TAHAP 4D
## GFVIP Admin Classification Review & Reconciliation Architecture Audit

**Project:** Global Fishing Vessel Monitoring Platform (GFVIP)  
**Workspace:** `C:\XPROJECT\global-fishing-vessel-intelligence`  
**Waktu Audit:** 2026-11-08 03:10 WIB  
**Mode:** STRICT READ-ONLY AUDIT  

---

## 1. TUJUAN AUDIT READ-ONLY TAHAP 4D

Audit ini bertujuan untuk menginspeksi seluruh komponen arsitektur yang telah dibangun pada Tahap 4A, 4B, 4C, dan 4C.2, mengidentifikasi komponen yang dapat digunakan kembali (*reusable*), mendeteksi celah integrasi, dan memastikan bahwa sistem **Admin Classification Review & Reconciliation** yang akan dibangun tidak bertentangan dengan implementasi sebelumnya.

---

## 2. HASIL AUDIT KOMPONEN EKSISTING

### 2.1. Model `app/Models/Vessel.php`
Model `Vessel` telah dilengkapi dengan atribut dan metode inti hasil Tahap 4A dan 4C.2:
- **Atribut Provenance:**
  - `verification_status` (`varchar(255)`): Berisi status seperti `unverified`, `provider_reported`, `unverified_historical`, `verified`, `reclassified`, `rejected`, `under_review`.
  - `classification_source` (`varchar(255)`): Menyimpan sumber klasifikasi (e.g. `AIS_TYPE_5`, `AIS_TYPE_24B`, `AIS_TYPE_19`, `ANALYST_VERIFICATION`).
  - `ais_ship_type_code` (`integer`): Menyimpan kode ITU AIS numerik (e.g. `30` Fishing, `70` Cargo, `80` Tanker).
  - `classified_at` (`datetime`): Timestamp saat klasifikasi ditetapkan.
- **Logika Keamanan Retensi & Konflik:**
  - `isVerifiedFishing()`: Menegakkan aturan non-konflik. Mengembalikan `false` jika `ais_ship_type_code !== null && ais_ship_type_code !== 30`. Mengembalikan `true` jika kode 30 atau verifikasi manual dengan kode `null`.
  - `isClassificationDisputed()`: Mengembalikan `true` jika `vessel_type === 'fishing'` tetapi `ais_ship_type_code` adalah kode non-fishing (> 0 dan != 30).
  - `isUnderProtectiveHold()`: Mengembalikan `true` jika kapal termasuk dalam 5 kapal historis, berstatus `isClassificationDisputed()`, `isUnverifiedFishing()`, atau berstatus `under_review`/`unverified_historical`/`disputed`.
  - `scopeVerifiedFishing()` & `scopeUnderProtectiveHold()`: Query scopes tersinkronisasi 100% dengan metode model.

*Rekomendasi Tahap 4D:* Tambahkan `scopeDisputedClassification()` pada model `Vessel` untuk mempermudah filter query pada antarmuka admin, serta relasi `auditLogs()` untuk menampilkan riwayat review.

---

### 2.2. Service `app/Services/DataRetentionPolicyService.php`
- `getVerifiedFishingVesselIds()` mendelegasikan secara langsung ke `Vessel::verifiedFishing()->pluck('id')`.
- `getProtectiveHoldVesselIds()` mendelegasikan secara langsung ke `Vessel::underProtectiveHold()->pluck('id')`.
- Kapal yang berstatus `isClassificationDisputed()` secara otomatis masuk ke `Protective Hold` dan dikecualikan secara mutlak dari kandidat purge.
- Simulasi dry-run (`ais:prune-telemetry --dry-run`) mengonfirmasi bahwa posisi kapal fishing dan kapal hold tidak pernah disentuh.

---

### 2.3. Controller, Route, Middleware, dan Authorization
- **Routing & Middleware:**
  - Middleware alias `role` (`EnsureUserRole.php`) mendukung pengecekan multi-role: `role:super_admin,admin,analyst`.
  - User model memiliki helper `isAdmin()` (`super_admin`, `admin`, `administrator`) dan `isAnalyst()`.
  - Sistem portal membatasi akses modul operasional hanya untuk pengguna yang aktif (`is_active = true`).
- **Menu Navigasi:**
  - Terletak pada `resources/views/layouts/app.blade.php`.
  - Submenu `Classification Review` dapat ditempatkan di bawah `Vessel Registry` (`vessels.index`), dapat diakses oleh `super_admin`, `admin`, dan `analyst`.

---

### 2.4. Struktur Audit Log (`app/Models/AuditLog.php`)
- **Tabel `audit_logs`:**
  - Kolom: `id` (uuid), `user_id` (foreignId), `action` (string), `entity_type` (string), `entity_id` (string), `ip_address`, `user_agent`, `details` (json), `created_at` (timestamp).
  - Metode `AuditLog::log(action, entityType, entityId, details, userId)` sudah tersedia dan digunakan di modul SAR, Backup, dan Retention.
  - Sifat: Append-only secara inheren.
- **Penggunaan di Tahap 4D:**
  - Setiap keputusan review analis dicatat menggunakan `AuditLog::log()` dengan `action = 'vessel_classification_review'`, mencatat identitas analis, alasan (*reason*), bukti (*evidence*), nilai sebelum (*previous*), dan nilai sesudah (*new*).
  - Operasi review dibungkus dalam `DB::transaction()`. Jika pembuatan audit log gagal, seluruh perubahan klasifikasi dibatalkan (*rollback*).

---

### 2.5. Struktur Database & Migration Aktual
- PostgreSQL 18 + PostGIS pada database `gfw-db`.
- Tabel `vessels` telah memiliki kolom `classification_source`, `ais_ship_type_code`, dan `classified_at` via migration `2026_11_08_020000_add_classification_provenance_to_vessels_table.php`.
- Kolom `verification_status` sudah tersedia secara default.
- Kolom `metadata` berformat `json` dapat digunakan untuk menyimpan riwayat klasifikasi lokal (`classification_history`) sebagai redundansi cepat selain tabel `audit_logs`.
- **Tidak diperlukan migration baru** untuk skema dasar karena kolom yang ada sudah lengkap dan memenuhi kebutuhan Tahap 4D. Jika dibutuhkan kolom tambahan, hanya dibuat migration additive.

---

### 2.6. Ingestion Telemetri AIS (`AisStreamAdapter.php`)
- Pada baris 1050-1072 `AisStreamAdapter.php`, paket statis AIS (Type 5, 19, 24B) dilindungi dari pesan yang datang terlambat (*out-of-order*) menggunakan pembandingan `$observedAt->lt($existingClassifiedAt)`.
- **Temuan Celah Kritis yang Perlu Diperkuat:**
  - Jika sebuah kapal telah diverifikasi manual oleh analis (`classification_source === 'ANALYST_VERIFICATION'` atau `verification_status === 'verified'`), kedatangan paket AIS baru tidak boleh serta-merta mengganti `vessel_type` kembali ke nilai mentah AIS.
  - Sebaliknya, jika paket AIS baru membawa kode yang bertentangan dengan keputusan analis, sistem harus memperbarui `ais_ship_type_code` (yang memicu `isClassificationDisputed() = true` dan mengalihkan kapal ke *Protective Hold / Under Review*) tanpa menghapus keputusan historis analis.

---

### 2.7. Status Test Suites Eksisting
- `AisRetentionSafeguardTest`: 24/24 PASS (119 assertions)
- `DataRetentionPolicyTest`: 9/9 PASS (42 assertions)
- `AisPhase4aIngestionAndSyncTest`: 9/9 PASS (76 assertions)
- `ais/test_worker_hardening.mjs`: 10/10 PASS

Seluruh test suite eksisting stabil dan siap dijadikan tolok ukur regresi (*regression baseline*).

---

## 3. RENCANA IMPLEMENTASI TAHAP 4D

Berdasarkan hasil audit, implementasi Tahap 4D akan mengeksekusi langkah-langkah berikut:

1. **Penguatan Model & Scopes (`app/Models/Vessel.php`):**
   - Tambahkan `scopeDisputedClassification($query)`.
   - Tambahkan helper method `getConflictStatusAttribute()` untuk antarmuka UI.
   - Tambahkan relasi `auditLogs()` untuk mengambil log riwayat kapal secara langsung.
2. **Controller & Routes (`VesselClassificationReviewController.php` & `routes/web.php`):**
   - Route index: `GET /vessels/classification-review` (nama: `vessels.classification-review.index`).
   - Route show/detail: `GET /vessels/classification-review/{id}` (nama: `vessels.classification-review.show`).
   - Route action: `POST /vessels/classification-review/{id}/decision` (nama: `vessels.classification-review.decision`).
   - Dilindungi middleware `role:super_admin,admin,analyst`.
3. **Logika Keputusan Analis Berbasis Transaksi Atomik:**
   - Validasi ketat: alasan (*reason*) dan bukti (*evidence*) wajib diisi.
   - Penolakan otomatis jika memilih "Verify Fishing" sementara `ais_ship_type_code` adalah 70 atau 80 (aturan non-konflik).
   - Atomic transaction `DB::transaction()` dengan `AuditLog::log()`.
4. **Penguatan Ingestion AIS (`AisStreamAdapter.php`):**
   - Lindungi keputusan analis agar tidak tertimpa oleh pesan AIS lama.
   - Jika AIS baru membawa kode yang bertentangan, pertahankan riwayat dan tandai kapal sebagai berkonflik/perlu ditinjau ulang.
5. **Tampilan UI (Blade Views):**
   - `resources/views/vessels/classification_review/index.blade.php`: Tabel interaktif dengan filter server-side, search, dan pagination.
   - `resources/views/vessels/classification_review/show.blade.php`: Detail bukti klasifikasi, data AIS, indikator konflik, dan formulir keputusan analis.
   - Navigasi sidebar terintegrasi pada `resources/views/layouts/app.blade.php`.
6. **Feature Test Suite Komprehensif (`tests/Feature/AisClassificationReviewTest.php`):**
   - Mencakup 20 skenario uji wajib yang ditetapkan pada instruksi Tahap 4D.

---

## 4. KESIMPULAN AUDIT

Tidak ditemukan konflik arsitektur yang menghalangi implementasi. Seluruh pondasi data dan keamanan dari Tahap 4A, 4B, 4C, dan 4C.2 berada dalam kondisi solid dan siap dihubungkan dengan modul **Admin Classification Review & Reconciliation**.

