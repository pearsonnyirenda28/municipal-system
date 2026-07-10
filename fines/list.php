<?php
/**
 * fines/list.php
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pagination.php';
requireRole(['Admin', 'Officer', 'Clerk']);

$db = getDb();
$statusFilter = $_GET['status'] ?? '';
$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';
$search = trim($_GET['q'] ?? '');

$where = [];
$params = [];
if (in_array($statusFilter, ['Unpaid', 'Paid'], true)) {
    $where[] = 'payment_status = :status';
    $params['status'] = $statusFilter;
}
if ($from !== '') {
    $where[] = 'date_issued >= :from';
    $params['from'] = $from;
}
if ($to !== '') {
    $where[] = 'date_issued <= :to_end';
    $params['to_end'] = $to . ' 23:59:59';
}
if ($search !== '') {
    $where[] = '(offender_name LIKE :q OR fine_number LIKE :q OR house_number LIKE :q OR vehicle_plate LIKE :q)';
    $params['q'] = "%{$search}%";
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$pageInfo = paginate($db, "SELECT COUNT(*) FROM Fines{$whereSql}", $params);

$stmt = $db->prepare("SELECT * FROM Fines{$whereSql} ORDER BY date_issued DESC LIMIT {$pageInfo['perPage']} OFFSET {$pageInfo['offset']}");
$stmt->execute($params);
$fines = $stmt->fetchAll();

$hasFilters = $statusFilter || $from || $to || $search;

require __DIR__ . '/../includes/header.php';
?>

<div class="page-actions">
    <div>
        <h1>Fines</h1>
        <p class="subtitle"><?= (int) $pageInfo['total'] ?> fine(s) found, most recent first.</p>
    </div>
    <?php if (in_array(currentUser()['role'], ['Admin', 'Officer'], true)): ?>
        <a class="btn" href="issue.php">+ Issue a Fine</a>
    <?php endif; ?>
</div>

<div class="filter-bar">
    <form method="get" class="filter-row">
        <div class="filter-field grow">
            <label for="q">Search</label>
            <input type="text" id="q" name="q" placeholder="Offender, fine no., house or plate..." value="<?= htmlspecialchars($search) ?>">
        </div>
        <div class="filter-field">
            <label for="status">Status</label>
            <select id="status" name="status">
                <option value="">All</option>
                <option value="Unpaid" <?= $statusFilter === 'Unpaid' ? 'selected' : '' ?>>Unpaid</option>
                <option value="Paid" <?= $statusFilter === 'Paid' ? 'selected' : '' ?>>Paid</option>
            </select>
        </div>
        <div class="filter-field">
            <label for="from">Issued from</label>
            <input type="date" id="from" name="from" value="<?= htmlspecialchars($from) ?>">
        </div>
        <div class="filter-field">
            <label for="to">Issued to</label>
            <input type="date" id="to" name="to" value="<?= htmlspecialchars($to) ?>">
        </div>
        <button type="submit" class="btn">Apply Filters</button>
        <?php if ($hasFilters): ?><a href="list.php" class="btn secondary">Clear</a><?php endif; ?>
    </form>
</div>

<div class="table-responsive">
<table class="data-table">
    <thead>
        <tr>
            <th>Fine No.</th><th>Offender</th><th>Offence</th><th>Amount</th>
            <th>Status</th><th>Date Issued</th><th></th>
        </tr>
    </thead>
    <tbody>
        <?php if (!$fines): ?>
            <tr><td colspan="7">No fines found.</td></tr>
        <?php endif; ?>
        <?php foreach ($fines as $f): ?>
            <tr>
                <td><?= htmlspecialchars($f['fine_number']) ?></td>
                <td><?= htmlspecialchars($f['offender_name']) ?></td>
                <td><?= htmlspecialchars($f['offence_type']) ?></td>
                <td>US$<?= number_format((float) $f['amount'], 2) ?></td>
                <td><span class="badge <?= strtolower($f['payment_status']) ?>"><?= htmlspecialchars($f['payment_status']) ?></span></td>
                <td><?= htmlspecialchars(date('d M Y', strtotime($f['date_issued']))) ?></td>
                <td>
                    <?php if ($f['payment_status'] === 'Unpaid'): ?>
                        <a href="pay.php?fine_id=<?= (int) $f['fine_id'] ?>">Record Payment</a>
                    <?php else: ?>
                        <a href="receipt.php?fine_id=<?= (int) $f['fine_id'] ?>">View Receipt</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>

<?= renderPagination($pageInfo) ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
