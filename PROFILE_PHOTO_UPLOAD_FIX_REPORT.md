# PROFILE PHOTO UPLOAD FIX REPORT

**Project:** Global Fishing Vessel Monitoring Platform (GFVIP)  
**Halaman:** `http://127.0.0.1:8000/settings/profile`  
**Framework:** Laravel 13, PHP 8.4  
**Audit & Fix Timestamp:** 2026-11-08 04:50:00 UTC+07:00  
**Status Akhir:** `PASS — PROFILE PHOTO UPLOAD, STORAGE, DATABASE UPDATE AND DISPLAY VERIFIED`

---

## 1. ROOT CAUSE (AKAR MASALAH)

Berdasarkan audit end-to-end terhadap implementasi profil pengguna, ditemukan 2 akar masalah utama mengapa foto profil tidak tersimpan atau tidak tampil ketika pengguna memperbarui profil di `http://127.0.0.1:8000/settings/profile`:

### Akar Masalah 1: Form Profil Utama Tidak Mendukung Atribut Berkas & Pengendali Backend Tidak Menerima `avatar`
- **Gejala:** Pengguna memilih berkas foto baru lewat kartu profil kiri, lalu berpindah ke formulir utama "Informasi Profil Akun" di sebelah kanan dan menekan tombol **"Simpan Perubahan"** (`PATCH /settings/profile`).
- **Penyebab Faktual:**
  1. Formulir utama `<form action="{{ route('profile.update') }}" method="POST">` awalnya **tidak memiliki atribut `enctype="multipart/form-data"`**.
  2. Method `UserProfileController@update` pada backend hanya memvalidasi `name` (`$request->validate(['name' => ...])`) dan **sama sekali mengabaikan berkas avatar**.
  3. Meskipun tombol khusus "Simpan Foto Baru" (`POST /settings/profile/avatar`) tersedia di kartu kiri, pemisahan dua form yang tidak terhubung ini membingungkan pengguna: mengklik tombol "Simpan Perubahan" di form informasi akun tidak pernah menyimpan foto yang telah dipilih.

### Akar Masalah 2: Resolusi URL Foto Profil Menggunakan `Storage::disk('public')->url()` Berbasis Default `APP_URL=http://localhost`
- **Gejala:** Foto berhasil tersimpan di disk `storage/app/public/avatars/` dan path tercatat di database, namun setelah halaman di-refresh di browser pada alamat `http://127.0.0.1:8000/settings/profile`, foto profil gagal dimuat (broken image / cross-port origin issue).
- **Penyebab Faktual:**
  - Method accessor `getAvatarUrlAttribute()` di model `User` memanggil `Storage::disk('public')->url($this->avatar_path)`.
  - Berdasarkan `config/filesystems.php`, `url` disk `public` dibangun dari `env('APP_URL', 'http://localhost') . '/storage'`.
  - Ketika aplikasi dijalankan via `php artisan serve` pada port `8000` (`http://127.0.0.1:8000`), URL yang dihasilkan oleh Storage adalah `http://localhost/storage/avatars/...` (tanpa port 8000). Browser gagal mengambil gambar dari host `http://localhost` (port 80 standar) sementara server berjalan di port 8000.

---

## 2. FILE YANG DIPERIKSA

1. `routes/web.php`: Route grup `settings/profile` (`edit`, `update`, `avatar.update`, `avatar.destroy`).
2. `app/Http/Controllers/UserProfileController.php`: Controller penanganan update profil dan avatar.
3. `app/Models/User.php`: Konfigurasi `$fillable`, attribute casting, dan accessor `avatar_url`.
4. `database/migrations/2026_10_29_000500_add_avatar_and_google_id_to_users_table.php`: Skema database kolom `avatar_path`.
5. `resources/views/profile/edit.blade.php`: Tampilan Blade, struktur form upload foto profil dan form update profil.
6. `resources/views/layouts/app.blade.php`: Header bar dan sidebar user profile avatar preview.
7. `config/filesystems.php`: Konfigurasi disk `public` dan symlink `storage:link`.
8. `.env`: Variabel konfigurasi lingkungan (`APP_URL`, `FILESYSTEM_DISK`).
9. `tests/Feature/UserSettingsTest.php`: Automated test suite untuk user profile settings.

---

## 3. FILE YANG DIUBAH

1. **`app/Models/User.php`**
   - Mengubah accessor `getAvatarUrlAttribute()` agar menggunakan helper standar Laravel `asset('storage/' . $this->avatar_path)` alih-alih `Storage::disk('public')->url()`.
   - Hal ini memastikan URL gambar selalu dinamis mengikuti host, skema, dan port dari request aktif saat ini (misal `http://127.0.0.1:8000` pada local dev, atau `https://gfw.nagakecil.site` pada server production).
2. **`app/Http/Controllers/UserProfileController.php`**
   - Memperluas method `update(Request $request)` agar dapat memvalidasi dan memproses upload `avatar` secara atomik bersamaan dengan pembaruan `name`.
   - Menerapkan validasi ketat MIME binary header (`getimagesize()`), format JPEG, PNG, WebP, dan batas maksimal 2 MB.
   - Menghapus foto profil lama dari disk public setelah foto profil baru berhasil disimpan ke database.
   - Menambahkan event logging pada `AuditLog` dengan status `avatar_updated`.
