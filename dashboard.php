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

$db   = getDb();
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

// Version 3.0 - Meter Reader Dashboard: daily workload, progress,
// remaining houses, and a full reading-statistics breakdown, all scoped
// to this reader's own assigned wards, using the same getReaderRound()
// the guided navigator itself uses (one source of truth for "today").
$readerWorkload = null;
if ($role === 'MeterReader') {
    $wardIds = getAssignedWardIds($db, (int) $user['id']);
    $round   = getReaderRound($db, $wardIds, (int) $user['id'], date('Y-m-d'));

    $statusCounts = array_fill_keys(
        ['Pending', 'Completed', 'Skipped', 'DamagedMeter', 'Vacant', 'Locked', 'IllegalConnection', 'RevisitRequired'],
        0
    );
    foreach ($round as $h) {
        if (isset($statusCounts[$h['visit_status']])) {
            $statusCounts[$h['visit_status']]++;
        }
    }

    $readerWorkload = [
        'wardCount'    => count($wardIds),
        'totalHouses'  => count($round),
        'remaining'    => $statusCounts['Pending'],
        'completed'    => count($round) - $statusCounts['Pending'],
        'statusCounts' => $statusCounts,
    ];
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
    'Clerk'            => 'Clerk / Cashier',
    'MeterReader'      => 'Meter Reader',
    'PoliceSupervisor' => 'Police Supervisor',
    'MeterSupervisor'  => 'Meter Reading Supervisor',
];

require __DIR__ . '/includes/header.php';
?>

<h1>Dashboard</h1>
<p class="subtitle">
    Welcome back, <?= htmlspecialchars($user['name']) ?>
    (<?= htmlspecialchars($roleLabels[$role] ?? $role) ?>).
</p>

<!-- ── Top-line stats ──────────────────────────────────────────── -->
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
            <div class="stat-value"><?= $readerWorkload['completed'] ?></div>
            <div class="stat-label">Visited today</div>
        </div>
        <div class="stat-card amber">
            <div class="stat-value"><?= $readerWorkload['remaining'] ?></div>
            <div class="stat-label">Remaining today</div>
        </div>
        <div class="stat-card">
            <div class="stat-value" id="unsyncedCount">&hellip;</div>
            <div class="stat-label">Unsynced on this device</div>
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

<!-- ── Meter Reader progress card ──────────────────────────────── -->
<?php if ($readerWorkload !== null && $readerWorkload['totalHouses'] > 0): ?>
    <?php $pct = (int) round(($readerWorkload['completed'] / $readerWorkload['totalHouses']) * 100); ?>
    <div class="card" style="margin-bottom: 2rem;">
        <p style="margin: 0 0 0.75rem; font-size: 0.95rem; color: var(--text-white);">
            <strong>Today's progress:</strong>
            <?= $readerWorkload['completed'] ?> of <?= $readerWorkload['totalHouses'] ?>
            houses (<?= $pct ?>%)
        </p>
        <div style="background: rgba(255,255,255,0.06);
                    border: 1px solid var(--glass-border);
                    border-radius: 8px;
                    height: 14px;
                    overflow: hidden;">
            <div style="background: var(--accent-green);
                        height: 100%;
                        width: <?= $pct ?>%;
                        transition: width 0.4s ease;"></div>
        </div>
        <a class="btn" href="meter-readings/capture.php" style="margin-top: 1rem;">
            Continue Round &rarr;
        </a>
    </div>

    <?php
    $statusLabels = [
        'Pending'           => 'Pending',
        'Completed'         => 'Completed',
        'Skipped'           => 'Skipped',
        'DamagedMeter'      => 'Damaged meter',
        'Vacant'            => 'Vacant',
        'Locked'            => 'Locked premises',
        'IllegalConnection' => 'Illegal connection',
        'RevisitRequired'   => 'Revisit required',
    ];
    ?>
    <h2>Today's Reading Statistics</h2>
    <div class="card-grid">
        <?php foreach ($statusLabels as $key => $label): ?>
            <?php if (!empty($readerWorkload['statusCounts'][$key])): ?>
                <div class="stat-card">
                    <div class="stat-value"><?= $readerWorkload['statusCounts'][$key] ?></div>
                    <div class="stat-label"><?= htmlspecialchars($label) ?></div>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>

<?php elseif ($readerWorkload !== null && $readerWorkload['totalHouses'] === 0): ?>
    <div class="alert warning">
        You aren't assigned to any ward yet, so there's no workload to show.
        Ask an Administrator to assign you to a ward under
        <strong>Wards &rarr; Assign Readers</strong>.
    </div>
<?php endif; ?>

<!-- ── Quick actions ───────────────────────────────────────────── -->
<h2>Quick Actions</h2>
<div class="card-grid">
    <?php if (in_array($role, ['Officer', 'Admin'], true)): ?>
        <a class="btn" href="cases/create.php">+ Record a Case</a>
        <a class="btn" href="fines/issue.php">+ Issue a Fine</a>
    <?php endif; ?>

    <?php if (in_array($role, ['MeterReader', 'Admin'], true)): ?>
        <a class="btn" href="meter-readings/capture.php">+ Capture Meter Reading</a>
        <a class="btn" href="damaged-meters/report.php">+ Report Damaged Meter</a>
    <?php endif; ?>

    <?php if ($role === 'Admin'): ?>
        <a class="btn btn-secondary" href="users/create.php">+ Create User Account</a>
    <?php endif; ?>

    <?php if (in_array($role, ['Admin', 'PoliceSupervisor'], true)): ?>
        <a class="btn btn-secondary" href="reports/cases.php">Case Report</a>
        <a class="btn btn-secondary" href="reports/fines.php">Fine Report</a>
    <?php endif; ?>

    <?php if (in_array($role, ['Admin', 'MeterSupervisor'], true)): ?>
        <a class="btn btn-secondary" href="reports/readings.php">Meter Reading Report</a>
        <a class="btn btn-secondary" href="reports/damage.php">Damaged Meter Report</a>
    <?php endif; ?>
</div>

<!-- ── Recent Activity (bounded, internally scrollable) ────────── -->
<h2><?= $role === 'Admin' ? 'Recent Activity (all users)' : 'Your Recent Activity' ?></h2>

<div class="activity-card">
    <div class="activity-scroll">
        <table class="data-table activity-table">
            <thead>
                <tr>
                    <?php if ($role === 'Admin'): ?>
                        <th class="col-user">User</th>
                    <?php endif; ?>
                    <th>Action</th>
                    <th class="col-when">When</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$recentActivity): ?>
                    <tr>
                        <td colspan="<?= $role === 'Admin' ? 3 : 2 ?>" class="empty-row">
                            Nothing logged yet.
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($recentActivity as $a): ?>
                    <tr>
                        <?php if ($role === 'Admin'): ?>
                            <td class="col-user">
                                <?= htmlspecialchars($a['full_name'] ?? 'Unknown') ?>
                            </td>
                        <?php endif; ?>
                        <td><?= htmlspecialchars($a['action_description']) ?></td>
                        <td class="col-when">
                            <?= htmlspecialchars(date('d M Y H:i', strtotime($a['date_time']))) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($readerWorkload !== null): ?>
<script src="/municipal-system/assets/js/offline-queue.js"></script>
<script>
// Reuses the same IndexedDB queue the guided navigator writes to (any
// type - readings, visit-status marks) - this is genuinely what's still
// sitting on this device, not a server-side guess.
if (typeof countPending === 'function') {
    countPending().then(function (count) {
        var el = document.getElementById('unsyncedCount');
        if (el) el.textContent = count;
    });
}
</script>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>