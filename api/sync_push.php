<?php
/**
 * api/sync_push.php
 * Endpoint running on the central Municipal Workspace Server (DB_DRIVER=
 * mysql). Called by cli_sync_engine.php running on each field workstation,
 * over the municipal LAN only - never meant to be reachable from the
 * public internet. Processes a batch of queued local transactions and
 * writes them into the central MySQL idempotently, keyed by sync_id.
 *
 * Two operations, matching LocalSyncQueue.operation:
 *   INSERT - append-only tables (MeterReadings, Fines). A sync_id that
 *            already exists centrally means this exact push was already
 *            applied (a retried request after a lost ack, say) - skip it,
 *            don't insert a second copy.
 *   UPSERT - HouseVisits, whose local row is updated in place as a
 *            reader's day progresses. The SAME house+reader+day may
 *            arrive under several different sync_ids over time (one per
 *            local status change) - each should update the one central
 *            row for that day, not create a new one. reading_sync_id
 *            (not a local reading_id, which means nothing off the
 *            workstation that generated it) is resolved to whatever
 *            reading_id MeterReadings actually has centrally for that
 *            sync_id; if that reading hasn't arrived yet, reading_id is
 *            just left null on this pass rather than blocking the visit.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/central_store.php';

requireSyncApiKey();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Use POST.']);
    exit;
}

$rawInput = file_get_contents('php://input');
if (!$rawInput) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Empty request body.']);
    exit;
}

try {
    $payload = json_decode($rawInput, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Malformed JSON payload.']);
    exit;
}

if (!isset($payload['records']) || !is_array($payload['records'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing or invalid "records" array.']);
    exit;
}
if (empty($payload['device_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing device_id.']);
    exit;
}

$mysql = getCentralDb();
$records = $payload['records'];
$results = [];
$allowedTables = ['MeterReadings' => 'INSERT', 'Fines' => 'INSERT', 'HouseVisits' => 'UPSERT'];

// Every record is applied in its OWN transaction. A data-level failure (a
// constraint violation, a bad value) is reported for THAT record only and never
// rolls back or blocks the good records beside it. With one transaction around
// the whole batch, a single bad record made the server answer HTTP 500 for the
// entire batch, the workstation retried the identical batch forever, and
// nothing behind the bad record ever synced.
//
// Infrastructure failures (database unreachable, connection lost) are NOT the
// record's fault: those still return HTTP 500 for the whole request so the
// workstation just retries later without counting an attempt against the data.
// Anything already committed is safe to resend - it is skipped by sync_id.
try {
    foreach ($records as $item) {
        $syncId = $item['sync_id'] ?? null;
        $tableName = $item['table_name'] ?? null;
        $operation = $item['operation'] ?? 'INSERT';
        $data = $item['data'] ?? [];

        if (!$syncId || !$tableName || !isset($allowedTables[$tableName]) || empty($data)) {
            $results[] = ['sync_id' => $syncId, 'status' => 'error', 'message' => 'Invalid table, payload, or missing sync_id.'];
            continue;
        }
        if ($operation !== $allowedTables[$tableName]) {
            $results[] = ['sync_id' => $syncId, 'status' => 'error', 'message' => "{$tableName} rows must use operation {$allowedTables[$tableName]}."];
            continue;
        }

        // Idempotency: this exact push may have already landed in an earlier
        // attempt (applied here, but the ack never reached the workstation) -
        // succeed quietly rather than reprocessing it.
        $checkStmt = $mysql->prepare("SELECT COUNT(*) FROM `{$tableName}` WHERE `sync_id` = :sync_id");
        $checkStmt->execute(['sync_id' => $syncId]);
        if ((int) $checkStmt->fetchColumn() > 0) {
            $results[] = ['sync_id' => $syncId, 'status' => 'already_synced'];
            continue;
        }

        unset($data['sync_status']);
        $data['sync_status'] = 'SYNCED';
        $data['synced_at'] = date('Y-m-d H:i:s');
        $note = null;

        try {
            $mysql->beginTransaction();

            if ($operation === 'UPSERT') {
                // Only HouseVisits today. reading_sync_id isn't a real column -
                // resolve it to this Workspace Server's own reading_id (or leave
                // the FK null if that reading hasn't synced yet) and drop it from
                // $data before it reaches the INSERT/UPDATE below.
                $readingSyncId = $data['reading_sync_id'] ?? null;
                unset($data['reading_sync_id']);
                $data['reading_id'] = null;
                if ($readingSyncId) {
                    $lookup = $mysql->prepare('SELECT reading_id FROM MeterReadings WHERE sync_id = :sid');
                    $lookup->execute(['sid' => $readingSyncId]);
                    $resolved = $lookup->fetchColumn();
                    if ($resolved !== false) {
                        $data['reading_id'] = (int) $resolved;
                    }
                }

                $existingStmt = $mysql->prepare(
                    'SELECT visit_id FROM HouseVisits WHERE house_id = :h AND reader_id = :r AND visit_date = :d'
                );
                $existingStmt->execute(['h' => $data['house_id'], 'r' => $data['reader_id'], 'd' => $data['visit_date']]);
                $existingVisitId = $existingStmt->fetchColumn();

                if ($existingVisitId !== false) {
                    $updateData = $data;
                    unset($updateData['house_id'], $updateData['reader_id'], $updateData['visit_date']);
                    $setList = implode(', ', array_map(fn($c) => "`{$c}` = :{$c}", array_keys($updateData)));
                    $updateData['visit_id'] = $existingVisitId;
                    $mysql->prepare("UPDATE HouseVisits SET {$setList} WHERE visit_id = :visit_id")->execute($updateData);
                } else {
                    $columns = array_keys($data);
                    $colList = implode(', ', array_map(fn($c) => "`{$c}`", $columns));
                    $placeholders = implode(', ', array_map(fn($c) => ":{$c}", $columns));
                    $mysql->prepare("INSERT INTO `HouseVisits` ({$colList}) VALUES ({$placeholders})")->execute($data);
                }
            } else {
                // Fine numbers are generated per workstation (FINE-YYYY-NNNN from
                // that machine's own last number), so two workstations can both
                // issue FINE-2026-0002. Centrally fine_number is UNIQUE; rather
                // than reject a perfectly valid fine, keep it under a number made
                // unique with a short suffix derived from its sync_id (stable
                // across retries). The workstation keeps the number on the receipt.
                if ($tableName === 'Fines' && !empty($data['fine_number'])) {
                    $numStmt = $mysql->prepare('SELECT COUNT(*) FROM Fines WHERE fine_number = :n');
                    $numStmt->execute(['n' => $data['fine_number']]);
                    if ((int) $numStmt->fetchColumn() > 0) {
                        $original = (string) $data['fine_number'];
                        $data['fine_number'] = substr($original, 0, 15) . '-' . substr(hash('sha256', (string) $syncId), 0, 4);
                        $note = "Fine number {$original} was already in use on the Workspace Server; stored centrally as {$data['fine_number']}.";
                    }
                }

                // Plain append-only INSERT (MeterReadings, Fines).
                $columns = array_keys($data);
                $colList = implode(', ', array_map(fn($c) => "`{$c}`", $columns));
                $placeholders = implode(', ', array_map(fn($c) => ":{$c}", $columns));
                $mysql->prepare("INSERT INTO `{$tableName}` ({$colList}) VALUES ({$placeholders})")->execute($data);
            }

            $mysql->commit();
            $result = ['sync_id' => $syncId, 'status' => 'synced'];
            if ($note !== null) {
                $result['note'] = $note;
            }
            $results[] = $result;
        } catch (Throwable $e) {
            if ($mysql->inTransaction()) {
                $mysql->rollBack();
            }
            // SQLSTATE class 22 (bad data), 23 (constraint) or 42 (unknown column) =
            // this record's own problem. Anything else is infrastructure: rethrow
            // so the whole request fails and the workstation simply retries later.
            $recordLevel = $e instanceof PDOException && in_array(substr((string) $e->getCode(), 0, 2), ['22', '23', '42'], true);
            if (!$recordLevel) {
                throw $e;
            }
            error_log("sync_push.php: {$tableName} {$syncId} rejected: " . $e->getMessage());
            $results[] = [
                'sync_id' => $syncId,
                'status'  => 'error',
                'message' => substr((string) $e->getCode(), 0, 2) === '23'
                    ? 'Rejected by the Workspace Server database (constraint violation, for example a user or house that does not exist there yet).'
                    : 'Rejected by the Workspace Server database (invalid data).',
            ];
        }
    }

    echo json_encode(['success' => true, 'results' => $results], JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    if ($mysql->inTransaction()) {
        $mysql->rollBack();
    }
    error_log('sync_push.php failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Central sync failed.', 'results' => $results]);
}
