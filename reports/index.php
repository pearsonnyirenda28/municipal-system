<?php
/**
 * reports/index.php
 * Links to the four report types documented in Activity 4, Section 2.2.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
requireRole(['Admin', 'Supervisor']);

require __DIR__ . '/../includes/header.php';
?>

<h1>Reports</h1>
<p class="subtitle">Filterable reports, matching Activity 4, Section 2.2.</p>

<div class="card-grid">
    <a class="stat-card" style="text-decoration:none;color:inherit;" href="cases.php">
        <div class="stat-label">Police Case Report</div>
        <div style="color:#5B6472;font-size:0.85rem;margin-top:6px;">Filter by date range, officer or status</div>
    </a>
    <a class="stat-card" style="text-decoration:none;color:inherit;" href="fines.php">
        <div class="stat-label">Fine Report</div>
        <div style="color:#5B6472;font-size:0.85rem;margin-top:6px;">Filter by date range or payment status</div>
    </a>
    <a class="stat-card" style="text-decoration:none;color:inherit;" href="readings.php">
        <div class="stat-label">Meter Reading Report</div>
        <div style="color:#5B6472;font-size:0.85rem;margin-top:6px;">Filter by ward, house or date</div>
    </a>
    <a class="stat-card" style="text-decoration:none;color:inherit;" href="damage.php">
        <div class="stat-label">Damaged Meter Report</div>
        <div style="color:#5B6472;font-size:0.85rem;margin-top:6px;">Filter by status or house</div>
    </a>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