3. **`resources/views/profile/edit.blade.php`**
   - Menambahkan `enctype="multipart/form-data"` dan hidden input `profileFormAvatarInput` pada form `#profileUpdateForm`.
   - Mengubah label tombol di kartu kiri dari ambigu "Simpan Foto Baru" menjadi **"Unggah Foto"** untuk kejelasan fungsional.
   - Menambahkan JavaScript sinkronisasi otomatis (`DataTransfer`) di `handleAvatarSelected()` sehingga ketika pengguna memilih file foto, file tersebut secara simultan siap dikirim baik lewat tombol "Unggah Foto" maupun tombol "Simpan Perubahan".
4. **`tests/Feature/UserSettingsTest.php`**
   - Menambahkan 5 skenario automated test baru untuk memverifikasi seluruh kemungkinan upload foto profil (PNG/WebP, form profil terpadu, pencegahan modifikasi foto pengguna lain, retensi tampilan pasca-reload, dan penolakan file PDF).

---

## 4. SKEMA DATABASE & LOKASI PENYIMPANAN

- **Nama Kolom Database:** `avatar_path` (tipe `VARCHAR(255)`, nullable).
- **Tabel:** `users`.
- **Status `$fillable`:** Terdaftar di atribut `#[Fillable([... 'avatar_path', ...])]` pada model `User`.
- **Lokasi Penyimpanan Disk:** `storage/app/public/avatars/`.
- **Public Symlink:** `public/storage` (Junction ke `storage/app/public`).
- **Format Penamaan Berkas:** `user_{id}_{random24}.{ext}` (misal: `user_1_N7ASs3I6gUEX07CQ5qEzAtwW.jpg`) untuk mencegah tabrakan nama dan serangan directory traversal.

---

## 5. HASIL PENGUJIAN OTOMATIS (AUTOMATED TESTING)

### 5.1 Test Suite Profil Pengguna (`tests/Feature/UserSettingsTest.php`)
Perintah: `php artisan test tests/Feature/UserSettingsTest.php`
Hasil: **15 passed (56 assertions)** — 100% Green.

| No | Skenario Pengujian | Hasil |
| :---: | :--- | :---: |
| 1 | `guest cannot access user settings` | **PASS** |
| 2 | `authenticated user can view user settings` | **PASS** |
| 3 | `user can update profile name` | **PASS** |
| 4 | `user cannot update name with empty or short value` | **PASS** |
| 5 | `user can upload valid avatar image` (JPG) | **PASS** |
| 6 | `user can upload valid png and webp avatar image` | **PASS** |
| 7 | `user can update profile and avatar via main form` | **PASS** |
| 8 | `user cannot modify other user avatar` (Otorisasi) | **PASS** |
| 9 | `avatar retains after reloading profile view` (Persistensi) | **PASS** |
| 10 | `avatar upload rejects pdf file` (MIME Protection) | **PASS** |
| 11 | `avatar upload rejects oversized file` (> 2 MB) | **PASS** |
| 12 | `avatar upload rejects disallowed and executable formats` (.php) | **PASS** |
| 13 | `avatar upload rejects spoofed non image content` (Fake header) | **PASS** |
| 14 | `old avatar file is pruned when replaced` (Zero orphan file) | **PASS** |
| 15 | `user can delete avatar and revert to default` (Fallback SVG) | **PASS** |

### 5.2 Regresi Penuh Sistem (`php artisan test`)
Perintah: `php artisan test`  
Hasil: **429 passed (2,727 assertions)** — Nol kegagalan.

### 5.3 Kompilasi Aset Frontend (`npm run build`)
Perintah: `npm run build`  
Hasil: Vite v7.3.7 mentransformasi 63 modul dalam 4.66s tanpa error.

---

## 6. CARA VERIFIKASI MANUAL

1. Masuk ke aplikasi menggunakan browser di `http://127.0.0.1:8000/login`.
2. Buka menu navigasi sidebar atau klik profil di pojok kiri bawah, pilih **"User Settings"** (`http://127.0.0.1:8000/settings/profile`).
3. Pada kartu **"Foto Profil"**:
   - Klik tombol **"Pilih Berkas Foto"** atau klik area lingkaran avatar.
   - Pilih berkas foto (JPG, PNG, atau WebP) dengan ukuran di bawah 2 MB.
   - Preview foto akan langsung muncul di lingkaran avatar.
   - Klik tombol **"Unggah Foto"** ATAU ubah nama profil lalu klik tombol **"Simpan Perubahan"** di formulir sebelah kanan.
4. Notifikasi hijau *"Foto profil berhasil diperbarui"* atau *"Profil dan foto profil berhasil diperbarui"* akan muncul.
5. Lakukan refresh browser (**F5** atau **Ctrl+R**):
   - Foto profil tetap tampil di avatar preview.
   - Label status menampilkan badge hijau *"✓ Foto Kustom Aktif"*.
   - Thumbnail foto di pojok kiri bawah sidebar dan navbar atas otomatis menampilkan foto profil baru pengguna.
6. Untuk menghapus foto kustom dan kembali ke inisial default:
   - Klik tombol merah *"🗑️ Hapus Foto Profil"*.
   - Konfirmasi dialog penghapusan.
   - Avatar otomatis kembali ke ikon avatar sistem SVG default.

---

## 7. KESIMPULAN

Seluruh ketentuan bug fix telah terpenuhi: foto profil berhasil diunggah, tersimpan di disk public storage dengan path unik pada database, persisten setelah reload, aman dari manipulasi file lain, dan tidak mengganggu alur sistem lainnya.

**STATUS AKHIR:**  
`PASS — PROFILE PHOTO UPLOAD, STORAGE, DATABASE UPDATE AND DISPLAY VERIFIED`

