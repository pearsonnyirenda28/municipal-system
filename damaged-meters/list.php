<?php
/**
 * damaged-meters/list.php
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pagination.php';
requireRole(['Admin', 'MeterReader']);

$db = getDb();
$statusFilter = $_GET['status'] ?? '';
$search = trim($_GET['q'] ?? '');
$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';

$where = [];
$params = [];
if (in_array($statusFilter, ['Pending', 'In Progress', 'Fixed'], true)) {
    $where[] = 'd.repair_status = :status';
    $params['status'] = $statusFilter;
}
if ($search !== '') {
    $where[] = '(h.house_number LIKE :q OR h.ward LIKE :q OR d.description LIKE :q)';
    $params['q'] = "%{$search}%";
}
if ($from !== '') {
    $where[] = 'd.date_reported >= :from';
    $params['from'] = $from;
}
if ($to !== '') {
    $where[] = 'd.date_reported <= :to';
    $params['to'] = $to;
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$countSql = "SELECT COUNT(*) FROM DamagedMeters d JOIN Houses h ON h.house_id = d.house_id{$whereSql}";
$pageInfo = paginate($db, $countSql, $params);

$sql = "SELECT d.*, h.house_number, h.ward, u.full_name AS reported_by_name
        FROM DamagedMeters d
        JOIN Houses h ON h.house_id = d.house_id
        LEFT JOIN Users u ON u.user_id = d.reported_by
        {$whereSql}
        ORDER BY d.date_reported DESC
        LIMIT {$pageInfo['perPage']} OFFSET {$pageInfo['offset']}";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$reports = $stmt->fetchAll();

$hasFilters = $statusFilter || $search || $from || $to;

require __DIR__ . '/../includes/header.php';
?>

<div class="page-actions">
    <div>
        <h1>Damaged Meters</h1>
        <p class="subtitle"><?= (int) $pageInfo['total'] ?> report(s) found.</p>
    </div>
    <a class="btn amber" href="report.php">+ Report Damage</a>
</div>

<div class="filter-bar">
    <form method="get" class="filter-row">
        <div class="filter-field grow">
            <label for="q">Search</label>
            <input type="text" id="q" name="q" placeholder="House, ward or description..." value="<?= htmlspecialchars($search) ?>">
        </div>
        <div class="filter-field">
            <label for="status">Status</label>
            <select id="status" name="status">
                <option value="">All</option>
                <?php foreach (['Pending', 'In Progress', 'Fixed'] as $s): ?>
                    <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= $s ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-field">
            <label for="from">Reported from</label>
            <input type="date" id="from" name="from" value="<?= htmlspecialchars($from) ?>">
        </div>
        <div class="filter-field">
            <label for="to">Reported to</label>
            <input type="date" id="to" name="to" value="<?= htmlspecialchars($to) ?>">
        </div>
        <button type="submit" class="btn">Apply Filters</button>
        <?php if ($hasFilters): ?><a href="list.php" class="btn secondary">Clear</a><?php endif; ?>
    </form>
</div>

<div class="table-responsive">
<table class="data-table">
    <thead>
        <tr><th>House</th><th>Ward</th><th>Description</th><th>Photo</th><th>Status</th><th>Reported By</th><th>Date</th><th></th></tr>
    </thead>
    <tbody>
        <?php if (!$reports): ?>
            <tr><td colspan="8">No damage reports found.</td></tr>
        <?php endif; ?>
        <?php foreach ($reports as $r): ?>
            <tr>
                <td><?= htmlspecialchars($r['house_number']) ?></td>
                <td><?= htmlspecialchars($r['ward']) ?></td>
                <td><?= htmlspecialchars($r['description']) ?></td>
                <td>
                    <?php if ($r['photo_path']): ?>
                        <a href="/municipal-system/<?= htmlspecialchars($r['photo_path']) ?>" target="_blank">View photo</a>
                    <?php else: ?>
                        &mdash;
                    <?php endif; ?>
                </td>
                <td><span class="badge <?= $r['repair_status'] === 'Fixed' ? 'paid' : 'unpaid' ?>"><?= htmlspecialchars($r['repair_status']) ?></span></td>
                <td><?= htmlspecialchars($r['reported_by_name'] ?? '-') ?></td>
                <td><?= htmlspecialchars(date('d M Y', strtotime($r['date_reported']))) ?></td>
                <td>
                    <?php if (currentUser()['role'] === 'Admin' && $r['repair_status'] !== 'Fixed'): ?>
                        <a href="update_status.php?damage_id=<?= (int) $r['damage_id'] ?>">Update Status</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>

<?= renderPagination($pageInfo) ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
