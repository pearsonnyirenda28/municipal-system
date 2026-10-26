/**
 * assets/js/offline-db-sqlite.js
 * Version 4.0 - SQLite-backed offline queue (WASM).
 *
 * Drop-in replacement for offline-queue.js. Same public API, so callers
 * (meter-reading.js, fine-issue.js, status-actions.js, dashboard.php)
 * do not change. The difference is the storage engine:
 *
 *   Before: IndexedDB (browser's built-in key-value store)
 *   Now:    SQLite compiled to WebAssembly, persisted as a binary blob
 *           inside IndexedDB so it survives page reloads.
 *
 * Schema:
 *   CREATE TABLE queue (
 *       client_ref  TEXT PRIMARY KEY,
 *       type        TEXT NOT NULL,
 *       payload     TEXT NOT NULL,     -- JSON
 *       device_id   TEXT NOT NULL,
 *       user_id     INTEGER,
 *       created_at  TEXT NOT NULL,
 *       synced      INTEGER DEFAULT 0,
 *       retry_count INTEGER DEFAULT 0,
 *       last_error  TEXT
 *   );
 */

const SQLITE_DB_KEY   = 'municipal-offline-sqlite-v1';  // IndexedDB key for the blob
const SQLITE_IDB_NAME = 'municipalSQLiteStore';
const SQLITE_IDB_STORE = 'blobs';
const SQLITE_SAVE_DEBOUNCE_MS = 400;

let __sqliteReady = null;   // Promise<SQL.Database>
let __saveTimer = null;
let __savePending = false;

// ── Load and initialise SQLite ─────────────────────────────────────
function getDb() {
    if (__sqliteReady) return __sqliteReady;

    __sqliteReady = (async () => {
        if (typeof initSqlJs !== 'function') {
            throw new Error('sql-wasm.js not loaded — check the <script> tag');
        }

        const SQL = await initSqlJs({
            locateFile: (file) => `/municipal-system/assets/vendor/${file}`,
        });

        // Try to restore a previously saved database from IndexedDB
        const saved = await loadBlobFromIdb();
        const db = saved ? new SQL.Database(saved) : new SQL.Database();

        db.run(`
            CREATE TABLE IF NOT EXISTS queue (
                client_ref  TEXT PRIMARY KEY,
                type        TEXT NOT NULL,
                payload     TEXT NOT NULL,
                device_id   TEXT NOT NULL,
                user_id     INTEGER,
                created_at  TEXT NOT NULL,
                synced      INTEGER DEFAULT 0,
                retry_count INTEGER DEFAULT 0,
                last_error  TEXT
            );
            CREATE INDEX IF NOT EXISTS idx_queue_synced ON queue(synced);
            CREATE INDEX IF NOT EXISTS idx_queue_type   ON queue(type);
        `);

        return db;
    })();

    return __sqliteReady;
}

// ── IndexedDB persistence layer for the SQLite blob ────────────────
function openIdb() {
    return new Promise((resolve, reject) => {
        const req = indexedDB.open(SQLITE_IDB_NAME, 1);
        req.onupgradeneeded = () => {
            const db = req.result;
            if (!db.objectStoreNames.contains(SQLITE_IDB_STORE)) {
                db.createObjectStore(SQLITE_IDB_STORE);
            }
        };
        req.onsuccess = () => resolve(req.result);
        req.onerror   = () => reject(req.error);
    });
}

function loadBlobFromIdb() {
    return openIdb().then((idb) => new Promise((resolve, reject) => {
        const tx = idb.transaction(SQLITE_IDB_STORE, 'readonly');
        const req = tx.objectStore(SQLITE_IDB_STORE).get(SQLITE_DB_KEY);
        req.onsuccess = () => resolve(req.result || null);
        req.onerror   = () => reject(req.error);
    }));
}

function saveBlobToIdb(bytes) {
    return openIdb().then((idb) => new Promise((resolve, reject) => {
        const tx = idb.transaction(SQLITE_IDB_STORE, 'readwrite');
        tx.objectStore(SQLITE_IDB_STORE).put(bytes, SQLITE_DB_KEY);
        tx.oncomplete = () => resolve();
        tx.onerror    = () => reject(tx.error);
    }));
}

/**
 * Persist the SQLite database back to IndexedDB. Debounced so that
 * rapid writes (e.g. syncing 20 records at once) don't serialize the
 * whole DB 20 times.
 */
