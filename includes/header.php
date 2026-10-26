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
 *
 * The $menus array below lists what each role SHOULD see. It is then
 * filtered against PAGE_ACCESS (defined in includes/auth.php) before
 * being rendered, so a link can only appear if the destination page
 * would actually let that role in. This means the menu and the page
 * guard can never disagree - see canAccess() for the single source of
 * truth.
 */
$user = currentUser();

$menus = [
    'Admin'            => [['dashboard.php', 'Dashboard'], ['cases/list.php', 'Cases'], ['fines/list.php', 'Fines'],
                            ['meter-readings/list.php', 'Meter Readings'], ['meter-readings/duplicates.php', 'Duplicate Readings'],
                            ['damaged-meters/list.php', 'Damaged Meters'],
                            ['wards/list.php', 'Wards'], ['houses/list.php', 'Houses'],
                            ['reports/index.php', 'Reports'], ['users/list.php', 'Users'],
                            ['backup.php', 'Backups'], ['error_log_viewer.php', 'Error Log'],
                            ['sync/conflicts.php', 'Sync Conflicts'], ['sync/status.php', 'Sync Status'],
                            ['profile.php', 'My Account']],
    'Officer'          => [['dashboard.php', 'Dashboard'], ['cases/list.php', 'Cases'], ['fines/list.php', 'Fines'],
                            ['profile.php', 'My Account']],
    'MeterReader'      => [['dashboard.php', 'Dashboard'], ['meter-readings/list.php', 'Meter Readings'],
                            ['damaged-meters/list.php', 'Damaged Meters'],
                            ['profile.php', 'My Account']],
    'Clerk'            => [['dashboard.php', 'Dashboard'], ['fines/list.php', 'Fines'],
                            ['profile.php', 'My Account']],
    'PoliceSupervisor' => [['dashboard.php', 'Dashboard'], ['cases/list.php', 'Cases'], ['fines/list.php', 'Fines'],
                            ['reports/cases.php', 'Case Report'], ['reports/fines.php', 'Fine Report'],
                            ['profile.php', 'My Account']],
    'MeterSupervisor'  => [['dashboard.php', 'Dashboard'], ['meter-readings/list.php', 'Meter Readings'],
                            ['meter-readings/duplicates.php', 'Duplicate Readings'],
                            ['damaged-meters/list.php', 'Damaged Meters'],
                            ['reports/readings.php', 'Reading Report'], ['reports/damage.php', 'Damage Report'],
                            ['profile.php', 'My Account']],
];

// Filter every item against the canonical access map. Any link whose
// destination the current role can't actually open is silently dropped,
// so an accidental mismatch between a role list above and a page's own
// requireRole() shows up as a missing link, not a 403 surprise.
$myMenu = array_values(array_filter(
    $menus[$user['role']] ?? [],
    fn($item) => canAccess($item[0])
));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= htmlspecialchars(csrfToken(), ENT_QUOTES) ?>">
<meta name="current-user-id" content="<?= (int) ($_SESSION['user_id'] ?? 0) ?>">
<title><?= htmlspecialchars(APP_NAME) ?></title>
<link rel="stylesheet" href="/municipal-system/assets/css/style.css">

<!-- Version 4.0 - installable PWA -->
<link rel="manifest" href="/municipal-system/manifest.json">
<meta name="theme-color" content="#16223E">
<link rel="apple-touch-icon" href="/municipal-system/assets/icons/icon-192.png">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
</head>
<body>

<!-- ── Django-style app shell ─────────────────────────────────── -->
<div class="app-viewport">

    <!-- ── Fixed glass top bar ────────────────────────────────── -->
    <header class="topbar">
        <div class="brand">Beitbridge Municipality</div>

        <div class="topbar-right">
            <span id="connectivityBadge" class="connectivity-badge"
                  title="Server connectivity">Checking...</span>

            <button class="hamburger" id="navToggle"
                    type="button"
                    aria-label="Open menu" aria-expanded="false"
                    aria-controls="mainNav">
                <span></span><span></span><span></span>
            </button>
        </div>
    </header>

    <!-- ── Slide-in glass drawer nav ──────────────────────────── -->
    <nav class="mainnav" id="mainNav" aria-label="Main navigation">

        <!-- Close / minimize button — top-right of the drawer -->
        <button type="button"
                class="drawer-close"
                id="navClose"
                aria-label="Close menu">
            <span aria-hidden="true">&times;</span>
        </button>

        <?php foreach ($myMenu as [$href, $label]): ?>
            <a href="/municipal-system/<?= $href ?>"><?= htmlspecialchars($label) ?></a>
        <?php endforeach; ?>

        <div class="userbox userbox-mobile">
            <span><?= htmlspecialchars($user['name']) ?> (<?= htmlspecialchars($user['role']) ?>)</span>
            <a href="/municipal-system/logout.php" class="btn-logout">Logout</a>
        </div>
    </nav>

    <!-- Desktop userbox is kept for compatibility (hidden via CSS) -->
    <div class="userbox userbox-desktop">
        <span><?= htmlspecialchars($user['name']) ?> (<?= htmlspecialchars($user['role']) ?>)</span>
        <a href="/municipal-system/logout.php" class="btn-logout">Logout</a>
    </div>

    <!-- ── Internally-scrollable content area ─────────────────── -->
    <main class="content-wrapper">