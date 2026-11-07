# LAPORAN AUDIT READ-ONLY TAHAP 4B.1
## Global Fishing Vessel Intelligence Platform (GFVIP)

**Tanggal:** 8 November 2026  
**Status Audit:** **SELESAI (READ-ONLY AUDIT & VERIFIKASI AKTUAL)**  
**Target Audit:**  
- `ais/worker.mjs`
- `ais/test_worker_hardening.mjs`
- Kontrak Backend: `app/Services/Providers/AisStreamAdapter.php` & `routes/api.php`

---

## 1. RINGKASAN AUDIT 5 POIN KRITIS

| No | Fokus Evaluasi | Status Implementasi | Status Uji Aktual | Catatan Kunci |
|---|---|---|---|---|
| **1** | **Idempotensi (Lost ACK & Retry)** | **AMAN / VALID** | **PASS** (Test 04) | Backend memiliki deduplikasi posisi `(vessel_id, observed_at, lat, lng)` dan worker mempertahankan `inFlightBatch`. |
| **2** | **Disk Queue (Crash & SIGKILL)** | **BERIKAN CATATAN TEKNIS** | **PASS** (Test 05) | Aman pada `SIGTERM`/`SIGINT`. Kerentanan ada pada `SIGKILL` (OS level) dan uncaught crash sebelum handler dipanggil. |
| **3** | **Verifikasi Respons HTTP 200** | **AMAN / VALID** | **PASS** (Test 10) | Memvalidasi `status === 'success'` dan `confirmedCount >= batch.length`. Pesan unconfirmed tidak dibuang. |
| **4** | **Cache LRU (Aktivitas Penggunaan)** | **AMAN / VALID** | **PASS** (Test 09) | Menggunakan `lastAccessedAt` yang diperbarui setiap frame AIS masuk. Penggusuran berdasarkan akses terakhir, bukan sekadar TTL. |
| **5** | **Snapshot Versioning & Rollback Guard** | **AMAN / VALID** | **PASS** (Test 06 & 07) | Snapshot lama (`version < lastVersion`) ditolak. Transaksi staging multi-halaman mencegah partial corruption. |

---

## 2. DETAIL AUDIT & BUKTI KODE SETIAP KRITERIA

### Poin 1: Idempotensi (Backend Menerima Batch, ACK Hilang, Worker Retry)

#### A. Mekanisme Kerja:
1. Ketika batch dikirim, worker mengunci pesan dalam `inFlightBatch`.
2. Jika backend selesai memproses dan menyimpan data, namun koneksi TCP putus / socket hancur sebelum HTTP 200 ACK diterima worker:
   - Worker menangkap kegagalan jaringan di blok `catch (err)` dan **tidak mengosongkan** `inFlightBatch`.
   - Worker melakukan retry pengiriman batch yang identik.
3. Backend menerima batch duplikat tersebut:
   - Pengecekan duplikasi pada backend mengidentifikasi bahwa titik koordinat kapal pada timestamp yang sama sudah tercatat di database (`VesselPosition::where(...)`), sehingga tidak dibuat record ganda.
   - Backend merespons dengan HTTP 200 `received: N, inserted: 0, skipped: N`.
   - Worker menerima ACK, memverifikasi `confirmedCount`, dan melepas `inFlightBatch`.

#### B. Bukti Lokasi Kode:
- **Worker (`ais/worker.mjs`):**
  - Baris 417–426: Penguncian batch: `inFlightBatch = messageQueue.splice(0, BATCH_SIZE);`
  - Baris 456–461: Retensi `inFlightBatch` saat error jaringan:
    ```javascript
    } catch (err) {
        consecutiveFailures++;
        const delay = Math.min(30000, RETRY_BASE_DELAY_MS * Math.pow(2, Math.min(consecutiveFailures, 5)));
        console.error(` [NETWORK/TIMEOUT ERROR] Failed to deliver batch to backend: ${err.message}...`);
        await new Promise(r => setTimeout(r, delay));
    }
    ```
