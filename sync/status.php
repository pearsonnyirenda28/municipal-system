<?php
/**
 * sync/status.php
 * Version 4.5. This workstation's own view of the local-first sync
 * pipeline: what's still pending, what the background engine
 * (cli_sync_engine.php) last managed to do, and anything stuck in
 * SYNC_ERROR that needs a look. Reads LocalSyncQueue and
 * SyncEngineState directly - both are workstation-local tables (see
 * includes/local_store.php), so this only has something to show when
 * DB_DRIVER=sqlite. A Workspace Server (DB_DRIVER=mysql) is the
 * destination records sync TO, not a workstation with a queue of its
 * own, so it has neither table - this page says so plainly instead of
 * erroring.
 */
require_once __DIR__ . '/../includes/local_store.php';
require_once __DIR__ . '/../includes/auth.php';
requireRole(['Admin']);

$db = getDb();
$isWorkstation = (DB_DRIVER === 'sqlite');

$engineState = null;
$counts = [];
$recentQueue = [];
$stuckCount = 0;

if ($isWorkstation) {
    $engineState = getSyncEngineState($db);

    $counts = $db->query(
        "SELECT status, COUNT(*) AS n FROM LocalSyncQueue GROUP BY status"
    )->fetchAll(PDO::FETCH_KEY_PAIR);
    $counts += ['PENDING' => 0, 'SYNCING' => 0, 'SYNCED' => 0, 'SYNC_ERROR' => 0];

    $stuckCount = (int) $counts['SYNC_ERROR'];

    $recentQueue = $db->query(
        "SELECT sync_id, table_name, operation, status, attempts, error_message, created_at, updated_at
         FROM LocalSyncQueue
         ORDER BY (status = 'SYNC_ERROR') DESC, updated_at DESC
         LIMIT 50"
    )->fetchAll();
}

$stateLabels = [
    'WORKSPACE_UNAVAILABLE' => ['Workspace Server unreachable', 'error'],
    'ONLINE'                => ['Online - syncing', 'warning'],
    'SYNCING'               => ['Syncing now', 'warning'],
    'SYNCHRONIZED'          => ['Fully synchronized', 'success'],
    'SYNC_ERROR'            => ['Sync error', 'error'],
];

require __DIR__ . '/../includes/header.php';
?>

<div class="page-actions">
    <div>
        <h1>Sync Status</h1>
        <p class="subtitle">This workstation's connection to the central Workspace Server.</p>
    </div>
</div>

<?php if (!$isWorkstation): ?>

    <div class="alert warning">
        This instance is running <code>DB_DRIVER=mysql</code> - it <strong>is</strong> the central
        Workspace Server, so it has no local outbox of its own (see <code>includes/local_store.php</code>).
        This page shows a <em>field workstation's</em> own sync status - visit it there instead. To see
        what has arrived here so far, check <a href="/municipal-system/meter-readings/list.php">Meter Readings</a>,
        <a href="/municipal-system/fines/list.php">Fines</a>, each record's Device column shows which
        workstation it came from.
    </div>

<?php else: ?>

    <?php
        $label = $stateLabels[$engineState['state']] ?? ['Unknown', 'warning'];
        $centralConfigured = CENTRAL_SERVER_URL !== '' && SYNC_API_KEY !== '';
    ?>

    <?php if (!$centralConfigured): ?>
        <div class="alert warning">
            <code>CENTRAL_SERVER_URL</code> and/or <code>SYNC_API_KEY</code> are not set in <code>.env</code> -
            <code>cli_sync_engine.php</code> has nothing to sync to and is idling. This workstation still
            works normally on its own; nothing captured here will reach a central database until those are
            configured and the sync engine is running. See <code>README.md</code>, "Deploying more than one
            workstation".
        </div>
    <?php elseif (!isSyncEngineRunning($db)): ?>
        <div class="alert warning">
            The sync engine doesn't appear to have run recently (no status update in the last few minutes).
            Records are still being saved and queued normally, but nothing will reach the Workspace Server
            until <code>cli_sync_engine.php</code> is running in the background - see
            <code>README.md</code>, "Running the sync engine".
        </div>
    <?php endif; ?>

    <div class="card-grid" style="margin-bottom:20px;">
        <div class="stat-card">
            <div class="stat-value"><span class="alert <?= $label[1] ?>" style="display:inline-block;padding:4px 12px;font-size:0.9rem;"><?= htmlspecialchars($label[0]) ?></span></div>
            <div class="stat-label">Engine state</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= (int) $counts['PENDING'] + (int) $counts['SYNCING'] ?></div>
            <div class="stat-label">Pending</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= (int) $counts['SYNCED'] ?></div>
            <div class="stat-label">Synced</div>
        </div>
        <div class="stat-card <?= $stuckCount ? 'amber' : '' ?>">
            <div class="stat-value"><?= $stuckCount ?></div>
            <div class="stat-label">Needs attention</div>
        </div>
    </div>

    <p style="font-size:0.85rem;color:#5B6472;">
        Device: <strong><?= htmlspecialchars(DEVICE_ID) ?></strong> &bull;
        Target: <strong><?= htmlspecialchars(CENTRAL_SERVER_URL ?: '(not configured)') ?></strong> &bull;
        Last push attempt: <?= $engineState['last_push_attempt_at'] ? htmlspecialchars(date('d M Y H:i:s', strtotime($engineState['last_push_attempt_at']))) : 'never' ?> &bull;
        Last successful push: <?= $engineState['last_push_success_at'] ? htmlspecialchars(date('d M Y H:i:s', strtotime($engineState['last_push_success_at']))) : 'never' ?> &bull;
        Last pull: <?= $engineState['last_pull_success_at'] ? htmlspecialchars(date('d M Y H:i:s', strtotime($engineState['last_pull_success_at']))) : 'never' ?>
    </p>
    <?php if ($engineState['last_error']): ?>
        <p style="font-size:0.85rem;color:#B3261E;">Last error: <?= htmlspecialchars($engineState['last_error']) ?></p>
    <?php endif; ?>

    <div class="table-responsive" style="margin-top:16px;">
    <table class="data-table">
        <thead><tr><th>Table</th><th>Op</th><th>Status</th><th>Attempts</th><th>Last error</th><th>Updated</th></tr></thead>
        <tbody>
            <?php if (!$recentQueue): ?>
                <tr><td colspan="6">Nothing has been queued for central sync on this workstation yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($recentQueue as $q): ?>
                <tr>
                    <td><?= htmlspecialchars($q['table_name']) ?></td>
                    <td><?= htmlspecialchars($q['operation']) ?></td>
                    <td><span class="alert <?= $q['status'] === 'SYNC_ERROR' ? 'error' : ($q['status'] === 'SYNCED' ? 'success' : 'warning') ?>" style="display:inline-block;padding:2px 8px;font-size:0.8rem;"><?= htmlspecialchars($q['status']) ?></span></td>
                    <td><?= (int) $q['attempts'] ?></td>
                    <td><?= $q['error_message'] ? htmlspecialchars($q['error_message']) : '' ?></td>
                    <td><?= htmlspecialchars(date('d M Y H:i', strtotime($q['updated_at']))) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <p style="font-size:0.82rem;color:#5B6472;margin-top:10px;">
        Showing the 50 most recent, errors first. A row in <code>SYNC_ERROR</code> has been rejected by the
        Workspace Server <?= (int) SYNC_MAX_ATTEMPTS ?> times and will not retry automatically - check the
        error message above, fix the underlying data if needed, and update its status back to
        <code>PENDING</code> directly in the database to try again.
    </p>

<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
