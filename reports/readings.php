<?php
/**
 * reports/readings.php
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
requireRole(['Admin', 'MeterSupervisor']);

$db = getDb();
$wardFilter = $_GET['ward_id'] ?? '';
$houseId = $_GET['house_id'] ?? '';
$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';

$wards = $db->query('SELECT ward_id, ward_name FROM Wards ORDER BY ward_name')->fetchAll();
$houses = $db->query('SELECT house_id, house_number FROM Houses ORDER BY house_number')->fetchAll();

$sql = 'SELECT r.*, h.house_number, w.ward_name
        FROM MeterReadings r
        JOIN Houses h ON h.house_id = r.house_id
        LEFT JOIN Wards w ON w.ward_id = h.ward_id
        WHERE 1=1';
$params = [];
if ($wardFilter !== '') { $sql .= ' AND h.ward_id = :ward_id'; $params['ward_id'] = $wardFilter; }
if ($houseId !== '') { $sql .= ' AND r.house_id = :house_id'; $params['house_id'] = $houseId; }
if ($from) { $sql .= ' AND r.date_taken >= :from'; $params['from'] = $from; }
if ($to)   { $sql .= ' AND r.date_taken <= :to'; $params['to'] = $to; }
$sql .= ' ORDER BY r.date_taken DESC';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

require __DIR__ . '/../includes/header.php';
?>

<h1>Meter Reading Report</h1>

<form method="get" class="no-print" style="background:#fff;padding:18px;border-radius:10px;margin-bottom:20px;box-shadow:0 2px 8px rgba(22,34,62,0.08);">
    <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end;">
        <div><label>Ward</label>
            <select name="ward_id">
                <option value="">All</option>
                <?php foreach ($wards as $w): ?><option value="<?= (int) $w['ward_id'] ?>" <?= (string) $wardFilter === (string) $w['ward_id'] ? 'selected' : '' ?>><?= htmlspecialchars($w['ward_name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div><label>House</label>
            <select name="house_id">
                <option value="">All</option>
                <?php foreach ($houses as $h): ?><option value="<?= (int) $h['house_id'] ?>" <?= (string) $houseId === (string) $h['house_id'] ? 'selected' : '' ?>><?= htmlspecialchars($h['house_number']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div><label>From</label><input type="date" name="from" value="<?= htmlspecialchars($from) ?>"></div>
        <div><label>To</label><input type="date" name="to" value="<?= htmlspecialchars($to) ?>"></div>
        <button type="submit" class="btn" style="margin-top:0;">Filter</button>
        <button type="button" class="btn secondary" style="margin-top:0;" onclick="window.print()">Print</button>
    </div>
</form>

<div class="table-responsive">
<table class="data-table">
    <thead><tr><th>House</th><th>Ward</th><th>Previous</th><th>Current</th><th>Consumption</th><th>Status</th><th>Date</th></tr></thead>
    <tbody>
        <?php if (!$rows): ?><tr><td colspan="7">No matching readings.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= htmlspecialchars($r['house_number']) ?></td>
                <td><?= htmlspecialchars($r['ward_name'] ?? '-') ?></td>
                <td><?= number_format((float) $r['previous_reading'], 2) ?></td>
                <td><?= number_format((float) $r['current_reading'], 2) ?></td>
                <td><?= number_format((float) $r['consumption'], 2) ?> m&sup3;</td>
                <td><?= $r['is_abnormal'] ? 'Abnormal' : 'Normal' ?></td>
                <td><?= htmlspecialchars(date('d M Y', strtotime($r['date_taken']))) ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>
<p class="no-print"><a href="index.php">&larr; Back to Reports</a></p>

<?php require __DIR__ . '/../includes/footer.php'; ?>