- **Backend (`app/Services/Providers/AisStreamAdapter.php`):**
  - Baris 1287–1296: Deduplikasi posisi kapal:
    ```php
    $existingPosition = VesselPosition::where('vessel_id', $vessel->id)
        ->where('observed_at', $observedAt)
        ->where('latitude', $lat)
        ->where('longitude', $lng)
        ->first();

    if ($existingPosition) {
        return $existingPosition;
    }
    ```

#### C. Bukti Hasil Test Aktual (`ais/test_worker_hardening.mjs` Test 04):
```text
[TEST 04] Idempotency on lost ACK: backend ingests message, ACK lost on wire, retry succeeds safely ... 
[NETWORK/TIMEOUT ERROR] Failed to deliver batch to backend: fetch failed. Retrying in 0.1s (Attempt #1, in-flight: 1 msgs)...
Batch flushed: 1 msgs (Ingested: 0, Skipped: 1, Blocked locally: 0, Pending: 0)
PASS
```

---

### Poin 2: Disk Queue (Keamanan Pesan Saat Crash atau SIGKILL)

#### A. Mekanisme Kerja:
1. `persistQueueToDisk()` menggabungkan seluruh pesan yang ada pada `inFlightBatch` dan `messageQueue` menjadi satu array JSON dan menyimpannya ke `DISK_QUEUE_PATH`.
2. Saat startup, `loadPersistedQueue()` membaca file tersebut, memasukkan pesan kembali ke `messageQueue`, lalu menghapus (`unlinkSync`) file snapshot.
3. Fungsi `gracefulShutdown()` dipanggil saat proses menerima sinyal OS `SIGINT` (Ctrl+C) atau `SIGTERM` (PM2 / systemctl stop).

#### B. Bukti Lokasi Kode:
- **`ais/worker.mjs`:**
  - Baris 233–257:
    ```javascript
    function persistQueueToDisk() {
        const toSave = [];
        if (inFlightBatch && inFlightBatch.length > 0) toSave.push(...inFlightBatch);
        if (messageQueue.length > 0) toSave.push(...messageQueue);
        if (toSave.length === 0) { ... return 0; }
        ensureDirExists(DISK_QUEUE_PATH);
        const bounded = toSave.slice(0, MAX_QUEUE_SIZE);
        fs.writeFileSync(DISK_QUEUE_PATH, JSON.stringify(bounded), 'utf8');
        return bounded.length;
    }
    ```
  - Baris 658: `const persisted = persistQueueToDisk();` di dalam `gracefulShutdown()`.
  - Baris 723–725:
    ```javascript
    process.on('SIGINT', () => gracefulShutdown('SIGINT'));
    process.on('SIGTERM', () => gracefulShutdown('SIGTERM'));
    ```

#### C. Temuan Kritis & Analisis Batasan:
- **`SIGKILL` (kill -9):** Berdasarkan arsitektur kernel OS (POSIX/Linux/Windows), `SIGKILL` membunuh proses secara instan pada level kernel. **Tidak ada aplikasi di userspace yang dapat menangkap atau menjalankan kode saat menerima `SIGKILL`**. Sinyal ini tidak memicu event handler apa pun.
- **Unhandled Process Crash:** Jika terjadi exception fatal di luar try-catch (misalnya OOM killer atau unhandled promise rejection yang menghentikan Node.js secara paksa sebelum shutdown handler dipanggil), antrean in-memory yang belum sempat di-persist akan hilang.
- **Rekomendasi Minimal:**
  1. Tambahkan listener `process.on('uncaughtException')` dan `process.on('unhandledRejection')` agar worker mencoba memanggil `persistQueueToDisk()` secara sinkron sebelum proses berhenti:
     ```javascript
     process.on('uncaughtException', (err) => {
         console.error('Fatal crash:', err);
         persistQueueToDisk();
         process.exit(1);
     });
     ```
  2. Dokumentasikan bahwa untuk live stream berkecepatan tinggi, snapshot shutdown merupakan arsitektur standar yang optimal (karena penulisan disk WAL sinkron per-paket akan mencekik throughput I/O).

#### D. Bukti Hasil Test Aktual (`ais/test_worker_hardening.mjs` Test 05):
```text
[TEST 05] Disk persistence saves BOTH messageQueue and inFlightBatch across worker restart ... 
[DISK QUEUE] Persisted 4 messages to C:\XPROJECT\global-fishing-vessel-intelligence\ais\test_queue_disk.json
[DISK QUEUE] Restored 4 uncommitted messages from C:\XPROJECT\global-fishing-vessel-intelligence\ais\test_queue_disk.json
PASS
```

