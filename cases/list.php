<?php
/**
 * cases/list.php
 * "Record Police Case" and "Update Case Status" use cases (Activity 4,
 * Section 6.1).
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pagination.php';
requireRole(['Admin', 'Officer']);

$db = getDb();
$statusFilter = $_GET['status'] ?? '';
$officerFilter = $_GET['officer_id'] ?? '';
$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';
$search = trim($_GET['q'] ?? '');

$officers = $db->query("SELECT user_id, full_name FROM Users WHERE role = 'Officer' ORDER BY full_name")->fetchAll();

$where = [];
$params = [];
if (in_array($statusFilter, ['Open', 'In Progress', 'Closed'], true)) {
    $where[] = 'c.status = :status';
    $params['status'] = $statusFilter;
}
if ($officerFilter !== '') {
    $where[] = 'c.officer_id = :officer_id';
    $params['officer_id'] = $officerFilter;
}
if ($from !== '') {
    $where[] = 'c.date_opened >= :from';
    $params['from'] = $from;
}
if ($to !== '') {
    $where[] = 'c.date_opened <= :to';
    $params['to'] = $to;
}
if ($search !== '') {
    $where[] = '(c.offender_name LIKE :q OR c.case_number LIKE :q OR c.offence_type LIKE :q)';
    $params['q'] = "%{$search}%";
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$countSql = "SELECT COUNT(*) FROM Cases c{$whereSql}";
$pageInfo = paginate($db, $countSql, $params);

$sql = "SELECT c.*, u.full_name AS officer_name FROM Cases c LEFT JOIN Users u ON u.user_id = c.officer_id{$whereSql}
        ORDER BY c.date_opened DESC LIMIT {$pageInfo['perPage']} OFFSET {$pageInfo['offset']}";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$cases = $stmt->fetchAll();

$hasFilters = $statusFilter || $officerFilter || $from || $to || $search;

require __DIR__ . '/../includes/header.php';
?>

<div class="page-actions">
    <div>
        <h1>Police Cases</h1>
        <p class="subtitle"><?= (int) $pageInfo['total'] ?> case(s) found.</p>
    </div>
    <a class="btn" href="create.php">+ Record a Case</a>
</div>

<div class="filter-bar">
    <form method="get" class="filter-row">
        <div class="filter-field grow">
            <label for="q">Search</label>
            <input type="text" id="q" name="q" placeholder="Offender, case no. or offence..." value="<?= htmlspecialchars($search) ?>">
        </div>
        <div class="filter-field">
            <label for="status">Status</label>
            <select id="status" name="status">
                <option value="">All</option>
                <?php foreach (['Open', 'In Progress', 'Closed'] as $s): ?>
                    <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= $s ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-field">
            <label for="officer_id">Officer</label>
            <select id="officer_id" name="officer_id">
                <option value="">All</option>
                <?php foreach ($officers as $o): ?>
                    <option value="<?= (int) $o['user_id'] ?>" <?= (string) $officerFilter === (string) $o['user_id'] ? 'selected' : '' ?>><?= htmlspecialchars($o['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-field">
            <label for="from">Opened from</label>
            <input type="date" id="from" name="from" value="<?= htmlspecialchars($from) ?>">
        </div>
        <div class="filter-field">
            <label for="to">Opened to</label>
            <input type="date" id="to" name="to" value="<?= htmlspecialchars($to) ?>">
        </div>
        <button type="submit" class="btn">Apply Filters</button>
        <?php if ($hasFilters): ?><a href="list.php" class="btn secondary">Clear</a><?php endif; ?>
    </form>
</div>

<div class="table-responsive">
<table class="data-table">
    <thead>
        <tr><th>Case No.</th><th>Offender</th><th>Offence</th><th>Status</th><th>Officer</th><th>Date Opened</th><th></th></tr>
    </thead>
    <tbody>
        <?php if (!$cases): ?>
            <tr><td colspan="7">No cases found.</td></tr>
        <?php endif; ?>
        <?php foreach ($cases as $c): ?>
            <tr>
                <td><?= htmlspecialchars($c['case_number']) ?></td>
                <td><?= htmlspecialchars($c['offender_name']) ?></td>
                <td><?= htmlspecialchars($c['offence_type']) ?></td>
                <td><span class="badge <?= $c['status'] === 'Closed' ? 'paid' : 'unpaid' ?>"><?= htmlspecialchars($c['status']) ?></span></td>
                <td><?= htmlspecialchars($c['officer_name'] ?? '-') ?></td>
                <td><?= htmlspecialchars(date('d M Y', strtotime($c['date_opened']))) ?></td>
                <td>
                    <?php if ($c['status'] !== 'Closed'): ?>
                        <a href="update_status.php?case_id=<?= (int) $c['case_id'] ?>">Update Status</a>
                    <?php endif; ?>
                    &nbsp;|&nbsp;
                    <a href="/municipal-system/fines/issue.php?case_id=<?= (int) $c['case_id'] ?>">Issue Fine</a>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>

<?= renderPagination($pageInfo) ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
