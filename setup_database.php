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
        migrateDuplicateColumns($pdo);
        migrateUserActiveColumn($pdo);
        migrateIndexes($pdo);

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
 * Adds the duplicate-review columns to MeterReadings on older installs
 * (Improvement 2: Duplicate Record Detection). save_reading.php has always
 * detected possible duplicates, but only wrote them into ActivityLogs as
 * text - these columns let a dedicated review screen actually query for
 * them and record who resolved each one. Safe to run repeatedly.
 */
function migrateDuplicateColumns(PDO $pdo): void
{
    $columns = array_column($pdo->query('PRAGMA table_info(MeterReadings)')->fetchAll(), 'name');

    if (!in_array('is_duplicate_flagged', $columns, true)) {
        $pdo->exec('ALTER TABLE MeterReadings ADD COLUMN is_duplicate_flagged INTEGER NOT NULL DEFAULT 0');
    }
    if (!in_array('duplicate_resolved_by', $columns, true)) {
        $pdo->exec('ALTER TABLE MeterReadings ADD COLUMN duplicate_resolved_by INTEGER NULL');
    }
    if (!in_array('duplicate_resolved_at', $columns, true)) {
        $pdo->exec('ALTER TABLE MeterReadings ADD COLUMN duplicate_resolved_at TEXT NULL');
    }
}

/**
 * Adds Users.is_active on older installs (Improvement 11: User
 * Management - activate/deactivate accounts). Defaults every existing
 * account to active, so nobody is locked out by this migration.
 */
function migrateUserActiveColumn(PDO $pdo): void
{
    $columns = array_column($pdo->query('PRAGMA table_info(Users)')->fetchAll(), 'name');
    if (!in_array('is_active', $columns, true)) {
        $pdo->exec('ALTER TABLE Users ADD COLUMN is_active INTEGER NOT NULL DEFAULT 1');
    }
}

/**
 * Creates every secondary index from schema.sql (Improvement 15) on
 * older installs. CREATE INDEX IF NOT EXISTS makes this safe to run on
 * every setup_database.php visit, same as the CREATE TABLE statements.
 */
function migrateIndexes(PDO $pdo): void
{
    $indexes = [
        'idx_cases_status'      => 'Cases(status)',
        'idx_cases_officer'     => 'Cases(officer_id)',
        'idx_cases_date_opened' => 'Cases(date_opened)',
        'idx_fines_status'      => 'Fines(payment_status)',
        'idx_fines_date_issued' => 'Fines(date_issued)',
        'idx_fines_case'        => 'Fines(case_id)',
        'idx_payments_fine'     => 'Payments(fine_id)',
        'idx_wardassign_ward'   => 'WardAssignments(ward_id)',
        'idx_wardassign_user'   => 'WardAssignments(user_id)',
        'idx_houses_ward'       => 'Houses(ward_id)',
        'idx_houses_number'     => 'Houses(house_number)',
        'idx_readings_house'    => 'MeterReadings(house_id)',
        'idx_readings_date'     => 'MeterReadings(date_taken)',
        'idx_readings_reader'   => 'MeterReadings(reader_id)',
        'idx_readings_abnormal' => 'MeterReadings(is_abnormal)',
        'idx_readings_dupflag'  => 'MeterReadings(is_duplicate_flagged)',
        'idx_damaged_house'     => 'DamagedMeters(house_id)',
        'idx_damaged_status'    => 'DamagedMeters(repair_status)',
        'idx_damaged_reporter'  => 'DamagedMeters(reported_by)',
        'idx_visits_house'      => 'HouseVisits(house_id)',
        'idx_visits_reader'     => 'HouseVisits(reader_id)',
        'idx_visits_date'       => 'HouseVisits(visit_date)',
        'idx_activitylogs_user' => 'ActivityLogs(user_id)',
        'idx_activitylogs_time' => 'ActivityLogs(date_time)',
        'idx_users_role'        => 'Users(role)',
        'idx_users_active'      => 'Users(is_active)',
    ];
    foreach ($indexes as $name => $target) {
        $pdo->exec("CREATE INDEX IF NOT EXISTS {$name} ON {$target}");
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
