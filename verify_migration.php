<?php
/**
 * verify_migration.php  (Admin only, read-only)
 * Compares the SQLite database with the MySQL database after running
 * migrate_to_mysql.php, and reports PASS / FAIL for each check:
 *   1. Row counts per table
 *   2. Row-by-row content of every migrated column (primary key by primary key)
 *   3. Deactivated users still deactivated; soft-deleted rows still soft-deleted
 *   4. No orphaned foreign keys in MySQL
 *   5. AUTO_INCREMENT counters are ahead of the highest id
 * Needs the same .env settings as migrate_to_mysql.php. Writes nothing.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
requireRole(['Admin']);

$tables = [
    'Users' => ['user_id', ['user_id','username','password_hash','role','full_name','is_active','created_at']],
    'Wards' => ['ward_id', ['ward_id','ward_name','description']],
    'WardAssignments' => ['assignment_id', ['assignment_id','ward_id','user_id','assigned_at']],
    'Houses' => ['house_id', ['house_id','house_number','street','ward_id','owner_name','meter_number','address']],
    'Cases' => ['case_id', ['case_id','case_number','offender_name','id_number','offence_type','description','status','officer_id','date_opened']],
    'Fines' => ['fine_id', ['fine_id','fine_number','case_id','offender_name','house_number','vehicle_plate','offence_type','amount','payment_status','issued_by','date_issued','client_ref','is_duplicate_flagged','duplicate_resolved_by','duplicate_resolved_at','discarded_at','discarded_by','discard_reason']],
    'Payments' => ['payment_id', ['payment_id','fine_id','amount_received','date_paid','received_by','receipt_number']],
    'MeterReadings' => ['reading_id', ['reading_id','house_id','previous_reading','current_reading','consumption','is_abnormal','reader_id','date_taken','client_ref','is_duplicate_flagged','duplicate_resolved_by','duplicate_resolved_at','discarded_at','discarded_by','discard_reason']],
    'DamagedMeters' => ['damage_id', ['damage_id','house_id','description','photo_path','reported_by','date_reported','repair_status','date_fixed']],
    'HouseVisits' => ['visit_id', ['visit_id','house_id','reader_id','visit_date','status','reading_id','notes','updated_at']],
    'ActivityLogs' => ['log_id', ['log_id','user_id','action_description','ip_address','date_time']],
];
$orphanChecks = [
    'Cases.officer_id -> Users'      => 'SELECT COUNT(*) FROM Cases c LEFT JOIN Users u ON u.user_id=c.officer_id WHERE c.officer_id IS NOT NULL AND u.user_id IS NULL',
    'Fines.case_id -> Cases'         => 'SELECT COUNT(*) FROM Fines f LEFT JOIN Cases c ON c.case_id=f.case_id WHERE f.case_id IS NOT NULL AND c.case_id IS NULL',
    'Fines.issued_by -> Users'       => 'SELECT COUNT(*) FROM Fines f LEFT JOIN Users u ON u.user_id=f.issued_by WHERE f.issued_by IS NOT NULL AND u.user_id IS NULL',
    'Payments.fine_id -> Fines'      => 'SELECT COUNT(*) FROM Payments p LEFT JOIN Fines f ON f.fine_id=p.fine_id WHERE f.fine_id IS NULL',
    'Houses.ward_id -> Wards'        => 'SELECT COUNT(*) FROM Houses h LEFT JOIN Wards w ON w.ward_id=h.ward_id WHERE h.ward_id IS NOT NULL AND w.ward_id IS NULL',
    'MeterReadings.house_id -> Houses' => 'SELECT COUNT(*) FROM MeterReadings r LEFT JOIN Houses h ON h.house_id=r.house_id WHERE h.house_id IS NULL',
    'HouseVisits.house_id -> Houses' => 'SELECT COUNT(*) FROM HouseVisits v LEFT JOIN Houses h ON h.house_id=v.house_id WHERE h.house_id IS NULL',
    'DamagedMeters.house_id -> Houses' => 'SELECT COUNT(*) FROM DamagedMeters d LEFT JOIN Houses h ON h.house_id=d.house_id WHERE h.house_id IS NULL',
];
$checks = []; $fatal = null;
function norm($v): string {            // make SQLite and MySQL values comparable
    if ($v === null) return '<NULL>';
    // MySQL DATETIME pads a date-only value with midnight; that is the same moment, not a data change.
    if (is_string($v) && preg_match('/^(\d{4}-\d{2}-\d{2}) 00:00:00$/', $v, $m)) return $m[1];
    if (is_numeric($v)) return rtrim(rtrim(number_format((float)$v, 4, '.', ''), '0'), '.');
    return (string)$v;
}
function add(array &$c, string $name, bool $ok, string $detail = ''): void { $c[] = [$name, $ok, $detail]; }

try {
    $sqlite = new PDO('sqlite:' . resolveDbPath(DB_PATH), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $mysql  = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

    foreach ($tables as $t => [$pk, $cols]) {
        $list = implode(', ', $cols);
        $a = $sqlite->query("SELECT {$list} FROM {$t} ORDER BY {$pk}")->fetchAll();
        $b = $mysql->query("SELECT {$list} FROM {$t} ORDER BY {$pk}")->fetchAll();
        add($checks, "{$t}: row count", count($a) === count($b), 'SQLite ' . count($a) . ' / MySQL ' . count($b));
        $bBy = []; foreach ($b as $r) $bBy[$r[$pk]] = $r;
        $bad = [];
        foreach ($a as $r) {
            $o = $bBy[$r[$pk]] ?? null;
            if ($o === null) { $bad[] = "{$pk}={$r[$pk]} missing"; continue; }
            foreach ($cols as $c) if (norm($r[$c]) !== norm($o[$c])) { $bad[] = "{$pk}={$r[$pk]} column {$c}"; break; }
            if (count($bad) >= 5) break;
        }
        add($checks, "{$t}: every row and column identical", !$bad, $bad ? implode('; ', $bad) : count($a) . ' rows compared');
    }
    $q = fn(PDO $p, string $sql) => (int)$p->query($sql)->fetchColumn();
    foreach ([['Users deactivated', 'SELECT COUNT(*) FROM Users WHERE is_active = 0'],
              ['Fines soft-deleted', 'SELECT COUNT(*) FROM Fines WHERE discarded_at IS NOT NULL'],
              ['MeterReadings soft-deleted', 'SELECT COUNT(*) FROM MeterReadings WHERE discarded_at IS NOT NULL']] as [$n, $sql]) {
        $x = $q($sqlite, $sql); $y = $q($mysql, $sql);
        add($checks, "{$n} preserved", $x === $y, "SQLite {$x} / MySQL {$y}");
    }
    foreach ($orphanChecks as $n => $sql) { $x = $q($mysql, $sql); add($checks, "No orphans: {$n}", $x === 0, "{$x} orphan(s)"); }
    foreach ($tables as $t => [$pk]) {
        $max = $q($mysql, "SELECT COALESCE(MAX({$pk}),0) FROM {$t}");
        $st = $mysql->prepare("SELECT AUTO_INCREMENT FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
        $st->execute([$t]); $next = (int)$st->fetchColumn();
        add($checks, "{$t}: next id is ahead of max id", $next > $max, "max {$max}, next {$next}");
    }
} catch (Throwable $e) { $fatal = $e->getMessage(); }
$allOk = !$fatal && !array_filter($checks, fn($c) => !$c[1]);
?>
<!DOCTYPE html><html><head><meta charset="utf-8"><title>Migration verification</title>
<style>body{font-family:Calibri,Arial,sans-serif;margin:30px}table{border-collapse:collapse}td,th{border:1px solid #bbb;padding:5px 10px;text-align:left}.ok{color:#0a7a2f;font-weight:bold}.bad{color:#b00020;font-weight:bold}</style></head><body>
<h1>SQLite &rarr; MySQL migration check</h1>
<?php if ($fatal): ?><p class="bad">Could not run: <?= htmlspecialchars($fatal) ?></p>
<?php else: ?><p class="<?= $allOk ? 'ok' : 'bad' ?>"><?= $allOk ? 'ALL CHECKS PASSED' : 'SOME CHECKS FAILED' ?> (<?= count(array_filter($checks, fn($c) => $c[1])) ?> of <?= count($checks) ?> passed)</p>
<table><tr><th>Check</th><th>Result</th><th>Detail</th></tr>
<?php foreach ($checks as [$n, $ok, $d]): ?><tr><td><?= htmlspecialchars($n) ?></td><td class="<?= $ok ? 'ok' : 'bad' ?>"><?= $ok ? 'PASS' : 'FAIL' ?></td><td><?= htmlspecialchars($d) ?></td></tr><?php endforeach; ?></table><?php endif; ?>
<p>Read-only page. Delete it from the submission folder when you have finished testing.</p></body></html>
