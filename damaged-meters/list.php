<?php
/**
 * damaged-meters/list.php
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pagination.php';
requireRole(['Admin', 'MeterReader']);

$db = getDb();
$user = currentUser();
$statusFilter = $_GET['status'] ?? '';
$search = trim($_GET['q'] ?? '');
$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';

// Version 2.5: scope to the reader's assigned wards, same as everywhere else.
$assignedWardIds = $user['role'] === 'MeterReader' ? getAssignedWardIds($db, (int) $user['id']) : null;

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
if (in_array($statusFilter, ['Pending', 'In Progress', 'Fixed'], true)) {
    $where[] = 'd.repair_status = ?';
    $params[] = $statusFilter;
}
if ($search !== '') {
    $where[] = '(h.house_number LIKE ? OR w.ward_name LIKE ? OR d.description LIKE ?)';
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}
if ($from !== '') {
    $where[] = 'd.date_reported >= ?';
    $params[] = $from;
}
if ($to !== '') {
    $where[] = 'd.date_reported <= ?';
    $params[] = $to;
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$joinSql = 'FROM DamagedMeters d JOIN Houses h ON h.house_id = d.house_id LEFT JOIN Wards w ON w.ward_id = h.ward_id';

$countSql = "SELECT COUNT(*) {$joinSql}{$whereSql}";
$pageInfo = paginate($db, $countSql, $params);

$sql = "SELECT d.*, h.house_number, w.ward_name, u.full_name AS reported_by_name
        {$joinSql}
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

<?php if ($assignedWardIds !== null && !$assignedWardIds): ?>
    <div class="alert warning">You aren't assigned to any ward yet - ask an Administrator to assign you one.</div>
<?php endif; ?>

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
                <td><?= htmlspecialchars($r['ward_name'] ?? '-') ?></td>
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
