<?php
/**
 * sync/conflicts.php
 * Version 3.5 - Conflict Detection: "Supervisor resolves any conflicts."
 * Every "possible duplicate" flag raised during sync (meter readings
 * since Version 3.0, fines since this version) was already being
 * written to ActivityLogs, but had no screen of its own - this is that
 * screen.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pagination.php';
requireRole(['Admin']);

$db = getDb();
$search = trim($_GET['q'] ?? '');
$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';

$where = ["a.action_description LIKE '%POSSIBLE DUPLICATE%'"];
$params = [];

if ($search !== '') {
    $where[] = '(u.full_name LIKE :q OR u.username LIKE :q OR a.action_description LIKE :q)';
    $params['q'] = "%{$search}%";
}
if ($from !== '') {
    $where[] = 'a.date_time >= :from';
    $params['from'] = $from . ' 00:00:00';
}
if ($to !== '') {
    $where[] = 'a.date_time <= :to';
    $params['to'] = $to . ' 23:59:59';
}
$whereSql = ' WHERE ' . implode(' AND ', $where);

$countSql = "SELECT COUNT(*) FROM ActivityLogs a LEFT JOIN Users u ON u.user_id = a.user_id{$whereSql}";
$pageInfo = paginate($db, $countSql, $params, 25);

$sql = "SELECT a.*, u.full_name, u.username
        FROM ActivityLogs a LEFT JOIN Users u ON u.user_id = a.user_id
        {$whereSql}
        ORDER BY a.date_time DESC
        LIMIT {$pageInfo['perPage']} OFFSET {$pageInfo['offset']}";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$hasFilters = $search || $from || $to;

require __DIR__ . '/../includes/header.php';
?>

<div class="page-actions">
    <div>
        <h1>Sync Conflicts</h1>
        <p class="subtitle"><?= (int) $pageInfo['total'] ?> possible duplicate(s) flagged during sync.</p>
    </div>
</div>

<div class="alert warning">
    These are records that were saved successfully but looked like they might duplicate an
    existing one (same offender/house and date, different sync attempt). Nothing was
    automatically rejected - review each one against the real Fines / Meter Readings lists
    and correct or delete manually if it genuinely is a duplicate.
</div>

<div class="filter-bar">
    <form method="get" class="filter-row">
        <div class="filter-field grow">
            <label for="q">Search</label>
            <input type="text" id="q" name="q" placeholder="User or description..." value="<?= htmlspecialchars($search) ?>">
        </div>
        <div class="filter-field">
            <label for="from">From</label>
            <input type="date" id="from" name="from" value="<?= htmlspecialchars($from) ?>">
        </div>
        <div class="filter-field">
            <label for="to">To</label>
            <input type="date" id="to" name="to" value="<?= htmlspecialchars($to) ?>">
        </div>
        <button type="submit" class="btn">Apply Filters</button>
        <?php if ($hasFilters): ?><a href="conflicts.php" class="btn secondary">Clear</a><?php endif; ?>
    </form>
</div>

<div class="table-responsive">
<table class="data-table">
    <thead><tr><th>Flagged By</th><th>Detail</th><th>When</th></tr></thead>
    <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="3">No conflicts flagged - nothing to review.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= $r['full_name'] ? htmlspecialchars($r['full_name']) : '<span style="color:#5B6472;">Unknown</span>' ?></td>
                <td><?= htmlspecialchars($r['action_description']) ?></td>
                <td><?= htmlspecialchars(date('d M Y H:i', strtotime($r['date_time']))) ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>

<?= renderPagination($pageInfo) ?>

<p style="font-size:0.82rem;color:#5B6472;margin-top:16px;">
    Related lists: <a href="/municipal-system/fines/list.php">Fines</a> &bull;
    <a href="/municipal-system/meter-readings/list.php">Meter Readings</a>
</p>

<?php require __DIR__ . '/../includes/footer.php'; ?>
