<?php
/**
 * meter_readings.php
 * Displays recorded meter readings with local vs. synced status badges.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/local_store.php';

requireLogin();

$user = currentUser();
$userRole = $user['role'] ?? 'Reader';

// Determine LAN server connectivity state
$isServerConnected = false;
$serverUrl = CENTRAL_SERVER_URL;

$ch = curl_init($serverUrl);
curl_setopt_array($ch, [
    CURLOPT_NOBODY => true,
    CURLOPT_TIMEOUT => 2,
    CURLOPT_CONNECTTIMEOUT => 2,
    CURLOPT_RETURNTRANSFER => true,
]);
curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode >= 200 && $httpCode < 500) {
    $isServerConnected = true;
}

// Fetch readings from local SQLite store
$localDb = getLocalDb();
$stmt = $localDb->query("
    SELECT reading_id, sync_id, house_id, reader_id, previous_reading, current_reading, reading_date, sync_status 
    FROM MeterReadings 
    ORDER BY reading_id DESC 
    LIMIT 100
");
$readings = $stmt->fetchAll();

require __DIR__ . '/includes/header.php';
?>

<h1>Meter Readings</h1>
<p class="subtitle">Local resilience log & central synchronization status.</p>

<div class="table-responsive">
<table class="data-table">
    <thead>
        <tr>
            <th>Reading ID</th>
            <th>House ID</th>
            <th>Previous (m³)</th>
            <th>Current (m³)</th>
            <th>Date</th>
            <th>Status</th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($readings)): ?>
            <tr>
                <td colspan="6">No meter readings recorded locally yet.</td>
            </tr>
        <?php else: ?>
            <?php foreach ($readings as $row): ?>
                <?php $syncStatus = $row['sync_status'] ?? 'PENDING'; ?>
                <tr>
                    <td>#<?= htmlspecialchars((string)$row['reading_id']) ?></td>
                    <td><?= htmlspecialchars((string)$row['house_id']) ?></td>
                    <td><?= number_format((float)$row['previous_reading'], 2) ?></td>
                    <td><?= number_format((float)$row['current_reading'], 2) ?></td>
                    <td><?= htmlspecialchars((string)$row['reading_date']) ?></td>
                    <td>
                        <?php if ($userRole === 'Admin' || $isServerConnected): ?>
                            <?php if ($syncStatus === 'SYNCED'): ?>
                                <span class="status-badge badge-synced">Synced</span>
                            <?php else: ?>
                                <span class="status-badge badge-pending">Local</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="status-badge badge-offline">Stored Locally</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>