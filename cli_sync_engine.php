<?php
/**
 * cli_sync_engine.php
 * Version 4.5. Background worker for a field workstation: drains
 * LocalSyncQueue to the central Workspace Server over the LAN (push)
 * and keeps Users/Wards/Houses/WardAssignments current locally (pull -
 * principle: master data flows MySQL -> SQLite). Run this as a
 * persistent background process, not by hand in a terminal someone has
 * to leave open - see README.md, "Running the sync engine", for a
 * systemd unit (Linux) and Task Scheduler / NSSM setup (Windows, the
 * more likely case given this app targets XAMPP).
 *
 * A single, deliberately simple loop rather than pcntl-based signal
 * handling for graceful shutdown: pcntl doesn't exist on Windows PHP
 * builds at all, which is where this is most likely to actually run.
 * Instead, correctness under an abrupt kill (closing the console,
 * `taskkill`, a service restart) comes from two things: every batch is
 * small and bounded, and on startup this script resets any queue rows
 * still stuck in SYNCING back to PENDING - the only way a row can be
 * in that state when a fresh instance starts is that the previous
 * instance died mid-batch, after marking it SYNCING but before hearing
 * back from the Workspace Server.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("cli_sync_engine.php must be run from the command line, e.g.: php cli_sync_engine.php\n");
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/local_store.php';

function syncLog(string $level, string $message): void
{
    echo '[' . date('Y-m-d H:i:s') . "] {$level}: {$message}\n";
    writeErrorLog($level, "[sync_engine] {$message}");
}

/** A cheap reachability check, reusing the same no-auth endpoint connectivity.js polls from the browser tier. */
function isWorkspaceReachable(): bool
{
    if (CENTRAL_SERVER_URL === '') {
        return false;
    }
    $ch = curl_init(rtrim(CENTRAL_SERVER_URL, '/') . '/api/ping.php');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $response !== false && $httpCode === 200;
}

/**
 * Pushes up to SYNC_BATCH_SIZE pending LocalSyncQueue rows to
 * api/sync_push.php and applies the per-record results back locally.
 */
