<?php
/**
 * logout.php
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

if (!empty($_SESSION['user_id'])) {
    logActivity($_SESSION['user_id'], 'Logged out');
}

endSession();   // empties the session, expires the cookie, destroys the server-side data
header('Location: login.php');
exit;
