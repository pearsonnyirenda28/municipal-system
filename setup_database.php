<?php
/**
 * setup_database.php
 * Run this ONCE in your browser to create the SQLite database file and
 * all tables (Chapter 4, Section 3.2), before running setup_admin.php.
 *
 * There is no phpMyAdmin-style tool for SQLite, so this replaces the old
 * "import schema.sql via phpMyAdmin" step with a single click.
 */
require_once __DIR__ . '/config.php';

$message = '';
$success = false;

if (DB_DRIVER !== 'sqlite') {
    $message = 'DB_DRIVER is set to "' . DB_DRIVER . '" in your .env file. This script only '
             . 'sets up SQLite - please import schema.sql into MySQL yourself, or remove '
             . 'DB_DRIVER from .env to use SQLite instead.';
} else {
    try {
        $path = resolveDbPath(DB_PATH);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');

        $sql = file_get_contents(__DIR__ . '/schema.sql');
        $pdo->exec($sql);

        $success = true;
    } catch (Throwable $e) {
        $message = 'Setup failed: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Database Setup</title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="login-wrap">
    <div class="login-box">
        <h1>Database Setup</h1>

        <?php if ($success): ?>
            <div class="alert success">
                Database created successfully at <code><?= htmlspecialchars($path) ?></code>,
                with three sample houses ready for meter reading tests.
            </div>
            <p style="font-size:0.85rem;">Next step: create your Administrator account.</p>
            <a class="btn" href="setup_admin.php" style="width:100%;text-align:center;">Continue to Admin Setup</a>
        <?php else: ?>
            <div class="alert error"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <p style="font-size:0.75rem;color:#8a94a3;margin-top:18px;">
            Safe to run more than once - existing tables and data are left untouched
            (schema.sql uses CREATE TABLE IF NOT EXISTS).
        </p>
    </div>
</div>
</body>
</html>