function persist(db) {
    __savePending = true;
    if (__saveTimer) return;
    __saveTimer = setTimeout(async () => {
        __saveTimer = null;
        if (!__savePending) return;
        __savePending = false;
        const bytes = db.export();     // Uint8Array
        try { await saveBlobToIdb(bytes); }
        catch (e) { console.warn('SQLite persist failed:', e); }
    }, SQLITE_SAVE_DEBOUNCE_MS);
}

// ── Public API — same names as offline-queue.js ────────────────────

function makeClientRef() {
    return crypto.randomUUID
        ? crypto.randomUUID()
        : ('cr-' + Date.now() + '-' + Math.random().toString(36).slice(2, 10));
}

function getDeviceId() {
    let id = localStorage.getItem('deviceId');
    if (!id) {
        id = 'dev-' + (crypto.randomUUID
            ? crypto.randomUUID()
            : (Date.now() + '-' + Math.random().toString(36).slice(2)));
        localStorage.setItem('deviceId', id);
    }
    return id;
}

async function queueRecord(type, payload, userId) {
    const db = await getDb();
    const clientRef = payload.clientRef || makeClientRef();
    const deviceId  = getDeviceId();
    const createdAt = new Date().toISOString();

    db.run(
        `INSERT OR REPLACE INTO queue
         (client_ref, type, payload, device_id, user_id, created_at, synced, retry_count, last_error)
         VALUES (?, ?, ?, ?, ?, ?, 0, 0, NULL)`,
        [clientRef, type, JSON.stringify(payload), deviceId, userId || null, createdAt]
    );
    persist(db);

    return {
        clientRef, type, payload, deviceId,
        userId, createdAt, synced: false, retryCount: 0, lastError: null,
    };
}

async function getUnsyncedRecords(type = null) {
    const db = await getDb();
    const sql = type
        ? 'SELECT * FROM queue WHERE synced = 0 AND type = ? ORDER BY created_at ASC'
        : 'SELECT * FROM queue WHERE synced = 0 ORDER BY created_at ASC';
    const stmt = db.prepare(sql);
    stmt.bind(type ? [type] : []);
    const rows = [];
    while (stmt.step()) {
        const r = stmt.getAsObject();
        rows.push({
            clientRef:  r.client_ref,
            type:       r.type,
            payload:    JSON.parse(r.payload),
            deviceId:   r.device_id,
            userId:     r.user_id,
            createdAt:  r.created_at,
            synced:     r.synced === 1,
            retryCount: r.retry_count,
            lastError:  r.last_error,
        });
    }
    stmt.free();
    return rows;
}

// The id of whoever is logged in on this page (set from the page's own <meta> tag by
// connectivity.js, or inline by the page itself).
function currentUserId() {
    const id = Number(window.CURRENT_USER_ID);
    return Number.isFinite(id) && id > 0 ? id : null;
}

// Records saved by SOMEONE ELSE on this shared device. They stay in the queue, untouched,
// and are sent when that person logs in - never attributed to whoever is logged in now.
function belongsToOtherUser(record) {
    const me = currentUserId();
    return me !== null && record.userId != null && Number(record.userId) !== me;
}

// Counts the records that will be sent for the CURRENT user.
async function countPending(type = null) {
    const db = await getDb();
    const me = currentUserId();
    let sql = 'SELECT COUNT(*) AS n FROM queue WHERE synced = 0';
    const params = [];
    if (type) { sql += ' AND type = ?'; params.push(type); }
    if (me !== null) { sql += ' AND (user_id IS NULL OR user_id = ?)'; params.push(me); }
    const stmt = db.prepare(sql);
    stmt.bind(params);
    stmt.step();
    const n = stmt.getAsObject().n;
    stmt.free();
    return n;
}

async function markQueueSynced(clientRef) {
    const db = await getDb();
    db.run('UPDATE queue SET synced = 1 WHERE client_ref = ?', [clientRef]);
    persist(db);
}

async function recordSyncFailure(clientRef, errorMessage) {
    const db = await getDb();
    db.run(
        'UPDATE queue SET retry_count = retry_count + 1, last_error = ? WHERE client_ref = ?',
        [errorMessage || 'Unknown error', clientRef]
    );
    persist(db);
}

// ── Sync engine (identical logic to offline-queue.js) ──────────────
const SYNC_ENDPOINTS = {
    reading:     '/municipal-system/api/save_reading.php',
    visitStatus: '/municipal-system/api/mark_visit.php',
    fine:        '/municipal-system/api/save_fine.php',
};

const MAX_SYNC_RETRIES = 5;

