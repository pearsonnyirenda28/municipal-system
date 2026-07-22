<?php
/**
 * users/reset_password.php
 * Improvement 11: User Management - lets an Admin set a new temporary
 * password for any account (e.g. after a lockout, or when a reader loses
 * their phone). Reuses the same passwordPolicyErrors() rules as account
 * creation, so a reset password can't be weaker than a new one.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
requireRole(['Admin']);

$db = getDb();
$targetId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

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
    $password = (string) ($_POST['password'] ?? '');
    $errors = passwordPolicyErrors($password, $target['username'], $target['full_name']);

    if (!$errors) {
        $db->prepare('UPDATE Users SET password_hash = :p WHERE user_id = :id')
           ->execute(['p' => password_hash($password, PASSWORD_BCRYPT), 'id' => $targetId]);
        logActivity(currentUser()['id'], "Reset password for user '{$target['username']}'");
        $success = true;
    }
}

require __DIR__ . '/../includes/header.php';
?>

<h1>Reset Password</h1>
<p class="subtitle">Setting a new temporary password for <?= htmlspecialchars($target['full_name']) ?> (<?= htmlspecialchars($target['username']) ?>).</p>

<?php if ($success): ?>
    <div class="alert success">Password reset. Let them know their new temporary password directly - it isn't emailed or shown anywhere else. <a href="list.php">Back to Users</a>.</div>
<?php else: ?>
    <?php if ($errors): ?>
        <div class="alert error"><?php foreach ($errors as $e): ?>&bull; <?= htmlspecialchars($e) ?><br><?php endforeach; ?></div>
    <?php endif; ?>

    <div class="form-panel">
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="id" value="<?= (int) $targetId ?>">
            <label for="password">New temporary password</label>
            <input type="password" id="password" name="password" required minlength="8" autofocus>
            <p style="font-size:0.78rem;color:#5B6472;margin:6px 0 0;">
                At least 8 characters, with an uppercase letter, a lowercase letter, and a number.
                They should change it after logging in.
            </p>
            <button type="submit" class="btn">Reset Password</button>
            <a href="list.php" class="btn secondary">Cancel</a>
        </form>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
