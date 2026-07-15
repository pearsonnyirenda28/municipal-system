<?php
/**
 * dashboard.php
 * "View Dashboard" use case (Activity 4, Section 6.1).
 *
 * Each role only ever queries and renders the stats relevant to it.
 * In particular, PoliceSupervisor and MeterSupervisor are kept
 * completely separate: a police supervisor never sees meter-reading or
 * damaged-meter figures, and a meter-reading supervisor never sees case
 * or fine figures - not just hidden in the menu, the data itself is
 * never fetched for the "wrong" role.
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();

$db = getDb();
$user = currentUser();
$role = $user['role'];

$showPolice = in_array($role, ['Admin', 'Officer', 'Clerk', 'PoliceSupervisor'], true);
$showMeter  = in_array($role, ['Admin', 'MeterReader', 'MeterSupervisor'], true);

if ($showPolice) {
    $unpaidFines    = $db->query("SELECT COUNT(*) c, COALESCE(SUM(amount),0) total FROM Fines WHERE payment_status = 'Unpaid'")->fetch();
    $finesThisMonth = $db->query("SELECT COUNT(*) c FROM Fines WHERE " . dbCurrentMonthMatch('date_issued'))->fetch();
    $openCases      = $db->query("SELECT COUNT(*) c FROM Cases WHERE status != 'Closed'")->fetch();
}

if ($showMeter) {
    $abnormalReadings = $db->query("SELECT COUNT(*) c FROM MeterReadings WHERE is_abnormal = 1 AND " . dbWithinLast('date_taken', 30, 'DAY'))->fetch();
    $pendingRepairs   = $db->query("SELECT COUNT(*) c FROM DamagedMeters WHERE repair_status != 'Fixed'")->fetch();
}

// Version 2.5 - Meter Reader Dashboard: daily workload, progress, and
// remaining houses, scoped to this reader's own assigned wards only.
$readerWorkload = null;
if ($role === 'MeterReader') {
    $wardIds = getAssignedWardIds($db, (int) $user['id']);
    if ($wardIds) {
        $placeholders = implode(',', array_fill(0, count($wardIds), '?'));

        $stmt = $db->prepare("SELECT COUNT(*) FROM Houses WHERE ward_id IN ({$placeholders})");
        $stmt->execute($wardIds);
        $totalHouses = (int) $stmt->fetchColumn();

        $stmt = $db->prepare(
            "SELECT COUNT(DISTINCT house_id) FROM MeterReadings
             WHERE date_taken = CURRENT_DATE AND house_id IN (
                 SELECT house_id FROM Houses WHERE ward_id IN ({$placeholders})
             )"
        );
        $stmt->execute($wardIds);
        $readToday = (int) $stmt->fetchColumn();

        $readerWorkload = [
            'wardCount' => count($wardIds),
            'totalHouses' => $totalHouses,
            'readToday' => $readToday,
            'remaining' => max(0, $totalHouses - $readToday),
        ];
    } else {
        $readerWorkload = ['wardCount' => 0, 'totalHouses' => 0, 'readToday' => 0, 'remaining' => 0];
    }
}

// Recent Activity widget: Admin sees the whole system's recent activity;
// everyone else sees only their own - a supervisor never sees another
// role's activity feed, consistent with the rest of this dashboard.
if ($role === 'Admin') {
    $recentActivity = $db->query(
        "SELECT a.*, u.full_name FROM ActivityLogs a LEFT JOIN Users u ON u.user_id = a.user_id
         ORDER BY a.date_time DESC LIMIT 8"
    )->fetchAll();
} else {
    $stmt = $db->prepare(
        "SELECT a.*, u.full_name FROM ActivityLogs a LEFT JOIN Users u ON u.user_id = a.user_id
         WHERE a.user_id = :uid ORDER BY a.date_time DESC LIMIT 8"
    );
    $stmt->execute(['uid' => $user['id']]);
    $recentActivity = $stmt->fetchAll();
}

$roleLabels = [
    'Admin'            => 'Administrator',
    'Officer'          => 'Police Officer',
    'Clerk'             => 'Clerk / Cashier',
    'MeterReader'      => 'Meter Reader',
    'PoliceSupervisor' => 'Police Supervisor',
    'MeterSupervisor'  => 'Meter Reading Supervisor',
];

require __DIR__ . '/includes/header.php';
?>

<h1>Dashboard</h1>
<p class="subtitle">Welcome back, <?= htmlspecialchars($user['name']) ?> (<?= htmlspecialchars($roleLabels[$role] ?? $role) ?>).</p>

<div class="card-grid">
    <?php if ($showPolice): ?>
        <div class="stat-card">
            <div class="stat-value"><?= (int) $openCases['c'] ?></div>
            <div class="stat-label">Open police cases</div>
        </div>
        <div class="stat-card amber">
            <div class="stat-value"><?= (int) $unpaidFines['c'] ?></div>
            <div class="stat-label">Unpaid fines (US$<?= number_format((float) $unpaidFines['total'], 2) ?>)</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= (int) $finesThisMonth['c'] ?></div>
            <div class="stat-label">Fines issued this month</div>
        </div>
    <?php endif; ?>

    <?php if ($readerWorkload !== null): ?>
        <div class="stat-card">
            <div class="stat-value"><?= $readerWorkload['totalHouses'] ?></div>
            <div class="stat-label">Houses across your <?= $readerWorkload['wardCount'] ?> ward(s)</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $readerWorkload['readToday'] ?></div>
            <div class="stat-label">Read today</div>
        </div>
        <div class="stat-card amber">
            <div class="stat-value"><?= $readerWorkload['remaining'] ?></div>
            <div class="stat-label">Remaining today</div>
        </div>
    <?php endif; ?>

    <?php if ($showMeter): ?>
        <div class="stat-card amber">
            <div class="stat-value"><?= (int) $abnormalReadings['c'] ?></div>
            <div class="stat-label">Abnormal readings (last 30 days)</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= (int) $pendingRepairs['c'] ?></div>
            <div class="stat-label">Damaged meters awaiting repair</div>
        </div>
    <?php endif; ?>
</div>

<?php if ($readerWorkload !== null && $readerWorkload['totalHouses'] > 0): ?>
    <?php $pct = (int) round(($readerWorkload['readToday'] / $readerWorkload['totalHouses']) * 100); ?>
    <div class="form-panel" style="max-width:none;margin-bottom:26px;">
        <p style="margin:0 0 8px;font-size:0.9rem;"><strong>Today's progress:</strong> <?= $readerWorkload['readToday'] ?> of <?= $readerWorkload['totalHouses'] ?> houses (<?= $pct ?>%)</p>
        <div style="background:#E1E6ED;border-radius:8px;height:14px;overflow:hidden;">
            <div style="background:#2C7873;height:100%;width:<?= $pct ?>%;"></div>
        </div>
    </div>
<?php elseif ($readerWorkload !== null && $readerWorkload['totalHouses'] === 0): ?>
    <div class="alert warning" style="margin-bottom:26px;">
        You aren't assigned to any ward yet, so there's no workload to show. Ask an Administrator to
        assign you to a ward under Wards &rarr; Assign Readers.
    </div>
<?php endif; ?>

<h2>Quick actions</h2>
<div class="card-grid">
    <?php if (in_array($role, ['Officer', 'Admin'], true)): ?>
        <a class="btn" href="cases/create.php">+ Record a Case</a>
        <a class="btn" href="fines/issue.php">+ Issue a Fine</a>
    <?php endif; ?>
    <?php if (in_array($role, ['MeterReader', 'Admin'], true)): ?>
        <a class="btn amber" href="meter-readings/capture.php">+ Capture Meter Reading</a>
        <a class="btn amber" href="damaged-meters/report.php">+ Report Damaged Meter</a>
    <?php endif; ?>
    <?php if ($role === 'Admin'): ?>
        <a class="btn secondary" href="users/create.php">+ Create User Account</a>
    <?php endif; ?>
    <?php if (in_array($role, ['Admin', 'PoliceSupervisor'], true)): ?>
        <a class="btn secondary" href="reports/cases.php">Case Report</a>
        <a class="btn secondary" href="reports/fines.php">Fine Report</a>
    <?php endif; ?>
    <?php if (in_array($role, ['Admin', 'MeterSupervisor'], true)): ?>
        <a class="btn secondary" href="reports/readings.php">Meter Reading Report</a>
        <a class="btn secondary" href="reports/damage.php">Damaged Meter Report</a>
    <?php endif; ?>
</div>

<h2><?= $role === 'Admin' ? 'Recent Activity (all users)' : 'Your Recent Activity' ?></h2>
<div class="table-responsive">
<table class="data-table">
    <thead>
        <tr><?php if ($role === 'Admin'): ?><th>User</th><?php endif; ?><th>Action</th><th>When</th></tr>
    </thead>
    <tbody>
        <?php if (!$recentActivity): ?>
            <tr><td colspan="<?= $role === 'Admin' ? 3 : 2 ?>">Nothing logged yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($recentActivity as $a): ?>
            <tr>
                <?php if ($role === 'Admin'): ?><td><?= htmlspecialchars($a['full_name'] ?? 'Unknown') ?></td><?php endif; ?>
                <td><?= htmlspecialchars($a['action_description']) ?></td>
                <td><?= htmlspecialchars(date('d M Y H:i', strtotime($a['date_time']))) ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
