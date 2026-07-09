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
$search = trim($_GET['q'] ?? '');

$where = [];
$params = [];
if (in_array($statusFilter, ['Unpaid', 'Paid'], true)) {
    $where[] = 'payment_status = :status';
    $params['status'] = $statusFilter;
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

<?= renderSearchBox('Search by offender, fine no., house or plate...') ?>

<p>
    Filter:
    <a href="<?= htmlspecialchars(pageUrl(['status' => null, 'page' => null])) ?>" class="btn secondary">All</a>
    <a href="<?= htmlspecialchars(pageUrl(['status' => 'Unpaid', 'page' => null])) ?>" class="btn secondary">Unpaid</a>
    <a href="<?= htmlspecialchars(pageUrl(['status' => 'Paid', 'page' => null])) ?>" class="btn secondary">Paid</a>
</p>

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
