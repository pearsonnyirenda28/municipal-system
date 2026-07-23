<?php
/**
 * ws/server.php
 * Real-time notifications: a standalone WebSocket server, written
 * against PHP's own stream functions rather than a library (Ratchet,
 * the usual choice, needs Composer - this app has stayed dependency-free
 * everywhere else, from the vendored FPDF to the CDN-free CSS, so this
 * follows the same rule). It implements just enough of RFC 6455 to push
 * one-way JSON notification updates to logged-in browser tabs: the
 * handshake, text-frame encoding, and enough client-frame decoding to
 * handle close/ping.
 *
 * WHAT THIS DOES: every few seconds, for each connected client, it
 * re-runs the exact same getNotifications() used by the header bell
 * (includes/notifications.php) - there is deliberately no separate
 * "event queue" table duplicating that logic - and pushes an update
 * only when the result actually changed for that user.
 *
 * WHAT THIS DOESN'T DO: this process is NOT Apache/php-fpm. It has to
 * be started and kept running separately - see ws/README.md. If it
 * isn't running, or a browser can't reach it, nothing breaks: the
 * header bell still works exactly as before, just without the live
 * push (it's checked again on every normal page load regardless).
 *
 * Run: php ws/server.php   (Ctrl+C to stop; see ws/README.md for
 * running it as a background/scheduled service instead.)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('ws/server.php only runs from the command line.');
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/WebSocketServer.php';

$server = new WebSocketServer(WS_PORT);
$server->run();