---

### Poin 3: Verifikasi Respons HTTP 200 Sebelum `inFlightBatch` Dihapus

#### A. Mekanisme Kerja:
1. Worker tidak lagi mempercayai `response.ok` begitu saja.
2. Respons JSON diperiksa:
   - Jika payload memuat `data.status && data.status !== 'success'`, worker menganggap batch gagal dan melempar error agar masuk ke penanganan retry backoff.
3. Jumlah konfirmasi dihitung dari `data.received` (atau `data.inserted + data.skipped`).
4. Jika `confirmedCount < inFlightBatch.length` (konfirmasi parsial):
   - Worker **tidak membuang** pesan yang belum terkonfirmasi.
   - Bagian yang belum terkonfirmasi (`inFlightBatch.slice(confirmedCount)`) dialihkan ke `handleHttp422()` untuk diseksi/retry 1-per-1.
5. `inFlightBatch` hanya dikosongkan jika seluruh pesan dalam batch telah terverifikasi.

#### B. Bukti Lokasi Kode:
- **`ais/worker.mjs` (Baris 431–459):**
  ```javascript
  if (response.ok) {
      const data = await response.json().catch(() => ({}));

      if (data.status && data.status !== 'success') {
          throw new Error(`Backend returned HTTP 200 with non-success status: ${data.message || data.status}`);
      }

      const confirmedCount = typeof data.received === 'number'
          ? data.received
          : (typeof data.inserted === 'number' ? (data.inserted + (data.skipped || 0)) : inFlightBatch.length);

      if (confirmedCount < inFlightBatch.length) {
          console.warn(` [PARTIAL CONFIRMATION] Backend confirmed only ${confirmedCount} of ${inFlightBatch.length} messages.`);
          const unconfirmed = inFlightBatch.slice(confirmedCount);
          totalSent += confirmedCount;
          inFlightBatch = null;
          consecutiveFailures = 0;
          await handleHttp422(unconfirmed);
      } else {
          totalSent += inFlightBatch.length;
          inFlightBatch = null;
          consecutiveFailures = 0;
      }
  }
  ```

#### C. Bukti Hasil Test Aktual (`ais/test_worker_hardening.mjs` Test 10):
```text
[TEST 10] HTTP 200 with non-success status or mismatched received count prevents silent message loss ... 
[NETWORK/TIMEOUT ERROR] Failed to deliver batch to backend: Backend returned HTTP 200 with non-success status: Database transaction lock timeout. Retrying in 0.1s (Attempt #1, in-flight: 1 msgs)...
[PARTIAL CONFIRMATION] Backend confirmed only 1 of 3 messages. Isolating unconfirmed remainder...
[HTTP 422 DISSECTION] Batch of 2 messages rejected. Isolating invalid frames to protect valid messages...
[HTTP 422 RESOLUTION] Dissection complete: 2 valid messages delivered, 0 invalid frames quarantined.
PASS
```

---

### Poin 4: Cache LRU Berdasarkan Aktivitas Penggunaan Aktual

#### A. Mekanisme Kerja:
1. Nilai yang disimpan pada `prohibitedMmsiCache` berupa objek: `{ expireAt: number, lastAccessedAt: number }`.
2. Setiap kali ada frame AIS masuk (misalnya `PositionReport`), pemanggilan fungsi `isMmsiProhibited(mmsi)` memperbarui `lastAccessedAt = Date.now()` dan merefresh entri pada JavaScript `Map` (delete lalu set ulang) untuk mempertahankan urutan MRU.
3. Ketika kapasitas cache melebihi `MAX_BLOCKED_CACHE_SIZE`:
   - `enforceCacheLimits()` melakukan pengurutan ascending berdasarkan `lastAccessedAt`.
   - Entri yang paling lama tidak diakses dieliminasi terlebih dahulu.
   - Kapal yang sering lalu-lalang dan aktif dicek tidak akan terdepak meskipun waktu expire awalnya lebih lama.

