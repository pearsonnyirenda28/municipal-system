<?php
/**
 * migrate_to_mysql.php
 * Copies every row from the local SQLite database (used for development)
 * into a MySQL database (used for production / "the main database that
 * keeps records of everything").
 *
 * BEFORE running this:
 *   1. Leave DB_DRIVER=sqlite in your .env for now - this script opens
 *      both databases directly, independent of DB_DRIVER, so it doesn't
 *      matter what it's set to, but it's clearer to migrate first and
 *      switch over second.
 *   2. Fill in DB_HOST / DB_NAME / DB_USER / DB_PASS in your .env for
 *      the MySQL server you're moving to.
 *   3. Import schema.mysql.sql into that MySQL database (phpMyAdmin's
 *      Import tab, or `mysql -u root -p municipal_system < schema.mysql.sql`).
 *      This script copies DATA only - it does not create tables.
 *
 * AFTER running this successfully:
 *   Change DB_DRIVER=mysql in your .env. The app will then use MySQL as
 *   the live/main database going forward. Your SQLite file is left
 *   untouched, so nothing is lost if anything here goes wrong.
 *
 * Safe to re-run: every insert uses INSERT IGNORE, so rows already
 * present in MySQL (matched by primary key) are simply skipped again.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';

$results = [];
$error = null;
$ran = false;

// Tables in dependency order - parents before children, so foreign keys
// never point at a row that hasn't been copied across yet.
$tables = [
    'Users'          => ['user_id', 'username', 'password_hash', 'role', 'full_name', 'created_at'],
    'Wards'          => ['ward_id', 'ward_name', 'description'],
    'WardAssignments' => ['assignment_id', 'ward_id', 'user_id', 'assigned_at'],
    'Houses'         => ['house_id', 'house_number', 'street', 'ward_id', 'owner_name', 'meter_number', 'address'],
    'Cases'          => ['case_id', 'case_number', 'offender_name', 'id_number', 'offence_type',
                          'description', 'status', 'officer_id', 'date_opened'],
    'Fines'          => ['fine_id', 'fine_number', 'case_id', 'offender_name', 'house_number',
                          'vehicle_plate', 'offence_type', 'amount', 'payment_status', 'issued_by', 'date_issued'],
    'Payments'       => ['payment_id', 'fine_id', 'amount_received', 'date_paid', 'received_by', 'receipt_number'],
    'MeterReadings'  => ['reading_id', 'house_id', 'previous_reading', 'current_reading', 'consumption',
                          'is_abnormal', 'reader_id', 'date_taken', 'client_ref'],
    'DamagedMeters'  => ['damage_id', 'house_id', 'description', 'photo_path', 'reported_by',
                          'date_reported', 'repair_status', 'date_fixed'],
    'ActivityLogs'   => ['log_id', 'user_id', 'action_description', 'ip_address', 'date_time'],
];

// Primary key column per table, used afterwards to re-sync MySQL's
// AUTO_INCREMENT counter so new rows don't collide with migrated IDs.
$primaryKeys = [
    'Users' => 'user_id', 'Wards' => 'ward_id', 'WardAssignments' => 'assignment_id',
    'Houses' => 'house_id', 'Cases' => 'case_id', 'Fines' => 'fine_id',
    'Payments' => 'payment_id', 'MeterReadings' => 'reading_id', 'DamagedMeters' => 'damage_id',
    'ActivityLogs' => 'log_id',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $ran = true;
    try {
        $sqlitePath = resolveDbPath(DB_PATH);
        if (!file_exists($sqlitePath)) {
            throw new RuntimeException("No SQLite database found at {$sqlitePath}. Nothing to migrate.");
        }
        $sqlite = new PDO('sqlite:' . $sqlitePath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $mysqlDsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $mysql = new PDO($mysqlDsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        $mysql->exec('SET FOREIGN_KEY_CHECKS = 0');

        foreach ($tables as $table => $columns) {
            $colList = implode(', ', $columns);
            $rows = $sqlite->query("SELECT {$colList} FROM {$table}")->fetchAll();

            $placeholders = implode(', ', array_map(fn($c) => ":{$c}", $columns));
            $insertStmt = $mysql->prepare("INSERT IGNORE INTO {$table} ({$colList}) VALUES ({$placeholders})");

            $copied = 0;
            foreach ($rows as $row) {
                // SQLite stores booleans as 0/1 integers already - passes through fine to MySQL's BOOLEAN/TINYINT.
                $insertStmt->execute($row);
                $copied += $insertStmt->rowCount();
            }

            // Re-sync AUTO_INCREMENT so the next INSERT in MySQL doesn't collide with a migrated ID.
            $pk = $primaryKeys[$table];
            $maxId = (int) $mysql->query("SELECT COALESCE(MAX({$pk}), 0) FROM {$table}")->fetchColumn();
            if ($maxId > 0) {
                $mysql->exec("ALTER TABLE {$table} AUTO_INCREMENT = " . ($maxId + 1));
            }

            $results[$table] = ['found' => count($rows), 'copied' => $copied];
        }

        $mysql->exec('SET FOREIGN_KEY_CHECKS = 1');

    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Migrate SQLite &rarr; MySQL</title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="login-wrap">
    <div class="login-box" style="width:520px;">
        <h1>Migrate SQLite &rarr; MySQL</h1>
        <p class="subtitle">Copies every record from your local SQLite database into MySQL.</p>

        <?php if (!$ran): ?>
            <div class="alert warning">
                Before clicking below, make sure: (1) <code>.env</code> has your MySQL
                <code>DB_HOST</code> / <code>DB_NAME</code> / <code>DB_USER</code> / <code>DB_PASS</code>
                filled in, and (2) you have already imported <code>schema.mysql.sql</code> into that
                MySQL database (this script copies data only, it does not create tables).
            </div>
            <form method="post">
                <?= csrfField() ?>
                <button type="submit" class="btn" style="width:100%;">Start Migration</button>
            </form>

        <?php elseif ($error): ?>
            <div class="alert error">Migration failed: <?= htmlspecialchars($error) ?></div>
            <form method="post"><?= csrfField() ?><button type="submit" class="btn secondary" style="width:100%;">Try Again</button></form>

        <?php else: ?>
            <div class="alert success">Migration completed.</div>
            <table class="data-table" style="margin-top:14px;">
                <thead><tr><th>Table</th><th>Found in SQLite</th><th>Newly copied to MySQL</th></tr></thead>
                <tbody>
                    <?php foreach ($results as $table => $r): ?>
                        <tr>
                            <td><?= htmlspecialchars($table) ?></td>
                            <td><?= (int) $r['found'] ?></td>
                            <td><?= (int) $r['copied'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p style="font-size:0.85rem;margin-top:16px;">
                "Found" but not "copied" usually just means that row was already migrated in a
                previous run (INSERT IGNORE skips it safely).
            </p>
            <p style="font-size:0.85rem;">
                <strong>Next step:</strong> set <code>DB_DRIVER=mysql</code> in your <code>.env</code>
                file. The app will then read and write MySQL as the main database from here on.
            </p>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
