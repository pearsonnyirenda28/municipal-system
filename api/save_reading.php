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
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not logged in.']);
    exit;
}
if (!in_array($_SESSION['role'], ['Admin', 'MeterReader'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have permission to do this.']);
    exit;
}

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
    // different client reference - flag for admin review, but still save
    // (Activity 4, Section 5.3), rather than rejecting the reader's work.
    $dupStmt = $db->prepare(
        'SELECT reading_id FROM MeterReadings WHERE house_id = :house_id AND date_taken = :date AND client_ref != :ref'
    );
    $dupStmt->execute(['house_id' => $houseId, 'date' => $dateTaken, 'ref' => $clientRef]);
    $possibleDuplicate = (bool) $dupStmt->fetch();

    $db->beginTransaction();

    $stmt = $db->prepare(
        'INSERT INTO MeterReadings
            (house_id, previous_reading, current_reading, consumption, is_abnormal, reader_id, date_taken, client_ref)
         VALUES (:house_id, :previous, :current, :consumption, :abnormal, :reader_id, :date_taken, :client_ref)'
    );
    $stmt->execute([
        'house_id'    => $houseId,
        'previous'    => $previous,
        'current'     => $current,
        'consumption' => $consumption,
        'abnormal'    => $isAbnormal ? 1 : 0,
        'reader_id'   => $_SESSION['user_id'],
        'date_taken'  => $dateTaken,
        'client_ref'  => $clientRef,
    ]);

    $db->commit();

    $logMsg = "Captured meter reading for house_id {$houseId} ({$current} m3)";
    if ($possibleDuplicate) {
        $logMsg .= ' - POSSIBLE DUPLICATE, flagged for admin review';
    }
    logActivity($_SESSION['user_id'], $logMsg);

    echo json_encode([
        'success'  => true,
        'abnormal' => $isAbnormal,
        'duplicate_flagged' => $possibleDuplicate,
    ]);

} catch (PDOException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('save_reading.php failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not save the reading. Please try again.']);
}
