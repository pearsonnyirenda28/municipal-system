/**
 * assets/js/offline-queue.js
 * Version 3.5 - Intelligent Offline Synchronization.
 *
 * A single IndexedDB queue shared by every offline-capable action in the
 * app (meter readings, visit status marks, fine issuing). Each queued
 * record carries a UUID (clientRef), device ID, user ID, timestamp,
 * sync status, retry count and last error.
 *
 * syncOfflineQueue() walks every unsynced record, POSTs it to its API
 * endpoint, and marks it synced on success. It returns
 * { synced, failed, skipped, errors } so callers can show a real result
 * to the user rather than silently doing nothing.
 */

const QUEUE_DB_NAME = 'offlineQueueDB';
const QUEUE_STORE_NAME = 'queue';

function openQueueDb() {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(QUEUE_DB_NAME, 1);
        request.onupgradeneeded = (event) => {
            const db = event.target.result;
            if (!db.objectStoreNames.contains(QUEUE_STORE_NAME)) {
                const store = db.createObjectStore(QUEUE_STORE_NAME, { keyPath: 'clientRef' });
                store.createIndex('synced', 'synced', { unique: false });
                store.createIndex('type', 'type', { unique: false });
            }
        };
        request.onsuccess = (event) => resolve(event.target.result);
        request.onerror = (event) => reject(event.target.error);
    });
}

/** Persistent per-device identifier - survives across sessions/tabs on
 *  the same device. */
function getDeviceId() {
    let id = localStorage.getItem('deviceId');
    if (!id) {
        id = 'dev-' + (crypto.randomUUID ? crypto.randomUUID() : (Date.now() + '-' + Math.random().toString(36).slice(2)));
        localStorage.setItem('deviceId', id);
    }
    return id;
}

function makeClientRef() {
    return crypto.randomUUID ? crypto.randomUUID() : ('cr-' + Date.now() + '-' + Math.random().toString(36).slice(2, 10));
}

/**
 * Adds a record to the queue. `type` identifies what kind of record it
 * is ('reading', 'visitStatus', 'fine'). `payload` is whatever that
 * endpoint needs - opaque to the queue itself, except that if `payload`
 * already has its own `clientRef`, that same value is reused as the
 * queue's key too, rather than generating a second, unrelated UUID.
 */
async function queueRecord(type, payload, userId) {
    const record = {
        clientRef: payload.clientRef || makeClientRef(),
        type,
        payload,
        deviceId: getDeviceId(),
        userId,
        createdAt: new Date().toISOString(),
        synced: false,
        retryCount: 0,
        lastError: null,
    };
    const db = await openQueueDb();
    return new Promise((resolve, reject) => {
        const tx = db.transaction(QUEUE_STORE_NAME, 'readwrite');
        tx.objectStore(QUEUE_STORE_NAME).put(record);
        tx.oncomplete = () => resolve(record);
        tx.onerror = () => reject(tx.error);
    });
}

async function getUnsyncedRecords(type = null) {
    const db = await openQueueDb();
    return new Promise((resolve, reject) => {
        const tx = db.transaction(QUEUE_STORE_NAME, 'readonly');
        const request = tx.objectStore(QUEUE_STORE_NAME).getAll();
        request.onsuccess = () => {
            let records = request.result.filter(r => !r.synced);
            if (type) records = records.filter(r => r.type === type);
            resolve(records);
        };
        request.onerror = () => reject(request.error);
    });
}

async function countPending(type = null) {
    const pending = await getUnsyncedRecords(type);
    return pending.length;
}

async function markQueueSynced(clientRef) {
    const db = await openQueueDb();
    return new Promise((resolve, reject) => {
        const tx = db.transaction(QUEUE_STORE_NAME, 'readwrite');
        const store = tx.objectStore(QUEUE_STORE_NAME);
        const getReq = store.get(clientRef);
        getReq.onsuccess = () => {
            const record = getReq.result;
            if (record) {
                record.synced = true;
                store.put(record);
            }
        };
        tx.oncomplete = () => resolve();
        tx.onerror = () => reject(tx.error);
    });
}

