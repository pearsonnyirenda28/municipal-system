<?php
/**
 * cli_backup.php
 * Improvement 16: Backup and Recovery - automatic daily backups.
 *
 * Meant to be run from the command line by a scheduler, not from a
 * browser - it exits immediately if it detects a web server context, so
 * it can't be triggered by an anonymous HTTP request even if someone
 * finds the URL.
 *
 * Cron (Linux/macOS), once a day at 2am:
 *   0 2 * * * /usr/bin/php /full/path/to/municipal-system/cli_backup.php >> /full/path/to/municipal-system/backups/cli.log 2>&1
 *
 * Windows Task Scheduler: create a daily trigger with
 *   Program: C:\xampp\php\php.exe
 *   Arguments: C:\xampp\htdocs\municipal-system\cli_backup.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script only runs from the command line.');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/backup.php';

try {
    $db = getDb();
    $filename = createBackup($db, null); // no logged-in user in a cron context
    echo '[' . date('Y-m-d H:i:s') . "] Backup created: {$filename}" . PHP_EOL;

    // Keep the last 30 daily backups, prune anything older so backups/
    // doesn't grow forever.
    $backups = listBackups();
    foreach (array_slice($backups, 30) as $old) {
        unlink(backupDir() . '/' . $old['filename']);
        echo '[' . date('Y-m-d H:i:s') . "] Pruned old backup: {$old['filename']}" . PHP_EOL;
    }
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] Backup FAILED: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
