<?php
/**
 * includes/auth.php
 * Session handling, login-required guard, role-based access guard,
 * account-lockout / session-timeout controls (Activity 4, Section 5.2),
 * CSRF protection, and baseline security headers.
 */

require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

    session_name(SESSION_COOKIE_NAME);          // own cookie name - see config.php
    ini_set('session.use_strict_mode', '1');    // refuse session IDs this server never issued (session fixation)
    ini_set('session.use_only_cookies', '1');   // never accept a session ID from the URL
    // PHP's own garbage collector must never delete a session sooner than the idle timeout allows.
    ini_set('session.gc_maxlifetime', (string) max(SESSION_TIMEOUT_SECONDS + 600, 1440));

    // Harden the session cookie itself before starting the session.
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',    // keep "/": the WebSocket handshake is on another port and path
        'secure'   => $isHttps,   // only sent over HTTPS when the site is served over HTTPS
        'httponly' => true,   // JavaScript can never read the session cookie
        'samesite' => 'Lax',  // blocks the cookie being sent on cross-site POSTs - first line of CSRF defence
    ]);
    session_start();
}

// ------------------------------------------------------------
// Baseline security headers - sent on every page that includes this
// file (which is effectively every page in the app).
//
// CSP is NOT set here. It is set authoritatively by includes/db.php,
// which is included first (see the require_once at the top). Sending a
// second, narrower CSP header here used to silently combine with
// db.php's policy (browsers enforce the intersection), which blocked
// every inline <script> block and every inline onclick/onchange/onsubmit
// handler in the app - including the Restore button on the backups
// page and the CSRF token inline scripts on capture.php.
// ------------------------------------------------------------
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');   // stops the browser "guessing" a file is HTML/JS when it isn't
    header('X-Frame-Options: DENY');              // stops this app being loaded inside someone else's <iframe> (clickjacking)
    header('Referrer-Policy: same-origin');
}

const MAX_FAILED_LOGINS = 5;
const LOCKOUT_MINUTES    = 10;

/** Fully ends the current session: empties it, expires the cookie in the browser,
 *  and destroys the server-side data. */
function endSession(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies') && !headers_sent()) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'] ?? 'Lax',
        ]);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

/** A fingerprint of the password hash. Stored in the session at login; if the account's
 *  password is changed or reset anywhere else, the fingerprints stop matching and every
 *  other open session of that account is ended. */
function sessionFingerprint(string $passwordHash): string
{
    return hash('sha256', $passwordHash);
}

/**
 * Is the current session still allowed to exist? Returns null if so, otherwise a short
 * reason: 'none' (nobody logged in), 'timeout', 'deactivated' or 'password_changed'.
 *
 * Every SESSION_REVALIDATE_SECONDS it also re-reads the account from the Users table, so
 * that deactivating a user, changing their role, or changing their password takes effect on
 * a session that is already open - before this, "Deactivate" only blocked nothing at all.
 */
function sessionProblem(): ?string
{
    if (empty($_SESSION['user_id'])) {
        return 'none';
    }

    if (isset($_SESSION['last_activity']) &&
        (time() - (int) $_SESSION['last_activity']) > SESSION_TIMEOUT_SECONDS) {
        return 'timeout';
    }

    if ((time() - (int) ($_SESSION['revalidated_at'] ?? 0)) >= SESSION_REVALIDATE_SECONDS) {
        try {
            try {
                $stmt = getDb()->prepare('SELECT full_name, role, is_active, password_hash FROM Users WHERE user_id = :id');
                $stmt->execute(['id' => $_SESSION['user_id']]);
                $u = $stmt->fetch();
            } catch (Throwable $noColumn) {
                // is_active is an optional column: databases created from the schema files
                // do not have it. Re-read without it (such accounts count as active) so the
                // role and password-change checks still work.
                $stmt = getDb()->prepare('SELECT full_name, role, password_hash FROM Users WHERE user_id = :id');
                $stmt->execute(['id' => $_SESSION['user_id']]);
                $u = $stmt->fetch();
                if ($u) { $u['is_active'] = null; }
            }
        } catch (Throwable $e) {
            error_log('sessionProblem(): could not re-check the account: ' . $e->getMessage());
            return null;   // a database hiccup must not log everybody out
        }
        if (!$u || ($u['is_active'] !== null && (int) $u['is_active'] === 0)) {
            return 'deactivated';
        }
        if (!empty($_SESSION['pw_fingerprint'])
            && !hash_equals($_SESSION['pw_fingerprint'], sessionFingerprint((string) $u['password_hash']))) {
            return 'password_changed';
        }
        $_SESSION['role']           = $u['role'];        // a role change applies immediately
        $_SESSION['full_name']      = $u['full_name'];
        $_SESSION['revalidated_at'] = time();
    }
    return null;
}

/** Redirects to the login page if nobody is logged in (or the session has timed out, or the
 *  account was deactivated / its password changed), and enforces the idle session timeout
 *  (Activity 4, Section 5.2). */
