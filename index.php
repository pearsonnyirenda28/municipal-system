<?php
/**
 * index.php
 * Simple entry point - sends the visitor to the dashboard if logged in,
 * otherwise to the login page.
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
} else {
    header('Location: login.php');
}
exit;
