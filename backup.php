<?php
/**
 * backup.php
 * Improvement 16: Backup and Recovery - Admin UI.
 * Create a backup on demand, download existing ones, restore from one,
 * or delete old ones. Automated daily backups run via cli_backup.php on
 * a schedule (cron / Windows Task Scheduler) - see the note on this page
 * and README.md section 10 for the exact command.
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/backup.php';
requireRole(['Admin']);

$db = getDb();
$user = currentUser();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create') {
            $filename = createBackup($db, $user['id']);
            $message = "Backup '{$filename}' created.";

        } elseif ($action === 'restore') {
            $filename = $_POST['filename'] ?? '';
            if (($_POST['confirm_text'] ?? '') !== 'RESTORE') {
                throw new RuntimeException('Please type RESTORE to confirm - this replaces all current data.');
            }
            restoreBackup($db, $filename, $user['id']);
            $message = "Database restored from '{$filename}'. Every table now matches that backup.";

        } elseif ($action === 'delete') {
            $filename = basename($_POST['filename'] ?? '');
            $path = backupDir() . '/' . $filename;
            if (is_file($path) && str_starts_with($filename, 'backup-')) {
                unlink($path);
                logActivity($user['id'], "Deleted backup file '{$filename}'");
                $message = "Deleted '{$filename}'.";
            }
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// Plain file download, not a POST action - GET is fine here since it's
// read-only and doesn't need CSRF protection.
if (isset($_GET['download'])) {
    $filename = basename($_GET['download']);
    $path = backupDir() . '/' . $filename;
    if (is_file($path) && str_starts_with($filename, 'backup-')) {
        logActivity($user['id'], "Downloaded backup file '{$filename}'");
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }
}

$backups = listBackups();

require __DIR__ . '/includes/header.php';
?>

<h1>Backups</h1>
<p class="subtitle">Full data snapshots of every table. Restoring replaces all current data with the backup's data - the table structure itself is untouched.</p>

<?php if ($message): ?><div class="alert success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="form-panel" style="max-width:none;margin-bottom:20px;">
    <form method="post" style="display:inline;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create">
        <button type="submit" class="btn">Create Backup Now</button>
    </form>
    <p style="font-size:0.85rem;color:#5B6472;margin-top:12px;">
        For automatic daily backups, schedule <code>php cli_backup.php</code> (in the project root) to run once a
        day via cron (Linux/macOS) or Task Scheduler (Windows) - see README.md section 10 for the exact command.
    </p>
</div>

<div class="table-responsive">
<table class="data-table">
    <thead>
        <tr><th>Backup File</th><th>Created</th><th>Size</th><th>Actions</th></tr>
    </thead>
    <tbody>
        <?php if (!$backups): ?>
            <tr><td colspan="4">No backups yet - create one above.</td></tr>
        <?php endif; ?>
        <?php foreach ($backups as $b): ?>
            <tr>
                <td style="font-family:monospace;font-size:0.85rem;"><?= htmlspecialchars($b['filename']) ?></td>
                <td><?= htmlspecialchars(date('d M Y H:i', $b['created_at'])) ?></td>
                <td><?= number_format($b['size'] / 1024, 1) ?> KB</td>
                <td style="white-space:nowrap;">
                    <a href="?download=<?= urlencode($b['filename']) ?>">Download</a>
                    &nbsp;|&nbsp;
                    <button type="button" class="link-btn" style="color:#2C7873;"
                            onclick="document.getElementById('restore-<?= htmlspecialchars($b['filename']) ?>').style.display='block';">Restore</button>
                    &nbsp;|&nbsp;
                    <form method="post" style="display:inline;" onsubmit="return confirm('Delete this backup file permanently?');">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="filename" value="<?= htmlspecialchars($b['filename']) ?>">
                        <button type="submit" class="link-btn" style="color:#C0392B;">Delete</button>
                    </form>

                    <div id="restore-<?= htmlspecialchars($b['filename']) ?>" style="display:none;margin-top:10px;padding:12px;background:#FBEAEA;border-radius:6px;">
                        <form method="post" onsubmit="return confirm('This will permanently replace ALL current data with this backup. Continue?');">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="restore">
                            <input type="hidden" name="filename" value="<?= htmlspecialchars($b['filename']) ?>">
                            <label style="margin-top:0;">Type <strong>RESTORE</strong> to confirm replacing all current data:</label>
                            <input type="text" name="confirm_text" style="max-width:200px;display:inline-block;" required>
                            <button type="submit" class="btn" style="background:#C0392B;">Confirm Restore</button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
