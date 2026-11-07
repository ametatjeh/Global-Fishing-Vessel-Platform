# FISHING VESSEL COG VISUAL ENHANCEMENT REPORT
**Project:** Global Fishing Vessel Monitoring Platform (GFVIP)  
**Halaman:** `http://127.0.0.1:8000/map`  
**Engine Peta:** MapLibre GL JS v6.15  
**Tanggal Verifikasi:** 2026-11-08 05:12:00 UTC+07:00  
**Status Akhir:** `PASS — DISTINCT FISHING TRIANGLE ICON AND AIS COG DIRECTION VERIFIED`

---

## 1. KONDISI SEBELUM PERUBAHAN
Sebelum peningkatan ini:
- Simbol kapal pada `vessels-oriented-layer` terbelah menjadi dua bentuk yang berbeda: bentuk lambung melengkung (*curved ship hull*) jika data berasal dari `heading`, dan bentuk panah segitiga (*chevron arrowhead*) jika data berasal dari `cog`.
- Kapal nelayan (*fishing*) menggunakan bentuk yang mirip dengan tipe kapal lainnya dan hanya dibedakan berdasarkan warna serta titik tengah lingkaran kecil tanpa siluet kapal nelayan yang jelas.
- Kategori kapal seperti `cargo` dan `tanker` belum memiliki pemetaan warna segitiga yang spesifik (hanya fallback ke warna umum atau cyan/special).
- Legenda pada popover peta menampilkan deskripsi haluan lama dan lingkaran sederhana tanpa bentuk segitiga nyata sesuai taksonomi warna.

---

## 2. MEKANISME MARKER YANG DIGUNAKAN
- **Renderer:** MapLibre GL JS `symbol` layer (`vessels-oriented-layer`) dan `circle` layer fallback (`vessels-circle-layer`).
- **Pendaftaran Gambar:** Dinamis menggunakan HTML5 Canvas 2D (`registerVesselIcons()`) yang menghasilkan `ImageData` resolusi tinggi (`size: 32px`, `pixelRatio: 2`), bebas dependensi eksternal, tajam pada layar Retina/HiDPI, dan berkinerja tinggi tanpa memory leak.
- **Dukungan Basemap:** Memiliki layer bayangan/latar gelap (*dark outline underlayer* `rgba(0,0,0,0.65)`) di bagian bawah segitiga sehingga marker terlihat kontras dan terbaca jelas baik di atas basemap CARTO Dark, Positron Light, maupun Esri Dark Ocean.
- **Rotasi Peta:** Menggunakan `'icon-rotation-alignment': 'map'` dan `'icon-pitch-alignment': 'map'`. Marker berputar mengikuti koordinat geografis bumi saat peta diputar atau dimiringkan.

---

## 3. FILE YANG DIUBAH
1. **`resources/js/map/global-map.js`**
   - Mengubah `registerVesselIcons()`:
     - Mengimplementasikan `createFishingIcon()` khusus kapal penangkap ikan: segitiga berarah Amber `#F59E0B` dengan palang cadik/trawler (*outrigger boom stabilizer*), kabin kemudi (*wheelhouse*), dan garis haluan depan (*forward mast centerline*).
     - Mengimplementasikan `createStandardTriangleIcon()` untuk seluruh tipe kapal lainnya dengan segitiga directional bergaris tepi putih bersih (`#FFFFFF`).
     - Mendaftarkan seluruh kategori kapal sesuai spesifikasi teknis.
   - Mengubah style expression pada layer `vessels-oriented-layer` dan `vessels-circle-layer` agar memetakan kategori `fishing`, `cargo`, `tanker`, `passenger`, `support`, `patrol`, `military`, `law_enforcement`, dan `unknown`.
2. **`resources/views/map/index.blade.php`**
   - Memperbarui popover Legenda Ilmiah (`#popoverLegend`):
     - Bagian 1 (Tipe Kapal): Menampilkan representasi visual segitiga asli dengan warna hex resmi untuk Fishing, Cargo, Tanker, Passenger, Support, Patrol, dan Unknown.
     - Bagian 2 (Arah Gerak): Menambahkan teks spesifik: *"Triangle orientation indicates Course Over Ground (COG)"*.

---

## 4. DESAIN VISUAL MARKER FISHING (SPECIAL FISHING TRIANGLE)
Marker kapal penangkap ikan (`vessel-fishing-cog`) dirancang khusus agar langsung dikenali tanpa harus membuka panel informasi:
- **Bentuk Dasar:** Segitiga lancip mengarah ke depan (Utara / 0° rotasi awal).
- **Warna Dominan (Fill):** Amber Gold `#F59E0B` (versi usang: `#92400e`).
- **Garis Luar (Outline):** Putih murni `#FFFFFF` dengan bayangan kontras gelap `rgba(0, 0, 0, 0.65)`.
- **Detail Siluet Nelayan:**
  1. **Palang Cadik / Boom Trawler:** Garis horisontal putih di badan kapal (`y: 17`) yang melambangkan jaring atau cadik kapal nelayan tradisional maupun modern.
  2. **Stabilizer Bulat:** Dua titik tumpu gelap `#451a03` di ujung cadik kiri dan kanan.
  3. **Kabin Kemudi / Wheelhouse:** Lingkaran kabin di tengah kapal (`y: 13`) berwarna gelap `#1e293b` dengan outline putih.
  4. **Tiang Haluan / Forward Mast:** Garis penunjuk arah di depan kabin kemudi (`y: 4` ke `10`).

