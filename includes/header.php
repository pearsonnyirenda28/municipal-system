<?php
/**
 * includes/header.php
 * Shared layout top - role-based menu per Activity 4, Section 4.2.
 * Include AFTER calling requireLogin() or requireRole() in the page itself.
 */
$user = currentUser();

$menus = [
    'Admin'       => [['dashboard.php', 'Dashboard'], ['cases/list.php', 'Cases'], ['fines/list.php', 'Fines'],
                       ['meter-readings/list.php', 'Meter Readings'], ['damaged-meters/list.php', 'Damaged Meters'],
                       ['reports/index.php', 'Reports'], ['users/list.php', 'Users']],
    'Officer'     => [['dashboard.php', 'Dashboard'], ['cases/list.php', 'Cases'], ['fines/list.php', 'Fines']],
    'MeterReader' => [['dashboard.php', 'Dashboard'], ['meter-readings/list.php', 'Meter Readings'],
                       ['damaged-meters/list.php', 'Damaged Meters']],
    'Clerk'       => [['dashboard.php', 'Dashboard'], ['fines/list.php', 'Fines']],
    'Supervisor'  => [['dashboard.php', 'Dashboard'], ['reports/index.php', 'Reports']],
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
</head>
<body>
<header class="topbar">
    <div class="brand">Beitbridge Municipality</div>
    <nav class="mainnav">
        <?php foreach ($myMenu as [$href, $label]): ?>
            <a href="/municipal-system/<?= $href ?>"><?= htmlspecialchars($label) ?></a>
        <?php endforeach; ?>
    </nav>
    <div class="userbox">
        <span><?= htmlspecialchars($user['name']) ?> (<?= htmlspecialchars($user['role']) ?>)</span>
        <a href="/municipal-system/logout.php" class="btn-logout">Logout</a>
    </div>
</header>
<main class="content">
