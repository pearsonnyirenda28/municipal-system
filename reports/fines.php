<?php
/**
 * reports/fines.php
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
requireRole(['Admin', 'PoliceSupervisor']);

$db = getDb();
$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';
$status = $_GET['status'] ?? '';

$sql = 'SELECT * FROM Fines WHERE 1=1';
$params = [];
if ($from) { $sql .= ' AND date_issued >= :from'; $params['from'] = $from; }
if ($to)   { $sql .= ' AND date_issued <= :to_end'; $params['to_end'] = $to . ' 23:59:59'; }
if (in_array($status, ['Unpaid', 'Paid'], true)) { $sql .= ' AND payment_status = :status'; $params['status'] = $status; }
$sql .= ' ORDER BY date_issued DESC';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
$total = array_sum(array_column($rows, 'amount'));

require __DIR__ . '/../includes/header.php';
?>

<h1>Fine Report</h1>

<form method="get" class="no-print" style="background:#fff;padding:18px;border-radius:10px;margin-bottom:20px;box-shadow:0 2px 8px rgba(22,34,62,0.08);">
    <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end;">
        <div><label>From</label><input type="date" name="from" value="<?= htmlspecialchars($from) ?>"></div>
        <div><label>To</label><input type="date" name="to" value="<?= htmlspecialchars($to) ?>"></div>
        <div><label>Status</label>
            <select name="status">
                <option value="">All</option>
                <option value="Unpaid" <?= $status === 'Unpaid' ? 'selected' : '' ?>>Unpaid</option>
                <option value="Paid" <?= $status === 'Paid' ? 'selected' : '' ?>>Paid</option>
            </select>
        </div>
        <button type="submit" class="btn" style="margin-top:0;">Filter</button>
        <button type="button" class="btn secondary" style="margin-top:0;" onclick="window.print()">Print</button>
        <a class="btn amber" style="margin-top:0;" href="fines_pdf.php?<?= htmlspecialchars(http_build_query($_GET)) ?>">Export PDF</a>
    </div>
</form>

<p><strong>Total for this selection: US$<?= number_format($total, 2) ?></strong> across <?= count($rows) ?> fine(s).</p>

<div class="table-responsive">
<table class="data-table">
    <thead><tr><th>Fine No.</th><th>Offender</th><th>Offence</th><th>Amount</th><th>Status</th><th>Date Issued</th></tr></thead>
    <tbody>
        <?php if (!$rows): ?><tr><td colspan="6">No matching fines.</td></tr><?php endif; ?>
        <?php foreach ($rows as $f): ?>
            <tr>
                <td><?= htmlspecialchars($f['fine_number']) ?></td>
                <td><?= htmlspecialchars($f['offender_name']) ?></td>
                <td><?= htmlspecialchars($f['offence_type']) ?></td>
                <td>US$<?= number_format((float) $f['amount'], 2) ?></td>
                <td><?= htmlspecialchars($f['payment_status']) ?></td>
                <td><?= htmlspecialchars(date('d M Y', strtotime($f['date_issued']))) ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>
<p class="no-print"><a href="index.php">&larr; Back to Reports</a></p>

<?php require __DIR__ . '/../includes/footer.php'; ?>
