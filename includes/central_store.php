<?php
/**
 * includes/central_store.php
 * The Workspace Server side of the sync layer (Version 4.5) - the
 * counterpart to includes/local_store.php on each field workstation.
 * A Workspace Server is just this same codebase running with
 * DB_DRIVER=mysql; getCentralDb() is that instance's own central MySQL
 * connection, used by api/sync_push.php and api/sync_pull.php (which
 * are called by field workstations over the LAN, not by browsers, so
 * they open their own connection here rather than going through the
 * session-oriented getDb() flow in includes/db.php).
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

function getCentralDb(): PDO
{
    static $mysqlPdo = null;
    if ($mysqlPdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $mysqlPdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $mysqlPdo;
}

/**
 * Both sync_push.php and sync_pull.php require this same check -
 * machine-to-machine LAN traffic with no browser session behind it, so
 * without it anyone who can reach these URLs could read or write the
 * central MySQL directly. An empty SYNC_API_KEY (the default, before an
 * operator configures one) refuses every request rather than accepting
 * everything, so a forgotten setup step fails loudly instead of quietly
 * running unauthenticated.
 */
function requireSyncApiKey(): void
{
    $providedKey = $_SERVER['HTTP_X_SYNC_KEY'] ?? '';
    if (SYNC_API_KEY === '' || !hash_equals(SYNC_API_KEY, $providedKey)) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Missing or invalid X-Sync-Key.']);
        exit;
    }
}
