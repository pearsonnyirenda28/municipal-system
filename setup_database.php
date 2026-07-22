<?php
/**
 * setup_database.php
 * Run this ONCE in your browser to create the SQLite database file and
 * all tables (Chapter 4, Section 3.2), before running setup_admin.php.
 *
 * There is no phpMyAdmin-style tool for SQLite, so this replaces the old
 * "import schema.sql via phpMyAdmin" step with a single click.
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

        $success = true;
    } catch (Throwable $e) {
        $message = 'Setup failed: ' . $e->getMessage();
    }
}

/**
 * Version 2.5 migration: older installs have a Houses table with a
 * free-text `ward` column and no `street`/`ward_id` columns at all
 * (CREATE TABLE IF NOT EXISTS in schema.sql above won't touch an
 * already-existing table). This brings any such database up to date
 * without losing data: adds the new columns, turns each distinct old
 * `ward` value into a real row in Wards, and points every house at the
 * right one. Safe to run repeatedly - every step checks first.
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

    // Nothing to backfill from if this install never had the old text column.
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
 * Version 3.5 migration: adds Fines.client_ref for older installs, used
 * to make offline-issued fines idempotent on sync (same pattern as
 * MeterReadings.client_ref from Version 3.0). Safe to run repeatedly.
 */
function migrateFinesClientRef(PDO $pdo): void
{
    $columns = array_column($pdo->query('PRAGMA table_info(Fines)')->fetchAll(), 'name');
    if (!in_array('client_ref', $columns, true)) {
        $pdo->exec('ALTER TABLE Fines ADD COLUMN client_ref TEXT NULL');
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
            CREATE TABLE IF NOT EXISTS, and the one-time Version 2.5 ward migration only ever
            adds columns/rows, never removes anything.
        </p>
    </div>
</div>
</body>
</html>
