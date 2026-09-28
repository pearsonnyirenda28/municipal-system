<?php
/**
 * users/toggle_active.php
 * Improvement 11: User Management - activate/deactivate accounts.
 * POST-only action, redirects back to list.php with the same filters.
 *
 * Two safety rules, same reasoning as the Admin count check already used
 * in users/... elsewhere in the app: an Admin can lock themselves out
 * indirectly by deactivating their own account or the last other active
 * Admin, with no way back in short of a database console.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
requireRole(['Admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: list.php');
    exit;
}
verifyCsrf();

$db = getDb();
$user = currentUser();
$targetId = (int) ($_POST['id'] ?? 0);
$redirectQs = $_POST['return_qs'] ?? '';

$stmt = $db->prepare('SELECT user_id, username, role, is_active FROM Users WHERE user_id = :id');
$stmt->execute(['id' => $targetId]);
$target = $stmt->fetch();

$flashError = '';

if (!$target) {
    $flashError = 'User not found.';
} elseif ($targetId === (int) $user['id']) {
    $flashError = 'You cannot deactivate your own account.';
} else {
    $newStatus = $target['is_active'] ? 0 : 1;

    if ($newStatus === 0 && $target['role'] === 'Admin') {
        $activeAdmins = (int) $db->query("SELECT COUNT(*) FROM Users WHERE role = 'Admin' AND is_active = 1")->fetchColumn();
        if ($activeAdmins <= 1) {
            $flashError = 'Cannot deactivate the only active Administrator account.';
        }
    }

    if ($flashError === '') {
        $db->prepare('UPDATE Users SET is_active = :a WHERE user_id = :id')
           ->execute(['a' => $newStatus, 'id' => $targetId]);
        $action = $newStatus ? 'Activated' : 'Deactivated';
        logActivity($user['id'], "{$action} user account '{$target['username']}'");
    }
}

$_SESSION['user_action_flash'] = $flashError;
header('Location: list.php' . ($redirectQs ? '?' . $redirectQs : ''));
exit;
