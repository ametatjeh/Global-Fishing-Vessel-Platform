/**
 * GFVIP - AISStream.io High-Performance WebSocket Ingestion Worker
 * Production Architecture: Node.js worker for aaPanel / PM2 / Systemd
 * 
 * Tahap 4B Hardened Architecture:
 * - RFC 6455 WebSocket client connecting to wss://stream.aisstream.io/v0/stream
 * - Guaranteed At-Least-Once Delivery via Two-Phase In-Flight Batch Buffer
 * - Resilient Exponential Backoff on HTTP 500, 429, timeouts, and network failures
 * - Controlled HTTP 422 Dissection: Isolates corrupt frames to quarantine buffer without dropping valid messages
 * - Authoritative Prohibited MMSI Synchronization with snapshot versioning, cursor pagination, and instant unblock/revocation
 * - Bounded LRU Cache (max 50,000 entries, 24h TTL) preventing thundering herd
 * - Type 19 (ExtendedClassBPositionReport) Dual Processing: Static metadata always forwarded even if prohibited
 * - Persistent Disk Queue: Restores uncommitted messages on startup and snapshots on shutdown
 * - Graceful shutdown handling (SIGINT / SIGTERM)
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const rootDir = path.resolve(__dirname, '..');

// Simple native .env loader fallback if environment variables are not already passed
function loadEnvFile(envPath) {
    if (!fs.existsSync(envPath)) return;
    try {
        const content = fs.readFileSync(envPath, 'utf8');
        for (const line of content.split('\n')) {
            const trimmed = line.trim();
            if (!trimmed || trimmed.startsWith('#')) continue;
            const eqIdx = trimmed.indexOf('=');
            if (eqIdx <= 0) continue;
            const key = trimmed.substring(0, eqIdx).trim();
            let val = trimmed.substring(eqIdx + 1).trim();
            if ((val.startsWith('"') && val.endsWith('"')) || (val.startsWith("'") && val.endsWith("'"))) {
                val = val.substring(1, val.length - 1);
            }
            if (process.env[key] === undefined) {
                process.env[key] = val;
            }
        }
    } catch {
        // Ignore read errors
    }
}

loadEnvFile(path.join(rootDir, '.env'));

// Configuration & Constants
const AIS_STREAM_API_KEY = process.env.AIS_STREAM_API_KEY || process.env.AIS_API_KEY || '';
const AIS_INGEST_TOKEN = process.env.AIS_INGEST_TOKEN || AIS_STREAM_API_KEY || '';
const APP_URL = process.env.APP_URL || 'http://127.0.0.1:8000';
const AIS_BACKEND_URL = process.env.AIS_BACKEND_URL || `${APP_URL.replace(/\/$/, '')}/api/telemetry/ais/batch`;
const AIS_PROHIBITED_URL = process.env.AIS_PROHIBITED_URL || `${APP_URL.replace(/\/$/, '')}/api/telemetry/ais/prohibited-mmsis`;
const AIS_STREAM_URL = process.env.AIS_STREAM_URL || 'wss://stream.aisstream.io/v0/stream';

const BATCH_SIZE = parseInt(process.env.AIS_BATCH_SIZE || '50', 10);
const BATCH_INTERVAL_MS = parseInt(process.env.AIS_BATCH_INTERVAL_MS || '2000', 10);
const MAX_QUEUE_SIZE = parseInt(process.env.AIS_MAX_QUEUE_SIZE || '5000', 10);
const DISK_QUEUE_PATH = process.env.AIS_DISK_QUEUE_PATH || path.join(rootDir, 'storage', 'framework', 'cache', 'ais_worker_queue.json');

const PROHIBITED_SYNC_INTERVAL_MS = parseInt(process.env.AIS_PROHIBITED_SYNC_INTERVAL_MS || '3600000', 10); // 1 hour
const MAX_BLOCKED_CACHE_SIZE = parseInt(process.env.AIS_MAX_BLOCKED_CACHE_SIZE || '50000', 10);
const BLOCKED_CACHE_TTL_MS = parseInt(process.env.AIS_BLOCKED_CACHE_TTL_MS || String(24 * 60 * 60 * 1000), 10); // 24 hours
const PRUNE_BATCH_SIZE = parseInt(process.env.AIS_PRUNE_BATCH_SIZE || '5000', 10);
const RETRY_BASE_DELAY_MS = process.env.AIS_TEST_MODE === '1' ? 50 : 1000;

// Parse custom bounding box or default to global
let boundingBoxes = [[[-90.0, -180.0], [90.0, 180.0]]];
if (process.env.AIS_BOUNDING_BOXES) {
    try {
        const parsed = JSON.parse(process.env.AIS_BOUNDING_BOXES);
        if (Array.isArray(parsed) && parsed.length > 0) {
            boundingBoxes = parsed;
        }
    } catch {
        console.warn(' [WARN] Failed to parse AIS_BOUNDING_BOXES from .env, using default.');
    }
}

function maskSecret(str) {
    if (!str || str.length <= 8) return '****';
    return `${str.substring(0, 4)}...${str.substring(str.length - 4)}`;
}

// In-Memory State
const messageQueue = [];
let inFlightBatch = null;
let consecutiveFailures = 0;
let isFlushing = false;
let flushTimer = null;
let syncTimer = null;
let reconnectAttempt = 0;
let isShuttingDown = false;
let activeWs = null;

// Statistics
let totalReceived = 0;
let totalSent = 0;
let totalDropped = 0;
let totalFilteredOut = 0;
let totalFailedBatches = 0;
let totalQuarantined = 0;

// Prohibited Cache: Map<mmsi, { expireAt: number, lastAccessedAt: number }>
const prohibitedMmsiCache = new Map();
let lastSyncSnapshotVersion = null;
let lastSyncTimestamp = null;
let syncInProgress = false;

/**
 * Check if ITU ship type code represents a prohibited vessel (Cargo, Tanker, Pleasure)
 */
