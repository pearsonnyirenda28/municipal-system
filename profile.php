<?php
/**
 * profile.php
 * "Every user must be able to edit their own account details" - a plain
 * requireLogin() page (not requireRole(['Admin'])), so it's available to
 * every role. Two separate forms/actions:
 *   - update_details: full name + username
 *   - change_password: requires the CURRENT password, unlike
 *     users/reset_password.php (Admin-only, no current-password check -
 *     that's the whole point of a reset). This page is you proving it's
 *     still you before changing your own credentials.
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();

$db = getDb();
$userId = (int) currentUser()['id'];

$stmt = $db->prepare('SELECT user_id, username, full_name, role, created_at FROM Users WHERE user_id = :id');
$stmt->execute(['id' => $userId]);
$account = $stmt->fetch();

$detailErrors = [];
$passwordErrors = [];
$detailSuccess = false;
$passwordSuccess = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_details') {
        $fullName = trim($_POST['full_name'] ?? '');
        $username = trim($_POST['username'] ?? '');

        if ($fullName === '') {
            $detailErrors[] = 'Full name is required.';
        }
        if ($username === '') {
            $detailErrors[] = 'Username is required.';
        }

        if (!$detailErrors) {
            $check = $db->prepare('SELECT user_id FROM Users WHERE username = :u AND user_id != :id');
            $check->execute(['u' => $username, 'id' => $userId]);
            if ($check->fetch()) {
                $detailErrors[] = 'That username is already taken.';
            }
        }

        if (!$detailErrors) {
            $db->prepare('UPDATE Users SET full_name = :f, username = :u WHERE user_id = :id')
               ->execute(['f' => $fullName, 'u' => $username, 'id' => $userId]);

            $changes = [];
            if ($fullName !== $account['full_name']) { $changes[] = 'name'; }
            if ($username !== $account['username'])  { $changes[] = 'username'; }
            if ($changes) {
                logActivity($userId, 'Updated own account details (' . implode(', ', $changes) . ')');
            }

            // Session drives the header/greeting immediately, not just
            // the database - otherwise the old name would keep showing
            // until the next login.
            $_SESSION['full_name'] = $fullName;

            $account['full_name'] = $fullName;
            $account['username'] = $username;
            $detailSuccess = true;
        }

    } elseif ($action === 'change_password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');

        $hashStmt = $db->prepare('SELECT password_hash FROM Users WHERE user_id = :id');
        $hashStmt->execute(['id' => $userId]);
        $currentHash = $hashStmt->fetchColumn();

        if (!password_verify($current, $currentHash)) {
            $passwordErrors[] = 'Your current password is incorrect.';
        }
        if ($new !== $confirm) {
            $passwordErrors[] = 'New password and confirmation do not match.';
        }
        $passwordErrors = array_merge($passwordErrors, passwordPolicyErrors($new, $account['username'], $account['full_name']));

        if (!$passwordErrors) {
            $db->prepare('UPDATE Users SET password_hash = :p WHERE user_id = :id')
               ->execute(['p' => password_hash($new, PASSWORD_BCRYPT), 'id' => $userId]);
            logActivity($userId, 'Changed own password');
            $passwordSuccess = true;
        }
    }
}

require __DIR__ . '/includes/header.php';
?>

<h1>My Account</h1>
<p class="subtitle">Role: <span class="badge normal"><?= htmlspecialchars($account['role']) ?></span>
    &nbsp;&middot;&nbsp; Member since <?= htmlspecialchars(date('d M Y', strtotime($account['created_at']))) ?></p>

<div class="form-panel" style="margin-bottom:20px;">
    <h3 style="margin-top:0;">Account Details</h3>
    <?php if ($detailSuccess): ?><div class="alert success">Details updated.</div><?php endif; ?>
    <?php if ($detailErrors): ?>
        <div class="alert error"><?php foreach ($detailErrors as $e): ?>&bull; <?= htmlspecialchars($e) ?><br><?php endforeach; ?></div>
    <?php endif; ?>
    <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="update_details">
        <label for="full_name">Full Name</label>
        <input type="text" id="full_name" name="full_name" value="<?= htmlspecialchars($account['full_name']) ?>" required>
        <label for="username">Username</label>
        <input type="text" id="username" name="username" value="<?= htmlspecialchars($account['username']) ?>" required>
        <button type="submit" class="btn">Save Details</button>
    </form>
</div>

<div class="form-panel">
    <h3 style="margin-top:0;">Change Password</h3>
    <?php if ($passwordSuccess): ?><div class="alert success">Password changed. Use your new password next time you log in.</div><?php endif; ?>
    <?php if ($passwordErrors): ?>
        <div class="alert error"><?php foreach ($passwordErrors as $e): ?>&bull; <?= htmlspecialchars($e) ?><br><?php endforeach; ?></div>
    <?php endif; ?>
    <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="change_password">
        <label for="current_password">Current Password</label>
        <input type="password" id="current_password" name="current_password" required>
        <label for="new_password">New Password</label>
        <input type="password" id="new_password" name="new_password" required minlength="8">
        <p style="font-size:0.78rem;color:#5B6472;margin:6px 0 0;">At least 8 characters, with an uppercase letter, a lowercase letter, and a number.</p>
        <label for="confirm_password">Confirm New Password</label>
        <input type="password" id="confirm_password" name="confirm_password" required minlength="8">
        <button type="submit" class="btn">Change Password</button>
    </form>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