/** Bumps the retry count and records the last error, so a record that
 *  keeps failing to sync is visible rather than silently retried forever. */
async function recordSyncFailure(clientRef, errorMessage) {
    const db = await openQueueDb();
    return new Promise((resolve, reject) => {
        const tx = db.transaction(QUEUE_STORE_NAME, 'readwrite');
        const store = tx.objectStore(QUEUE_STORE_NAME);
        const getReq = store.get(clientRef);
        getReq.onsuccess = () => {
            const record = getReq.result;
            if (record) {
                record.retryCount = (record.retryCount || 0) + 1;
                record.lastError = errorMessage;
                store.put(record);
            }
        };
        tx.oncomplete = () => resolve();
        tx.onerror = () => reject(tx.error);
    });
}

/** Server-side endpoint each queue "type" syncs against. */
const SYNC_ENDPOINTS = {
    reading: '/municipal-system/api/save_reading.php',
    visitStatus: '/municipal-system/api/mark_visit.php',
    fine: '/municipal-system/api/save_fine.php',
};

const MAX_SYNC_RETRIES = 5;

/**
 * Walks every unsynced record (any type), POSTs it to its endpoint, and
 * marks it synced on success. Stops retrying an individual record after
 * MAX_SYNC_RETRIES failures rather than hammering the server forever,
 * but keeps trying the others - one bad record shouldn't block the rest
 * of the queue.
 *
 * Returns { synced, failed, skipped, errors[] } so the caller can show
 * a real result. Every failure is also logged to the browser console
 * with the exact HTTP status and server message.
 */
async function syncOfflineQueue() {
    const result = { synced: 0, failed: 0, skipped: 0, errors: [] };

    if (!navigator.onLine) {
        result.errors.push('Browser reports offline - sync skipped.');
        return result;
    }

    // If the page didn't set a CSRF token, every POST will be rejected
    // with 403 by the server. Better to say so up front than to have it
    // look like a mysterious failure halfway through the queue.
    if (!window.CSRF_TOKEN) {
        result.errors.push('CSRF token missing on this page - sync would be rejected by the server. Reload the page.');
        console.error('[sync] window.CSRF_TOKEN is not set. The page did not provide a CSRF token to the JS layer.');
        return result;
    }

    const pending = await getUnsyncedRecords();

    for (const record of pending) {
        if ((record.retryCount || 0) >= MAX_SYNC_RETRIES) {
            result.skipped++;
            result.errors.push(`${record.type} ${record.clientRef} skipped - retried ${record.retryCount} times.`);
            console.warn('[sync] skipping record after max retries:', record.clientRef, record.lastError);
            continue;
        }

        const endpoint = SYNC_ENDPOINTS[record.type];
        if (!endpoint) {
            console.warn('[sync] unknown record type, skipping:', record.type);
            continue;
        }

        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': window.CSRF_TOKEN,
                },
                credentials: 'same-origin',
                body: JSON.stringify(record.payload),
            });

            let body = {};
            try { body = await response.json(); } catch (_) { /* non-JSON response */ }

            if (response.ok && body.success) {
                await markQueueSynced(record.clientRef);
                result.synced++;
                console.log('[sync]', record.type, record.clientRef, '-> synced');
            } else {
                const msg = body.message || `HTTP ${response.status}`;
                await recordSyncFailure(record.clientRef, msg);
                result.failed++;
                result.errors.push(`${record.type}: ${msg}`);
                console.warn('[sync]', record.type, record.clientRef, '-> rejected:', msg);
            }
        } catch (err) {
            // Network-level failure (server unreachable, DNS, abort).
            await recordSyncFailure(record.clientRef, err.message);
            result.failed++;
            result.errors.push(`${record.type}: ${err.message}`);
            console.error('[sync]', record.type, record.clientRef, '-> error:', err);
            break; // connection likely dropped mid-sync - stop for now
        }
    }

    return result;
}