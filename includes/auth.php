<?php
/**
 * includes/auth.php
 * Session handling, login-required guard, role-based access guard,
 * and the account-lockout / session-timeout controls from Activity 4,
 * Section 5.2.
 */

require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

const MAX_FAILED_LOGINS = 5;
const LOCKOUT_MINUTES    = 10;

/** Redirects to the login page if nobody is logged in, and enforces the
 *  15-minute idle session timeout (Activity 4, Section 5.2). */
function requireLogin(): void
{
    if (empty($_SESSION['user_id'])) {
        header('Location: /municipal-system/login.php');
        exit;
    }

    if (isset($_SESSION['last_activity']) &&
        (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT_SECONDS) {
        session_unset();
        session_destroy();
        header('Location: /municipal-system/login.php?timeout=1');
        exit;
    }

    $_SESSION['last_activity'] = time();
}

/** Blocks access unless the logged-in user has one of the given roles
 *  (Activity 4, Section 4.2 - role-based navigation). */
function requireRole(array $allowedRoles): void
{
    requireLogin();
    if (!in_array($_SESSION['role'], $allowedRoles, true)) {
        http_response_code(403);
        echo '<div style="font-family:Calibri,sans-serif;padding:40px;text-align:center;">'
           . '<h2>Access denied</h2>'
           . '<p>You do not have permission to view this page.</p>'
           . '<p><a href="/municipal-system/dashboard.php">Back to dashboard</a></p>'
           . '</div>';
        exit;
    }
}

function currentUser(): array
{
    return [
        'id'   => $_SESSION['user_id']   ?? null,
        'name' => $_SESSION['full_name'] ?? '',
        'role' => $_SESSION['role']      ?? '',
    ];
}

/** Returns true if this account is currently locked out. */
function isAccountLocked(string $username): bool
{
    if (empty($_SESSION['login_attempts'][$username])) {
        return false;
    }
    $attempt = $_SESSION['login_attempts'][$username];
    if ($attempt['count'] >= MAX_FAILED_LOGINS) {
        $unlockAt = $attempt['last_attempt'] + (LOCKOUT_MINUTES * 60);
        if (time() < $unlockAt) {
            return true;
        }
        // Lockout window has passed - reset the counter.
        unset($_SESSION['login_attempts'][$username]);
    }
    return false;
}

function recordFailedLogin(string $username): void
{
    if (!isset($_SESSION['login_attempts'][$username])) {
        $_SESSION['login_attempts'][$username] = ['count' => 0, 'last_attempt' => time()];
    }
    $_SESSION['login_attempts'][$username]['count']++;
    $_SESSION['login_attempts'][$username]['last_attempt'] = time();
}

function clearFailedLogins(string $username): void
{
    unset($_SESSION['login_attempts'][$username]);
}
