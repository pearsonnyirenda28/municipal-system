<?php
/**
 * error_log_viewer.php
 * Improvement 18: Global Error Handling - lets an Admin actually see
 * what config.php's error handlers have been logging, without needing
 * shell/FTP access to error_log.txt.
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
requireRole(['Admin']);

$lines = [];
if (is_file(ERROR_LOG_PATH)) {
    // Tail the last 200 lines rather than loading a potentially large
    // file whole - this page is for a quick recent-errors check, not a
    // full log archive (Download below covers that case).
    $all = file(ERROR_LOG_PATH, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $lines = array_reverse(array_slice($all, -200));
}

if (isset($_GET['download']) && is_file(ERROR_LOG_PATH)) {
    header('Content-Type: text/plain');
    header('Content-Disposition: attachment; filename="error_log.txt"');
    readfile(ERROR_LOG_PATH);
    exit;
}

require __DIR__ . '/includes/header.php';
?>

<div class="page-actions">
    <div>
        <h1>System Error Log</h1>
        <p class="subtitle">Most recent <?= count($lines) ?> entr<?= count($lines) === 1 ? 'y' : 'ies' ?> (newest first). Running in
            <strong><?= htmlspecialchars(APP_ENV) ?></strong> mode.</p>
    </div>
    <?php if ($lines): ?><a class="btn secondary" href="?download=1">Download Full Log</a><?php endif; ?>
</div>

<?php if (APP_ENV !== 'production'): ?>
    <div class="alert warning">APP_ENV is "<?= htmlspecialchars(APP_ENV) ?>" - errors are also showing directly on-screen to every visitor right now.
        Set <code>APP_ENV=production</code> in .env before a real deployment.</div>
<?php endif; ?>

<?php if (!$lines): ?>
    <div class="alert success">No errors logged yet.</div>
<?php else: ?>
    <div class="form-panel" style="max-width:none;">
        <pre style="white-space:pre-wrap;font-size:0.82rem;line-height:1.6;margin:0;max-height:70vh;overflow-y:auto;"><?php
            foreach ($lines as $line) {
                echo htmlspecialchars($line) . "\n";
            }
        ?></pre>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
