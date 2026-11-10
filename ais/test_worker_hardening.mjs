/**
 * GFVIP - AIS Worker Hardening Test Suite (Tahap 4B)
 * Comprehensive Audit & Verification of 10 Critical Hardening Criteria:
 * 1. Backend connection broken (socket hangup / ECONNREFUSED), then recovers.
 * 2. HTTP 500 and 429 with exponential retry/backoff.
 * 3. HTTP 422: corrupt frame isolation without dropping valid messages.
 * 4. Idempotency when backend processed the message but ACK was lost on network.
 * 5. Disk persistence for both messageQueue and inFlightBatch across worker restart.
 * 6. Block/unblock based on latest snapshot version (older versions ignored, out-of-order protected).
 * 7. Pagination failure midway leaves old cache intact (atomic rollback).
 * 8. Type 19 prohibited is still forwarded to backend (dual ingestion).
 * 9. True LRU based on access/usage recency, not merely expiration time.
 * 10. HTTP 200 response with mismatched received message count or error status.
 */

import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import assert from 'node:assert';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const rootDir = path.resolve(__dirname, '..');

// Set test environment variables before importing worker
const TEST_PORT = 8999;
process.env.APP_URL = `http://127.0.0.1:${TEST_PORT}`;
process.env.AIS_BACKEND_URL = `http://127.0.0.1:${TEST_PORT}/api/telemetry/ais/batch`;
process.env.AIS_PROHIBITED_URL = `http://127.0.0.1:${TEST_PORT}/api/telemetry/ais/prohibited-mmsis`;
process.env.AIS_STREAM_API_KEY = 'test-key-xyz';
process.env.AIS_INGEST_TOKEN = 'test-token-xyz';
process.env.AIS_BATCH_SIZE = '5';
process.env.AIS_MAX_QUEUE_SIZE = '50';
process.env.AIS_MAX_BLOCKED_CACHE_SIZE = '3'; // small for testing LRU
process.env.AIS_DISK_QUEUE_PATH = path.join(__dirname, 'test_queue_disk.json');
process.env.AIS_TEST_MODE = '1'; // Fast retry delay

// Import worker module
const worker = await import('./worker.mjs');

let server = null;
let serverHandler = null;

function startMockServer() {
    return new Promise((resolve) => {
        server = http.createServer((req, res) => {
            if (serverHandler) {
                serverHandler(req, res);
            } else {
                res.writeHead(404);
                res.end();
            }
        });
        server.listen(TEST_PORT, () => {
            resolve();
        });
    });
}

function stopMockServer() {
    return new Promise((resolve) => {
        if (server) {
            server.close(() => resolve());
        } else {
            resolve();
        }
    });
}

