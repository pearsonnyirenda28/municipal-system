<?php
/**
 * api/mark_visit.php
 * Version 3.5: the offline-capable version of what mark_status.php used
 * to do as a plain form POST. Called by assets/js/status-actions.js via
 * fetch(), with an offline queue fallback - closing the gap flagged at
 * the end of Version 3.0 ("status buttons need a live connection").
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
    upsertHouseVisit($db, $houseId, (int) $_SESSION['user_id'], $visitDate, $status, null, $notes ?: null);

    $stmt = $db->prepare('SELECT house_number FROM Houses WHERE house_id = :id');
    $stmt->execute(['id' => $houseId]);
    $houseNumber = $stmt->fetchColumn() ?: $houseId;

    logActivity($_SESSION['user_id'], "Marked house {$houseNumber} as {$status}" . ($notes ? " ({$notes})" : ''));

    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    error_log('mark_visit.php failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not save the status. Please try again.']);
}
