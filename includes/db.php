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

/**
 * Improvement 3 (dashboard trend charts): a "YYYY-MM" SQL expression for
 * GROUP BY, in whichever dialect DB_DRIVER needs. Used with
 * monthlyBuckets() below, which fills in any month a GROUP BY skipped
 * because it had zero rows, so a chart never silently drops a bar.
 */
function dbMonthKey(string $column): string
{
    if (DB_DRIVER === 'mysql') {
        return "DATE_FORMAT({$column}, '%Y-%m')";
    }
    return "strftime('%Y-%m', {$column})";
}

/**
 * Builds the last $months month keys (oldest first, e.g. ['2026-02', ...,
 * '2026-07']) and maps $rows (each with a 'month' key from dbMonthKey()
 * and one or more count columns) onto them, so every month appears even
 * with zero activity.
 */
function monthlyBuckets(array $rows, int $months, array $valueColumns): array
{
    $byMonth = [];
    foreach ($rows as $row) {
        $byMonth[$row['month']] = $row;
    }

    $result = [];
    for ($i = $months - 1; $i >= 0; $i--) {
        $key = date('Y-m', strtotime("-{$i} months"));
        $entry = ['month' => $key, 'label' => date('M', strtotime($key . '-01'))];
        foreach ($valueColumns as $col) {
            $entry[$col] = isset($byMonth[$key]) ? (float) $byMonth[$key][$col] : 0;
        }
        $result[] = $entry;
    }
    return $result;
}

/**
 * Version 2.5: which wards is this meter reader assigned to? Returns an
 * empty array for anyone who isn't a MeterReader (Admin isn't scoped by
 * ward at all - it sees everything, checked separately by each caller).
 */
function getAssignedWardIds(PDO $db, int $userId): array
{
    $stmt = $db->prepare('SELECT ward_id FROM WardAssignments WHERE user_id = :uid');
    $stmt->execute(['uid' => $userId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Version 3.0 - Guided Field Operations: the reader's ordered "round" for
 * a given day - every house in scope, walking order (street, then house
 * number), each tagged with today's visit status ('Pending' if nothing
 * has been recorded yet). Pass $wardIds = null for an unrestricted round
 * (Admin); pass an array (possibly empty) to scope to those wards only.
 */
function getReaderRound(PDO $db, ?array $wardIds, int $readerId, string $date): array
{
    $whereWard = '';
    $wardParams = [];
    if ($wardIds !== null) {
        if (!$wardIds) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($wardIds), '?'));
        $whereWard = "WHERE h.ward_id IN ({$placeholders})";
        $wardParams = $wardIds;
    }

    $sql = "SELECT h.house_id, h.house_number, h.street, w.ward_name,
                   COALESCE(v.status, 'Pending') AS visit_status
            FROM Houses h
            LEFT JOIN Wards w ON w.ward_id = h.ward_id
            LEFT JOIN HouseVisits v ON v.house_id = h.house_id AND v.reader_id = ? AND v.visit_date = ?
            {$whereWard}
            ORDER BY h.street, h.house_number, h.house_id";

    $stmt = $db->prepare($sql);
    $stmt->execute(array_merge([$readerId, $date], $wardParams));
    return $stmt->fetchAll();
}

/**
 * Records or updates today's visit status for one house. Used both by
 * the explicit status buttons (Skip/Vacant/Locked/...) and, indirectly,
 * by a successful meter reading save (status becomes 'Completed').
 * A plain check-then-insert-or-update rather than SQLite's
 * "ON CONFLICT" or MySQL's "ON DUPLICATE KEY" - avoids yet another pair
 * of driver-specific SQL dialects for one simple operation.
 */
function upsertHouseVisit(PDO $db, int $houseId, int $readerId, string $date, string $status, ?int $readingId = null, ?string $notes = null): void
{
    $check = $db->prepare('SELECT visit_id FROM HouseVisits WHERE house_id = :h AND reader_id = :r AND visit_date = :d');
    $check->execute(['h' => $houseId, 'r' => $readerId, 'd' => $date]);
    $existing = $check->fetch();

    if ($existing) {
        $stmt = $db->prepare(
            'UPDATE HouseVisits SET status = :status, reading_id = :reading_id, notes = :notes, updated_at = CURRENT_TIMESTAMP
             WHERE visit_id = :id'
        );
        $stmt->execute(['status' => $status, 'reading_id' => $readingId, 'notes' => $notes, 'id' => $existing['visit_id']]);
    } else {
        $stmt = $db->prepare(
            'INSERT INTO HouseVisits (house_id, reader_id, visit_date, status, reading_id, notes)
             VALUES (:h, :r, :d, :status, :reading_id, :notes)'
        );
        $stmt->execute(['h' => $houseId, 'r' => $readerId, 'd' => $date, 'status' => $status, 'reading_id' => $readingId, 'notes' => $notes]);
    }
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
