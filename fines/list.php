<?php
/**
 * fines/list.php
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
requireRole(['Admin', 'Officer', 'Clerk']);

$db = getDb();
$statusFilter = $_GET['status'] ?? '';

$sql = 'SELECT * FROM Fines';
$params = [];
if (in_array($statusFilter, ['Unpaid', 'Paid'], true)) {
    $sql .= ' WHERE payment_status = :status';
    $params['status'] = $statusFilter;
}
$sql .= ' ORDER BY date_issued DESC';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$fines = $stmt->fetchAll();

require __DIR__ . '/../includes/header.php';
?>

<div class="page-actions">
    <div>
        <h1>Fines</h1>
        <p class="subtitle">All fines issued, most recent first.</p>
    </div>
    <?php if (in_array(currentUser()['role'], ['Admin', 'Officer'], true)): ?>
        <a class="btn" href="issue.php">+ Issue a Fine</a>
    <?php endif; ?>
</div>

<p>
    Filter:
    <a href="list.php" class="btn secondary">All</a>
    <a href="list.php?status=Unpaid" class="btn secondary">Unpaid</a>
    <a href="list.php?status=Paid" class="btn secondary">Paid</a>
</p>

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

<?php require __DIR__ . '/../includes/footer.php'; ?>
