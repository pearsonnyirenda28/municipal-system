<?php
/**
 * includes/db.php
 * One shared PDO connection used by every page.
 * Fully Windows-compatible with proper path handling.
 */

require_once __DIR__ . '/../config.php';

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
                // SQLite - Windows-compatible path handling
                $path = DB_PATH;
                
                // If the path is not absolute, prepend the project root
                if (!preg_match('#^([a-zA-Z]:)?[/\\\\]#', $path)) {
                    $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . $path;
                }
                
                // Normalize slashes to the current OS standard
                $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
                
                // Ensure the directory exists
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
 * Writes one row to ActivityLogs (full audit trail).
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
        error_log('logActivity failed: ' . $e->getMessage());
    }
}