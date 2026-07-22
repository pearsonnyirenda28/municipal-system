/**
 * assets/js/offline-db.js
 * Thin wrapper around IndexedDB for storing meter readings captured while
 * offline (Activity 4, Section 3.3 - "the browser's built-in IndexedDB").
 */

const DB_NAME = 'meterReadingsDB';
const STORE_NAME = 'readings';

function openOfflineDb() {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, 1);

        request.onupgradeneeded = (event) => {
            const db = event.target.result;
            if (!db.objectStoreNames.contains(STORE_NAME)) {
                const store = db.createObjectStore(STORE_NAME, { keyPath: 'clientRef' });
                store.createIndex('synced', 'synced', { unique: false });
            }
        };
        request.onsuccess = (event) => resolve(event.target.result);
        request.onerror = (event) => reject(event.target.error);
    });
}

async function saveOfflineReading(record) {
    const db = await openOfflineDb();
    return new Promise((resolve, reject) => {
        const tx = db.transaction(STORE_NAME, 'readwrite');
        tx.objectStore(STORE_NAME).put(record);
        tx.oncomplete = () => resolve(record);
        tx.onerror = () => reject(tx.error);
    });
}

async function getUnsyncedReadings() {
    const db = await openOfflineDb();
    return new Promise((resolve, reject) => {
        const tx = db.transaction(STORE_NAME, 'readonly');
        const request = tx.objectStore(STORE_NAME).getAll();
        request.onsuccess = () => resolve(request.result.filter(r => !r.synced));
        request.onerror = () => reject(request.error);
    });
}

async function markReadingSynced(clientRef) {
    const db = await openOfflineDb();
    return new Promise((resolve, reject) => {
        const tx = db.transaction(STORE_NAME, 'readwrite');
        const store = tx.objectStore(STORE_NAME);
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

async function countPendingReadings() {
    const pending = await getUnsyncedReadings();
    return pending.length;
}

function makeClientRef() {
    return 'rd-' + Date.now() + '-' + Math.random().toString(36).slice(2, 8);
}
