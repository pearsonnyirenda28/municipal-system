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
$reason = (string) ($_GET['reason'] ?? (!empty($_GET['timeout']) ? 'timeout' : ''));
if ($reason === 'timeout') {
    $mins = SESSION_TIMEOUT_SECONDS / 60;
    $after = $mins >= 1 ? round($mins) . ' minute' . (round($mins) == 1 ? '' : 's') : SESSION_TIMEOUT_SECONDS . ' seconds';
    $error = "You were logged out after {$after} of inactivity. Please log in again.";
} elseif ($reason === 'deactivated') {
    $error = 'Your account has been deactivated. Please contact the system administrator.';
} elseif ($reason === 'password_changed') {
    $error = 'Your password was changed, so you have been logged out. Please log in with the new password.';
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

        // Always run exactly one bcrypt comparison, even for a username that does not exist
        // (against a throw-away hash), so the response time never reveals which usernames are real.
        $hash       = $user ? (string) $user['password_hash'] : '$2y$10$xTeHfnb8oN8r8Q5EExtIg.5kACY1LX9GWy8d1m8iKZFVyjxjAMpda';
        $passwordOk = password_verify($password, $hash) && $user;

        if ($passwordOk && array_key_exists('is_active', $user) && $user['is_active'] !== null && (int) $user['is_active'] === 0) {
            // Correct password, but an administrator has deactivated the account.
            logActivity((int) $user['user_id'], 'Login refused: account is deactivated');
            $error = 'This account has been deactivated. Please contact the system administrator.';
        } elseif ($passwordOk) {
            clearFailedLogins($username);
            session_regenerate_id(true);           // new session ID at the moment of login (session fixation)
            unset($_SESSION['csrf_token']);        // and a fresh CSRF token for the new login
            $_SESSION['user_id']        = $user['user_id'];
            $_SESSION['full_name']      = $user['full_name'];
            $_SESSION['role']           = $user['role'];
            $_SESSION['last_activity']  = time();
            $_SESSION['revalidated_at'] = time();
            $_SESSION['pw_fingerprint'] = sessionFingerprint((string) $user['password_hash']);
            logActivity($user['user_id'], 'Logged in');
            header('Location: dashboard.php');
            exit;
        } else {
            recordFailedLogin($username);          // also writes the audit-log entry
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
<script src="/municipal-system/assets/js/auth-cleanup.js"></script>
</body>
</html>