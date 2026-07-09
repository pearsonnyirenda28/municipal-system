<?php
/**
 * logout.php
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

if (!empty($_SESSION['user_id'])) {
    logActivity($_SESSION['user_id'], 'Logged out');
}

session_unset();
session_destroy();
header('Location: login.php');
exit;
