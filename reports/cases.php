<?php
/**
 * reports/cases.php
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
requireRole(['Admin', 'PoliceSupervisor']);

$db = getDb();
$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';
$status = $_GET['status'] ?? '';
$officers = $db->query("SELECT user_id, full_name FROM Users WHERE role = 'Officer' ORDER BY full_name")->fetchAll();
$officerId = $_GET['officer_id'] ?? '';

$sql = 'SELECT c.*, u.full_name AS officer_name FROM Cases c LEFT JOIN Users u ON u.user_id = c.officer_id WHERE 1=1';
$params = [];
if ($from) { $sql .= ' AND c.date_opened >= :from'; $params['from'] = $from; }
if ($to)   { $sql .= ' AND c.date_opened <= :to';   $params['to'] = $to; }
if (in_array($status, ['Open', 'In Progress', 'Closed'], true)) { $sql .= ' AND c.status = :status'; $params['status'] = $status; }
if ($officerId !== '') { $sql .= ' AND c.officer_id = :officer_id'; $params['officer_id'] = $officerId; }
$sql .= ' ORDER BY c.date_opened DESC';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

require __DIR__ . '/../includes/header.php';
?>

<h1>Police Case Report</h1>

<form method="get" class="no-print" style="background:#fff;padding:18px;border-radius:10px;margin-bottom:20px;box-shadow:0 2px 8px rgba(22,34,62,0.08);">
    <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end;">
        <div><label>From</label><input type="date" name="from" value="<?= htmlspecialchars($from) ?>"></div>
        <div><label>To</label><input type="date" name="to" value="<?= htmlspecialchars($to) ?>"></div>
        <div><label>Status</label>
            <select name="status">
                <option value="">All</option>
                <?php foreach (['Open', 'In Progress', 'Closed'] as $s): ?>
                    <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= $s ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div><label>Officer</label>
            <select name="officer_id">
                <option value="">All</option>
                <?php foreach ($officers as $o): ?>
                    <option value="<?= (int) $o['user_id'] ?>" <?= (string) $officerId === (string) $o['user_id'] ? 'selected' : '' ?>><?= htmlspecialchars($o['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn" style="margin-top:0;">Filter</button>
        <button type="button" class="btn secondary" style="margin-top:0;" onclick="window.print()">Print</button>
    </div>
</form>

<table class="data-table">
    <thead><tr><th>Case No.</th><th>Offender</th><th>Offence</th><th>Status</th><th>Officer</th><th>Date Opened</th></tr></thead>
    <tbody>
        <?php if (!$rows): ?><tr><td colspan="6">No matching cases.</td></tr><?php endif; ?>
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
<p class="no-print"><a href="index.php">&larr; Back to Reports</a></p>

<?php require __DIR__ . '/../includes/footer.php'; ?>
