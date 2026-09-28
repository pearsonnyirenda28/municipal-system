<?php
/**
 * reports/cases.php
 *
 * Police Case Report — filter by date, status and officer, then print.
 * Fully CSP-compliant: no inline handlers, no inline scripts.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
requireRole(['Admin', 'PoliceSupervisor']);

$db = getDb();

$from      = $_GET['from']       ?? '';
$to        = $_GET['to']         ?? '';
$status    = $_GET['status']     ?? '';
$officerId = $_GET['officer_id'] ?? '';

$officers = $db->query(
    "SELECT user_id, full_name FROM Users WHERE role = 'Officer' ORDER BY full_name"
)->fetchAll();

// ── Build query ────────────────────────────────────────────────
$sql = 'SELECT c.*, u.full_name AS officer_name
        FROM Cases c
        LEFT JOIN Users u ON u.user_id = c.officer_id
        WHERE 1=1';
$params = [];

if ($from !== '') {
    $sql .= ' AND c.date_opened >= :from';
    $params['from'] = $from;
}
if ($to !== '') {
    $sql .= ' AND c.date_opened <= :to';
    $params['to'] = $to;
}
if (in_array($status, ['Open', 'In Progress', 'Closed'], true)) {
    $sql .= ' AND c.status = :status';
    $params['status'] = $status;
}
if ($officerId !== '') {
    $sql .= ' AND c.officer_id = :officer_id';
    $params['officer_id'] = $officerId;
}

$sql .= ' ORDER BY c.date_opened DESC';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$hasFilters = ($from !== '' || $to !== '' || $status !== '' || $officerId !== '');

require __DIR__ . '/../includes/header.php';
?>

<h1>Police Case Report</h1>
<p class="subtitle">
    Filter by date range, status and officer, then print or save as PDF.
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
                <?php foreach (['Open', 'In Progress', 'Closed'] as $s): ?>
                    <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>>
                        <?= $s ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter-field">
            <label for="officer_id">Officer</label>
            <select id="officer_id" name="officer_id">
                <option value="">All</option>
                <?php foreach ($officers as $o): ?>
                    <option value="<?= (int) $o['user_id'] ?>"
                        <?= (string) $officerId === (string) $o['user_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($o['full_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <button type="submit" class="btn">Filter</button>

        <button type="button" class="btn btn-print" data-print>Print</button>

        <?php if ($hasFilters): ?>
            <a href="cases.php" class="btn secondary">Clear</a>
        <?php endif; ?>
    </form>
</div>

<!-- ── Data table (prints) ───────────────────────────────────── -->
<div class="table-responsive">
    <table class="data-table">
        <thead>
            <tr>
                <th>Case No.</th>
                <th>Offender</th>
                <th>Offence</th>
                <th>Status</th>
                <th>Officer</th>
                <th>Date Opened</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="6">No matching cases.</td></tr>
            <?php endif; ?>

            <?php foreach ($rows as $c): ?>
                <tr>
                    <td><?= htmlspecialchars($c['case_number']) ?></td>
                    <td><?= htmlspecialchars($c['offender_name']) ?></td>
                    <td><?= htmlspecialchars($c['offence_type']) ?></td>
                    <td><?= htmlspecialchars($c['status']) ?></td>
                    <td><?= htmlspecialchars($c['officer_name'] ?? '-') ?></td>
                    <td><?= htmlspecialchars(date('d M Y', strtotime($c['date_opened']))) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<p class="no-print">
    <a href="index.php">&larr; Back to Reports</a>
</p>

<?php require __DIR__ . '/../includes/footer.php'; ?>