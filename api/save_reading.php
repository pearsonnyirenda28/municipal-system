<?php
/**
 * api/save_reading.php
 * JSON API used by assets/js/meter-reading.js. Requires an active session
 * (the browser sends the session cookie automatically via
 * credentials: 'same-origin' in fetch()).
 *
 * Implements the process design from Activity 4, Section 2.3
 * ("Calculate Consumption and Flag Abnormal Reading") and the duplicate
 * handling from Activity 4, Section 5.3 (flag, don't reject).
 *
 * Duplicate handling, in detail:
 *   - If this client_ref has already been saved, quietly return success
 *     (idempotent - safe for retries from the offline queue).
 *   - Otherwise, look for existing UNRESOLVED readings for the same
 *     house + date under a different client_ref. If any exist, the new
 *     row and every one of those existing rows is flagged
 *     is_duplicate_flagged = 1 in the same transaction, and a
 *     "POSSIBLE DUPLICATE" line is written to ActivityLogs (which is
 *     what the Sync Conflicts screen reads).
 *   - Already-resolved rows are never re-flagged - a supervisor's
 *     decision that two readings were not duplicates is final.
 */
require_once __DIR__ . '/../includes/local_store.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

requireApiLogin(['Admin', 'MeterReader']);   // 401 + code on an ended session, 403 on the wrong role, 409 on another user's records

verifyCsrfHeader();

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request body.']);
    exit;
}

$houseId    = (int) ($input['houseId'] ?? 0);
$previous   = is_numeric($input['previous'] ?? null) ? (float) $input['previous'] : null;
$current    = is_numeric($input['current'] ?? null) ? (float) $input['current'] : null;
$dateTaken  = $input['dateTaken'] ?? date('Y-m-d');
$clientRef  = trim((string) ($input['clientRef'] ?? ''));

if (!$houseId || $previous === null || $current === null || $clientRef === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing or invalid reading data.']);
    exit;
}
if ($current < $previous) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Current reading cannot be lower than the previous reading.']);
    exit;
}

$db = getDb();

