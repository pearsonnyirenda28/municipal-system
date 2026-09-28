<?php
/**
 * reports/damage.php
 *
 * Damaged Meter Report — filter by repair status and house, then print.
 * Fully CSP-compliant: no inline handlers, no inline scripts.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
requireRole(['Admin', 'MeterSupervisor']);

$db = getDb();

$status  = $_GET['status']   ?? '';
$houseId = $_GET['house_id'] ?? '';

$houses = $db->query(
    'SELECT house_id, house_number FROM Houses ORDER BY house_number'
)->fetchAll();

// ── Build query ────────────────────────────────────────────────
$sql = 'SELECT d.*, h.house_number, w.ward_name
        FROM DamagedMeters d
        JOIN Houses h ON h.house_id = d.house_id
        LEFT JOIN Wards w ON w.ward_id = h.ward_id
        WHERE 1=1';
$params = [];

if (in_array($status, ['Pending', 'In Progress', 'Fixed'], true)) {
    $sql .= ' AND d.repair_status = :status';
    $params['status'] = $status;
}
if ($houseId !== '') {
    $sql .= ' AND d.house_id = :house_id';
    $params['house_id'] = $houseId;
}

$sql .= ' ORDER BY d.date_reported DESC';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$hasFilters = ($status !== '' || $houseId !== '');

require __DIR__ . '/../includes/header.php';
?>

<h1>Damaged Meter Report</h1>
<p class="subtitle">
    Filter by repair status and house, then print or save as PDF.
</p>

<!-- ── Filter bar (hidden when printing) ─────────────────────── -->
<div class="filter-bar no-print">
    <form method="get" class="filter-row">
        <div class="filter-field">
            <label for="status">Status</label>
            <select id="status" name="status">
                <option value="">All</option>
                <?php foreach (['Pending', 'In Progress', 'Fixed'] as $s): ?>
                    <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>>
                        <?= $s ?>
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

        <button type="submit" class="btn">Filter</button>

        <button type="button" class="btn btn-print" data-print>Print</button>

        <?php if ($hasFilters): ?>
            <a href="damage.php" class="btn secondary">Clear</a>
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
                <th>Description</th>
                <th>Status</th>
                <th>Date Reported</th>
                <th>Date Fixed</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="6">No matching reports.</td></tr>
            <?php endif; ?>

            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= htmlspecialchars($r['house_number']) ?></td>
                    <td><?= htmlspecialchars($r['ward_name'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($r['description']) ?></td>
                    <td><?= htmlspecialchars($r['repair_status']) ?></td>
                    <td><?= htmlspecialchars(date('d M Y', strtotime($r['date_reported']))) ?></td>
                    <td><?= $r['date_fixed']
                            ? htmlspecialchars(date('d M Y', strtotime($r['date_fixed'])))
                            : '-' ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<p class="no-print">
    <a href="index.php">&larr; Back to Reports</a>
</p>

<?php require __DIR__ . '/../includes/footer.php'; ?>