function requireLogin(): void
{
    $problem = sessionProblem();
    if ($problem !== null) {
        endSession();
        header('Location: /municipal-system/login.php' . ($problem === 'none' ? '' : '?reason=' . $problem));
        exit;
    }
    $_SESSION['last_activity'] = time();
}

/**
 * Guard for the JSON API endpoints (api/save_*.php, api/mark_visit.php).
 *
 * Unlike requireLogin() it never redirects: it answers 401 with a machine-readable
 * "code", so the browser's offline queue can tell "your session ended" (keep the record,
 * ask the user to log in again) from "this record is bad" (count a failed attempt). It also
 * applies the same idle-timeout / deactivation / password-change rules as page loads, which
 * the API endpoints previously skipped entirely.
 *
 * The queue sends X-Queued-By: the id of the user who saved the record on the device. If that
 * is not the user now logged in, the request is refused (409) so that, on a shared computer,
 * one person's saved records can never be attributed to whoever logs in next.
 */
function requireApiLogin(array $allowedRoles = []): void
{
    header('Content-Type: application/json');

    $problem = sessionProblem();
    if ($problem !== null) {
        endSession();
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'code'    => 'SESSION_EXPIRED',
            'reason'  => $problem,
            'message' => 'Your session has ended. Please log in again - your saved records are still on this device.',
        ]);
        exit;
    }

    if ($allowedRoles && !in_array($_SESSION['role'] ?? '', $allowedRoles, true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'code' => 'FORBIDDEN', 'message' => 'You do not have permission to do this.']);
        exit;
    }

    $queuedBy = $_SERVER['HTTP_X_QUEUED_BY'] ?? '';
    if ($queuedBy !== '' && (int) $queuedBy !== (int) $_SESSION['user_id']) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'code'    => 'WRONG_USER',
            'message' => 'These saved records belong to a different user. They will sync when that user logs in on this device.',
        ]);
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

/** The audit-log text for one failed attempt. The single source of truth: written by
 *  recordFailedLogin(), counted by recentFailedLogins(). */
function failedLoginDescription(string $username): string
{
    return "Failed login attempt for username '" . trim($username) . "'";
}

/**
 * Failed attempts for this username inside the lockout window, counting only those after
 * their last successful login. Counted from ActivityLogs - the server's own record - NOT
 * from the visitor's session: a counter kept in the session is wiped by simply discarding
 * the cookie, so the old lockout never stopped anyone. Times are compared using the
 * DATABASE clock, because logActivity() stamps rows with CURRENT_TIMESTAMP (UTC on SQLite).
 */
function recentFailedLogins(string $username): int
{
    $db   = getDb();
    $mins = (int) LOCKOUT_MINUTES;
    $since = (string) (DB_DRIVER === 'mysql'
        ? $db->query("SELECT DATE_FORMAT(DATE_SUB(NOW(), INTERVAL {$mins} MINUTE), '%Y-%m-%d %H:%i:%s')")->fetchColumn()
        : $db->query("SELECT datetime('now', '-{$mins} minutes')")->fetchColumn());

    $idStmt = $db->prepare('SELECT user_id FROM Users WHERE LOWER(username) = LOWER(:u)');
    $idStmt->execute(['u' => trim($username)]);
    $userId = $idStmt->fetchColumn();
    if ($userId !== false) {
        $okStmt = $db->prepare("SELECT MAX(date_time) FROM ActivityLogs WHERE user_id = :id AND action_description = 'Logged in'");
        $okStmt->execute(['id' => $userId]);
        $lastOk = $okStmt->fetchColumn();
        if ($lastOk && (string) $lastOk > $since) {
            $since = (string) $lastOk;   // a successful login resets the count
        }
    }

    $cnt = $db->prepare(
        'SELECT COUNT(*) FROM ActivityLogs
          WHERE user_id IS NULL AND LOWER(action_description) = LOWER(:d) AND date_time > :since'
    );
    $cnt->execute(['d' => failedLoginDescription($username), 'since' => $since]);
    return (int) $cnt->fetchColumn();
}

/** Returns true if this account is currently locked out. */
function isAccountLocked(string $username): bool
{
    try {
        return recentFailedLogins($username) >= MAX_FAILED_LOGINS;
    } catch (Throwable $e) {
        error_log('isAccountLocked(): ' . $e->getMessage());
        return false;   // never lock everybody out because the log could not be read
    }
}

function recordFailedLogin(string $username): void
{
    logActivity(null, failedLoginDescription($username));
}

function clearFailedLogins(string $username): void
{
    // Nothing to delete: the successful login is itself written to ActivityLogs ('Logged in')
    // and recentFailedLogins() only counts failures after it. The audit trail stays intact.
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
        echo json_encode(['success' => false, 'code' => 'CSRF', 'message' => 'Request could not be verified. Please reload the page and try again.']);
        exit;
    }
}

