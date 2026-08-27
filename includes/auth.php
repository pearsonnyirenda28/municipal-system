<?php
/**
 * includes/auth.php
 * Session handling, login-required guard, role-based access guard,
 * account-lockout / session-timeout controls (Activity 4, Section 5.2),
 * CSRF protection, and baseline security headers.
 */

require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    // Harden the session cookie itself before starting the session.
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,   // JavaScript can never read the session cookie
        'samesite' => 'Lax',  // blocks the cookie being sent on cross-site POSTs - first line of CSRF defence
    ]);
    session_start();
}

// ------------------------------------------------------------
// Baseline security headers - sent on every page that includes this
// file (which is effectively every page in the app).
// ------------------------------------------------------------
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');   // stops the browser "guessing" a file is HTML/JS when it isn't
    header('X-Frame-Options: DENY');              // stops this app being loaded inside someone else's <iframe> (clickjacking)
    header('Referrer-Policy: same-origin');
    // Deliberately no 'unsafe-inline' style/script blanket allowance beyond what the app
    // actually uses - all JS here lives in assets/js/*.js, not inline <script> blocks with
    // untrusted content, and the few inline <script> tags only ever print server-generated
    // integers/tokens, never raw user input.
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'");
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

// ------------------------------------------------------------
// CSRF protection
//
// Every state-changing form (anything using method="post") includes a
// hidden csrf_token field via csrfField(). Every page that handles a
// POST calls verifyCsrf() before doing anything else. A form submitted
// without a valid, matching token - e.g. from a malicious third-party
// page trying to trick a logged-in user's browser into submitting a
// request here - is rejected outright.
// ------------------------------------------------------------

/** Returns the current session's CSRF token, generating one if needed. */
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Echoes a ready-to-use hidden <input> for forms. */
function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken()) . '">';
}

/**
 * Call at the top of every POST handler, before touching $_POST for
 * anything else. Ends the request with a 403 if the token is missing or
 * doesn't match - it deliberately does NOT try to "helpfully" continue.
 */
function verifyCsrf(): void
{
    $submitted = $_POST['csrf_token'] ?? '';
    $expected  = $_SESSION['csrf_token'] ?? '';

    if ($submitted === '' || $expected === '' || !hash_equals($expected, $submitted)) {
        http_response_code(403);
        echo '<div style="font-family:Calibri,sans-serif;padding:40px;text-align:center;">'
           . '<h2>Request could not be verified</h2>'
           . '<p>This usually means the page was left open too long, or the form was submitted '
           . 'from somewhere it shouldn\'t have been. Please go back and try again.</p>'
           . '<p><a href="javascript:history.back()">Go back</a></p>'
           . '</div>';
        exit;
    }
}

/**
 * Same check as verifyCsrf(), but for the JSON API (api/save_reading.php)
 * where the token travels as a custom header instead of a form field,
 * since that endpoint is called via fetch(), not a submitted <form>.
 */
function verifyCsrfHeader(): void
{
    $submitted = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $expected  = $_SESSION['csrf_token'] ?? '';

    if ($submitted === '' || $expected === '' || !hash_equals($expected, $submitted)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Request could not be verified. Please reload the page and try again.']);
        exit;
    }
}

// ------------------------------------------------------------
// Password policy
//
// Used by setup_admin.php (first Admin account) and users/create.php
// (every account created afterwards) - the only two places a password
// is ever set in this system. Returns an array of human-readable
// problems; an empty array means the password is acceptable.
// ------------------------------------------------------------
function passwordPolicyErrors(string $password, string $username = '', string $fullName = ''): array
{
    $errors = [];

    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters long.';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'Password must include at least one uppercase letter.';
    }
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'Password must include at least one lowercase letter.';
    }
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = 'Password must include at least one number.';
    }

    // Block the handful of passwords that defeat every other rule above.
    $tooCommon = ['password', 'password1', 'Password1', 'Password123', '12345678',
                  'qwertyui', 'Qwerty123', 'Beitbridge1', 'Municipal1'];
    if (in_array($password, $tooCommon, true)) {
        $errors[] = 'That password is too common - please choose something less guessable.';
    }

    // Don't allow the password to just be the username or full name.
    if ($username !== '' && strcasecmp($password, $username) === 0) {
        $errors[] = 'Password cannot be the same as the username.';
    }
    if ($fullName !== '' && strcasecmp($password, $fullName) === 0) {
        $errors[] = 'Password cannot be the same as the full name.';
    }

    return $errors;
}

