<?php
/**
 * includes/backup.php
 * Improvement 16: Backup and Recovery.
 *
 * Dumps every table's data (not the schema - schema.sql / schema.mysql.sql
 * already handle that, and setup_database.php recreates it idempotently)
 * as JSON rather than raw SQL. A hand-rolled SQL dumper has to either
 * split multi-statement text on semicolons/newlines (fragile - a case
 * description or damage report can legitimately contain either) or
 * escape everything through $pdo->quote() and hope nothing slips through.
 * JSON with real PDO prepared-statement inserts on the way back in sidesteps
 * both problems and works identically for SQLite and MySQL.
 */

// Dependency order matters for restore (parents before children) and for
// delete (children before parents, i.e. this list reversed).
const BACKUP_TABLE_ORDER = [
    'Users', 'Wards', 'Cases', 'Fines', 'Payments', 'WardAssignments',
    'Houses', 'MeterReadings', 'DamagedMeters', 'HouseVisits', 'ActivityLogs',
];

function backupDir(): string
{
    $dir = __DIR__ . '/../backups';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    return $dir;
}

/** Dumps every table to a timestamped JSON file and returns its filename. */
function createBackup(PDO $db, ?int $byUserId = null): string
{
    $data = ['created_at' => date('c'), 'driver' => DB_DRIVER, 'tables' => []];
    foreach (BACKUP_TABLE_ORDER as $table) {
        $data['tables'][$table] = $db->query("SELECT * FROM {$table}")->fetchAll(PDO::FETCH_ASSOC);
    }

    $filename = 'backup-' . date('Ymd-His') . '.json';
    $path = backupDir() . '/' . $filename;
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));

    logActivity($byUserId, "Created database backup '{$filename}' ("
        . array_sum(array_map('count', $data['tables'])) . ' total row(s))');

    return $filename;
}

/** @return array<int, array{filename:string, size:int, created_at:int}> newest first */
function listBackups(): array
{
    $files = glob(backupDir() . '/backup-*.json') ?: [];
    $backups = array_map(fn($f) => [
        'filename'   => basename($f),
        'size'       => filesize($f),
        'created_at' => filemtime($f),
    ], $files);
    usort($backups, fn($a, $b) => $b['created_at'] <=> $a['created_at']);
    return $backups;
}

/**
 * Wipes every table's current data and reloads it from the given backup
 * file. Schema (tables/columns/constraints) is untouched - only the rows
 * change. Runs inside a transaction: if anything fails partway through,
 * nothing is left half-restored.
 */
function restoreBackup(PDO $db, string $filename, ?int $byUserId = null): void
{
    $path = backupDir() . '/' . basename($filename); // basename() blocks path traversal via the filename param
    if (!is_file($path)) {
        throw new RuntimeException('Backup file not found.');
    }
    $data = json_decode(file_get_contents($path), true);
    if (!is_array($data) || !isset($data['tables'])) {
        throw new RuntimeException('This file is not a valid backup.');
    }

    $db->beginTransaction();
    try {
        if (DB_DRIVER === 'mysql') {
            $db->exec('SET FOREIGN_KEY_CHECKS = 0');
        } else {
            $db->exec('PRAGMA foreign_keys = OFF');
        }

        foreach (array_reverse(BACKUP_TABLE_ORDER) as $table) {
            $db->exec("DELETE FROM {$table}");
        }

        foreach (BACKUP_TABLE_ORDER as $table) {
            $rows = $data['tables'][$table] ?? [];
            if (!$rows) {
                continue;
            }
            $columns = array_keys($rows[0]);
            $placeholders = ':' . implode(', :', $columns);
            $colList = implode(', ', $columns);
            $stmt = $db->prepare("INSERT INTO {$table} ({$colList}) VALUES ({$placeholders})");
            foreach ($rows as $row) {
                $stmt->execute($row);
            }
        }

        if (DB_DRIVER === 'mysql') {
            $db->exec('SET FOREIGN_KEY_CHECKS = 1');
        } else {
            $db->exec('PRAGMA foreign_keys = ON');
        }

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    logActivity($byUserId, "Restored database from backup '{$filename}'");
}
