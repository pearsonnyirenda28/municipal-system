<?php
/**
 * meter-readings/mark_status.php
 * Version 3.0 - Guided Field Operations. Records a visit status that
 * isn't a meter reading (Skipped, Vacant, Locked, Illegal Connection,
 * Revisit Required), then sends the reader straight to the next house
 * in their round - the "guided" part of guided field operations.
 *
 * Not yet offline-capable: unlike meter readings, these status updates
 * currently require a live connection (see README, "What's built vs.
 * what's still on the roadmap").
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
requireRole(['Admin', 'MeterReader']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: capture.php');
    exit;
}
verifyCsrf();

$db = getDb();
$user = currentUser();

$houseId = (int) ($_POST['house_id'] ?? 0);
$status = $_POST['status'] ?? '';
$notes = trim($_POST['notes'] ?? '');
$nextHouseId = (int) ($_POST['next_house_id'] ?? 0);

$validStatuses = ['Skipped', 'Vacant', 'Locked', 'IllegalConnection', 'RevisitRequired'];

if ($houseId && in_array($status, $validStatuses, true)) {
    upsertHouseVisit($db, $houseId, (int) $user['id'], date('Y-m-d'), $status, null, $notes ?: null);

    $stmt = $db->prepare('SELECT house_number FROM Houses WHERE house_id = :id');
    $stmt->execute(['id' => $houseId]);
    $houseNumber = $stmt->fetchColumn() ?: $houseId;

    logActivity($user['id'], "Marked house {$houseNumber} as {$status}" . ($notes ? " ({$notes})" : ''));
}

header('Location: capture.php' . ($nextHouseId ? "?house_id={$nextHouseId}" : ''));
exit;
