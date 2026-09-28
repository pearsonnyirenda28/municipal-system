<?php
/**
 * reports/readings.php
 *
 * Meter Reading Report — filter by ward, house and date range, then print.
 * Fully CSP-compliant: no inline handlers, no inline scripts.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
requireRole(['Admin', 'MeterSupervisor']);

$db = getDb();

$wardFilter = $_GET['ward_id']  ?? '';
$houseId    = $_GET['house_id'] ?? '';
$from       = $_GET['from']     ?? '';
$to         = $_GET['to']       ?? '';

$wards = $db->query(
    'SELECT ward_id, ward_name FROM Wards ORDER BY ward_name'
)->fetchAll();

$houses = $db->query(
    'SELECT house_id, house_number FROM Houses ORDER BY house_number'
)->fetchAll();

// ── Build query ────────────────────────────────────────────────
$sql = 'SELECT r.*, h.house_number, w.ward_name
        FROM MeterReadings r
        JOIN Houses h ON h.house_id = r.house_id
        LEFT JOIN Wards w ON w.ward_id = h.ward_id
        WHERE 1=1';
$params = [];

if ($wardFilter !== '') {
    $sql .= ' AND h.ward_id = :ward_id';
    $params['ward_id'] = $wardFilter;
}
if ($houseId !== '') {
    $sql .= ' AND r.house_id = :house_id';
    $params['house_id'] = $houseId;
}
if ($from !== '') {
    $sql .= ' AND r.date_taken >= :from';
    $params['from'] = $from;
}
if ($to !== '') {
    $sql .= ' AND r.date_taken <= :to';
    $params['to'] = $to;
}

$sql .= ' ORDER BY r.date_taken DESC';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$hasFilters = ($wardFilter !== '' || $houseId !== '' || $from !== '' || $to !== '');

require __DIR__ . '/../includes/header.php';
?>

<h1>Meter Reading Report</h1>
<p class="subtitle">
    Filter by ward, house and date range, then print or save as PDF.
</p>

<!-- ── Filter bar (hidden when printing) ─────────────────────── -->
<div class="filter-bar no-print">
    <form method="get" class="filter-row">
        <div class="filter-field">
            <label for="ward_id">Ward</label>
            <select id="ward_id" name="ward_id">
                <option value="">All</option>
                <?php foreach ($wards as $w): ?>
                    <option value="<?= (int) $w['ward_id'] ?>"
                        <?= (string) $wardFilter === (string) $w['ward_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($w['ward_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter-field">
            <label for="house_id">House</label>
            <select id="house_id" name="house_id">
                <option value="">All</option>
                <?php foreach ($houses as $h): ?>
                    <option value="<?= (int) $h['house_id'] ?>"
                        <?= (string) $houseId === (string) $h['house_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($h['house_number']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

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

        <button type="submit" class="btn">Filter</button>

        <button type="button" class="btn btn-print" data-print>Print</button>

        <?php if ($hasFilters): ?>
            <a href="readings.php" class="btn secondary">Clear</a>
        <?php endif; ?>
    </form>
</div>

<!-- ── Data table (prints) ───────────────────────────────────── -->
<div class="table-responsive">
    <table class="data-table">
        <thead>
            <tr>
                <th>House</th>
                <th>Ward</th>
                <th>Previous</th>
                <th>Current</th>
                <th>Consumption</th>
                <th>Status</th>
                <th>Date</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="7">No matching readings.</td></tr>
            <?php endif; ?>

            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= htmlspecialchars($r['house_number']) ?></td>
                    <td><?= htmlspecialchars($r['ward_name'] ?? '-') ?></td>
                    <td><?= number_format((float) $r['previous_reading'], 2) ?></td>
                    <td><?= number_format((float) $r['current_reading'], 2) ?></td>
                    <td><?= number_format((float) $r['consumption'], 2) ?> m&sup3;</td>
                    <td><?= $r['is_abnormal'] ? 'Abnormal' : 'Normal' ?></td>
                    <td><?= htmlspecialchars(date('d M Y', strtotime($r['date_taken']))) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<p class="no-print">
    <a href="index.php">&larr; Back to Reports</a>
</p>

<?php require __DIR__ . '/../includes/footer.php'; ?>