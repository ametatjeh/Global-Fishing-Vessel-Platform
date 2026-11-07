/**
 * GFVIP - AISStream.io High-Performance WebSocket Ingestion Worker
 * Production Architecture: Node.js worker for aaPanel / PM2 / Systemd
 * 
 * Features:
 * - RFC 6455 WebSocket client connecting to wss://stream.aisstream.io/v0/stream
 * - Global bounding box: [[[-90.0, -180.0], [90.0, 180.0]]]
 * - Subscribes to 5 ITU message types: PositionReport, ShipStaticData,
 *   StandardClassBPositionReport, ExtendedClassBPositionReport, StaticDataReport
 * - Bounded message queue with memory-leak protection (FIFO eviction if backend is offline)
 * - Micro-batching (max 50 messages or 2000ms flush window)
 * - POSTs batches to Laravel at /api/telemetry/ais/batch with X-AIS-Token
 * - Exponential backoff with random jitter on connection drops
 * - Graceful shutdown on SIGINT / SIGTERM
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
    } catch (e) {
        // Ignore read errors
    }
}

loadEnvFile(path.join(rootDir, '.env'));

// Configuration
const AIS_STREAM_API_KEY = process.env.AIS_STREAM_API_KEY || process.env.AIS_API_KEY || '';
const AIS_INGEST_TOKEN = process.env.AIS_INGEST_TOKEN || AIS_STREAM_API_KEY || '';
const APP_URL = process.env.APP_URL || 'http://127.0.0.1:8000';
const AIS_BACKEND_URL = process.env.AIS_BACKEND_URL || `${APP_URL.replace(/\/$/, '')}/api/telemetry/ais/batch`;
const AIS_STREAM_URL = process.env.AIS_STREAM_URL || 'wss://stream.aisstream.io/v0/stream';
const BATCH_SIZE = parseInt(process.env.AIS_BATCH_SIZE || '50', 10);
const BATCH_INTERVAL_MS = parseInt(process.env.AIS_BATCH_INTERVAL_MS || '2000', 10);
const MAX_QUEUE_SIZE = parseInt(process.env.AIS_MAX_QUEUE_SIZE || '1000', 10);

function maskSecret(str) {
    if (!str || str.length <= 8) return '****';
    return `${str.substring(0, 4)}...${str.substring(str.length - 4)}`;
}

console.log('='.repeat(65));
console.log(' [GFVIP] AISStream Live Telemetry Worker (aaPanel / Node.js ESM)');
console.log('='.repeat(65));
console.log(` Target Stream:  ${AIS_STREAM_URL}`);
console.log(` Backend Batch:  ${AIS_BACKEND_URL}`);
console.log(` Stream Key:     ${maskSecret(AIS_STREAM_API_KEY)}`);
console.log(` Ingest Token:   ${maskSecret(AIS_INGEST_TOKEN)}`);
console.log(` Batch Config:   ${BATCH_SIZE} items or ${BATCH_INTERVAL_MS}ms window`);
console.log(` Queue Capacity: ${MAX_QUEUE_SIZE} (bounded memory leak guard)`);
console.log('='.repeat(65));

if (!AIS_STREAM_API_KEY) {
    console.error(' [ERROR] AIS_STREAM_API_KEY is not configured in .env or environment.');
    console.error(' Please provide a valid aisstream.io key to start streaming.');
    process.exit(1);
}

// Select WebSocket implementation (support both native globalThis.WebSocket and optional 'ws' package)
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

// In-memory queue & statistics
const messageQueue = [];
let isFlushing = false;
let flushTimer = null;
let reconnectAttempt = 0;
let isShuttingDown = false;
let activeWs = null;

let totalReceived = 0;
let totalSent = 0;
let totalDropped = 0;
let totalFailedBatches = 0;

/**
 * Send batch of collected messages to Laravel backend
 */
