<?php
/**
 * users/edit.php
 * Admin-only. Edit any user's full name, username, and role.
 *
 * Password changes deliberately live on a separate screen
 * (users/reset_password.php) so a routine detail edit can't
 * accidentally change credentials. A user editing their OWN password
 * does so at profile.php, which requires the current password - the
 * correct difference between "an Admin resetting someone else's" and
 * "you changing your own".
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
requireRole(['Admin']);

$db = getDb();
$targetId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$roles = ['Officer', 'MeterReader', 'Clerk', 'PoliceSupervisor', 'MeterSupervisor', 'Admin'];

$stmt = $db->prepare('SELECT user_id, username, full_name, role FROM Users WHERE user_id = :id');
$stmt->execute(['id' => $targetId]);
$target = $stmt->fetch();

if (!$target) {
    http_response_code(404);
    require __DIR__ . '/../includes/header.php';
    echo '<div class="alert error">User not found.</div><p><a href="list.php">Back to Users</a></p>';
    require __DIR__ . '/../includes/footer.php';
    exit;
}

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $fullName = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $role     = $_POST['role'] ?? '';

    if ($fullName === '') $errors[] = 'Full name is required.';
    if ($username === '') $errors[] = 'Username is required.';
    if (!in_array($role, $roles, true)) $errors[] = 'Please select a valid role.';

    // Safety: never let the last Admin be demoted - same reasoning as the
    // "last active Admin" guard in users/toggle_active.php.
    if (!$errors && $target['role'] === 'Admin' && $role !== 'Admin') {
        $adminCount = (int) $db->query("SELECT COUNT(*) FROM Users WHERE role = 'Admin'")->fetchColumn();
        if ($adminCount <= 1) {
            $errors[] = 'Cannot change the role of the only Administrator account.';
        }
    }

    if (!$errors) {
        $check = $db->prepare('SELECT user_id FROM Users WHERE username = :u AND user_id != :id');
        $check->execute(['u' => $username, 'id' => $targetId]);
        if ($check->fetch()) {
            $errors[] = 'That username is already taken.';
        }
    }

    if (!$errors) {
        $db->prepare('UPDATE Users SET full_name = :f, username = :u, role = :r WHERE user_id = :id')
           ->execute(['f' => $fullName, 'u' => $username, 'r' => $role, 'id' => $targetId]);

        $changes = [];
        if ($fullName !== $target['full_name']) $changes[] = 'name';
        if ($username !== $target['username']) $changes[] = 'username';
        if ($role     !== $target['role'])     $changes[] = 'role';
        if ($changes) {
            logActivity(currentUser()['id'], "Edited user '{$target['username']}' (" . implode(', ', $changes) . ')');
        }

        $target['full_name'] = $fullName;
        $target['username']  = $username;
        $target['role']      = $role;
        $success = true;
    }
}

require __DIR__ . '/../includes/header.php';
?>

<h1>Edit User</h1>
<p class="subtitle">Editing <strong><?= htmlspecialchars($target['full_name']) ?></strong> (<?= htmlspecialchars($target['username']) ?>).</p>

<?php if ($success): ?>
    <div class="alert success">Changes saved. <a href="list.php">Back to Users</a>.</div>
<?php endif; ?>
<?php if ($errors): ?>
    <div class="alert error"><?php foreach ($errors as $e): ?>&bull; <?= htmlspecialchars($e) ?><br><?php endforeach; ?></div>
<?php endif; ?>

<div class="form-panel">
    <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="id" value="<?= (int) $targetId ?>">

        <label for="full_name">Full name</label>
        <input type="text" id="full_name" name="full_name" required value="<?= htmlspecialchars($target['full_name']) ?>">

        <label for="username">Username</label>
        <input type="text" id="username" name="username" required value="<?= htmlspecialchars($target['username']) ?>">

        <label for="role">Role</label>
        <select id="role" name="role" required>
            <?php foreach ($roles as $r): ?>
                <option value="<?= $r ?>" <?= $target['role'] === $r ? 'selected' : '' ?>><?= $r ?></option>
            <?php endforeach; ?>
        </select>

        <button type="submit" class="btn">Save Changes</button>
        <a href="reset_password.php?id=<?= (int) $targetId ?>" class="btn secondary">Reset Password</a>
        <a href="list.php" class="btn secondary">Cancel</a>
    </form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>