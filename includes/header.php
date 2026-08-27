<?php
/**
 * includes/header.php
 * Shared layout top - role-based menu per Activity 4, Section 4.2.
 * Include AFTER calling requireLogin() or requireRole() in the page itself.
 *
 * PoliceSupervisor and MeterSupervisor are deliberately separate roles
 * (not one generic "Supervisor") so that a police supervisor never sees
 * meter-reading data and a meter-reading supervisor never sees police
 * case/fine data - each gets their own scoped dashboard and menu.
 */
$user = currentUser();

$menus = [
    'Admin'            => [['dashboard.php', 'Dashboard'], ['cases/list.php', 'Cases'], ['fines/list.php', 'Fines'],
                            ['meter-readings/list.php', 'Meter Readings'], ['damaged-meters/list.php', 'Damaged Meters'],
                            ['wards/list.php', 'Wards'], ['houses/list.php', 'Houses'],
                            ['reports/index.php', 'Reports'], ['users/list.php', 'Users'],
                            ['sync/conflicts.php', 'Sync Conflicts']],
    'Officer'          => [['dashboard.php', 'Dashboard'], ['cases/list.php', 'Cases'], ['fines/list.php', 'Fines']],
    'MeterReader'      => [['dashboard.php', 'Dashboard'], ['meter-readings/list.php', 'Meter Readings'],
                            ['damaged-meters/list.php', 'Damaged Meters']],
    'Clerk'            => [['dashboard.php', 'Dashboard'], ['fines/list.php', 'Fines']],
    'PoliceSupervisor' => [['dashboard.php', 'Dashboard'], ['cases/list.php', 'Cases'], ['fines/list.php', 'Fines'],
                            ['reports/cases.php', 'Case Report'], ['reports/fines.php', 'Fine Report']],
    'MeterSupervisor'  => [['dashboard.php', 'Dashboard'], ['meter-readings/list.php', 'Meter Readings'],
                            ['damaged-meters/list.php', 'Damaged Meters'],
                            ['reports/readings.php', 'Reading Report'], ['reports/damage.php', 'Damage Report']],
];
$myMenu = $menus[$user['role']] ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars(APP_NAME) ?></title>
<link rel="stylesheet" href="/municipal-system/assets/css/style.css">

<!-- Version 4.0 - installable PWA -->
<link rel="manifest" href="/municipal-system/manifest.json">
<meta name="theme-color" content="#16223E">
<link rel="apple-touch-icon" href="/municipal-system/assets/icons/icon-192.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
</head>
<body>
<header class="topbar">
    <div class="brand">Beitbridge Municipality</div>
    <span id="connectivityBadge" class="connectivity-badge" title="Server connectivity">Checking...</span>
    <button class="hamburger" id="navToggle" aria-label="Toggle menu" aria-expanded="false">
        <span></span><span></span><span></span>
    </button>
    <nav class="mainnav" id="mainNav">
        <?php foreach ($myMenu as [$href, $label]): ?>
            <a href="/municipal-system/<?= $href ?>"><?= htmlspecialchars($label) ?></a>
        <?php endforeach; ?>
        <div class="userbox userbox-mobile">
            <span><?= htmlspecialchars($user['name']) ?> (<?= htmlspecialchars($user['role']) ?>)</span>
            <a href="/municipal-system/logout.php" class="btn-logout">Logout</a>
        </div>
    </nav>
    <div class="userbox userbox-desktop">
        <span><?= htmlspecialchars($user['name']) ?> (<?= htmlspecialchars($user['role']) ?>)</span>
        <a href="/municipal-system/logout.php" class="btn-logout">Logout</a>
    </div>
</header>
<script src="/municipal-system/assets/js/nav.js"></script>
<script src="/municipal-system/assets/js/connectivity.js"></script>
<script src="/municipal-system/assets/js/register-sw.js"></script>
<main class="content">
