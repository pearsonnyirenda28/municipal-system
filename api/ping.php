<?php
/**
 * api/ping.php
 * Version 4.0 - Smart Connectivity Detection. navigator.onLine only ever
 * tells you the device has *some* network interface up, not whether the
 * municipal server itself is reachable (a captive portal, or the office
 * LAN with Apache/MySQL down, both read as "online"). This no-auth,
 * deliberately tiny endpoint lets assets/js/connectivity.js poll for the
 * real answer.
 */
header('Content-Type: application/json');
header('Cache-Control: no-store');
echo json_encode(['ok' => true, 'time' => date('c')]);