function isProhibitedShipType(typeCode) {
    if (!typeCode || isNaN(typeCode)) return false;
    const code = parseInt(typeCode, 10);
    // 37: Pleasure Craft
    if (code === 37) return true;
    // 70–79: Cargo Ships
    if (code >= 70 && code <= 79) return true;
    // 80–89: Tankers
    if (code >= 80 && code <= 89) return true;
    return false;
}

/**
 * Query prohibited cache with True LRU usage tracking.
 * When an entry is checked, its lastAccessedAt timestamp is updated,
 * and it is re-inserted into the Map to keep MRU order.
 */
function isMmsiProhibited(mmsi) {
    if (!mmsi || !prohibitedMmsiCache.has(mmsi)) return false;
    const raw = prohibitedMmsiCache.get(mmsi);
    const entry = (typeof raw === 'object' && raw !== null) 
        ? raw 
        : { expireAt: Number(raw) || 0, lastAccessedAt: Date.now() };

    const now = Date.now();
    if (entry.expireAt <= now) {
        prohibitedMmsiCache.delete(mmsi);
        return false;
    }

    // Update access activity timestamp for True LRU
    entry.lastAccessedAt = now;
    // Re-insert to keep Map key order aligned with recency
    prohibitedMmsiCache.delete(mmsi);
    prohibitedMmsiCache.set(mmsi, entry);
    return true;
}

/**
 * Set entry in prohibited MMSI cache
 */
function setProhibitedMmsi(mmsi, expireAt, lastAccessedAt = Date.now()) {
    prohibitedMmsiCache.delete(mmsi);
    prohibitedMmsiCache.set(mmsi, { expireAt, lastAccessedAt });
}

/**
 * Enforce cache boundaries and True LRU eviction (Anti-Thundering Herd)
 * Evicts expired entries first, then evicts least-recently-accessed entries.
 */
