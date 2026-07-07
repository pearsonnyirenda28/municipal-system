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

define('APP_NAME', 'Municipal Police and Meter Reading Management System');
define('APP_ENV', env('APP_ENV', 'local'));

// Session settings - 15 minute idle timeout, matching Activity 4, Section 5.2
define('SESSION_TIMEOUT_SECONDS', 15 * 60);

// Reserved for future use (Chapter 7, Section 5.0 - Future Plans): an SMS
// gateway key or similar would go here, read the same way as everything
// else above - never hard-coded, always from .env.
define('SMS_GATEWAY_API_KEY', env('SMS_GATEWAY_API_KEY', ''));
