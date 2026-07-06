<?php
/**
 * damaged-meters/list.php
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
requireRole(['Admin', 'MeterReader']);

$db = getDb();
$statusFilter = $_GET['status'] ?? '';

$sql = 'SELECT d.*, h.house_number, h.ward, u.full_name AS reported_by_name
        FROM DamagedMeters d
        JOIN Houses h ON h.house_id = d.house_id
        LEFT JOIN Users u ON u.user_id = d.reported_by';
$params = [];
if (in_array($statusFilter, ['Pending', 'In Progress', 'Fixed'], true)) {
    $sql .= ' WHERE d.repair_status = :status';
    $params['status'] = $statusFilter;
}
$sql .= ' ORDER BY d.date_reported DESC';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$reports = $stmt->fetchAll();

require __DIR__ . '/../includes/header.php';
?>

<div class="page-actions">
    <div>
        <h1>Damaged Meters</h1>
        <p class="subtitle">Reports of damaged or faulty water meters.</p>
    </div>
    <a class="btn amber" href="report.php">+ Report Damage</a>
</div>

<p>
    Filter:
    <a href="list.php" class="btn secondary">All</a>
    <a href="list.php?status=Pending" class="btn secondary">Pending</a>
    <a href="list.php?status=In Progress" class="btn secondary">In Progress</a>
    <a href="list.php?status=Fixed" class="btn secondary">Fixed</a>
</p>

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

<?php require __DIR__ . '/../includes/footer.php'; ?>
