<?php
/**
 * users/create.php
 * Replaces the manual SQL insert described in the original README - now
 * Admins can create Officer, MeterReader, Clerk and Supervisor accounts
 * (and other Admins) straight from the browser.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
requireRole(['Admin']);

$roles = ['Officer', 'MeterReader', 'Clerk', 'Supervisor', 'Admin'];
$errors = [];
$old = ['full_name' => '', 'username' => '', 'role' => ''];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old = [
        'full_name' => trim($_POST['full_name'] ?? ''),
        'username'  => trim($_POST['username'] ?? ''),
        'role'      => $_POST['role'] ?? '',
    ];
    $password = (string) ($_POST['password'] ?? '');

    if ($old['full_name'] === '') $errors[] = 'Full name is required.';
    if ($old['username'] === '') $errors[] = 'Username is required.';
    if (!in_array($old['role'], $roles, true)) $errors[] = 'Please select a valid role.';
    if (strlen($password) < 6) $errors[] = 'Password must be at least 6 characters.';

    if (!$errors) {
        $db = getDb();
        $check = $db->prepare('SELECT user_id FROM Users WHERE username = :u');
        $check->execute(['u' => $old['username']]);
        if ($check->fetch()) {
            $errors[] = 'That username is already taken.';
        } else {
            $stmt = $db->prepare(
                'INSERT INTO Users (username, password_hash, role, full_name) VALUES (:u, :p, :r, :f)'
            );
            $stmt->execute([
                'u' => $old['username'],
                'p' => password_hash($password, PASSWORD_BCRYPT),
                'r' => $old['role'],
                'f' => $old['full_name'],
            ]);
            logActivity(currentUser()['id'], "Created user account '{$old['username']}' ({$old['role']})");
            $success = true;
            $old = ['full_name' => '', 'username' => '', 'role' => ''];
        }
    }
}

require __DIR__ . '/../includes/header.php';
?>

<h1>Create User Account</h1>

<?php if ($success): ?>
    <div class="alert success">Account created successfully. <a href="list.php">View all users</a>.</div>
<?php endif; ?>
<?php if ($errors): ?>
    <div class="alert error"><?php foreach ($errors as $e): ?>&bull; <?= htmlspecialchars($e) ?><br><?php endforeach; ?></div>
<?php endif; ?>

<div class="form-panel">
    <form method="post">
        <label for="full_name">Full name</label>
        <input type="text" id="full_name" name="full_name" required value="<?= htmlspecialchars($old['full_name']) ?>">

        <label for="username">Username</label>
        <input type="text" id="username" name="username" required value="<?= htmlspecialchars($old['username']) ?>">

        <label for="role">Role</label>
        <select id="role" name="role" required>
            <option value="">-- Select --</option>
            <?php foreach ($roles as $r): ?>
                <option value="<?= $r ?>" <?= $old['role'] === $r ? 'selected' : '' ?>><?= $r ?></option>
            <?php endforeach; ?>
        </select>

        <label for="password">Temporary password</label>
        <input type="password" id="password" name="password" required minlength="6">

        <button type="submit" class="btn">Create Account</button>
        <a href="list.php" class="btn secondary">Cancel</a>
    </form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