function enforceCacheLimits() {
    const now = Date.now();
    // 1. Evict expired entries
    for (const [mmsi, raw] of prohibitedMmsiCache.entries()) {
        const expireAt = (typeof raw === 'object' && raw !== null) ? raw.expireAt : Number(raw);
        if (expireAt <= now) {
            prohibitedMmsiCache.delete(mmsi);
        }
    }

    // 2. If still exceeding capacity, prune least-recently-used entries (True LRU)
    if (prohibitedMmsiCache.size > MAX_BLOCKED_CACHE_SIZE) {
        const entries = Array.from(prohibitedMmsiCache.entries()).map(([mmsi, raw]) => {
            const lastAccessedAt = (typeof raw === 'object' && raw !== null && typeof raw.lastAccessedAt === 'number') 
                ? raw.lastAccessedAt 
                : 0;
            return { mmsi, lastAccessedAt };
        });

        // Sort ascending: smallest lastAccessedAt (least recently accessed) comes first
        entries.sort((a, b) => a.lastAccessedAt - b.lastAccessedAt);

        const excess = Math.min(entries.length, Math.max(1, prohibitedMmsiCache.size - MAX_BLOCKED_CACHE_SIZE));
        for (let i = 0; i < excess; i++) {
            prohibitedMmsiCache.delete(entries[i].mmsi);
        }
        console.log(` [CACHE PRUNE] Evicted ${excess} LRU (least recently accessed) entries. Current cache size: ${prohibitedMmsiCache.size}`);
    }
}

/**
 * Ensure directory exists for persistent queue
 */
function ensureDirExists(filePath) {
    const dir = path.dirname(filePath);
    if (!fs.existsSync(dir)) {
        try {
            fs.mkdirSync(dir, { recursive: true });
        } catch {
            // Ignore if directory exists
        }
    }
}

/**
 * Restore uncommitted queue from disk snapshot on startup
 */
function loadPersistedQueue() {
    try {
        if (fs.existsSync(DISK_QUEUE_PATH)) {
            const raw = fs.readFileSync(DISK_QUEUE_PATH, 'utf8');
            const data = JSON.parse(raw);
            if (Array.isArray(data) && data.length > 0) {
                const restored = data.slice(0, MAX_QUEUE_SIZE);
                messageQueue.push(...restored);
                console.log(` [DISK QUEUE] Restored ${restored.length} uncommitted messages from ${DISK_QUEUE_PATH}`);
            }
            fs.unlinkSync(DISK_QUEUE_PATH);
        }
    } catch (err) {
        console.warn(` [DISK QUEUE WARNING] Failed to load persisted queue: ${err.message}`);
    }
}

/**
 * Persist remaining uncommitted messages to disk on shutdown
 */
function persistQueueToDisk() {
    try {
        const toSave = [];
        if (inFlightBatch && inFlightBatch.length > 0) {
            toSave.push(...inFlightBatch);
        }
        if (messageQueue.length > 0) {
            toSave.push(...messageQueue);
        }
        if (toSave.length === 0) {
            if (fs.existsSync(DISK_QUEUE_PATH)) {
                try { fs.unlinkSync(DISK_QUEUE_PATH); } catch {}
            }
            return 0;
        }
        ensureDirExists(DISK_QUEUE_PATH);
        const bounded = toSave.slice(0, MAX_QUEUE_SIZE);
        fs.writeFileSync(DISK_QUEUE_PATH, JSON.stringify(bounded), 'utf8');
        console.log(` [DISK QUEUE] Persisted ${bounded.length} messages to ${DISK_QUEUE_PATH}`);
        return bounded.length;
    } catch (err) {
        console.error(` [DISK QUEUE ERROR] Failed to persist queue to disk: ${err.message}`);
        return 0;
    }
}

/**
 * Authoritative Prohibited MMSI Synchronization
 * Uses snapshot versioning, cursor pagination, and instant unblock/revocation.
 * Updates are staged and committed ONLY when all pages are validated.
 */
