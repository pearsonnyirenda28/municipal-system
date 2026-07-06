<?php
/**
 * cases/list.php
 * "Record Police Case" and "Update Case Status" use cases (Activity 4,
 * Section 6.1).
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
requireRole(['Admin', 'Officer']);

$db = getDb();
$statusFilter = $_GET['status'] ?? '';

$sql = 'SELECT c.*, u.full_name AS officer_name FROM Cases c LEFT JOIN Users u ON u.user_id = c.officer_id';
$params = [];
if (in_array($statusFilter, ['Open', 'In Progress', 'Closed'], true)) {
    $sql .= ' WHERE c.status = :status';
    $params['status'] = $statusFilter;
}
$sql .= ' ORDER BY c.date_opened DESC';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$cases = $stmt->fetchAll();

require __DIR__ . '/../includes/header.php';
?>

<div class="page-actions">
    <div>
        <h1>Police Cases</h1>
        <p class="subtitle">Executive summary of current cases, with quick status updates.</p>
    </div>
    <a class="btn" href="create.php">+ Record a Case</a>
</div>

<p>
    Filter:
    <a href="list.php" class="btn secondary">All</a>
    <a href="list.php?status=Open" class="btn secondary">Open</a>
    <a href="list.php?status=In Progress" class="btn secondary">In Progress</a>
    <a href="list.php?status=Closed" class="btn secondary">Closed</a>
</p>

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

<?php require __DIR__ . '/../includes/footer.php'; ?>