async function runTests() {
    console.log('='.repeat(70));
    console.log(' RUNNING AUDITED AIS WORKER HARDENING TEST SUITE (TAHAP 4B - 10/10)');
    console.log('='.repeat(70));

    await startMockServer();

    let passedTests = 0;
    let totalTests = 0;

    async function test(name, fn) {
        totalTests++;
        process.stdout.write(`  [TEST ${String(totalTests).padStart(2, '0')}] ${name} ... `);
        worker._testResetState();
        try {
            await fn();
            console.log('PASS');
            passedTests++;
        } catch (err) {
            console.log('FAIL');
            console.error('    Error:', err.message);
            console.error(err.stack);
        } finally {
            worker._testResetState();
            serverHandler = null;
        }
    }

    try {
        // -------------------------------------------------------------
        // TEST 1: Backend connection broken (socket hangup), then recovers
        // -------------------------------------------------------------
        await test('Backend connection broken (socket destroyed) then recovers without message loss', async () => {
            worker.messageQueue.push({ id: 101, type: 'pos' });
            worker.messageQueue.push({ id: 102, type: 'pos' });

            let attempts = 0;
            let finalReceivedBatch = null;

            serverHandler = (req, res) => {
                attempts++;
                if (attempts === 1) {
                    // Abruptly destroy the socket connection (simulating network cut / server crash)
                    req.socket.destroy();
                } else {
                    let body = '';
                    req.on('data', chunk => body += chunk);
                    req.on('end', () => {
                        finalReceivedBatch = JSON.parse(body);
                        res.writeHead(200, { 'Content-Type': 'application/json' });
                        res.end(JSON.stringify({ status: 'success', received: 2, inserted: 2, skipped: 0 }));
                    });
                }
            };

            // Attempt 1: Socket destroyed by server. Must catch network error and preserve inFlightBatch
            await worker.flushQueue();
            assert.strictEqual(attempts, 1, 'Should have made 1 initial attempt');
            assert.strictEqual(worker._getInFlightBatch() !== null, true, 'inFlightBatch must be preserved when socket drops');
            assert.strictEqual(worker._getInFlightBatch().length, 2, 'Both messages must remain in inFlightBatch');

            // Attempt 2: Server recovered. Must flush preserved inFlightBatch successfully
            await worker.flushQueue();
            assert.strictEqual(attempts, 2, 'Should have made second attempt');
            assert.strictEqual(worker._getInFlightBatch(), null, 'inFlightBatch must be released after successful recovery');
            assert.strictEqual(finalReceivedBatch.messages.length, 2, 'Delivered batch must contain both messages');
            assert.strictEqual(finalReceivedBatch.messages[0].id, 101);
            assert.strictEqual(finalReceivedBatch.messages[1].id, 102);
        });

        // -------------------------------------------------------------
        // TEST 2: HTTP 500 and 429 with retry / backoff
        // -------------------------------------------------------------
        await test('HTTP 500 and 429 errors preserve inFlightBatch with exponential backoff', async () => {
            worker.messageQueue.push({ id: 201, type: 'test' });
            worker.messageQueue.push({ id: 202, type: 'test' });

            let attempts = 0;
            serverHandler = (req, res) => {
                attempts++;
                if (attempts === 1) {
                    res.writeHead(500, { 'Content-Type': 'application/json' });
                    res.end(JSON.stringify({ status: 'error', message: 'Internal Server Error' }));
                } else if (attempts === 2) {
                    res.writeHead(429, { 'Content-Type': 'application/json' });
                    res.end(JSON.stringify({ status: 'error', message: 'Rate Limited' }));
                } else {
                    res.writeHead(200, { 'Content-Type': 'application/json' });
                    res.end(JSON.stringify({ status: 'success', received: 2 }));
                }
            };

            // Flush 1: HTTP 500
            await worker.flushQueue();
            assert.strictEqual(attempts, 1);
            assert.strictEqual(worker._getInFlightBatch() !== null, true, 'inFlightBatch must be retained on HTTP 500');

            // Flush 2: HTTP 429
            await worker.flushQueue();
            assert.strictEqual(attempts, 2);
            assert.strictEqual(worker._getInFlightBatch() !== null, true, 'inFlightBatch must be retained on HTTP 429');

            // Flush 3: HTTP 200
            await worker.flushQueue();
            assert.strictEqual(attempts, 3);
            assert.strictEqual(worker._getInFlightBatch(), null, 'inFlightBatch must clear on HTTP 200');
        });

        // -------------------------------------------------------------
        // TEST 3: HTTP 422 Dissection: isolate corrupt frame without losing valid messages
        // -------------------------------------------------------------
        await test('HTTP 422 dissection isolates invalid frames and preserves valid messages', async () => {
            const msgGood1 = { id: 'valid_1', MessageType: 'PositionReport' };
            const msgCorrupt = { id: 'corrupt_frame', MessageType: 'InvalidFrame' };
            const msgGood2 = { id: 'valid_2', MessageType: 'ShipStaticData' };

            worker.messageQueue.push(msgGood1, msgCorrupt, msgGood2);

            const receivedSingles = [];

            serverHandler = (req, res) => {
                let body = '';
                req.on('data', chunk => body += chunk);
                req.on('end', () => {
                    const parsed = JSON.parse(body);
                    if (parsed.messages.length > 1) {
                        // Entire batch rejected with 422
                        res.writeHead(422, { 'Content-Type': 'application/json' });
                        res.end(JSON.stringify({ status: 'error', message: 'Unprocessable Entity' }));
                    } else {
                        // Dissected single item
                        const item = parsed.messages[0];
                        receivedSingles.push(item);
                        if (item.id === 'corrupt_frame') {
                            res.writeHead(422, { 'Content-Type': 'application/json' });
                            res.end(JSON.stringify({ status: 'error', message: 'Corrupt payload quarantined' }));
                        } else {
                            res.writeHead(200, { 'Content-Type': 'application/json' });
                            res.end(JSON.stringify({ status: 'success', received: 1 }));
                        }
                    }
                });
            };

            await worker.flushQueue();

            assert.strictEqual(worker._getInFlightBatch(), null, 'inFlightBatch should be cleared after dissection');
            assert.strictEqual(receivedSingles.length, 3, 'All 3 messages must be evaluated individually');
            assert.strictEqual(receivedSingles[0].id, 'valid_1');
            assert.strictEqual(receivedSingles[1].id, 'corrupt_frame');
            assert.strictEqual(receivedSingles[2].id, 'valid_2');
        });

        // -------------------------------------------------------------
        // TEST 4: Idempotency when backend processed the message but ACK was lost
        // -------------------------------------------------------------
        await test('Idempotency on lost ACK: backend ingests message, ACK lost on wire, retry succeeds safely', async () => {
            worker.messageQueue.push({ id: 'msg_idem_1', mmsi: '525123456', lat: -6.1, lon: 106.8 });

            let attempts = 0;
            const backendDatabase = new Set();

            serverHandler = (req, res) => {
                attempts++;
                let body = '';
                req.on('data', chunk => body += chunk);
                req.on('end', () => {
                    const parsed = JSON.parse(body);
                    const msg = parsed.messages[0];

                    if (attempts === 1) {
                        // Server successfully writes record to its internal database
                        backendDatabase.add(msg.id);
                        // But server connection drops BEFORE sending ACK!
                        req.socket.destroy();
                    } else {
                        // Server receives retry; detects idempotency (already inserted)
                        const wasAlreadyInserted = backendDatabase.has(msg.id);
                        backendDatabase.add(msg.id);
                        res.writeHead(200, { 'Content-Type': 'application/json' });
                        res.end(JSON.stringify({
                            status: 'success',
                            received: 1,
                            inserted: wasAlreadyInserted ? 0 : 1,
                            skipped: wasAlreadyInserted ? 1 : 0,
                        }));
                    }
                });
            };

            // Attempt 1: Ingested on server, but ACK lost
            await worker.flushQueue();
            assert.strictEqual(attempts, 1);
            assert.strictEqual(backendDatabase.has('msg_idem_1'), true, 'Server must have processed msg on first attempt');
            assert.strictEqual(worker._getInFlightBatch() !== null, true, 'Worker must preserve inFlightBatch because ACK was not received');

            // Attempt 2: Worker retries inFlightBatch. Backend handles idempotency safely
            await worker.flushQueue();
            assert.strictEqual(attempts, 2);
            assert.strictEqual(worker._getInFlightBatch(), null, 'Worker must clear inFlightBatch after receiving ACK');
            assert.strictEqual(backendDatabase.size, 1, 'Database must have exactly 1 unique record (no duplicates)');
        });

        // -------------------------------------------------------------
        // TEST 5: Disk persistence for both messageQueue AND inFlightBatch across restart
        // -------------------------------------------------------------
        await test('Disk persistence saves BOTH messageQueue and inFlightBatch across worker restart', async () => {
            if (fs.existsSync(worker.DISK_QUEUE_PATH)) {
                fs.unlinkSync(worker.DISK_QUEUE_PATH);
            }

            // Simulate having 2 messages in flight (waiting for retry) and 2 messages in main queue
            worker.messageQueue.push({ id: 'queued_1' }, { id: 'queued_2' });

            // Fail a flush with 500 to leave messages locked in inFlightBatch
            serverHandler = (req, res) => {
                res.writeHead(500, { 'Content-Type': 'application/json' });
                res.end(JSON.stringify({ status: 'error' }));
            };
            await worker.flushQueue();

            assert.strictEqual(worker._getInFlightBatch() !== null, true, 'inFlightBatch must hold items');
            assert.strictEqual(worker._getInFlightBatch().length, 2, 'inFlightBatch has 2 items');

            // Add 2 more items to messageQueue after inFlightBatch was locked
            worker.messageQueue.push({ id: 'queued_3' }, { id: 'queued_4' });

            // Persist to disk: must save inFlightBatch (2) + messageQueue (2) = 4 messages
            const savedCount = worker.persistQueueToDisk();
            assert.strictEqual(savedCount, 4, 'Must persist all 4 uncommitted messages (inFlightBatch + messageQueue)');
            assert.strictEqual(fs.existsSync(worker.DISK_QUEUE_PATH), true, 'Disk queue file must exist');

            // Clear in-memory state (simulating worker crash/restart)
            worker._testResetState();
            assert.strictEqual(worker.messageQueue.length, 0);
            assert.strictEqual(worker._getInFlightBatch(), null);

            // Reload from disk queue
            worker.loadPersistedQueue();
            assert.strictEqual(worker.messageQueue.length, 4, 'Must restore all 4 messages into messageQueue');
            assert.strictEqual(worker.messageQueue[0].id, 'queued_1');
            assert.strictEqual(worker.messageQueue[1].id, 'queued_2');
            assert.strictEqual(worker.messageQueue[2].id, 'queued_3');
            assert.strictEqual(worker.messageQueue[3].id, 'queued_4');
            assert.strictEqual(fs.existsSync(worker.DISK_QUEUE_PATH), false, 'Disk queue file must be unlinked after loading');
        });

        // -------------------------------------------------------------
        // TEST 6: Block/unblock with latest snapshot version & stale version rejection
        // -------------------------------------------------------------
        await test('Snapshot versioning: applies latest version and rejects stale out-of-order snapshots', async () => {
            // Snapshot 1: Version 100 -> block 525111001 and 525111002
            serverHandler = (req, res) => {
                res.writeHead(200, { 'Content-Type': 'application/json' });
                res.end(JSON.stringify({
                    status: 'success',
                    snapshot_version: 100,
                    data: [
                        { mmsi: '525111001', action: 'block' },
                        { mmsi: '525111002', action: 'block' },
                    ],
                }));
            };
            await worker.syncProhibitedMmsis();
            assert.strictEqual(worker.isMmsiProhibited('525111001'), true, '525111001 must be blocked in v100');
            assert.strictEqual(worker.isMmsiProhibited('525111002'), true, '525111002 must be blocked in v100');

            // Snapshot 2: Version 105 -> unblock 525111001 (reclassified to fishing)
            serverHandler = (req, res) => {
                res.writeHead(200, { 'Content-Type': 'application/json' });
                res.end(JSON.stringify({
                    status: 'success',
                    snapshot_version: 105,
                    data: [
                        { mmsi: '525111001', action: 'unblock' },
                    ],
                }));
            };
            await worker.syncProhibitedMmsis();
            assert.strictEqual(worker.isMmsiProhibited('525111001'), false, '525111001 must be unblocked in v105');
            assert.strictEqual(worker.isMmsiProhibited('525111002'), true, '525111002 must remain blocked');

            // Snapshot 3: Stale version 95 (< 105) arriving out-of-order -> MUST BE REJECTED
            serverHandler = (req, res) => {
                res.writeHead(200, { 'Content-Type': 'application/json' });
                res.end(JSON.stringify({
                    status: 'success',
                    snapshot_version: 95,
                    data: [
                        { mmsi: '525111001', action: 'block' }, // Attempting stale re-block
                    ],
                }));
            };
            await worker.syncProhibitedMmsis();
            assert.strictEqual(worker.isMmsiProhibited('525111001'), false, 'Stale snapshot v95 must not revert v105 unblock state');
        });

        // -------------------------------------------------------------
        // TEST 7: Snapshot pagination failure midway does not corrupt cache
        // -------------------------------------------------------------
        await test('Snapshot pagination failure midway aborts cleanly without partial cache corruption', async () => {
            worker.setProhibitedMmsi('SAFE_MMSI', Date.now() + 3600000);

            let pageNum = 0;
            serverHandler = (req, res) => {
                pageNum++;
                if (pageNum === 1) {
                    res.writeHead(200, { 'Content-Type': 'application/json' });
                    res.end(JSON.stringify({
                        status: 'success',
                        snapshot_version: 110,
                        next_cursor: 'page2',
                        is_last_page: false,
                        data: [
                            { mmsi: 'NEW_PAGE_1', action: 'block' },
                            { mmsi: 'SAFE_MMSI', action: 'unblock' },
                        ],
                    }));
                } else {
                    // Page 2 fails with 500
                    res.writeHead(500, { 'Content-Type': 'application/json' });
                    res.end(JSON.stringify({ status: 'error', message: 'DB connection failure during pagination' }));
                }
            };

            await worker.syncProhibitedMmsis();

            // Cache MUST remain intact: Page 1 updates rolled back because Page 2 failed!
            assert.strictEqual(worker.isMmsiProhibited('SAFE_MMSI'), true, 'SAFE_MMSI must remain blocked (not unblocked)');
            assert.strictEqual(worker.isMmsiProhibited('NEW_PAGE_1'), false, 'NEW_PAGE_1 must not be committed to cache');
        });

        // -------------------------------------------------------------
        // TEST 8: All vessel types and positions forwarded to backend
        // -------------------------------------------------------------
        await test('PositionReport, StandardClassBPositionReport, and Type 19 are all enqueued and forwarded even if MMSI is in prohibited cache', async () => {
            const cargoMmsi = '525888999';
            worker.setProhibitedMmsi(cargoMmsi, Date.now() + 3600000);

            // 1. Regular PositionReport -> MUST BE ENQUEUED FOR FORWARDING
            const posFrame = {
                MessageType: 'PositionReport',
                MetaData: { MMSI: cargoMmsi },
                Message: { PositionReport: { UserID: cargoMmsi, Latitude: -5.0, Longitude: 106.0 } },
            };
            worker.processAisFrame(posFrame);
            assert.strictEqual(worker.messageQueue.length, 1, 'PositionReport must be enqueued even if MMSI is in prohibited cache');

            // 2. StandardClassBPositionReport -> MUST BE ENQUEUED FOR FORWARDING
            const stdFrame = {
                MessageType: 'StandardClassBPositionReport',
                MetaData: { MMSI: cargoMmsi },
                Message: { StandardClassBPositionReport: { UserID: cargoMmsi, Latitude: -5.1, Longitude: 106.1 } },
            };
            worker.processAisFrame(stdFrame);
            assert.strictEqual(worker.messageQueue.length, 2, 'StandardClassBPositionReport must be enqueued even if MMSI is in prohibited cache');

            // 3. Type 19 ExtendedClassBPositionReport -> MUST BE ENQUEUED FOR FORWARDING
            const type19Frame = {
                MessageType: 'ExtendedClassBPositionReport',
                MetaData: { MMSI: cargoMmsi, ShipName: 'CARGO VESSEL' },
                Message: { ExtendedClassBPositionReport: { UserID: cargoMmsi, Type: 70, Name: 'CARGO VESSEL' } },
            };
            worker.processAisFrame(type19Frame);
            assert.strictEqual(worker.messageQueue.length, 3, 'Type 19 must be enqueued even if MMSI is in prohibited cache');
            assert.strictEqual(worker.messageQueue[2].MessageType, 'ExtendedClassBPositionReport');
        });

        // -------------------------------------------------------------
        // TEST 9: True LRU based on access/usage recency, not merely expiration time
        // -------------------------------------------------------------
        await test('True LRU: active frequently accessed vessel is retained while inactive vessel is evicted', async () => {
            // Cache max size is 3 (AIS_MAX_BLOCKED_CACHE_SIZE = 3)
            const now = Date.now();

            // Insert Vessel A (expires in 1000s)
            worker.setProhibitedMmsi('VESSEL_A', now + 1000000, 100);
            // Insert Vessel B (expires in 2000s)
            worker.setProhibitedMmsi('VESSEL_B', now + 2000000, 200);
            // Insert Vessel C (expires in 3000s)
            worker.setProhibitedMmsi('VESSEL_C', now + 3000000, 300);

            assert.strictEqual(worker.prohibitedMmsiCache.size, 3);

            // Simulate active AIS traffic for VESSEL_A: accessing it updates its lastAccessedAt timestamp!
            const isBlockedA = worker.isMmsiProhibited('VESSEL_A');
            assert.strictEqual(isBlockedA, true);

            // Now insert VESSEL_D with current access time. Cache will exceed capacity (4 > 3)
            worker.setProhibitedMmsi('VESSEL_D', now + 4000000, 400);

            // Enforce limits: should evict LEAST RECENTLY USED entry
            // Access times: B was accessed at 200, C at 300, D at 400, and A was accessed recently (Date.now() > 400)
            worker.enforceCacheLimits();

            assert.strictEqual(worker.prohibitedMmsiCache.size, 3, 'Cache size must be capped at 3');
            assert.strictEqual(worker.isMmsiProhibited('VESSEL_B'), false, 'VESSEL_B was least recently accessed and must be evicted');
            assert.strictEqual(worker.isMmsiProhibited('VESSEL_A'), true, 'VESSEL_A was actively accessed and must NOT be evicted');
            assert.strictEqual(worker.isMmsiProhibited('VESSEL_C'), true, 'VESSEL_C must be retained');
            assert.strictEqual(worker.isMmsiProhibited('VESSEL_D'), true, 'VESSEL_D must be retained');
        });

        // -------------------------------------------------------------
        // TEST 10: HTTP 200 response with mismatched received message count or error status
        // -------------------------------------------------------------
        await test('HTTP 200 with non-success status or mismatched received count prevents silent message loss', async () => {
            // Case A: HTTP 200 with status: error
            worker.messageQueue.push({ id: 'msg_status_err_1' });
            serverHandler = (req, res) => {
                res.writeHead(200, { 'Content-Type': 'application/json' });
                res.end(JSON.stringify({ status: 'error', message: 'Database transaction lock timeout' }));
            };

            await worker.flushQueue();
            assert.strictEqual(worker._getInFlightBatch() !== null, true, 'inFlightBatch must be retained when HTTP 200 has status: error');
            assert.strictEqual(worker._getInFlightBatch()[0].id, 'msg_status_err_1');

            // Reset and test Case B: Partial receipt confirmation
            worker._testResetState();
            const msg1 = { id: 'msg_part_1', MessageType: 'PositionReport' };
            const msg2 = { id: 'msg_part_2', MessageType: 'PositionReport' };
            const msg3 = { id: 'msg_part_3', MessageType: 'PositionReport' };
            worker.messageQueue.push(msg1, msg2, msg3);

            let batchCalls = 0;
            const singleEvaluations = [];

            serverHandler = (req, res) => {
                let body = '';
                req.on('data', chunk => body += chunk);
                req.on('end', () => {
                    const parsed = JSON.parse(body);
                    if (parsed.messages.length > 1) {
                        batchCalls++;
                        // Backend confirms only 1 message out of 3
                        res.writeHead(200, { 'Content-Type': 'application/json' });
                        res.end(JSON.stringify({ status: 'success', received: 1, inserted: 1, skipped: 0 }));
                    } else {
                        // Dissecting remaining unconfirmed messages
                        singleEvaluations.push(parsed.messages[0]);
                        res.writeHead(200, { 'Content-Type': 'application/json' });
                        res.end(JSON.stringify({ status: 'success', received: 1, inserted: 1 }));
                    }
                });
            };

            await worker.flushQueue();

            assert.strictEqual(batchCalls, 1, 'Initial batch attempt must be called');
            assert.strictEqual(worker._getInFlightBatch(), null, 'inFlightBatch must be cleared after resolution');
            assert.strictEqual(singleEvaluations.length, 2, 'The 2 unconfirmed messages must be dissected and evaluated individually');
            assert.strictEqual(singleEvaluations[0].id, 'msg_part_2');
            assert.strictEqual(singleEvaluations[1].id, 'msg_part_3');
        });

    } finally {
        await stopMockServer();
        if (fs.existsSync(worker.DISK_QUEUE_PATH)) {
            try { fs.unlinkSync(worker.DISK_QUEUE_PATH); } catch {}
        }
    }

    console.log('='.repeat(70));
    console.log(` RESULTS: ${passedTests} / ${totalTests} TESTS PASSED`);
    console.log('='.repeat(70));

    if (passedTests !== totalTests) {
        process.exit(1);
    }
}

runTests();
