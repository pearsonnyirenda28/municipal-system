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
<link rel="stylesheet" href="/municipal-system/assets/css/style.css">
</head>
<body class="login-body">

    <div class="login-card">
        <span class="brand-title"><?= htmlspecialchars(APP_NAME) ?></span>
        <span class="brand-sub">Beitbridge Municipality</span>

        <?php if ($error): ?>
            <div class="errorlist"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" novalidate>
            <?= csrfField() ?>

            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required autofocus
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <div class="password-wrap">
                    <input type="password" id="password" name="password" required>
                    <button type="button"
                            class="password-toggle"
                            data-password-toggle="password"
                            aria-label="Show password"
                            aria-pressed="false"
                            title="Show / hide password">
                        <span class="eye-icon eye-show" aria-hidden="true">👁</span>
                        <span class="eye-icon eye-hide" aria-hidden="true" hidden>🙈</span>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn-login">Login</button>
        </form>

       
    </div>

    <script src="/municipal-system/assets/js/password-toggle.js"></script>
</body>
</html>