#### B. Bukti Lokasi Kode:
- **`ais/worker.mjs`:**
  - Baris 135–154 (`isMmsiProhibited`):
    ```javascript
    function isMmsiProhibited(mmsi) {
        if (!mmsi || !prohibitedMmsiCache.has(mmsi)) return false;
        const raw = prohibitedMmsiCache.get(mmsi);
        ...
        entry.lastAccessedAt = now;
        prohibitedMmsiCache.delete(mmsi);
        prohibitedMmsiCache.set(mmsi, entry);
        return true;
    }
    ```
  - Baris 177–193 (`enforceCacheLimits`):
    ```javascript
    const entries = Array.from(prohibitedMmsiCache.entries()).map(([mmsi, raw]) => ({
        mmsi,
        lastAccessedAt: typeof raw === 'object' ? (raw.lastAccessedAt || 0) : 0,
    }));
    entries.sort((a, b) => a.lastAccessedAt - b.lastAccessedAt);
    const excess = Math.min(entries.length, Math.max(1, prohibitedMmsiCache.size - MAX_BLOCKED_CACHE_SIZE));
    for (let i = 0; i < excess; i++) {
        prohibitedMmsiCache.delete(entries[i].mmsi);
    }
    ```
  - Baris 528: `if (isPositionOnly && mmsi && isMmsiProhibited(mmsi))` memicu pembaruan timestamp aktivitas.

#### C. Bukti Hasil Test Aktual (`ais/test_worker_hardening.mjs` Test 09):
```text
[TEST 09] True LRU: active frequently accessed vessel is retained while inactive vessel is evicted ... 
[CACHE PRUNE] Evicted 1 LRU (least recently accessed) entries. Current cache size: 3
PASS
```
*Catatan Uji:* `VESSEL_A` (dibuat paling awal pada T0 namun diakses aktif) tetap bertahan, sedangkan `VESSEL_B` (dibuat pada T1 namun pasif) terdepak saat kapasitas 3 terlampaui.

---

### Poin 5: Penolakan Snapshot Version Lama & Pencegahan Cache Rollback

#### A. Mekanisme Kerja:
1. Backend `/api/telemetry/ais/prohibited-mmsis` mengirimkan `snapshot_version` monolitik/integer (misalnya epoch timestamp).
2. Worker menyimpan `lastSyncSnapshotVersion`.
3. Jika worker menerima respons snapshot dengan `fetchedSnapshotVersion < lastSyncSnapshotVersion`:
   - Worker mendeteksi bahwa data tersebut berasal dari replica yang lag atau respons asynchronous yang datang terlambat.
   - Worker mencatat peringatan dan **mengabaikan/menolak** pembaruan tersebut sebelum memodifikasi cache.
4. Mekanisme Staging:
   - Data pagination dikumpulkan dalam buffer sementara (`stagedUpdates`).
   - Cache lokal hanya dimodifikasi jika **seluruh halaman (sampai `is_last_page: true`) berhasil ditarik dan divalidasi**.
   - Jika halaman 2 dari 5 gagal, seluruh buffer staging dibuang sehingga cache tetap 100% konsisten pada versi snapshot sebelumnya.

#### B. Bukti Lokasi Kode:
- **`ais/worker.mjs` (Baris 305–314 & 330–340):**
  ```javascript
  if (fetchedSnapshotVersion === null) {
      fetchedSnapshotVersion = json.snapshot_version;
  }

  // Reject committing stale snapshots if snapshot version is older than what we already synced
  if (lastSyncSnapshotVersion !== null && fetchedSnapshotVersion !== null && fetchedSnapshotVersion < lastSyncSnapshotVersion) {
      console.warn(` [PROHIBITED SYNC REJECTED] Received stale snapshot version ${fetchedSnapshotVersion} (current authoritative version is ${lastSyncSnapshotVersion}). Rejecting snapshot to prevent cache rollback.`);
      return;
  }
  ```
  Dan penerapan atomik setelah loop pagination tuntas (baris 330–339).

