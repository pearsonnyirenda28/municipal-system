<?php
/**
 * api/report_sync_failure.php
 * Improvement 17: Notifications - "Synchronization failures".
 * Called by meter-reading.js when a sync attempt throws (network drops
 * mid-sync, server error, etc.), so a failure that only the field
 * reader's device would otherwise know about also shows up for
 * Admin/MeterSupervisor as a notification, not just in that one
 * device's console.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false]);
    exit;
}
verifyCsrf();

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$pendingCount = (int) ($input['pendingCount'] ?? 0);
$reason = substr((string) ($input['reason'] ?? 'unknown error'), 0, 200);

logActivity(currentUser()['id'], "SYNC_FAILURE: {$pendingCount} reading(s) still pending after a failed sync attempt ({$reason})");

echo json_encode(['success' => true]);