function runPushCycle(PDO $localDb): void
{
    $stmt = $localDb->prepare("SELECT * FROM LocalSyncQueue WHERE status = 'PENDING' ORDER BY queue_id ASC LIMIT :limit");
    $stmt->bindValue(':limit', SYNC_BATCH_SIZE, PDO::PARAM_INT);
    $stmt->execute();
    $pendingItems = $stmt->fetchAll();

    if (!$pendingItems) {
        // Nothing to push right now, but still worth confirming
        // reachability so the status shown on sync/status.php reflects
        // reality even when this workstation has been idle a while.
        setSyncEngineState($localDb, isWorkspaceReachable() ? 'SYNCHRONIZED' : 'WORKSPACE_UNAVAILABLE', [
            'last_push_attempt_at' => date('Y-m-d H:i:s'),
        ]);
        return;
    }

    $batchRecords = [];
    $syncIds = [];
    foreach ($pendingItems as $item) {
        $syncIds[] = $item['sync_id'];
        $batchRecords[] = [
            'sync_id'    => $item['sync_id'],
            'table_name' => $item['table_name'],
            'operation'  => $item['operation'],
            'data'       => json_decode($item['payload'], true, 512, JSON_THROW_ON_ERROR),
        ];
    }
    $inClause = implode(',', array_fill(0, count($syncIds), '?'));

    $localDb->prepare("UPDATE LocalSyncQueue SET status = 'SYNCING', updated_at = CURRENT_TIMESTAMP WHERE sync_id IN ({$inClause})")->execute($syncIds);
    setSyncEngineState($localDb, 'SYNCING', ['last_push_attempt_at' => date('Y-m-d H:i:s')]);

    $payload = json_encode(['device_id' => DEVICE_ID, 'records' => $batchRecords], JSON_THROW_ON_ERROR);

    $ch = curl_init(rtrim(CENTRAL_SERVER_URL, '/') . '/api/sync_push.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-Sync-Key: ' . SYNC_API_KEY],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    // Transport failure, or the server rejected the whole batch (bad
    // auth, 5xx, ...): put everything back to PENDING for the next
    // poll rather than SYNC_ERROR - a network hiccup isn't the server
    // having looked at and rejected the data, which is a different,
    // per-record case handled below once we DO get a real response.
    if ($response === false || $httpCode !== 200) {
        $localDb->prepare("UPDATE LocalSyncQueue SET status = 'PENDING', attempts = attempts + 1, error_message = ?, updated_at = CURRENT_TIMESTAMP WHERE sync_id IN ({$inClause})")
                ->execute(array_merge([$curlError ?: "Workspace Server returned HTTP {$httpCode}"], $syncIds));
        setSyncEngineState($localDb, 'WORKSPACE_UNAVAILABLE', ['last_error' => $curlError ?: "HTTP {$httpCode}"]);
        syncLog('WARN', 'Push batch of ' . count($pendingItems) . ' failed: ' . ($curlError ?: "HTTP {$httpCode}"));
        return;
    }

    try {
        $responseData = json_decode((string) $response, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        $responseData = null;
    }
    if (empty($responseData['results']) || !is_array($responseData['results'])) {
        $localDb->prepare("UPDATE LocalSyncQueue SET status = 'PENDING', attempts = attempts + 1, error_message = 'Malformed response from Workspace Server', updated_at = CURRENT_TIMESTAMP WHERE sync_id IN ({$inClause})")->execute($syncIds);
        setSyncEngineState($localDb, 'SYNC_ERROR', ['last_error' => 'Malformed response from Workspace Server']);
        syncLog('WARN', 'Push batch got an unreadable response from the Workspace Server.');
        return;
    }

    $localDb->beginTransaction();
    $anyRejected = false;
    foreach ($responseData['results'] as $res) {
        $sId = $res['sync_id'] ?? null;
        $status = $res['status'] ?? 'error';
        if (!$sId) {
            continue;
        }

        if ($status === 'synced' || $status === 'already_synced') {
            if (!empty($res['note'])) {
                syncLog('WARN', (string) $res['note']);
            }
            $localDb->prepare("UPDATE LocalSyncQueue SET status = 'SYNCED', updated_at = CURRENT_TIMESTAMP WHERE sync_id = ?")->execute([$sId]);
            $tableStmt = $localDb->prepare('SELECT table_name FROM LocalSyncQueue WHERE sync_id = ?');
            $tableStmt->execute([$sId]);
            if ($tableName = $tableStmt->fetchColumn()) {
                $localDb->prepare("UPDATE `{$tableName}` SET sync_status = 'SYNCED', synced_at = CURRENT_TIMESTAMP WHERE sync_id = ?")->execute([$sId]);
            }
        } else {
            $anyRejected = true;
            $errMsg = $res['message'] ?? 'Rejected by Workspace Server.';
            // Bounded retries: past SYNC_MAX_ATTEMPTS, stop retrying
            // automatically and leave it in SYNC_ERROR for
            // sync/status.php to surface - a row the server actively
            // rejected (bad data, a constraint violation) won't fix
            // itself by trying again.
            $localDb->prepare(
                "UPDATE LocalSyncQueue SET
                    status = CASE WHEN attempts + 1 >= :max THEN 'SYNC_ERROR' ELSE 'PENDING' END,
                    attempts = attempts + 1, error_message = :err, updated_at = CURRENT_TIMESTAMP
                 WHERE sync_id = :sid"
            )->execute(['max' => SYNC_MAX_ATTEMPTS, 'err' => $errMsg, 'sid' => $sId]);
        }
    }
    $localDb->commit();

    setSyncEngineState($localDb, $anyRejected ? 'SYNC_ERROR' : 'ONLINE', ['last_push_success_at' => date('Y-m-d H:i:s')]);
    syncLog('INFO', 'Pushed batch of ' . count($pendingItems) . ' record(s)' . ($anyRejected ? ' - some rejected, see LocalSyncQueue.' : '.'));
}

/**
 * Upserts each row into a local master-data table, preserving its
 * central primary key exactly (see api/sync_pull.php's header comment
 * for why that matters). SQLite and MySQL don't share a portable
 * "INSERT ... ON CONFLICT" syntax the rest of this codebase relies on
 * staying driver-agnostic for, so this follows the same explicit
 * check-then-branch convention as includes/db.php::upsertHouseVisit
 * rather than reaching for one.
 */
function upsertMasterRows(PDO $db, string $table, array $columns, string $pkColumn, array $rows): int
{
    if (!$rows) {
        return 0;
    }
    $checkStmt = $db->prepare("SELECT 1 FROM `{$table}` WHERE `{$pkColumn}` = :pk");
    $colList = implode(', ', array_map(fn($c) => "`{$c}`", $columns));
    $placeholders = implode(', ', array_map(fn($c) => ":{$c}", $columns));
    $insertStmt = $db->prepare("INSERT INTO `{$table}` ({$colList}) VALUES ({$placeholders})");
    $updatable = array_filter($columns, fn($c) => $c !== $pkColumn);
    $setList = implode(', ', array_map(fn($c) => "`{$c}` = :{$c}", $updatable));
    $updateStmt = $db->prepare("UPDATE `{$table}` SET {$setList} WHERE `{$pkColumn}` = :{$pkColumn}");

    foreach ($rows as $row) {
        $params = [];
        foreach ($columns as $c) {
            $params[$c] = $row[$c] ?? null;
        }
        $checkStmt->execute(['pk' => $row[$pkColumn]]);
        $checkStmt->fetch() ? $updateStmt->execute($params) : $insertStmt->execute($params);
    }
    return count($rows);
}

/** Pulls the full master-data snapshot from api/sync_pull.php and upserts it locally. */
function runPullCycle(PDO $localDb): void
{
    setSyncEngineState($localDb, 'SYNCING', ['last_pull_attempt_at' => date('Y-m-d H:i:s')]);

    $ch = curl_init(rtrim(CENTRAL_SERVER_URL, '/') . '/api/sync_pull.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['X-Sync-Key: ' . SYNC_API_KEY],
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        setSyncEngineState($localDb, 'WORKSPACE_UNAVAILABLE', ['last_error' => $curlError ?: "Pull failed: HTTP {$httpCode}"]);
        syncLog('WARN', 'Pull failed: ' . ($curlError ?: "HTTP {$httpCode}"));
        return;
    }

    try {
        $data = json_decode((string) $response, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        syncLog('WARN', 'Pull returned malformed JSON.');
        return;
    }
    if (empty($data['success'])) {
        syncLog('WARN', 'Pull rejected: ' . ($data['error'] ?? 'unknown error'));
        return;
    }

    $localDb->beginTransaction();
    // Users, then Wards, then Houses (references Wards), then
    // WardAssignments (references both) - parents before children,
    // since SQLite checks foreign keys as each statement runs.
    $userCount = upsertMasterRows($localDb, 'Users', ['user_id', 'username', 'password_hash', 'role', 'full_name', 'created_at'], 'user_id', $data['users'] ?? []);
    $wardCount = upsertMasterRows($localDb, 'Wards', ['ward_id', 'ward_name', 'description'], 'ward_id', $data['wards'] ?? []);
    $houseCount = upsertMasterRows($localDb, 'Houses', ['house_id', 'house_number', 'street', 'ward_id', 'owner_name', 'meter_number', 'address'], 'house_id', $data['houses'] ?? []);
    $assignmentCount = upsertMasterRows($localDb, 'WardAssignments', ['assignment_id', 'ward_id', 'user_id', 'assigned_at'], 'assignment_id', $data['ward_assignments'] ?? []);
    $localDb->commit();

    setSyncEngineState($localDb, 'ONLINE', ['last_pull_success_at' => date('Y-m-d H:i:s')]);
    syncLog('INFO', "Pulled master data: {$userCount} user(s), {$wardCount} ward(s), {$houseCount} house(s), {$assignmentCount} assignment(s).");
}

// ── Main loop ────────────────────────────────────────────────────────

$localDb = getLocalDb();

$recovered = $localDb->exec("UPDATE LocalSyncQueue SET status = 'PENDING', updated_at = CURRENT_TIMESTAMP WHERE status = 'SYNCING'");
if ($recovered) {
    syncLog('INFO', "Recovered {$recovered} record(s) left in SYNCING state by a previous run.");
}

syncLog('INFO', 'Starting sync engine for device ' . DEVICE_ID . '. Target: ' . (CENTRAL_SERVER_URL ?: '(not configured)'));

$pollCount = 0;
while (true) {
    $pollCount++;

    try {
        if (CENTRAL_SERVER_URL === '' || SYNC_API_KEY === '') {
            setSyncEngineState($localDb, 'WORKSPACE_UNAVAILABLE', ['last_error' => 'CENTRAL_SERVER_URL / SYNC_API_KEY not configured in .env']);
        } else {
            runPushCycle($localDb);
            if ($pollCount % SYNC_PULL_EVERY_N_POLLS === 0) {
                runPullCycle($localDb);
            }
        }
    } catch (Throwable $e) {
        if ($localDb->inTransaction()) {
            $localDb->rollBack();
        }
        syncLog('ERROR', 'Unexpected exception: ' . $e->getMessage());
        setSyncEngineState($localDb, 'SYNC_ERROR', ['last_error' => $e->getMessage()]);
    }

    sleep(SYNC_POLL_SECONDS);
}