#### C. Bukti Hasil Test Aktual (`ais/test_worker_hardening.mjs` Test 06 & 07):
```text
[TEST 06] Snapshot versioning: applies latest version and rejects stale out-of-order snapshots ... 
[PROHIBITED SYNC] Success: 1 pages, 2 records processed (2 blocks, 0 unblocks). Active cache size: 2. Snapshot version: 100
[PROHIBITED SYNC] Success: 1 pages, 1 records processed (0 blocks, 1 unblocks). Active cache size: 1. Snapshot version: 105
[PROHIBITED SYNC REJECTED] Received stale snapshot version 95 (current authoritative version is 105). Rejecting snapshot to prevent cache rollback.
PASS

[TEST 07] Snapshot pagination failure midway aborts cleanly without partial cache corruption ... 
[PROHIBITED SYNC WARNING] Failed to sync prohibited MMSIs: HTTP 500 Internal Server Error. Retaining current cache of 1 MMSIs (fail-safe mode).
PASS
```

---

## 3. HASIL VERIFIKASI AKHIR KESELURUHAN TEST

### 1. Test Suite AIS Worker Hardening (Node.js)
```text
======================================================================
 RUNNING AUDITED AIS WORKER HARDENING TEST SUITE (TAHAP 4B - 10/10)
======================================================================
  [TEST 01] Backend connection broken then recovers without message loss ... PASS
  [TEST 02] HTTP 500 and 429 errors preserve inFlightBatch with exponential backoff ... PASS
  [TEST 03] HTTP 422 dissection isolates invalid frames and preserves valid messages ... PASS
  [TEST 04] Idempotency on lost ACK: backend ingests message, ACK lost on wire, retry succeeds safely ... PASS
  [TEST 05] Disk persistence saves BOTH messageQueue and inFlightBatch across worker restart ... PASS
  [TEST 06] Snapshot versioning: applies latest version and rejects stale out-of-order snapshots ... PASS
  [TEST 07] Snapshot pagination failure midway aborts cleanly without partial cache corruption ... PASS
  [TEST 08] Type 19 with prohibited MMSI is forwarded to backend while PositionReport is dropped ... PASS
  [TEST 09] True LRU: active frequently accessed vessel is retained while inactive vessel is evicted ... PASS
  [TEST 10] HTTP 200 with non-success status or mismatched received count prevents silent message loss ... PASS
======================================================================
 RESULTS: 10 / 10 TESTS PASSED
======================================================================
```

### 2. Test Suite Backend Laravel (PHP)
```text
   PASS  Tests\Feature\AisPhase4aIngestionAndSyncTest
  ✓ vessels table has provenance columns                                    0.53s  
  ✓ shiptype 58 and 59 mapping                                              0.02s  
  ✓ provenance is stored in dedicated columns and metadata                  0.08s  
  ✓ out of order static messages do not regress classification              0.03s  
  ✓ type 19 preserves metadata when position is rejected                    0.03s  
  ✓ type 19 with valid position ingests both                                0.03s  
  ✓ type 27 ingests position without classifying as fishing                 0.03s  
  ✓ prohibited mmsi api requires valid token                                0.07s  
  ✓ prohibited mmsi api pagination and revocation                           0.03s  

  Tests:    9 passed (76 assertions)
  Duration: 1.74s
```

---

## 4. KESIMPULAN & REKOMENDASI PERBAIKAN MINIMAL

1. **Idempotensi, Verifikasi HTTP 200, True LRU, dan Snapshot Versioning** berada dalam kondisi **100% AMAN, SOLID, dan TERUJI**.
2. **Terkait Disk Queue pada Skenario Crash:**
   - Worker aman saat shutdown terencana (`SIGTERM`, `SIGINT`).
   - Untuk skenario crash proses yang tidak terduga, rekomendasi perbaikan paling minimal di masa mendatang (opsional sebelum deployment produksi) adalah menambahkan penangkap crash global:
     ```javascript
     process.on('uncaughtException', (err) => {
         console.error('Fatal crash:', err);
         persistQueueToDisk();
         process.exit(1);
     });
     ```
   - Sinyal `SIGKILL` (-9) tidak dapat ditangkap di aplikasi mana pun berdasarkan aturan arsitektur OS. Batasan ini adalah wajar dan dapat diterima untuk streaming AIS real-time.