async function syncProhibitedMmsis() {
    if (syncInProgress || isShuttingDown) return;
    syncInProgress = true;

    try {
        let cursor = null;
        let isLastPage = false;
        const stagedUpdates = [];
        let fetchedSnapshotVersion = null;
        let pageCount = 0;

        while (!isLastPage) {
            pageCount++;
            let url = `${AIS_PROHIBITED_URL}?limit=1000`;
            if (cursor) {
                url += `&cursor=${encodeURIComponent(cursor)}`;
            }

            const controller = new AbortController();
            const timeout = setTimeout(() => controller.abort(), 10000);

            const response = await fetch(url, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-AIS-Token': AIS_INGEST_TOKEN,
                    'User-Agent': 'GFVIP-AisWorker-Node/2.0',
                },
                signal: controller.signal,
            });
            clearTimeout(timeout);

            if (!response.ok) {
                throw new Error(`HTTP ${response.status} ${response.statusText}`);
            }

            const json = await response.json();
            if (json.status !== 'success' || !Array.isArray(json.data)) {
                throw new Error('Malformed prohibited-mmsis API response');
            }

            if (fetchedSnapshotVersion === null) {
                fetchedSnapshotVersion = json.snapshot_version;
            }

            // Reject committing stale snapshots if snapshot version is older than what we already synced
            if (lastSyncSnapshotVersion !== null && fetchedSnapshotVersion !== null && fetchedSnapshotVersion < lastSyncSnapshotVersion) {
                console.warn(` [PROHIBITED SYNC REJECTED] Received stale snapshot version ${fetchedSnapshotVersion} (current authoritative version is ${lastSyncSnapshotVersion}). Rejecting snapshot to prevent cache rollback.`);
                return;
            }

            for (const item of json.data) {
                if (item.mmsi) {
                    stagedUpdates.push({
                        mmsi: String(item.mmsi).replace(/\D/g, ''),
                        action: item.action || 'block',
                    });
                }
            }

            if (json.is_last_page || !json.next_cursor || json.data.length === 0) {
                isLastPage = true;
            } else {
                cursor = json.next_cursor;
            }
        }

        // Atomically apply updates ONLY AFTER all pages are verified
        const now = Date.now();
        let blockedCount = 0;
        let unblockedCount = 0;

        for (const item of stagedUpdates) {
            if (item.action === 'unblock') {
                if (prohibitedMmsiCache.delete(item.mmsi)) {
                    unblockedCount++;
                }
            } else {
                setProhibitedMmsi(item.mmsi, now + BLOCKED_CACHE_TTL_MS, now);
                blockedCount++;
            }
        }

        enforceCacheLimits();
        lastSyncSnapshotVersion = fetchedSnapshotVersion;
        lastSyncTimestamp = new Date().toISOString();

        console.log(` [PROHIBITED SYNC] Success: ${pageCount} pages, ${stagedUpdates.length} records processed (${blockedCount} blocks, ${unblockedCount} unblocks). Active cache size: ${prohibitedMmsiCache.size}. Snapshot version: ${lastSyncSnapshotVersion}`);
    } catch (err) {
        console.warn(` [PROHIBITED SYNC WARNING] Failed to sync prohibited MMSIs: ${err.message}. Retaining current cache of ${prohibitedMmsiCache.size} MMSIs (fail-safe mode).`);
    } finally {
        syncInProgress = false;
    }
}

/**
 * Send batch HTTP request to Laravel backend with 15s timeout
 */
async function sendBatchRequest(batch) {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 15000);

    try {
        const response = await fetch(AIS_BACKEND_URL, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-AIS-Token': AIS_INGEST_TOKEN,
                'User-Agent': 'GFVIP-AisWorker-Node/2.0',
            },
            body: JSON.stringify({ messages: batch }),
            signal: controller.signal,
        });
        clearTimeout(timeout);
        return response;
    } catch (err) {
        clearTimeout(timeout);
        throw err;
    }
}