---

## 5. TABEL PEMETAAN WARNA FINAL (VESSEL COLOR TAXONOMY)

| Vessel Type | Kategori | Warna | Hex Code | Tipe Marker |
| :--- | :--- | :--- | :---: | :--- |
| **Fishing** | Perikanan | Amber | `#F59E0B` | Special Triangle (Siluet Trawler) |
| **Cargo** | Kargo / Kontainer | Blue | `#2563EB` | Standard Triangle |
| **Tanker** | Kapal Tangki Minyak/Gas | Purple | `#9333EA` | Standard Triangle |
| **Passenger** | Penumpang / Ferry | Cyan | `#06B6D4` | Standard Triangle |
| **Support** | Tug / Pasokan / Carrier | Slate | `#64748B` | Standard Triangle |
| **Patrol / Law Enforcement** | Patroli / Penegak Hukum | Red | `#DC2626` | Standard Triangle |
| **Military** | Militer / Pertahanan | Red | `#DC2626` | Standard Triangle |
| **Unknown** | Tidak Dikenal / Lainnya | Gray | `#94A3B8` | Standard Triangle |

*Semua kategori di luar taksonomi di atas secara otomatis menggunakan fallback Gray `#94A3B8`.*

---

## 6. MEKANISME COURSE OVER GROUND (COG) & NORMALISASI
- **Arah Sudut:**
  - `0°` = Utara (North)
  - `90°` = Timur (East)
  - `180°` = Selatan (South)
  - `270°` = Barat (West)
- **Orientasi Awal Marker:** Segitiga kanvas digambar menghadap tepat ke atas (Utara / 0°), sehingga rotasi MapLibre `'icon-rotate'` selaras 1:1 tanpa offset sudut tambahan.
- **Penanganan Nilai COG Null / Tidak Valid:**
  - Jika `course_degrees` (atau `heading_degrees`) tidak tersedia, kapal disaring oleh ekspresi `filter: ['!=', ['get', 'has_orientation'], true]` dan ditampilkan secara konsisten melalui `vessels-circle-layer` (beacon lingkaran netral dengan warna kategori terkait), menghindari kesalahan `NaN` atau rotasi acak.
- **Eliminasi Double Rotation:** Rotasi sepenuhnya didelegasikan kepada GPU MapLibre via property `orientation_degrees` GeoJSON dan `'icon-rotate'`. Tidak ada manipulasi CSS transform pada elemen DOM.

---

## 7. CLASSIFICATION-AWARE VISUALIZATION
- **Verified Fishing:** Ditampilkan dengan Special Fishing Triangle Amber `#F59E0B` dengan ring sorot amber tebal saat dipilih.
- **Vessel Under Review / Disputed:** Berdasarkan data model backend, kapal dalam peninjauan tidak akan mendapatkan status verifikasi palsu.
- **Verified Non-Fishing:** Menggunakan segitiga standar sesuai kategori kapal masing-masing (Cargo biru, Tanker ungu, Patrol merah, dll.).
- **Unknown Vessel:** Segitiga abu-abu netral `#94A3B8`.

---

## 8. HASIL TESTING & VERIFIKASI
1. **Pemeriksaan Simbologi Kapal Nelayan:** Berhasil diverifikasi menggunakan canvas 2D berkontras tinggi dan detail cadik.
2. **Pemeriksaan Segitiga Standar (Cargo, Tanker, Passenger, Support, Patrol, Unknown):** Berhasil diverifikasi dengan warna heksadesimal yang presisi.
3. **Pemeriksaan Rotasi COG (0°, 90°, 180°, 270°):** Berjalan mulus mengikuti `'icon-rotation-alignment': 'map'`.
4. **Pemeriksaan Nilai COG Null:** Ditampilkan dengan fallback beacon lingkaran warna kategori tanpa error console atau marker hilang.
5. **Realtime Updates & Popup:** Fitur popover, pencarian, dan tooltip hovering tetap berjalan 100% normal.
6. **Kompilasi Frontend Vite (`npm run build`):**
   - `vite v7.3.7 building client environment for production...`
   - `✓ 63 modules transformed.`
   - `✓ built in 10.00s` (Nol error).
7. **Automated Backend & Map Tests (`php artisan test`):**
   - `Tests\Feature\MapLayerApiTest`: **13 passed (155 assertions)**
   - `Tests\Feature\AdvancedMapFiltersTest`: **4 passed (69 assertions)**
   - Total: **17 passed (224 assertions)** — 100% Green.

---

## 9. KESIMPULAN
Peningkatan visualisasi peta telah selesai dilaksanakan secara sempurna dengan mematuhi batasan *strict scope*. Seluruh kapal kini menggunakan directional triangle marker dengan orientasi COG yang akurat, dan armada kapal penangkap ikan (*fishing*) memiliki identitas visual amber khusus yang sangat jelas dan informatif.

**STATUS AKHIR:**  
`PASS — DISTINCT FISHING TRIANGLE ICON AND AIS COG DIRECTION VERIFIED`

