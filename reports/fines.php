<?php
/**
 * reports/fines.php
 *
 * Fine Report — filter by date range and payment status, then print or
 * save as PDF. Fully CSP-compliant: no inline handlers, no inline scripts.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
requireRole(['Admin', 'PoliceSupervisor']);

$db = getDb();

$from   = $_GET['from']   ?? '';
$to     = $_GET['to']     ?? '';
$status = $_GET['status'] ?? '';

// ── Build query ────────────────────────────────────────────────
$sql    = 'SELECT * FROM Fines WHERE 1=1';
$params = [];

if ($from !== '') {
    $sql .= ' AND date_issued >= :from';
    $params['from'] = $from;
}
if ($to !== '') {
    $sql .= ' AND date_issued <= :to_end';
    $params['to_end'] = $to . ' 23:59:59';
}
if (in_array($status, ['Unpaid', 'Paid'], true)) {
    $sql .= ' AND payment_status = :status';
    $params['status'] = $status;
}

$sql .= ' ORDER BY date_issued DESC';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows  = $stmt->fetchAll();
$total = array_sum(array_column($rows, 'amount'));

$hasFilters = ($from !== '' || $to !== '' || $status !== '');

require __DIR__ . '/../includes/header.php';
?>

<h1>Fine Report</h1>
<p class="subtitle">
    Filter by date range and payment status, then print or save as PDF.
</p>

<!-- ── Filter bar (hidden when printing) ─────────────────────── -->
<div class="filter-bar no-print">
    <form method="get" class="filter-row">
        <div class="filter-field">
            <label for="from">From</label>
            <input type="date" id="from" name="from"
                   value="<?= htmlspecialchars($from) ?>">
        </div>

        <div class="filter-field">
            <label for="to">To</label>
            <input type="date" id="to" name="to"
                   value="<?= htmlspecialchars($to) ?>">
        </div>

        <div class="filter-field">
            <label for="status">Status</label>
            <select id="status" name="status">
                <option value="">All</option>
                <option value="Unpaid" <?= $status === 'Unpaid' ? 'selected' : '' ?>>Unpaid</option>
                <option value="Paid"   <?= $status === 'Paid'   ? 'selected' : '' ?>>Paid</option>
            </select>
        </div>

        <button type="submit" class="btn">Filter</button>

        <button type="button" class="btn btn-print" data-print>Print</button>

        <?php if ($hasFilters): ?>
            <a href="fines.php" class="btn secondary">Clear</a>
        <?php endif; ?>
    </form>
</div>

<!-- ── Summary line (prints) ─────────────────────────────────── -->
<p>
    <strong>Total for this selection:</strong>
    US$<?= number_format($total, 2) ?>
    across <?= count($rows) ?> fine(s).
</p>

<!-- ── Data table (prints) ───────────────────────────────────── -->
<div class="table-responsive">
    <table class="data-table">
        <thead>
            <tr>
                <th>Fine No.</th>
                <th>Offender</th>
                <th>Offence</th>
                <th>Amount</th>
                <th>Status</th>
                <th>Date Issued</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$rows): ?>
                <tr>
                    <td colspan="6">No matching fines.</td>
                </tr>
            <?php endif; ?>

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

<p class="no-print">
    <a href="index.php">&larr; Back to Reports</a>
</p>

<?php require __DIR__ . '/../includes/footer.php'; ?>