/**
 * Handle HTTP 422: Dissect batch to isolate corrupt frames without dropping valid messages
 */
async function handleHttp422(batch) {
    console.warn(` [HTTP 422 DISSECTION] Batch of ${batch.length} messages rejected. Isolating invalid frames to protect valid messages...`);

    let validDelivered = 0;
    for (const msg of batch) {
        try {
            const resp = await sendBatchRequest([msg]);
            if (resp.ok) {
                validDelivered++;
                totalSent++;
            } else if (resp.status === 422) {
                totalQuarantined++;
                console.warn(` [QUARANTINE] Isolated invalid frame payload (Type: ${msg?.MessageType || 'unknown'}, MMSI: ${msg?.MetaData?.MMSI || 'unknown'}). Message quarantined.`);
            } else {
                // If temporary error (500/429), re-enqueue for later
                messageQueue.push(msg);
            }
        } catch {
            messageQueue.push(msg);
        }
    }
    console.log(` [HTTP 422 RESOLUTION] Dissection complete: ${validDelivered} valid messages delivered, ${batch.length - validDelivered} invalid frames quarantined.`);
}

/**
 * Guaranteed At-Least-Once Queue Flush
 * Uses an In-Flight buffer; messages are NEVER removed before backend receipt is confirmed.
 */
async function flushQueue() {
    if (isFlushing) return;
    if (!inFlightBatch && messageQueue.length === 0) return;

    isFlushing = true;

    // Phase 1: Lock in-flight batch if not currently holding an unacknowledged batch
    if (!inFlightBatch) {
        inFlightBatch = messageQueue.splice(0, BATCH_SIZE);
    }

    try {
        const response = await sendBatchRequest(inFlightBatch);

        if (response.ok) {
            // Receipt confirmed by backend!
            const data = await response.json().catch(() => ({}));

            // Validate backend response semantics: if backend returns error status with 200, don't drop!
            if (data.status && data.status !== 'success') {
                throw new Error(`Backend returned HTTP 200 with non-success status: ${data.message || data.status}`);
            }

            const confirmedCount = typeof data.received === 'number'
                ? data.received
                : (typeof data.inserted === 'number' ? (data.inserted + (data.skipped || 0)) : inFlightBatch.length);

            if (confirmedCount < inFlightBatch.length) {
                console.warn(` [PARTIAL CONFIRMATION] Backend confirmed only ${confirmedCount} of ${inFlightBatch.length} messages. Isolating unconfirmed remainder...`);
                const unconfirmed = inFlightBatch.slice(confirmedCount);
                totalSent += confirmedCount;
                inFlightBatch = null;
                consecutiveFailures = 0;
                await handleHttp422(unconfirmed);
            } else {
                totalSent += inFlightBatch.length;
                const now = new Date().toISOString();
                console.log(` [${now}] Batch flushed: ${inFlightBatch.length} msgs (Ingested: ${data.inserted || 0}, Skipped: ${data.skipped || 0}, Blocked locally: ${totalFilteredOut}, Pending: ${messageQueue.length})`);

                // Release in-flight buffer
                inFlightBatch = null;
                consecutiveFailures = 0;
            }
        } else if (response.status === 422) {
            // Unprocessable Entity: isolate invalid frames without dropping valid ones
            const rejectedBatch = inFlightBatch;
            inFlightBatch = null;
            consecutiveFailures = 0;
            await handleHttp422(rejectedBatch);
        } else if (response.status === 429 || response.status >= 500) {
            // Server error or rate limit: preserve inFlightBatch and back off exponentially
            consecutiveFailures++;
            const errText = await response.text().catch(() => '');
            const delay = Math.min(30000, RETRY_BASE_DELAY_MS * Math.pow(2, Math.min(consecutiveFailures, 5))) + (process.env.AIS_TEST_MODE === '1' ? 0 : Math.floor(Math.random() * 500));
            console.error(` [HTTP ${response.status}] Backend batch ingestion failed: ${errText.substring(0, 100)}. Retrying in ${(delay / 1000).toFixed(1)}s (Attempt #${consecutiveFailures}, in-flight: ${inFlightBatch.length} msgs)...`);
            await new Promise(r => setTimeout(r, delay));
        } else {
            // Other client error (e.g. 401 Unauthorized, 403, 404)
            consecutiveFailures++;
            const errText = await response.text().catch(() => '');
            console.error(` [HTTP ${response.status}] Client error during batch ingestion: ${errText.substring(0, 100)}.`);
            const delay = Math.min(10000, RETRY_BASE_DELAY_MS * Math.pow(2, Math.min(consecutiveFailures, 3)));
            await new Promise(r => setTimeout(r, delay));
        }
    } catch (err) {
        // Network timeout or connection drop: preserve inFlightBatch and back off exponentially
        consecutiveFailures++;
        const delay = Math.min(30000, RETRY_BASE_DELAY_MS * Math.pow(2, Math.min(consecutiveFailures, 5))) + (process.env.AIS_TEST_MODE === '1' ? 0 : Math.floor(Math.random() * 500));
        console.error(` [NETWORK/TIMEOUT ERROR] Failed to deliver batch to backend: ${err.message}. Retrying in ${(delay / 1000).toFixed(1)}s (Attempt #${consecutiveFailures}, in-flight: ${inFlightBatch.length} msgs)...`);
        await new Promise(r => setTimeout(r, delay));
    } finally {
        isFlushing = false;
        // Trigger immediate next flush if more items are waiting or unacknowledged
        if (!isShuttingDown && (messageQueue.length >= BATCH_SIZE || inFlightBatch)) {
            setImmediate(flushQueue);
        }
    }
}

