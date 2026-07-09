<?php
/**
 * login.php
 * Implements the login test cases from Activity 5, Section 4.3
 * (valid login, invalid password, account lockout after 5 attempts).
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Already logged in? Skip straight to the dashboard.
if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
if (!empty($_GET['timeout'])) {
    $error = 'You were logged out after 15 minutes of inactivity. Please log in again.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $username = trim($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Please enter both a username and a password.';
    } elseif (isAccountLocked($username)) {
        $error = 'This account is temporarily locked after too many failed attempts. Please try again in '
                . LOCKOUT_MINUTES . ' minutes.';
    } else {
        $stmt = getDb()->prepare('SELECT * FROM Users WHERE username = :username');
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            clearFailedLogins($username);
            session_regenerate_id(true);
            $_SESSION['user_id']        = $user['user_id'];
            $_SESSION['full_name']      = $user['full_name'];
            $_SESSION['role']           = $user['role'];
            $_SESSION['last_activity']  = time();
            logActivity($user['user_id'], 'Logged in');
            header('Location: dashboard.php');
            exit;
        } else {
            recordFailedLogin($username);
            logActivity(null, "Failed login attempt for username '{$username}'");
            $error = 'Invalid username or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login &mdash; <?= htmlspecialchars(APP_NAME) ?></title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="login-wrap">
    <div class="login-box">
        <h1><?= htmlspecialchars(APP_NAME) ?></h1>
        <p class="subtitle">Beitbridge Municipality</p>

        <?php if ($error): ?>
            <div class="alert error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" novalidate>
            <?= csrfField() ?>
            <label for="username">Username</label>
            <input type="text" id="username" name="username" required autofocus
                   value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">

            <label for="password">Password</label>
            <input type="password" id="password" name="password" required>

            <button type="submit" class="btn" style="width:100%;">Login</button>
        </form>

        <p style="font-size:0.75rem;color:#8a94a3;margin-top:18px;">
            First time setting this system up? Run <code>setup_admin.php</code> once to create
            the first Administrator account, then delete that file.
        </p>
    </div>
</div>
</body>
</html>
