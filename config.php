<?php
/**
 * config.php
 * Loads settings from a .env file (never committed to GitHub - see
 * .gitignore) with sensible defaults so the app also runs out of the box
 * on a fresh clone before you've created your own .env.
 *
 * Copy .env.example to .env and edit it if you want to change anything.
 */

/** Minimal .env parser - no external library needed. */
function loadEnv(string $path): void
{
    if (!file_exists($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        // Strip matching surrounding quotes, e.g. KEY="some value"
        if (strlen($value) >= 2 && $value[0] === $value[-1] && in_array($value[0], ['"', "'"], true)) {
            $value = substr($value, 1, -1);
        }
        if ($key !== '' && getenv($key) === false) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
        }
    }
}

loadEnv(__DIR__ . '/.env');

function env(string $key, $default = null)
{
    $value = getenv($key);
    return $value === false ? $default : $value;
}

// ------------------------------------------------------------
// Database - defaults to SQLite, a single file, no server needed.
// Set DB_DRIVER=mysql in .env if you would rather point this at MySQL.
// ------------------------------------------------------------
define('DB_DRIVER', env('DB_DRIVER', 'sqlite'));
define('DB_PATH', env('DB_PATH', 'data/municipal_system.sqlite'));

define('DB_HOST', env('DB_HOST', 'localhost'));
define('DB_NAME', env('DB_NAME', 'municipal_system'));
define('DB_USER', env('DB_USER', 'root'));
define('DB_PASS', env('DB_PASS', ''));

/**
 * Resolves DB_PATH into a real filesystem path, correctly, on both
 * Windows and Linux/Mac.
 *
 * The bug this fixes: PHP's __DIR__ on Windows returns backslashes
 * (e.g. C:\xampp\htdocs\municipal-system). The old check here only
 * recognised an absolute path if it started with "C:/" (forward slash).
 * A Windows absolute path like "C:\xampp\..." was treated as *relative*,
 * so __DIR__ got prepended a second time, producing a garbled path with
 * a drive letter stuck in the middle - which is what caused the
 * "mkdir(): No such file or directory" / "unable to open database file"
 * errors.
 */
function resolveDbPath(string $path): string
{
    // Already absolute? Unix root (/...), Windows drive (C:\ or C:/), or UNC (\\server\share)
    if (preg_match('#^(/|[a-zA-Z]:[\\\\/]|\\\\\\\\)#', $path)) {
        return $path;
    }
    return __DIR__ . DIRECTORY_SEPARATOR . $path;
}

// ------------------------------------------------------------
// Central database / workstation sync settings (Version 4.x).
//
// These back the local resilience layer in includes/local_store.php
// and the CLI sync worker cli_sync_engine.php. Read them from .env -
// the defaults are safe for a single-workstation SQLite install (they
// simply make the sync engine a no-op until a real central server is
// configured).
// ------------------------------------------------------------
define('LOCAL_SQLITE_PATH', env('LOCAL_SQLITE_PATH', 'data/municipal_local.sqlite'));
define('DEVICE_ID', env('DEVICE_ID', 'WORKSTATION-01'));
define('CENTRAL_SERVER_URL', env('CENTRAL_SERVER_URL', ''));

define('APP_NAME', env('APP_NAME', 'Municipal Police and Meter Reading Management System'));
define('APP_ENV', env('APP_ENV', 'local'));

// Session settings - 15 minute idle timeout, matching Activity 4, Section 5.2
define('SESSION_TIMEOUT_SECONDS', 15 * 60);

// Reserved for future use (Chapter 7, Section 5.0 - Future Plans): an SMS
// gateway key or similar would go here, read the same way as everything
// else above - never hard-coded, always from .env.
define('SMS_GATEWAY_API_KEY', env('SMS_GATEWAY_API_KEY', ''));

// ------------------------------------------------------------
// Error logging (Improvement 18: Global Error Handling).
//
// Every PHP notice/warning/error and every uncaught exception gets
// written to error_log.txt at the project root, which the Admin-only
// error_log_viewer.php page then shows. In production, the user sees
// includes/error_page.php instead of a raw stack trace; in local/dev,
// errors still print on screen so you can actually debug them.
//
// Must come AFTER APP_ENV is defined above, since the exception
// handler below reads it to decide whether to show details on-screen.
// ------------------------------------------------------------
define('ERROR_LOG_PATH', __DIR__ . '/error_log.txt');

/**
 * Writes one entry to error_log.txt. Deliberately never throws - a
 * logging failure must not become a second, worse error than the one
 * it was trying to record.
 */
function writeErrorLog(string $level, string $message, string $file = '', int $line = 0): void
{
    try {
        $entry = '[' . date('Y-m-d H:i:s') . "] {$level}: {$message}";
        if ($file !== '') {
            $entry .= " in {$file}" . ($line ? " on line {$line}" : '');
        }
        $entry .= PHP_EOL;

        @file_put_contents(ERROR_LOG_PATH, $entry, FILE_APPEND | LOCK_EX);
    } catch (Throwable $ignored) {
        // Nothing useful we can do here - swallow it so we don't
        // replace the real error with a logging error.
    }
}

/**
 * Handles PHP notices/warnings/deprecations. Doesn't stop execution -
 * PHP's normal behaviour continues after this returns.
 */
set_error_handler(function (int $severity, string $message, string $file = '', int $line = 0): bool {
    // Respect error_reporting() - suppressed errors (via @) stay
    // suppressed, everything else is logged.
    if (!(error_reporting() & $severity)) {
        return false;
    }

    $levelName = match ($severity) {
        E_ERROR, E_USER_ERROR           => 'Fatal',
        E_WARNING, E_USER_WARNING       => 'Warning',
        E_NOTICE, E_USER_NOTICE         => 'Notice',
        E_DEPRECATED, E_USER_DEPRECATED => 'Deprecated',
        default                         => 'Error',
    };

    writeErrorLog($levelName, $message, $file, $line);
    return false; // let PHP's built-in handler continue as normal
});

/**
 * Handles uncaught exceptions. In production, the visitor sees a
 * friendly page (includes/error_page.php) and the details go to the
 * log only. In local/dev, we still show the details so they're easy
 * to debug.
 */
set_exception_handler(function (Throwable $e): void {
    writeErrorLog(
        'Uncaught ' . get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    );
    writeErrorLog('Stack trace', str_replace("\n", ' | ', $e->getTraceAsString()));

    http_response_code(500);

    if (APP_ENV === 'production' && is_file(__DIR__ . '/includes/error_page.php')) {
        require __DIR__ . '/includes/error_page.php';
        exit;
    }

    // Local/dev: print the error so it's visible while developing.
    echo '<pre style="font-family:monospace;padding:20px;background:#FBEAEA;color:#7a2020;'
       . 'border:1px solid #C0392B;border-radius:6px;margin:20px;white-space:pre-wrap;">';
    echo htmlspecialchars(get_class($e) . ': ' . $e->getMessage()) . "\n";
    echo htmlspecialchars($e->getFile()) . ' on line ' . $e->getLine() . "\n\n";
    echo htmlspecialchars($e->getTraceAsString());
    echo '</pre>';
    exit;
});

/**
 * Catches fatal errors (E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR)
 * that set_error_handler() cannot handle - these would otherwise kill
 * the script without ever hitting the log. This is exactly the category
 * the "Undefined constant" fatal on error_log_viewer.php was in.
 */
register_shutdown_function(function (): void {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        writeErrorLog(
            'Fatal',
            $error['message'],
            $error['file'] ?? '',
            (int) ($error['line'] ?? 0)
        );
    }
});