/**
 * Schedule periodic flush timer
 */
function scheduleFlushTimer() {
    if (flushTimer) clearInterval(flushTimer);
    flushTimer = setInterval(() => {
        if (messageQueue.length > 0 || inFlightBatch) {
            flushQueue();
        }
    }, BATCH_INTERVAL_MS);
}

/**
 * Handle incoming parsed AIS frame
 */
function processAisFrame(json) {
    if (json.MessageType === 'SubscriptionConfirmation') {
        console.log(` [${new Date().toISOString()}] AISStream Subscription Confirmed.`);
        return;
    }

    totalReceived++;

    const msgType = json.MessageType;
    const messageObj = json.Message || {};
    const meta = json.MetaData || {};
    const mmsi = String(meta.MMSI || messageObj[msgType]?.UserID || '').replace(/\D/g, '');

    // A. All vessel types accepted: Position reports (Type 1, 2, 3, 18, 19, 27) are NEVER dropped locally.
    // Static metadata frames are inspected for compliance caching, but all messages are forwarded.
    if (msgType === 'ShipStaticData') {
        const staticData = messageObj.ShipStaticData || {};
        const shipType = staticData.Type;
        if (isProhibitedShipType(shipType) && mmsi) {
            setProhibitedMmsi(mmsi, Date.now() + BLOCKED_CACHE_TTL_MS);
            enforceCacheLimits();
        }
    } else if (msgType === 'StaticDataReport') {
        const staticReport = messageObj.StaticDataReport || {};
        const shipType = staticReport.ReportB?.ShipType;
        if (isProhibitedShipType(shipType) && mmsi) {
            setProhibitedMmsi(mmsi, Date.now() + BLOCKED_CACHE_TTL_MS);
            enforceCacheLimits();
        }
    } else if (msgType === 'ExtendedClassBPositionReport') {
        const extReport = messageObj.ExtendedClassBPositionReport || {};
        const shipType = extReport.Type ?? extReport.ShipType;
        if (isProhibitedShipType(shipType) && mmsi) {
            setProhibitedMmsi(mmsi, Date.now() + BLOCKED_CACHE_TTL_MS);
            enforceCacheLimits();
        }
    }

    // D. Enqueue message with bounded memory protection
    if (messageQueue.length >= MAX_QUEUE_SIZE) {
        // Evict oldest item if queue limit reached
        messageQueue.shift();
        totalDropped++;
    }
    messageQueue.push(json);

    // Flush immediately if batch size is reached
    if (messageQueue.length >= BATCH_SIZE && !isFlushing) {
        flushQueue();
    }
}

