<?php
/**
 * meter-readings/list.php
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
requireRole(['Admin', 'MeterReader']);

$db = getDb();
$readings = $db->query(
    'SELECT r.*, h.house_number, h.ward, u.full_name AS reader_name
     FROM MeterReadings r
     JOIN Houses h ON h.house_id = r.house_id
     LEFT JOIN Users u ON u.user_id = r.reader_id
     ORDER BY r.date_taken DESC, r.reading_id DESC
     LIMIT 100'
)->fetchAll();

require __DIR__ . '/../includes/header.php';
?>

<div class="page-actions">
    <div>
        <h1>Meter Readings</h1>
        <p class="subtitle">Most recent 100 readings.</p>
    </div>
    <a class="btn amber" href="capture.php">+ Capture Reading</a>
</div>

<table class="data-table">
    <thead>
        <tr><th>House</th><th>Ward</th><th>Previous</th><th>Current</th><th>Consumption</th><th>Status</th><th>Reader</th><th>Date</th></tr>
    </thead>
    <tbody>
        <?php if (!$readings): ?>
            <tr><td colspan="8">No readings captured yet.</td></tr>
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

<?php require __DIR__ . '/../includes/footer.php'; ?>
