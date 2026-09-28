<?php
/**
 * setup_database.php
 * Run this ONCE in your browser to create the SQLite database file and
 * all tables (Chapter 4, Section 3.2), before running setup_admin.php.
 *
 * There is no phpMyAdmin-style tool for SQLite, so this replaces the old
 * "import schema.sql via phpMyAdmin" step with a single click.
 *
 * Safe to run more than once on an existing install: the CREATE TABLE
 * statements use IF NOT EXISTS, and every migrate*() function below
 * checks whether its column already exists before adding it.
 */
require_once __DIR__ . '/config.php';

$message = '';
$success = false;

if (DB_DRIVER !== 'sqlite') {
    $message = 'DB_DRIVER is set to "' . DB_DRIVER . '" in your .env file. This script only '
             . 'sets up SQLite - please import schema.sql into MySQL yourself, or remove '
             . 'DB_DRIVER from .env to use SQLite instead.';
} else {
    try {
        $path = resolveDbPath(DB_PATH);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');

        $sql = file_get_contents(__DIR__ . '/schema.sql');
        $pdo->exec($sql);

        migrateWardColumns($pdo);
        migrateFinesClientRef($pdo);
        migrateDuplicateTrackingColumns($pdo);
        migrateDiscardColumns($pdo);

        $success = true;
    } catch (Throwable $e) {
        $message = 'Setup failed: ' . $e->getMessage();
    }
}

/**
 * Version 2.5 migration: brings an older Houses table (with free-text
 * `ward` column) up to date, without losing data.
 */
function migrateWardColumns(PDO $pdo): void
{
    $columns = array_column($pdo->query('PRAGMA table_info(Houses)')->fetchAll(), 'name');

    if (!in_array('street', $columns, true)) {
        $pdo->exec('ALTER TABLE Houses ADD COLUMN street TEXT NULL');
    }
    if (!in_array('ward_id', $columns, true)) {
        $pdo->exec('ALTER TABLE Houses ADD COLUMN ward_id INTEGER NULL');
    }

    if (!in_array('ward', $columns, true)) {
        return;
    }

    $oldWards = $pdo->query("SELECT DISTINCT ward FROM Houses WHERE ward IS NOT NULL AND ward != ''")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($oldWards as $wardName) {
        $pdo->prepare('INSERT OR IGNORE INTO Wards (ward_name) VALUES (:name)')->execute(['name' => $wardName]);
        $pdo->prepare('UPDATE Houses SET ward_id = (SELECT ward_id FROM Wards WHERE ward_name = :name) WHERE ward = :name AND ward_id IS NULL')
            ->execute(['name' => $wardName]);
    }
}

/**
 * Version 3.5 migration: adds Fines.client_ref for older installs.
 */
function migrateFinesClientRef(PDO $pdo): void
{
    $columns = array_column($pdo->query('PRAGMA table_info(Fines)')->fetchAll(), 'name');
    if (!in_array('client_ref', $columns, true)) {
        $pdo->exec('ALTER TABLE Fines ADD COLUMN client_ref TEXT NULL');
    }
}

/**
 * Version 3.6 migration: adds the duplicate-tracking columns to
 * MeterReadings and Fines. Safe to re-run.
 */
function migrateDuplicateTrackingColumns(PDO $pdo): void
{
    foreach (['MeterReadings', 'Fines'] as $table) {
        $columns = array_column($pdo->query("PRAGMA table_info({$table})")->fetchAll(), 'name');

        if (!in_array('is_duplicate_flagged', $columns, true)) {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN is_duplicate_flagged INTEGER NOT NULL DEFAULT 0");
        }
        if (!in_array('duplicate_resolved_by', $columns, true)) {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN duplicate_resolved_by INTEGER NULL");
        }
        if (!in_array('duplicate_resolved_at', $columns, true)) {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN duplicate_resolved_at TEXT NULL");
        }
    }
}

/**
 * Version 3.6 migration: adds the discard (soft-delete) columns to
 * MeterReadings and Fines. When a supervisor resolves a duplicate
 * group by keeping one record, the losing rows are marked here rather
 * than deleted, so the audit trail is never lost. Safe to re-run.
 */
function migrateDiscardColumns(PDO $pdo): void
{
    foreach (['MeterReadings', 'Fines'] as $table) {
        $columns = array_column($pdo->query("PRAGMA table_info({$table})")->fetchAll(), 'name');

        if (!in_array('discarded_at', $columns, true)) {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN discarded_at TEXT NULL");
        }
        if (!in_array('discarded_by', $columns, true)) {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN discarded_by INTEGER NULL");
        }
        if (!in_array('discard_reason', $columns, true)) {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN discard_reason TEXT NULL");
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Database Setup</title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="login-wrap">
    <div class="login-box">
        <h1>Database Setup</h1>

        <?php if ($success): ?>
            <div class="alert success">
                Database created/updated successfully at <code><?= htmlspecialchars($path) ?></code>,
                with two sample wards and three sample houses ready for testing.
            </div>
            <p style="font-size:0.85rem;">Next step: create your Administrator account.</p>
            <a class="btn" href="setup_admin.php" style="width:100%;text-align:center;">Continue to Admin Setup</a>
        <?php else: ?>
            <div class="alert error"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <p style="font-size:0.75rem;color:#8a94a3;margin-top:18px;">
            Safe to run more than once. Existing data is never deleted - new tables use
            CREATE TABLE IF NOT EXISTS, and every migrate*() function only ever adds
            columns/rows, never removes anything.
        </p>
    </div>
</div>
</body>
</html>