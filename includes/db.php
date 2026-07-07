<?php
/**
 * includes/db.php
 * One shared PDO connection used by every page (Chapter 5, Section 2.1:
 * "Database access is centralised through a single Database class").
 * Defaults to SQLite (DB_DRIVER=sqlite in .env / config.php) - a single
 * file, no server to install or configure. Set DB_DRIVER=mysql in .env
 * if you would rather point this at a MySQL/MariaDB server instead.
 */

require_once __DIR__ . '/../config.php';

/**
 * The handful of places this app needs "is this date in the current
 * month" or "is this date within the last N days/months" use SQLite and
 * MySQL functions that aren't compatible with each other (SQLite's
 * strftime()/date() vs MySQL's MONTH()/YEAR()/DATE_SUB()). These two
 * helpers return the right SQL fragment for whichever DB_DRIVER is
 * active, so the exact same PHP code works against either database -
 * which matters once you migrate from SQLite (development) to MySQL
 * (production) with migrate_to_mysql.php.
 */
function dbCurrentMonthMatch(string $column): string
{
    if (DB_DRIVER === 'mysql') {
        return "MONTH({$column}) = MONTH(CURDATE()) AND YEAR({$column}) = YEAR(CURDATE())";
    }
    return "strftime('%Y-%m', {$column}) = strftime('%Y-%m', CURRENT_DATE)";
}

function dbWithinLast(string $column, int $amount, string $unit = 'DAY'): string
{
    if (DB_DRIVER === 'mysql') {
        return "{$column} >= DATE_SUB(CURDATE(), INTERVAL {$amount} {$unit})";
    }
    $sqliteUnit = strtolower($unit) === 'month' ? 'months' : 'days';
    return "{$column} >= date('now', '-{$amount} {$sqliteUnit}')";
}

function getDb(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        try {
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            if (DB_DRIVER === 'mysql') {
                $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
                $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            } else {
                $path = resolveDbPath(DB_PATH);
                $dir = dirname($path);
                if (!is_dir($dir)) {
                    mkdir($dir, 0775, true);
                }
                $pdo = new PDO('sqlite:' . $path, null, null, $options);
                $pdo->exec('PRAGMA foreign_keys = ON');
            }
        } catch (PDOException $e) {
            error_log('Database connection failed: ' . $e->getMessage());
            http_response_code(500);
            die('Could not connect to the database. Run setup_database.php first, '
              . 'and check the DB_* settings in your .env file.');
        }
    }

    return $pdo;
}

/**
 * Writes one row to ActivityLogs (Chapter 4, Section 5.2 - full audit trail
 * of every login and data change).
 */
function logActivity(?int $userId, string $description): void
{
    try {
        $stmt = getDb()->prepare(
            'INSERT INTO ActivityLogs (user_id, action_description, ip_address, date_time)
             VALUES (:user_id, :description, :ip, CURRENT_TIMESTAMP)'
        );
        $stmt->execute([
            'user_id'     => $userId,
            'description' => $description,
            'ip'          => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (PDOException $e) {
        // Logging must never break the page it is called from.
        error_log('logActivity failed: ' . $e->getMessage());
    }
}
