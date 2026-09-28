<?php
/**
 * includes/local_store.php
 * Local Workstation SQLite Safety & Resilience Layer.
 * Handles primary local persistence and local transactional queueing.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

/**
 * Singleton database connection wrapper for the local SQLite database.
 *
 * @return PDO
 */
function getLocalDb(): PDO {
    static $localPdo = null;

    if ($localPdo === null) {
        $dbPath = resolveDbPath(LOCAL_SQLITE_PATH);
        $dir = dirname($dbPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $localPdo = new PDO("sqlite:{$dbPath}", null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        // Enable write-ahead logging (WAL) for concurrency resilience
        $localPdo->exec("PRAGMA journal_mode = WAL;");

        // Initialize local queue schema
        $localPdo->exec("
            CREATE TABLE IF NOT EXISTS LocalSyncQueue (
                queue_id INTEGER PRIMARY KEY AUTOINCREMENT,
                sync_id TEXT NOT NULL UNIQUE,
                table_name TEXT NOT NULL,
                operation TEXT NOT NULL DEFAULT 'INSERT',
                payload TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'PENDING',
                error_message TEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE INDEX IF NOT EXISTS idx_queue_status ON LocalSyncQueue(status);

            CREATE TABLE IF NOT EXISTS MeterReadings (
                reading_id INTEGER PRIMARY KEY AUTOINCREMENT,
                sync_id TEXT NOT NULL UNIQUE,
                device_id TEXT NOT NULL,
                house_id INTEGER NOT NULL,
                reader_id INTEGER NOT NULL,
                previous_reading REAL NOT NULL DEFAULT 0.00,
                current_reading REAL NOT NULL,
                reading_date DATETIME DEFAULT CURRENT_TIMESTAMP,
                sync_status TEXT NOT NULL DEFAULT 'PENDING',
                synced_at DATETIME NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS Fines (
                fine_id INTEGER PRIMARY KEY AUTOINCREMENT,
                sync_id TEXT NOT NULL UNIQUE,
                device_id TEXT NOT NULL,
                house_id INTEGER NOT NULL,
                issuer_id INTEGER NOT NULL,
                reason TEXT NOT NULL,
                amount REAL NOT NULL,
                issued_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                sync_status TEXT NOT NULL DEFAULT 'PENDING',
                synced_at DATETIME NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS HouseVisits (
                visit_id INTEGER PRIMARY KEY AUTOINCREMENT,
                sync_id TEXT NOT NULL UNIQUE,
                device_id TEXT NOT NULL,
                house_id INTEGER NOT NULL,
                officer_id INTEGER NOT NULL,
                notes TEXT NOT NULL,
                visited_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                sync_status TEXT NOT NULL DEFAULT 'PENDING',
                synced_at DATETIME NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
        ");
    }

    return $localPdo;
}

/**
 * Generates a pseudo-random UUID v4 string for globally unique sync IDs.
 *
 * @return string
 */
function generateSyncId(): string {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * Atomically writes transaction to local SQLite storage and enqueues sync request.
 *
 * @param string $tableName Destination SQLite table name.
 * @param array $data Record data key-value array.
 * @return string Generated sync_id string.
 * @throws Exception
 */
function saveLocalTransaction(string $tableName, array $data): string {
    $db = getLocalDb();
    $syncId = generateSyncId();
    $deviceId = DEVICE_ID;
    $now = date('Y-m-d H:i:s');

    $data['sync_id'] = $syncId;
    $data['device_id'] = $deviceId;
    $data['sync_status'] = 'PENDING';
    $data['created_at'] = $now;

    $db->beginTransaction();
    try {
        // 1. Dynamic Local Insert
        $columns = array_keys($data);
        $colList = implode(', ', array_map(fn($c) => "`{$c}`", $columns));
        $placeholders = implode(', ', array_map(fn($c) => ":{$c}", $columns));

        $stmt = $db->prepare("INSERT INTO `{$tableName}` ({$colList}) VALUES ({$placeholders})");
        $stmt->execute($data);

        // 2. Queue for Central Sync
        $queueStmt = $db->prepare("
            INSERT INTO LocalSyncQueue (sync_id, table_name, operation, payload, status, created_at, updated_at)
            VALUES (:sync_id, :table_name, 'INSERT', :payload, 'PENDING', :created_at, :updated_at)
        ");
        $queueStmt->execute([
            'sync_id' => $syncId,
            'table_name' => $tableName,
            'payload' => json_encode($data, JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now
        ]);

        $db->commit();
        return $syncId;

    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}