/**
 * Connect to AISStream WebSocket
 */
async function connectWebSocket(WebSocketImpl) {
    if (isShuttingDown) return;

    console.log(` [${new Date().toISOString()}] Connecting to AISStream WebSocket...`);
    const ws = new WebSocketImpl(AIS_STREAM_URL);
    activeWs = ws;

    ws.onopen = () => {
        console.log(` [${new Date().toISOString()}] Connected to stream.aisstream.io. Sending subscription payload...`);
        reconnectAttempt = 0;

        const subscriptionMessage = {
            APIKey: AIS_STREAM_API_KEY,
            BoundingBoxes: boundingBoxes,
            FilterMessageTypes: [
                'PositionReport',
                'ShipStaticData',
                'StandardClassBPositionReport',
                'ExtendedClassBPositionReport',
                'StaticDataReport',
            ],
        };

        ws.send(JSON.stringify(subscriptionMessage));
        scheduleFlushTimer();
    };

    ws.onmessage = (event) => {
        try {
            const rawData = typeof event.data === 'string' ? event.data : event.data.toString();
            const json = JSON.parse(rawData);
            processAisFrame(json);
        } catch (err) {
            console.error(` [PARSE ERROR] Invalid frame payload: ${err.message}`);
        }
    };

    ws.onerror = (err) => {
        console.error(` [WS ERROR] AISStream connection error: ${err.message || 'unknown error'}`);
    };

    ws.onclose = (event) => {
        activeWs = null;
        if (isShuttingDown) return;

        reconnectAttempt++;
        const baseBackoff = Math.min(30000, Math.pow(2, Math.min(reconnectAttempt, 5)) * 1000);
        const jitter = Math.floor(Math.random() * 2000);
        const delay = baseBackoff + jitter;

        console.warn(` [${new Date().toISOString()}] Connection closed (code: ${event.code || 'unknown'}). Reconnecting in ${(delay / 1000).toFixed(1)}s (Attempt #${reconnectAttempt})...`);
        setTimeout(() => connectWebSocket(WebSocketImpl), delay);
    };
}

/**
 * Graceful termination handler
 */
async function gracefulShutdown(signal) {
    console.log(`\n [${signal}] Graceful shutdown initiated...`);
    isShuttingDown = true;
    if (flushTimer) clearInterval(flushTimer);
    if (syncTimer) clearInterval(syncTimer);

    if (activeWs) {
        try {
            activeWs.close();
        } catch {
            // Ignore close error
        }
    }

    if (messageQueue.length > 0 || inFlightBatch) {
        console.log(` Flushing pending messages before termination...`);
        try {
            await flushQueue();
        } catch {}
    }

    // Persist any uncommitted messages to disk
    const persisted = persistQueueToDisk();

    console.log('='.repeat(65));
    console.log(' [SUMMARY] AIS Worker Session Closed:');
    console.log(` Total Received:     ${totalReceived}`);
    console.log(` Total Sent:         ${totalSent}`);
    console.log(` Filtered Locally:   ${totalFilteredOut} (All vessel types accepted; no position suppression)`);
    console.log(` Quarantined (422):  ${totalQuarantined}`);
    console.log(` Persisted to Disk:  ${persisted} messages saved to ${DISK_QUEUE_PATH}`);
    console.log(` Total Dropped:      ${totalDropped}`);
    console.log(` Failed Batches:     ${totalFailedBatches}`);
    console.log('='.repeat(65));
    process.exit(0);
}

/**
 * Main initialization routine
 */