try {
    // Already saved before under this clientRef (e.g. the sync ran twice)?
    // Silently succeed rather than saving a second time.
    $check = $db->prepare('SELECT reading_id FROM MeterReadings WHERE client_ref = :ref');
    $check->execute(['ref' => $clientRef]);
    if ($check->fetch()) {
        echo json_encode(['success' => true, 'already_synced' => true, 'abnormal' => false]);
        exit;
    }

    $consumption = $current - $previous;

    // Flag as abnormal if more than double the average consumption for this
    // house over the last 6 months (Activity 4, Section 2.3).
    $avgStmt = $db->prepare(
        'SELECT AVG(consumption) AS avg_consumption FROM MeterReadings
         WHERE house_id = :house_id AND ' . dbWithinLast('date_taken', 6, 'MONTH')
    );
    $avgStmt->execute(['house_id' => $houseId]);
    $avgConsumption = (float) ($avgStmt->fetch()['avg_consumption'] ?? 0);
    $isAbnormal = $avgConsumption > 0 && $consumption > (2 * $avgConsumption);

    // Duplicate check: same house already read on the same date under a
    // different client reference. Only rows that haven't already been
    // resolved count - a resolved row is a decision, not a pending
    // question, and shouldn't be re-flagged.
    $dupStmt = $db->prepare(
        'SELECT reading_id FROM MeterReadings
         WHERE house_id = :house_id AND date_taken = :date
           AND client_ref != :ref
           AND duplicate_resolved_by IS NULL'
    );
    $dupStmt->execute(['house_id' => $houseId, 'date' => $dateTaken, 'ref' => $clientRef]);
    $existingDuplicateIds = array_map('intval', $dupStmt->fetchAll(PDO::FETCH_COLUMN));
    $possibleDuplicate = !empty($existingDuplicateIds);

    $db->beginTransaction();

    // Version 4.5: this row's idempotency key against the central
    // Workspace Server. Reuses clientRef when the browser supplied one
    // (the common case - see includes/local_store.php::resolveSyncId).
    $syncId = resolveSyncId($clientRef);

    // Insert the new reading. is_duplicate_flagged is written directly in
    // the INSERT so the whole group has the same flag from the moment
    // the row exists - no window where the new row is unflagged but its
    // siblings are.
    $stmt = $db->prepare(
        'INSERT INTO MeterReadings
            (house_id, previous_reading, current_reading, consumption, is_abnormal,
             reader_id, date_taken, client_ref, is_duplicate_flagged,
             sync_id, device_id, sync_status)
         VALUES (:house_id, :previous, :current, :consumption, :abnormal,
                 :reader_id, :date_taken, :client_ref, :is_duplicate,
                 :sync_id, :device_id, \'PENDING\')'
    );
    $stmt->execute([
        'house_id'      => $houseId,
        'previous'      => $previous,
        'current'       => $current,
        'consumption'   => $consumption,
        'abnormal'      => $isAbnormal ? 1 : 0,
        'reader_id'     => $_SESSION['user_id'],
        'date_taken'    => $dateTaken,
        'client_ref'    => $clientRef,
        'is_duplicate'  => $possibleDuplicate ? 1 : 0,
        'sync_id'       => $syncId,
        'device_id'     => DEVICE_ID,
    ]);
    $newReadingId = (int) $db->lastInsertId();

    // Flag every existing unresolved sibling so the whole group is
    // visible on the Duplicate Readings screen at once. Only touches
    // rows that haven't been resolved.
    if ($possibleDuplicate) {
        $placeholders = implode(',', array_fill(0, count($existingDuplicateIds), '?'));
        $db->prepare("UPDATE MeterReadings SET is_duplicate_flagged = 1 WHERE reading_id IN ({$placeholders})")
           ->execute($existingDuplicateIds);
    }

    // Queue this row for the central Workspace Server - same transaction
    // as the insert above, so a row can never exist locally without also
    // being queued (principle: transactional integrity).
    enqueueForCentralSync($db, $syncId, 'MeterReadings', 'INSERT', [
        'house_id'             => $houseId,
        'previous_reading'     => $previous,
        'current_reading'      => $current,
        'consumption'          => $consumption,
        'is_abnormal'          => $isAbnormal ? 1 : 0,
        'reader_id'            => $_SESSION['user_id'],
        'date_taken'           => $dateTaken,
        'client_ref'           => $clientRef,
        'is_duplicate_flagged' => $possibleDuplicate ? 1 : 0,
    ]);

    $db->commit();

    // Version 3.0: a successful reading marks today's visit to this house
    // as Completed - this is the single point both the online-direct save
    // and the later offline sync both pass through, so guided navigation
    // progress updates correctly either way. Version 4.5: also queued for
    // the center, under its own fresh sync_id (upsertHouseVisit updates
    // the row in place over the day, so each call needs a new id, not
    // the reading's) - reading_sync_id lets api/sync_push.php resolve
    // this visit's reading_id to whatever the central MySQL actually
    // assigned that reading, since the local $newReadingId only means
    // something on this workstation's own SQLite file.
    $visitSyncId = generateSyncId();
    $db->beginTransaction();
    upsertHouseVisit($db, $houseId, (int) $_SESSION['user_id'], $dateTaken, 'Completed', $newReadingId, null, $visitSyncId, DEVICE_ID);
    enqueueForCentralSync($db, $visitSyncId, 'HouseVisits', 'UPSERT', [
        'house_id'        => $houseId,
        'reader_id'       => $_SESSION['user_id'],
        'visit_date'      => $dateTaken,
        'status'          => 'Completed',
        'reading_sync_id' => $syncId,
        'notes'           => null,
    ]);
    $db->commit();

    $logMsg = "Captured meter reading for house_id {$houseId} ({$current} m3)";
    if ($possibleDuplicate) {
        $logMsg .= ' - POSSIBLE DUPLICATE, flagged for admin review';
    }
    logActivity($_SESSION['user_id'], $logMsg);

    echo json_encode([
        'success'            => true,
        'abnormal'           => $isAbnormal,
        'duplicate_flagged'  => $possibleDuplicate,
    ]);

} catch (PDOException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('save_reading.php failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not save the reading. Please try again.']);
}