async function flushQueue() {
    if (isFlushing || messageQueue.length === 0) return;
    isFlushing = true;

    // Take up to BATCH_SIZE messages
    const batch = messageQueue.splice(0, BATCH_SIZE);

    try {
        const response = await fetch(AIS_BACKEND_URL, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-AIS-Token': AIS_INGEST_TOKEN,
                'User-Agent': 'GFVIP-AisWorker-Node/1.0',
            },
            body: JSON.stringify({ messages: batch }),
        });

        if (response.ok) {
            const data = await response.json();
            totalSent += batch.length;
            const now = new Date().toISOString();
            console.log(` [${now}] Batch flushed: ${batch.length} msgs (Ingested: ${data.inserted || 0}, Skipped: ${data.skipped || 0}, Pending in queue: ${messageQueue.length})`);
        } else {
            totalFailedBatches++;
            const errText = await response.text();
            console.error(` [HTTP ${response.status}] Backend batch ingestion failed: ${errText.substring(0, 150)}`);
            // If backend returned 5xx or network issue, put messages back if under max capacity
            if (response.status >= 500 && (messageQueue.length + batch.length <= MAX_QUEUE_SIZE)) {
                messageQueue.unshift(...batch);
            } else {
                totalDropped += batch.length;
            }
        }
    } catch (err) {
        totalFailedBatches++;
        console.error(` [NETWORK ERROR] Failed to deliver batch to backend: ${err.message}`);
        if (messageQueue.length + batch.length <= MAX_QUEUE_SIZE) {
            messageQueue.unshift(...batch);
        } else {
            totalDropped += batch.length;
        }
    } finally {
        isFlushing = false;
        // If remaining items exceed batch size, trigger immediate next flush
        if (messageQueue.length >= BATCH_SIZE) {
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
        if (messageQueue.length > 0) {
            flushQueue();
        }
    }, BATCH_INTERVAL_MS);
}

/**
 * Connect to AISStream WebSocket
 */
function connectWebSocket() {
    if (isShuttingDown) return;

    console.log(` [${new Date().toISOString()}] Connecting to AISStream WebSocket...`);
    const ws = new WebSocketImpl(AIS_STREAM_URL);
    activeWs = ws;

    ws.onopen = () => {
        console.log(` [${new Date().toISOString()}] Connected to stream.aisstream.io. Sending subscription payload...`);
        reconnectAttempt = 0; // reset reconnect backoff

        const subscriptionMessage = {
            APIKey: AIS_STREAM_API_KEY,
            BoundingBoxes: [[[-90.0, -180.0], [90.0, 180.0]]],
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

            if (json.MessageType === 'SubscriptionConfirmation') {
                console.log(` [${new Date().toISOString()}] AISStream Subscription Confirmed.`);
                return;
            }

            totalReceived++;

            // Push to bounded queue
            if (messageQueue.length >= MAX_QUEUE_SIZE) {
                // Evict oldest item to prevent memory leak
                messageQueue.shift();
                totalDropped++;
            }
            messageQueue.push(json);

            // Flush immediately if batch size is reached
            if (messageQueue.length >= BATCH_SIZE && !isFlushing) {
                flushQueue();
            }
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
        setTimeout(connectWebSocket, delay);
    };
}

// Graceful termination
async function gracefulShutdown(signal) {
    console.log(`\n [${signal}] Graceful shutdown initiated...`);
    isShuttingDown = true;
    if (flushTimer) clearInterval(flushTimer);

    if (activeWs) {
        try {
            activeWs.close();
        } catch {
            // Ignore close error
        }
    }

    if (messageQueue.length > 0) {
        console.log(` Flushing remaining ${messageQueue.length} messages to backend before terminating...`);
        await flushQueue();
    }

    console.log('='.repeat(65));
    console.log(' [SUMMARY] AIS Worker Session Closed:');
    console.log(` Total Received: ${totalReceived}`);
    console.log(` Total Sent:     ${totalSent}`);
    console.log(` Total Dropped:  ${totalDropped}`);
    console.log(` Failed Batches: ${totalFailedBatches}`);
    console.log('='.repeat(65));
    process.exit(0);
}

process.on('SIGINT', () => gracefulShutdown('SIGINT'));
process.on('SIGTERM', () => gracefulShutdown('SIGTERM'));

// Start worker
connectWebSocket();

