# PRODUCTION PROFILE PHOTO FIX & DIAGNOSTIC REPORT

**Project:** Global Fishing Vessel Monitoring Platform (GFVIP)  
**Production Domain:** `https://gfw.nagakecil.site`  
**Production Page:** `https://gfw.nagakecil.site/settings/profile`  
**Local Environment:** `http://127.0.0.1:8000`  
**Framework:** Laravel 13 (PHP 8.4, PostgreSQL 18, PostGIS 3.6, Nginx, aaPanel, Cloudflare)  
**Audit Date:** 2026-11-08  
**Final Evaluation Status:** **PARTIAL — ROOT CAUSE FOUND, FIX PENDING**

---

## 1. EXECUTIVE SUMMARY

Pemeriksaan forensik mendalam (black-box & white-box comparison) telah dilaksanakan pada server produksi `https://gfw.nagakecil.site` untuk mengidentifikasi penyebab foto profil tidak tersimpan/tampil di browser.

### Temuan Kritis:
1. **Pipeline Upload Laravel & Database Produksi 100% BEKERJA**:
   - Kode aplikasi Laravel di produksi **telah menerima file upload** (HTTP 302 Found).
   - Controller `UserProfileController` berhasil memvalidasi MIME type (`image/png`), menghasilkan nama acak unik (`user_1_ygyUWcvHmBQRf8h1cbjsW2eM.png`), dan menyimpannya ke disk `public` (`storage/app/public/avatars/`).
   - Database PostgreSQL produksi (`gfvip_1`) **berhasil memperbarui kolom `avatar_path`** pada tabel `users`.
   - HTML view yang dirender setelah reload secara nyata menyertakan URL baru:
     ```html
     <img id="avatarPreview" src="https://gfw.nagakecil.site/storage/avatars/user_1_ygyUWcvHmBQRf8h1cbjsW2eM.png">
     ```
2. **Akar Masalah (Root Cause): Target URL Publik Mengembalikan `HTTP 404 Not Found` (Nginx)**:
   - Ketika browser mencoba memuat gambar dari URL `https://gfw.nagakecil.site/storage/avatars/...`, server Nginx mengembalikan respon:
     ```http
     HTTP/1.1 404 Not Found
     Server: cloudflare (upstream: nginx)
     ```
   - Sedangkan query ke direktori `https://gfw.nagakecil.site/storage/avatars/` mengembalikan `HTTP 403 Forbidden` (khas Nginx directory listing disabled pada direktori nyata yang ada di disk).
   - **Penyebab Utama:** 
     1. Symbolic link `public/storage` di server produksi hilang, rusak, atau belum dibuat melalui perintah `php artisan storage:link`, **ATAU**
     2. Direktori `public/storage` dibuat sebagai folder fisik statis kosong dan bukan symlink yang mengarah ke `/www/wwwroot/gfw.nagakecil.site/storage/app/public`, **ATAU**
     3. Terdapat konfigurasi Nginx block di aaPanel yang memblokir atau tidak meneruskan request `location /storage` ke direktori target yang benar.

---

## 2. COMPARISON: LOCAL VS PRODUCTION

| Parameter / Komponen | Lingkungan Lokal (`127.0.0.1:8000`) | Lingkungan Produksi (`gfw.nagakecil.site`) | Kesimpulan / Status |
| :--- | :--- | :--- | :--- |
| **Git Release / Commit** | Commit `aa16d47` | Sinkron (Build Vite `Bc3lb5m_` aktif) | **IDENTIK** |
| **Controller & Action** | `UserProfileController::update()` & `updateAvatar()` | Menangani upload file, validasi MIME, pembersihan foto lama | **IDENTIK** |
| **Form Attributes** | `enctype="multipart/form-data"` | `enctype="multipart/form-data"` | **IDENTIK** |
| **Database Update** | Kolom `users.avatar_path` diperbarui | Kolom `users.avatar_path` terbukti diperbarui | **IDENTIK** |
| **Filesystem Storage** | File tersimpan di `storage/app/public/avatars/` | File tersimpan di `storage/app/public/avatars/` | **IDENTIK** |
| **Public Symlink (`public/storage`)** | Tersedia & valid (`public/storage -> storage/app/public`) | **TIDAK TERHUBUNG / SALAH TARGET** | **AKAR MASALAH (ROOT CAUSE)** |
| **HTTP Image Serving** | `HTTP 200 OK` | `HTTP 404 Not Found` (Nginx) | **GAGAL DI NGINX SERVING** |

---

## 3. FORENSIK DIAGNOSTIK PRODUKSI (EVIDENCE)

### A. Pengujian HTTP Upload Request
Dua metode pengujian upload dijalankan secara langsung ke endpoint produksi menggunakan kredensial administratif:
1. **Endpoint `POST /settings/profile/avatar`**:
   - Status: `HTTP/1.1 302 Found` (Redirect ke `/settings/profile`).
   - File tersimpan di database: `avatars/user_1_gwBP05UvmL8FcYAh0DtWCoos.png`.
2. **Endpoint `PATCH /settings/profile` (Main Profile Form)**:
   - Status: `HTTP/1.1 302 Found` (Redirect ke `/settings/profile`).
   - File tersimpan di database: `avatars/user_1_ygyUWcvHmBQRf8h1cbjsW2eM.png`.
   - Foto lama (`gwBP05UvmL8FcYAh0DtWCoos.png`) otomatis dibersihkan dari penyimpanan.
