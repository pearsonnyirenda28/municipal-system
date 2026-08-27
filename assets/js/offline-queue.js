/**
 * assets/js/offline-queue.js
 * Version 3.5 - Intelligent Offline Synchronization.
 *
 * A single IndexedDB queue shared by every offline-capable action in the
 * app (meter readings, visit status marks, fine issuing - see README for
 * what's covered and what isn't yet). Each queued record carries exactly
 * what the roadmap asked for: a UUID (clientRef), device ID, user ID,
 * timestamp, sync status, and a retry count.
 *
 * This supersedes the old meter-reading-only offline-db.js. Existing
 * records saved under the old "meterReadingsDB" database are left alone
 * on the device (harmless, just unused) - nothing reads or writes that
 * database any more.
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
 *  the same device, so a supervisor reviewing sync activity can tell
 *  "which tablet did this come from" apart from "which user". */
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
 * is ('reading', 'visitStatus', 'fine') so a shared "Sync Now" can route
 * each one to the right API endpoint. `payload` is whatever that
 * endpoint needs - opaque to the queue itself, except that if `payload`
 * already has its own `clientRef` (used for server-side idempotency,
 * e.g. api/save_reading.php's duplicate check), that same value is
 * reused as the queue's own key too, rather than generating a second,
 * unrelated UUID.
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
 */
async function syncOfflineQueue() {
    if (!navigator.onLine) return { synced: 0, failed: 0 };

    const pending = await getUnsyncedRecords();
    let synced = 0, failed = 0;

    for (const record of pending) {
        if (record.retryCount >= MAX_SYNC_RETRIES) {
            failed++;
            continue;
        }
        const endpoint = SYNC_ENDPOINTS[record.type];
        if (!endpoint) {
            continue; // unknown type - shouldn't happen, but don't crash the loop
        }
        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN },
                credentials: 'same-origin',
                body: JSON.stringify(record.payload),
            });
            const result = await response.json();
            if (response.ok && result.success) {
                await markQueueSynced(record.clientRef);
                synced++;
            } else {
                await recordSyncFailure(record.clientRef, result.message || `Server returned ${response.status}`);
                failed++;
            }
        } catch (err) {
            await recordSyncFailure(record.clientRef, err.message);
            failed++;
            break; // connection likely dropped again mid-sync - stop for now
        }
    }

    return { synced, failed };
}
