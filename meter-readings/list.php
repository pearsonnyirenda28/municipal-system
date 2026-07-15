<?php
/**
 * meter-readings/list.php
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pagination.php';
requireRole(['Admin', 'MeterReader']);

$db = getDb();
$user = currentUser();
$search = trim($_GET['q'] ?? '');
$wardFilter = $_GET['ward_id'] ?? '';
$abnormalOnly = ($_GET['abnormal'] ?? '') === '1';
$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';

// Version 2.5: a MeterReader only sees readings for houses in wards
// they're assigned to. The ward dropdown itself is scoped the same way,
// so a reader can't even filter by a ward that isn't theirs.
$assignedWardIds = $user['role'] === 'MeterReader' ? getAssignedWardIds($db, (int) $user['id']) : null;

if ($assignedWardIds !== null) {
    if ($assignedWardIds) {
        $placeholders = implode(',', array_fill(0, count($assignedWardIds), '?'));
        $stmt = $db->prepare("SELECT ward_id, ward_name FROM Wards WHERE ward_id IN ({$placeholders}) ORDER BY ward_name");
        $stmt->execute($assignedWardIds);
        $wards = $stmt->fetchAll();
    } else {
        $wards = [];
    }
} else {
    $wards = $db->query('SELECT ward_id, ward_name FROM Wards ORDER BY ward_name')->fetchAll();
}

$where = [];
$params = [];

if ($assignedWardIds !== null) {
    if ($assignedWardIds) {
        $placeholders = implode(',', array_fill(0, count($assignedWardIds), '?'));
        $where[] = "h.ward_id IN ({$placeholders})";
        $params = array_merge($params, $assignedWardIds);
    } else {
        $where[] = '1=0';
    }
}
if ($search !== '') {
    $where[] = '(h.house_number LIKE ? OR w.ward_name LIKE ?)';
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}
if ($wardFilter !== '') {
    $where[] = 'h.ward_id = ?';
    $params[] = $wardFilter;
}
if ($abnormalOnly) {
    $where[] = 'r.is_abnormal = 1';
}
if ($from !== '') {
    $where[] = 'r.date_taken >= ?';
    $params[] = $from;
}
if ($to !== '') {
    $where[] = 'r.date_taken <= ?';
    $params[] = $to;
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$joinSql = 'FROM MeterReadings r JOIN Houses h ON h.house_id = r.house_id LEFT JOIN Wards w ON w.ward_id = h.ward_id';

$countSql = "SELECT COUNT(*) {$joinSql}{$whereSql}";
$pageInfo = paginate($db, $countSql, $params);

$sql = "SELECT r.*, h.house_number, w.ward_name, u.full_name AS reader_name
        {$joinSql}
        LEFT JOIN Users u ON u.user_id = r.reader_id
        {$whereSql}
        ORDER BY r.date_taken DESC, r.reading_id DESC
        LIMIT {$pageInfo['perPage']} OFFSET {$pageInfo['offset']}";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$readings = $stmt->fetchAll();

$hasFilters = $search || $wardFilter || $abnormalOnly || $from || $to;

require __DIR__ . '/../includes/header.php';
?>

<div class="page-actions">
    <div>
        <h1>Meter Readings</h1>
        <p class="subtitle"><?= (int) $pageInfo['total'] ?> reading(s) found.</p>
    </div>
    <a class="btn amber" href="capture.php">+ Capture Reading</a>
</div>

<?php if ($assignedWardIds !== null && !$assignedWardIds): ?>
    <div class="alert warning">You aren't assigned to any ward yet - ask an Administrator to assign you one.</div>
<?php endif; ?>

<div class="filter-bar">
    <form method="get" class="filter-row">
        <div class="filter-field grow">
            <label for="q">Search</label>
            <input type="text" id="q" name="q" placeholder="House number or ward..." value="<?= htmlspecialchars($search) ?>">
        </div>
        <div class="filter-field">
            <label for="ward_id">Ward</label>
            <select id="ward_id" name="ward_id">
                <option value="">All<?= $assignedWardIds !== null ? ' my wards' : '' ?></option>
                <?php foreach ($wards as $w): ?>
                    <option value="<?= (int) $w['ward_id'] ?>" <?= (string) $wardFilter === (string) $w['ward_id'] ? 'selected' : '' ?>><?= htmlspecialchars($w['ward_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-field">
            <label for="from">Taken from</label>
            <input type="date" id="from" name="from" value="<?= htmlspecialchars($from) ?>">
        </div>
        <div class="filter-field">
            <label for="to">Taken to</label>
            <input type="date" id="to" name="to" value="<?= htmlspecialchars($to) ?>">
        </div>
        <div class="filter-field">
            <label for="abnormal">&nbsp;</label>
            <label style="font-weight:normal;display:flex;align-items:center;gap:6px;height:38px;">
                <input type="checkbox" id="abnormal" name="abnormal" value="1" style="width:auto;" <?= $abnormalOnly ? 'checked' : '' ?>>
                Abnormal only
            </label>
        </div>
        <button type="submit" class="btn">Apply Filters</button>
        <?php if ($hasFilters): ?><a href="list.php" class="btn secondary">Clear</a><?php endif; ?>
    </form>
</div>

<div class="table-responsive">
<table class="data-table">
    <thead>
        <tr><th>House</th><th>Ward</th><th>Previous</th><th>Current</th><th>Consumption</th><th>Status</th><th>Reader</th><th>Date</th></tr>
    </thead>
    <tbody>
        <?php if (!$readings): ?>
            <tr><td colspan="8">No readings found.</td></tr>
        <?php endif; ?>
        <?php foreach ($readings as $r): ?>
            <tr>
                <td><?= htmlspecialchars($r['house_number']) ?></td>
                <td><?= htmlspecialchars($r['ward_name'] ?? '-') ?></td>
                <td><?= number_format((float) $r['previous_reading'], 2) ?></td>
                <td><?= number_format((float) $r['current_reading'], 2) ?></td>
                <td><?= number_format((float) $r['consumption'], 2) ?> m&sup3;</td>
                <td><span class="badge <?= $r['is_abnormal'] ? 'abnormal' : 'normal' ?>">
                        <?= $r['is_abnormal'] ? 'Abnormal' : 'Normal' ?>
                    </span></td>
                <td><?= htmlspecialchars($r['reader_name'] ?? '-') ?></td>
                <td><?= htmlspecialchars(date('d M Y', strtotime($r['date_taken']))) ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>

<?= renderPagination($pageInfo) ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
