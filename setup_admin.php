<?php
/**
 * setup_admin.php
 * Run this ONCE in your browser after setting up the database, to create
 * the first Administrator account. Delete this file afterwards (Chapter 6,
 * Section 3.1, Technical Manual, Step 5).
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

$errors = [];
$done = false;

$stmt = getDb()->query("SELECT COUNT(*) AS total FROM Users WHERE role = 'Admin'");
$adminExists = ((int) $stmt->fetch()['total']) > 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$adminExists) {
    verifyCsrf();

    $username = trim($_POST['username'] ?? '');
    $fullName = trim($_POST['full_name'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    if ($username === '') {
        $errors[] = 'Username is required.';
    }
    if ($fullName === '') {
        $errors[] = 'Full name is required.';
    }
    $errors = array_merge($errors, passwordPolicyErrors($password, $username, $fullName));

    if (!$errors) {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = getDb()->prepare(
            'INSERT INTO Users (username, password_hash, role, full_name) VALUES (:u, :p, "Admin", :f)'
        );
        $stmt->execute(['u' => $username, 'p' => $hash, 'f' => $fullName]);
        $done = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>First-Time Setup</title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="login-wrap">
    <div class="login-box">
        <h1>First-Time Setup</h1>

        <?php if ($adminExists && !$done): ?>
            <div class="alert warning">
                An Administrator account already exists. For security, delete this file
                (<code>setup_admin.php</code>) now.
            </div>
            <a class="btn" href="login.php" style="width:100%;text-align:center;">Go to Login</a>

        <?php elseif ($done): ?>
            <div class="alert success">
                Administrator account created successfully. Please delete
                <code>setup_admin.php</code> now, then log in.
            </div>
            <a class="btn" href="login.php" style="width:100%;text-align:center;">Go to Login</a>

        <?php else: ?>
            <p class="subtitle">Create the first Administrator account.</p>
            <?php if ($errors): ?>
                <div class="alert error"><?php foreach ($errors as $e): ?>&bull; <?= htmlspecialchars($e) ?><br><?php endforeach; ?></div>
            <?php endif; ?>
            <form method="post">
                <?= csrfField() ?>
                <label>Full name</label>
                <input type="text" name="full_name" required value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>">
                <label>Username</label>
                <input type="text" name="username" required value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                <label>Password</label>
                <input type="password" name="password" required minlength="8">
                <p style="font-size:0.78rem;color:#8a94a3;margin:6px 0 0;">
                    At least 8 characters, with an uppercase letter, a lowercase letter, and a number.
                </p>
                <button type="submit" class="btn" style="width:100%;">Create Administrator</button>
            </form>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
