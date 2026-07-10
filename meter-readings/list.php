<?php
/**
 * meter-readings/list.php
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pagination.php';
requireRole(['Admin', 'MeterReader']);

$db = getDb();
$search = trim($_GET['q'] ?? '');
$wardFilter = $_GET['ward'] ?? '';
$abnormalOnly = ($_GET['abnormal'] ?? '') === '1';
$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';

$wards = $db->query('SELECT DISTINCT ward FROM Houses ORDER BY ward')->fetchAll(PDO::FETCH_COLUMN);

$where = [];
$params = [];
if ($search !== '') {
    $where[] = '(h.house_number LIKE :q OR h.ward LIKE :q)';
    $params['q'] = "%{$search}%";
}
if ($wardFilter !== '') {
    $where[] = 'h.ward = :ward';
    $params['ward'] = $wardFilter;
}
if ($abnormalOnly) {
    $where[] = 'r.is_abnormal = 1';
}
if ($from !== '') {
    $where[] = 'r.date_taken >= :from';
    $params['from'] = $from;
}
if ($to !== '') {
    $where[] = 'r.date_taken <= :to';
    $params['to'] = $to;
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$countSql = "SELECT COUNT(*) FROM MeterReadings r JOIN Houses h ON h.house_id = r.house_id{$whereSql}";
$pageInfo = paginate($db, $countSql, $params);

$sql = "SELECT r.*, h.house_number, h.ward, u.full_name AS reader_name
        FROM MeterReadings r
        JOIN Houses h ON h.house_id = r.house_id
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

<div class="filter-bar">
    <form method="get" class="filter-row">
        <div class="filter-field grow">
            <label for="q">Search</label>
            <input type="text" id="q" name="q" placeholder="House number or ward..." value="<?= htmlspecialchars($search) ?>">
        </div>
        <div class="filter-field">
            <label for="ward">Ward</label>
            <select id="ward" name="ward">
                <option value="">All</option>
                <?php foreach ($wards as $w): ?>
                    <option value="<?= htmlspecialchars($w) ?>" <?= $wardFilter === $w ? 'selected' : '' ?>><?= htmlspecialchars($w) ?></option>
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
                <td><?= htmlspecialchars($r['ward']) ?></td>
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