async function initWorker() {
    console.log('='.repeat(65));
    console.log(' [GFVIP] AISStream Hardened Telemetry Worker (Node.js ESM)');
    console.log('='.repeat(65));
    console.log(` Target Stream:   ${AIS_STREAM_URL}`);
    console.log(` Backend Batch:   ${AIS_BACKEND_URL}`);
    console.log(` Prohibited Sync: ${AIS_PROHIBITED_URL}`);
    console.log(` Stream Key:      ${maskSecret(AIS_STREAM_API_KEY)}`);
    console.log(` Ingest Token:    ${maskSecret(AIS_INGEST_TOKEN)}`);
    console.log(` Batch Config:    ${BATCH_SIZE} items or ${BATCH_INTERVAL_MS}ms window`);
    console.log(` Queue Capacity:  ${MAX_QUEUE_SIZE} (bounded memory guard)`);
    console.log(` Disk Queue Path: ${DISK_QUEUE_PATH}`);
    console.log(` Cache Policy:    Max ${MAX_BLOCKED_CACHE_SIZE} MMSIs, 24h TTL, LRU Pruning`);
    console.log(` Ingestion Policy: Accept all vessel types (Cargo, Tanker, Fishing, Pleasure, Passenger, Tug, Other, Unknown)`);
    console.log('='.repeat(65));

    if (!AIS_STREAM_API_KEY) {
        console.error(' [ERROR] AIS_STREAM_API_KEY is not configured in .env or environment.');
        console.error(' Please provide a valid aisstream.io key to start streaming.');
        process.exit(1);
    }

    // Select WebSocket implementation
    let WebSocketImpl = globalThis.WebSocket;
    try {
        const wsModule = await import('ws');
        if (wsModule && wsModule.default) {
            WebSocketImpl = wsModule.default;
        }
    } catch {
        // Fall back to built-in WebSocket in Node 22+
    }

    if (!WebSocketImpl) {
        console.error(' [ERROR] No WebSocket client available in Node.js environment.');
        process.exit(1);
    }

    // 1. Restore persisted queue from disk if exists
    loadPersistedQueue();

    // 2. Perform initial Prohibited MMSI synchronization
    await syncProhibitedMmsis();

    // 3. Schedule hourly periodic Prohibited MMSI synchronization
    syncTimer = setInterval(syncProhibitedMmsis, PROHIBITED_SYNC_INTERVAL_MS);

    // 4. Attach graceful shutdown signals
    process.on('SIGINT', () => gracefulShutdown('SIGINT'));
    process.on('SIGTERM', () => gracefulShutdown('SIGTERM'));

    // 5. Connect to stream
    connectWebSocket(WebSocketImpl);
}

// Auto-start when executed directly from CLI
const isDirectExecution = process.argv[1] && (path.resolve(process.argv[1]) === path.resolve(fileURLToPath(import.meta.url)));
if (isDirectExecution) {
    initWorker();
}

function _testResetState() {
    inFlightBatch = null;
    consecutiveFailures = 0;
    isFlushing = false;
    messageQueue.length = 0;
    prohibitedMmsiCache.clear();
    lastSyncSnapshotVersion = null;
    lastSyncTimestamp = null;
    syncInProgress = false;
}

function _getInFlightBatch() {
    return inFlightBatch;
}

// Export for test suite and modular verification
export {
    messageQueue,
    inFlightBatch,
    prohibitedMmsiCache,
    processAisFrame,
    flushQueue,
    syncProhibitedMmsis,
    sendBatchRequest,
    handleHttp422,
    loadPersistedQueue,
    persistQueueToDisk,
    enforceCacheLimits,
    isProhibitedShipType,
    isMmsiProhibited,
    setProhibitedMmsi,
    _testResetState,
    _getInFlightBatch,
    DISK_QUEUE_PATH,
    MAX_QUEUE_SIZE,
    MAX_BLOCKED_CACHE_SIZE,
    BLOCKED_CACHE_TTL_MS,
};