/**
 * Drains every queued record to its endpoint. Version 4.5: checks the
 * real reachability status from connectivity.js (a periodic ping of
 * THIS server, see assets/js/connectivity.js) rather than
 * navigator.onLine, which only reports whether the device has *some*
 * network interface up - true on a captive portal or a LAN whose
 * Apache/MySQL is down, both cases where this fetch would just hang
 * or fail anyway. abnormalReadings collects any reading the server
 * flags as unusually high so callers can surface it - that check only
 * happens server-side (it compares against this house's recent
 * average), so under local-first saving it's necessarily known after
 * the fact, once the record actually reaches the server, not at the
 * moment it's queued.
 */
// Announces every finished sync pass so pages can refresh their "pending" counters,
// whoever started the sync (a page button, or connectivity.js on reconnect).
async function syncOfflineQueue() {
    const result = await _syncOfflineQueueInner();
    try { window.dispatchEvent(new CustomEvent('queuesynced', { detail: result })); } catch (e) { /* non-fatal */ }
    return result;
}

// Earlier versions counted a 401/403 caused by an ENDED SESSION as an ordinary failed
// attempt for every pending record, so after MAX_SYNC_RETRIES passes the push loop skipped
// them for good. Give those records another chance, once per page load.
let _rescuedAuthStranded = false;
async function rescueAuthStrandedRecords() {
    if (_rescuedAuthStranded) return;
    _rescuedAuthStranded = true;
    const db = await getDb();
    db.run(
        `UPDATE queue SET retry_count = 0, last_error = NULL
          WHERE synced = 0 AND retry_count > 0
            AND (last_error LIKE '%Not logged in%' OR last_error LIKE '%could not be verified%'
              OR last_error LIKE '%session has ended%' OR last_error LIKE '%Server returned 401%'
              OR last_error LIKE '%Server returned 403%' OR last_error LIKE '%Unexpected token%'
              OR last_error LIKE '%is not valid JSON%')`
    );
    persist(db);
}

async function _syncOfflineQueueInner() {
    const reachable = typeof window.getConnectivityStatus === 'function'
        ? window.getConnectivityStatus() === 'reachable'
        : navigator.onLine;
    if (!reachable) return { synced: 0, failed: 0, abnormalReadings: [], duplicateFines: [], sessionExpired: false, otherUsers: 0 };

    await rescueAuthStrandedRecords();

    const pending = await getUnsyncedRecords();
    let synced = 0, failed = 0, otherUsers = 0, sessionExpired = false;
    const abnormalReadings = [];
    const duplicateFines = [];

    for (const record of pending) {
        if (belongsToOtherUser(record)) { otherUsers++; continue; }
        if (record.retryCount >= MAX_SYNC_RETRIES) { failed++; continue; }
        const endpoint = SYNC_ENDPOINTS[record.type];
        if (!endpoint) continue;

        try {
            const headers = {
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.CSRF_TOKEN,
            };
            // Who saved this record on the device - the server refuses it if that is not
            // the user now logged in (shared computers).
            if (record.userId != null) headers['X-Queued-By'] = String(record.userId);

            const response = await fetch(endpoint, {
                method: 'POST',
                headers,
                credentials: 'same-origin',
                body: JSON.stringify(record.payload),
            });
            let result = null;
            try { result = await response.json(); } catch (e) { /* not JSON - handled below */ }
            const code = result && result.code;

            // An ENDED SESSION (idle timeout, deactivated account, changed password) is not
            // this record's fault. Stop the pass, count no failed attempt, keep every record,
            // and let the page tell the user to log in again.
            if (response.status === 401 || code === 'SESSION_EXPIRED' || code === 'CSRF'
                || (response.redirected && /login\.php/.test(response.url))) {
                sessionExpired = true;
                break;
            }
            // Someone else's record on a shared device: leave it queued for them.
            if (response.status === 409 && code === 'WRONG_USER') { otherUsers++; continue; }
            if (result === null) throw new Error(`Server returned ${response.status} (not JSON)`);

            if (response.ok && result.success) {
                await markQueueSynced(record.clientRef);
                synced++;
                if (record.type === 'reading' && result.abnormal) {
                    abnormalReadings.push(record.payload.houseNumber || record.payload.houseId);
                }
                if (record.type === 'fine' && result.duplicate_flagged) {
                    duplicateFines.push(result.fine_number || record.payload.offenderName);
                }
            } else {
                await recordSyncFailure(record.clientRef,
                    result.message || `Server returned ${response.status}`);
                failed++;
            }
        } catch (err) {
            await recordSyncFailure(record.clientRef, err.message);
            failed++;
            break;
        }
    }
    return { synced, failed, abnormalReadings, duplicateFines, sessionExpired, otherUsers };
}