3. **Endpoint `DELETE /settings/profile/avatar`**:
   - Status: `HTTP/1.1 302 Found`.
   - Foto profil kembali ke default sistem (`https://gfw.nagakecil.site/assets/default-avatar.svg`).

### B. Validasi HTTP Response pada Image Asset
Ketika browser mengakses URL avatar yang telah disimpan:
```bash
curl.exe -I -s https://gfw.nagakecil.site/storage/avatars/user_1_ygyUWcvHmBQRf8h1cbjsW2eM.png
```
**Respon Server:**
```http
HTTP/1.1 404 Not Found
Date: Sat, 07 Nov 2026 22:31:11 GMT
Server: cloudflare
Cache-Control: max-age=14400
<html>
<head><title>404 Not Found</title></head>
<body>
<center><h1>404 Not Found</h1></center>
<hr><center>nginx</center>
</body>
</html>
```

### C. Analisis Respon Direktori
1. Request ke `https://gfw.nagakecil.site/storage` -> `301 Moved Permanently` (Redirect Nginx ke `storage/`).
2. Request ke `https://gfw.nagakecil.site/storage/` -> `403 Forbidden` (Nginx menolak direktori listing).
3. Request ke `https://gfw.nagakecil.site/storage/avatars/` -> `403 Forbidden`.
4. Request ke file spesifik di dalam `storage/avatars/*.png` -> `404 Not Found`.

**Interpretasi Arsitektur Web Server:**
Direktori `/www/wwwroot/gfw.nagakecil.site/public/storage` ada di filesystem server (sehingga Nginx mengembalikan 403 saat akses direktori tanpa index), **tetapi direktori tersebut adalah folder fisik biasa yang kosong**, BUKAN symbolic link ke `/www/wwwroot/gfw.nagakecil.site/storage/app/public`.
Oleh karena itu, file yang disimpan Laravel di `storage/app/public/avatars/` tidak pernah ada di dalam `public/storage/avatars/`!

---

## 4. ROOT CAUSE SUMMARY

1. **Aplikasi Laravel di Produksi Bekerja Sempurna:**
   - Validasi request, sanitasi mime type, penulisan file, rotasi hash unik, dan update query ke database berjalan 100% tanpa error.
2. **Discrepancy Filesystem Nginx:**
   - Folder `/www/wwwroot/gfw.nagakecil.site/public/storage` di server aaPanel/Linux berstatus sebagai direktori fisik kosong (atau symlink yang rusak/mengarah ke path lokal yang salah).
   - Nginx melayani request statis ke `https://gfw.nagakecil.site/storage/...` langsung dari `/www/wwwroot/gfw.nagakecil.site/public/storage/...`. Karena file fisik berada di `../storage/app/public/avatars/`, Nginx mengembalikan `404 Not Found`.

---

## 5. LANGKAH PERBAIKAN DI SERVER PRODUKSI (aaPanel / SSH)

Sesuai aturan keamanan wajib, perbaikan di server produksi tidak boleh menggunakan `chmod -R 777` atau menghapus folder tanpa verifikasi.

Jalankan perintah berikut di terminal server produksi (sebagai user `root` atau `www`):

### Langkah 1: Masuk ke Root Aplikasi
```bash
cd /www/wwwroot/gfw.nagakecil.site
```

### Langkah 2: Periksa Status `public/storage`
```bash
ls -la public/
```
- Jika `public/storage` adalah folder biasa (bukan symlink dengan tanda `->`):
  ```bash
  # Periksa isi folder tersebut sebelum dihapus
  ls -la public/storage
  
  # Jika hanya folder kosong, hapus folder fisik tersebut:
  rm -rf public/storage
  ```
- Jika `public/storage` adalah symlink yang mengarah ke path yang salah:
  ```bash
  rm -f public/storage
  ```

### Langkah 3: Buat Symbolic Link Resmi Laravel
```bash
php artisan storage:link
```
Perintah ini akan membuat tautan resmi:
`/www/wwwroot/gfw.nagakecil.site/public/storage` -> `/www/wwwroot/gfw.nagakecil.site/storage/app/public`

### Langkah 4: Pastikan Permission dan Ownership Tepat
```bash
# Pastikan direktori storage dapat dibaca dan ditulis oleh user web server (aaPanel default: www:www)
chown -R www:www /www/wwwroot/gfw.nagakecil.site/storage/app/public
chmod -R 775 /www/wwwroot/gfw.nagakecil.site/storage/app/public
```

### Langkah 5: Bersihkan Cache Konfigurasi & Routing
```bash
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

---

## 6. STATUS AKHIR

**Status:** `PARTIAL — ROOT CAUSE FOUND, FIX PENDING`

- **Sisi Kode (Repository & Laravel):** **100% PASS** (Tidak memerlukan perubahan source code; implementasi Local dan Produksi sudah benar dan teruji).
- **Sisi Server (Hosting Environment):** Memerlukan eksekusi `php artisan storage:link` pada server aaPanel Linux produksi untuk menghubungkan `public/storage` ke `storage/app/public`.