// ------------------------------------------------------------
// Password policy
//
// Used by setup_admin.php (first Admin account), users/create.php
// (every account created afterwards), users/reset_password.php (Admin
// setting a temporary password), and profile.php (any user changing
// their own password). Returns an array of human-readable problems;
// an empty array means the password is acceptable.
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

// ------------------------------------------------------------
// Page access map — single source of truth for "who can reach which page".
//
// Both the hamburger (includes/header.php) and each page's own
// requireRole() read from this map. The entries below are kept in exact
// agreement with the actual requireRole() lines on every page file -
// verified file by file, not assumed from documentation.
//
// Adding a page: add one row here AND make requireRole() match.
// Removing a role from a page: change one line here and the matching
// requireRole() line on the page itself. Nothing else to update.
//
// Deny-by-default: an unknown path returns false from canAccess(), so a
// page someone forgets to add is invisible in the menu rather than
// silently exposed.
//
// Note on PoliceSupervisor: this role is deliberately scoped to
// read-only police reports (Case Report, Fine Report). It does NOT see
// the Cases or Fines management lists - those are for Admin, Officer
// and Clerk, who actually create and process case/fine records.
// ------------------------------------------------------------
const PAGE_ACCESS = [
    // Any logged-in user
    'dashboard.php'                    => 'any',
    'profile.php'                      => 'any',

    // Police side — management lists
    'cases/list.php'                   => ['Admin', 'Officer'],
    'cases/create.php'                 => ['Admin', 'Officer'],
    'cases/update_status.php'          => ['Admin', 'Officer'],
    'fines/list.php'                   => ['Admin', 'Officer', 'Clerk'],
    'fines/issue.php'                  => ['Admin', 'Officer'],
    'fines/pay.php'                    => ['Admin', 'Officer', 'Clerk'],
    'fines/receipt.php'                => ['Admin', 'Officer', 'Clerk'],

    // Meter side
    'meter-readings/list.php'          => ['Admin', 'MeterReader', 'MeterSupervisor'],
    'meter-readings/capture.php'       => ['Admin', 'MeterReader'],
    'meter-readings/duplicates.php'    => ['Admin', 'MeterSupervisor'],
    'damaged-meters/list.php'          => ['Admin', 'MeterReader', 'MeterSupervisor'],
    'damaged-meters/report.php'        => ['Admin', 'MeterReader'],
    'damaged-meters/update_status.php' => ['Admin'],

    // Admin-only management
    'wards/list.php'                   => ['Admin'],
    'wards/create.php'                 => ['Admin'],
    'wards/assign.php'                 => ['Admin'],
    'houses/list.php'                  => ['Admin'],
    'houses/create.php'                => ['Admin'],
    'houses/edit.php'                  => ['Admin'],
    'houses/bulk_assign.php'           => ['Admin'],

    'users/list.php'                   => ['Admin'],
    'users/create.php'                 => ['Admin'],
    'users/edit.php'                   => ['Admin'],
    'users/reset_password.php'         => ['Admin'],
    'users/login_history.php'          => ['Admin'],
    'users/toggle_active.php'          => ['Admin'],

    'backup.php'                       => ['Admin'],
    'error_log_viewer.php'             => ['Admin'],
    'sync/conflicts.php'               => ['Admin'],
    'sync/status.php'                  => ['Admin'],

    // Reports — each role only sees its own side
    'reports/index.php'                => ['Admin', 'PoliceSupervisor', 'MeterSupervisor'],
    'reports/cases.php'                => ['Admin', 'PoliceSupervisor'],
    'reports/cases_pdf.php'            => ['Admin', 'PoliceSupervisor'],
    'reports/fines.php'                => ['Admin', 'PoliceSupervisor'],
    'reports/fines_pdf.php'            => ['Admin', 'PoliceSupervisor'],
    'reports/readings.php'             => ['Admin', 'MeterSupervisor'],
    'reports/readings_pdf.php'         => ['Admin', 'MeterSupervisor'],
    'reports/damage.php'               => ['Admin', 'MeterSupervisor'],
    'reports/damage_pdf.php'           => ['Admin', 'MeterSupervisor'],
];

/**
 * Returns true if the currently logged-in user may reach $path.
 *
 * $path is relative to the project root, e.g. 'cases/create.php'.
 * Accepts an optional leading slash or 'municipal-system/' prefix so
 * callers can pass either form.
 */
function canAccess(string $path): bool
{
    if (empty($_SESSION['user_id'])) {
        return false;
    }

    $path = ltrim($path, '/');
    $path = preg_replace('#^municipal-system/#', '', $path);

    if (!isset(PAGE_ACCESS[$path])) {
        return false;
    }

    $allowed = PAGE_ACCESS[$path];
    if ($allowed === 'any') {
        return true;
    }
    return in_array($_SESSION['role'] ?? '', $allowed, true);
}