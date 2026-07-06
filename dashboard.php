<?php
/**
 * dashboard.php
 * "View Dashboard" use case (Activity 4, Section 6.1).
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();

$db = getDb();
$user = currentUser();

$unpaidFines   = $db->query("SELECT COUNT(*) c, COALESCE(SUM(amount),0) total FROM Fines WHERE payment_status = 'Unpaid'")->fetch();
$finesThisMonth = $db->query("SELECT COUNT(*) c FROM Fines WHERE strftime('%Y-%m', date_issued) = strftime('%Y-%m', CURRENT_DATE)")->fetch();
$openCases     = $db->query("SELECT COUNT(*) c FROM Cases WHERE status != 'Closed'")->fetch();
$abnormalReadings = $db->query("SELECT COUNT(*) c FROM MeterReadings WHERE is_abnormal = 1 AND date_taken >= date('now', '-30 days')")->fetch();
$pendingRepairs = $db->query("SELECT COUNT(*) c FROM DamagedMeters WHERE repair_status != 'Fixed'")->fetch();

require __DIR__ . '/includes/header.php';
?>

<h1>Dashboard</h1>
<p class="subtitle">Welcome back, <?= htmlspecialchars($user['name']) ?>.</p>

<div class="card-grid">
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
    <div class="stat-card amber">
        <div class="stat-value"><?= (int) $abnormalReadings['c'] ?></div>
        <div class="stat-label">Abnormal readings (last 30 days)</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= (int) $pendingRepairs['c'] ?></div>
        <div class="stat-label">Damaged meters awaiting repair</div>
    </div>
</div>

<h2>Quick actions</h2>
<div class="card-grid">
    <?php if (in_array($user['role'], ['Officer', 'Admin'], true)): ?>
        <a class="btn" href="cases/create.php">+ Record a Case</a>
        <a class="btn" href="fines/issue.php">+ Issue a Fine</a>
    <?php endif; ?>
    <?php if (in_array($user['role'], ['MeterReader', 'Admin'], true)): ?>
        <a class="btn amber" href="meter-readings/capture.php">+ Capture Meter Reading</a>
        <a class="btn amber" href="damaged-meters/report.php">+ Report Damaged Meter</a>
    <?php endif; ?>
    <?php if ($user['role'] === 'Admin'): ?>
        <a class="btn secondary" href="users/create.php">+ Create User Account</a>
    <?php endif; ?>
    <?php if (in_array($user['role'], ['Admin', 'Supervisor'], true)): ?>
        <a class="btn secondary" href="reports/index.php">View Reports</a>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
