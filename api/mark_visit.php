<?php
/**
 * api/mark_visit.php
 * Version 3.5: the offline-capable version of what mark_status.php used
 * to do as a plain form POST. Called by assets/js/status-actions.js via
 * fetch(), with an offline queue fallback - closing the gap flagged at
 * the end of Version 3.0 ("status buttons need a live connection").
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

$houseId = (int) ($input['houseId'] ?? 0);
$status = $input['status'] ?? '';
$notes = trim((string) ($input['notes'] ?? ''));
$visitDate = $input['visitDate'] ?? date('Y-m-d');

$validStatuses = ['Skipped', 'Vacant', 'Locked', 'IllegalConnection', 'RevisitRequired'];

if (!$houseId || !in_array($status, $validStatuses, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing or invalid status data.']);
    exit;
}

$db = getDb();

try {
    // Version 4.5: each status change is queued for the central
    // Workspace Server under its own fresh sync_id (see
    // includes/db.php::upsertHouseVisit - unlike readings/fines, this
    // table's own idempotency already comes from the house+reader+date
    // upsert, not a client-supplied token, so there's no clientRef to
    // reuse here the way save_reading.php/save_fine.php do).
    $syncId = generateSyncId();
    $db->beginTransaction();
    upsertHouseVisit($db, $houseId, (int) $_SESSION['user_id'], $visitDate, $status, null, $notes ?: null, $syncId, DEVICE_ID);
    enqueueForCentralSync($db, $syncId, 'HouseVisits', 'UPSERT', [
        'house_id'        => $houseId,
        'reader_id'       => $_SESSION['user_id'],
        'visit_date'      => $visitDate,
        'status'          => $status,
        'reading_sync_id' => null,
        'notes'           => $notes ?: null,
    ]);
    $db->commit();

    $stmt = $db->prepare('SELECT house_number FROM Houses WHERE house_id = :id');
    $stmt->execute(['id' => $houseId]);
    $houseNumber = $stmt->fetchColumn() ?: $houseId;

    logActivity($_SESSION['user_id'], "Marked house {$houseNumber} as {$status}" . ($notes ? " ({$notes})" : ''));

    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('mark_visit.php failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not save the status. Please try again.']);
}
