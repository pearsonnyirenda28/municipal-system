<?php
/**
 * includes/local_store.php
 * Workstation-tier local-first sync layer (Version 4.5).
 *
 * getLocalDb() is an explicit alias for getDb() (includes/db.php): on
 * a field workstation (DB_DRIVER=sqlite, the default) that connection
 * already IS this workstation's "Local SQLite" - every page already
 * reads and writes it directly. This file adds exactly one thing on
 * top of that: an outbox (LocalSyncQueue) that api/save_reading.php,
 * api/save_fine.php and api/mark_visit.php write to, in the same
 * transaction as their normal insert/update, so cli_sync_engine.php
 * has something to push to the central Workspace Server.
 *
 * (An earlier version of this file opened a SECOND, separate SQLite
 * file - data/municipal_local.sqlite - with its own shadow copies of
 * MeterReadings/Fines/HouseVisits. Nothing in the app ever wrote to
 * that copy, so it never held real data and the page that displayed
 * it (meter_readings.php) was always empty. Version 4.5 removes that
 * split: there is one local database per workstation, the same one
 * every other page already uses - see schema.sql for the sync_id /
 * device_id / sync_status / synced_at columns added directly to the
 * real tables, and the LocalSyncQueue / SyncEngineState tables.)
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** The workstation's local database - see the file header above. */
function getLocalDb(): PDO
{
    return getDb();
}

/**
 * Generates a pseudo-random UUID v4 string for globally unique sync IDs.
 */
function generateSyncId(): string
{
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * A row's sync_id is its idempotency key against the central Workspace
 * Server. When the browser's offline queue already gave this row a
 * client_ref, that's the natural choice - it's already unique, and it
 * means the row's identity is the same string end-to-end (browser to
 * workstation to central MySQL) instead of two unrelated tokens.
 * Otherwise (JS disabled, or a write path that doesn't queue) there is
 * no client_ref yet, so generate a fresh sync_id here.
 */
function resolveSyncId(?string $clientRef = null): string
{
    $clientRef = trim((string) $clientRef);
    return $clientRef !== '' ? $clientRef : generateSyncId();
}

/**
 * Queues one row for the central Workspace Server. Call this between
 * $db->beginTransaction() and $db->commit(), right after the
 * INSERT/UPDATE it's queuing for - if the transaction rolls back, the
 * queue entry rolls back with it, so a row can never exist locally
 * without being queued, or be queued without existing (principle:
 * transactional integrity).
 *
 * sync_id and device_id are set here (not left to the caller) so
 * every queued row is guaranteed to carry both correctly.
 *
 * @param PDO    $db        The same connection/transaction as the caller.
 * @param string $syncId    From resolveSyncId().
 * @param string $tableName One of the tables api/sync_push.php recognises (MeterReadings, Fines, HouseVisits).
 * @param string $operation 'INSERT' for append-only rows, 'UPSERT' for rows that get updated in place (HouseVisits).
 * @param array  $data      The row's syncable fields, central-table column names => values.
 */
function enqueueForCentralSync(PDO $db, string $syncId, string $tableName, string $operation, array $data): void
{
    $data['sync_id'] = $syncId;
    $data['device_id'] = DEVICE_ID;

    $stmt = $db->prepare(
        "INSERT INTO LocalSyncQueue (sync_id, table_name, operation, payload, status, created_at, updated_at)
         VALUES (:sync_id, :table_name, :operation, :payload, 'PENDING', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
    );
    $stmt->execute([
        'sync_id'    => $syncId,
        'table_name' => $tableName,
        'operation'  => $operation,
        'payload'    => json_encode($data, JSON_THROW_ON_ERROR),
    ]);
}

/**
 * How many rows on this workstation are still waiting to reach the
 * central Workspace Server (queued or currently mid-push).
 */
function countPendingCentralSync(PDO $db): int
{
    return (int) $db->query("SELECT COUNT(*) FROM LocalSyncQueue WHERE status IN ('PENDING','SYNCING')")->fetchColumn();
}

// ── SyncEngineState helpers ─────────────────────────────────────────
//
// cli_sync_engine.php runs as a separate OS process from the web
// server, so it can't just hold its status in a PHP variable -
// anything asking "is this workstation currently synchronized with
// the Workspace Server?" (sync/status.php today, potentially a
// dashboard widget later) needs to read it back from somewhere.
// SyncEngineState is a single row (id=1) the engine keeps current.
//
// States (the network-abstraction principle, applied at the
// workstation <-> Workspace Server tier - connectivity.js applies the
// same vocabulary one level up, at the browser <-> workstation tier):
//   WORKSPACE_UNAVAILABLE - CENTRAL_SERVER_URL isn't reachable right now
//   SYNCING                - a push or pull is in flight
//   SYNCHRONIZED            - reachable, and the queue is fully drained
//   ONLINE                  - reachable, but records are still pending
//   SYNC_ERROR              - reachable, but the server rejected the last attempt

const SYNC_ENGINE_STATES = ['WORKSPACE_UNAVAILABLE', 'ONLINE', 'SYNCING', 'SYNCHRONIZED', 'SYNC_ERROR'];

/**
 * @param array $fields Any of last_push_attempt_at / last_push_success_at /
 *                       last_pull_attempt_at / last_pull_success_at / last_error.
 *                       Pass CURRENT_TIMESTAMP-style values as PHP date('Y-m-d H:i:s') strings.
 */
function setSyncEngineState(PDO $db, string $state, array $fields = []): void
{
    if (!in_array($state, SYNC_ENGINE_STATES, true)) {
        throw new InvalidArgumentException("Unknown sync engine state: {$state}");
    }
    $allowedFields = ['last_push_attempt_at', 'last_push_success_at', 'last_pull_attempt_at', 'last_pull_success_at', 'last_error'];

    $set = ['state = :state', 'updated_at = CURRENT_TIMESTAMP'];
    $params = ['state' => $state];
    foreach ($fields as $key => $value) {
        if (!in_array($key, $allowedFields, true)) {
            continue;
        }
        $set[] = "{$key} = :{$key}";
        $params[$key] = $value;
    }

    $db->prepare('UPDATE SyncEngineState SET ' . implode(', ', $set) . ' WHERE id = 1')->execute($params);
}

function getSyncEngineState(PDO $db): array
{
    $row = $db->query('SELECT * FROM SyncEngineState WHERE id = 1')->fetch();
    return $row ?: ['state' => 'WORKSPACE_UNAVAILABLE'];
}

/**
 * Heuristic for sync/status.php: the engine touches SyncEngineState.
 * updated_at on every poll (SYNC_POLL_SECONDS, default 5s) regardless
 * of whether there was anything to push - a stale timestamp beyond a
 * couple of minutes means the process itself isn't running (crashed,
 * never started, machine rebooted without the service re-registering),
 * as distinct from it running but simply unable to reach the
 * Workspace Server (that's WORKSPACE_UNAVAILABLE, a state the row
 * would still be getting updated to).
 */
function isSyncEngineRunning(PDO $db): bool
{
    $updatedAt = $db->query('SELECT updated_at FROM SyncEngineState WHERE id = 1')->fetchColumn();
    if (!$updatedAt) {
        return false;
    }
    return (time() - strtotime($updatedAt)) < 120;
}
