<?php
/**
 * users/login_history.php
 * The Version 2.0 "login history" item - a dedicated view over the login/
 * logout/failed-attempt entries that were already being written to
 * ActivityLogs (by login.php and logout.php) but had no screen of their
 * own until now.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pagination.php';
requireRole(['Admin']);

$db = getDb();
$outcome = $_GET['outcome'] ?? '';
$search = trim($_GET['q'] ?? '');
$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';

// Base condition: only login-related activity, not every action in the system.
$where = ["(a.action_description LIKE 'Logged in%' OR a.action_description LIKE 'Failed login attempt%' OR a.action_description LIKE 'Logged out%')"];
$params = [];

if ($outcome === 'success') {
    $where[] = "a.action_description LIKE 'Logged in%'";
} elseif ($outcome === 'failed') {
    $where[] = "a.action_description LIKE 'Failed login attempt%'";
} elseif ($outcome === 'logout') {
    $where[] = "a.action_description LIKE 'Logged out%'";
}
if ($search !== '') {
    $where[] = '(u.full_name LIKE :q OR u.username LIKE :q OR a.action_description LIKE :q)';
    $params['q'] = "%{$search}%";
}
if ($from !== '') {
    $where[] = 'a.date_time >= :from';
    $params['from'] = $from . ' 00:00:00';
}
if ($to !== '') {
    $where[] = 'a.date_time <= :to';
    $params['to'] = $to . ' 23:59:59';
}
$whereSql = ' WHERE ' . implode(' AND ', $where);

$countSql = "SELECT COUNT(*) FROM ActivityLogs a LEFT JOIN Users u ON u.user_id = a.user_id{$whereSql}";
$pageInfo = paginate($db, $countSql, $params, 25);

$sql = "SELECT a.*, u.full_name, u.username, u.role
        FROM ActivityLogs a LEFT JOIN Users u ON u.user_id = a.user_id
        {$whereSql}
        ORDER BY a.date_time DESC
        LIMIT {$pageInfo['perPage']} OFFSET {$pageInfo['offset']}";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$hasFilters = $outcome || $search || $from || $to;

function loginOutcomeBadge(string $description): string
{
    if (str_starts_with($description, 'Logged in')) {
        return '<span class="badge paid">Success</span>';
    }
    if (str_starts_with($description, 'Failed login attempt')) {
        return '<span class="badge unpaid">Failed</span>';
    }
    return '<span class="badge normal">Logout</span>';
}

require __DIR__ . '/../includes/header.php';
?>

<div class="page-actions">
    <div>
        <h1>Login History</h1>
        <p class="subtitle"><?= (int) $pageInfo['total'] ?> event(s) found - successful logins, failed attempts, and logouts.</p>
    </div>
    <a class="btn secondary" href="list.php">&larr; Back to Users</a>
</div>

<div class="filter-bar">
    <form method="get" class="filter-row">
        <div class="filter-field grow">
            <label for="q">Search</label>
            <input type="text" id="q" name="q" placeholder="Name or username..." value="<?= htmlspecialchars($search) ?>">
        </div>
        <div class="filter-field">
            <label for="outcome">Outcome</label>
            <select id="outcome" name="outcome">
                <option value="">All</option>
                <option value="success" <?= $outcome === 'success' ? 'selected' : '' ?>>Successful login</option>
                <option value="failed" <?= $outcome === 'failed' ? 'selected' : '' ?>>Failed attempt</option>
                <option value="logout" <?= $outcome === 'logout' ? 'selected' : '' ?>>Logout</option>
            </select>
        </div>
        <div class="filter-field">
            <label for="from">From</label>
            <input type="date" id="from" name="from" value="<?= htmlspecialchars($from) ?>">
        </div>
        <div class="filter-field">
            <label for="to">To</label>
            <input type="date" id="to" name="to" value="<?= htmlspecialchars($to) ?>">
        </div>
        <button type="submit" class="btn">Apply Filters</button>
        <?php if ($hasFilters): ?><a href="login_history.php" class="btn secondary">Clear</a><?php endif; ?>
    </form>
</div>

<div class="table-responsive">
<table class="data-table">
    <thead>
        <tr><th>Outcome</th><th>User</th><th>Role</th><th>Detail</th><th>IP Address</th><th>When</th></tr>
    </thead>
    <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="6">No matching login activity.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= loginOutcomeBadge($r['action_description']) ?></td>
                <td><?= $r['full_name'] ? htmlspecialchars($r['full_name']) : '<span style="color:#5B6472;">Unknown</span>' ?></td>
                <td><?= $r['role'] ? htmlspecialchars($r['role']) : '&mdash;' ?></td>
                <td><?= htmlspecialchars($r['action_description']) ?></td>
                <td><?= $r['ip_address'] ? htmlspecialchars($r['ip_address']) : '&mdash;' ?></td>
                <td><?= htmlspecialchars(date('d M Y H:i', strtotime($r['date_time']))) ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>

<?= renderPagination($pageInfo) ?>

<p style="font-size:0.82rem;color:#5B6472;margin-top:16px;">
    "Unknown" users are failed login attempts against a username that either doesn't exist or
    couldn't be matched - the attempted username itself is shown in the Detail column.
</p>

<?php require __DIR__ . '/../includes/footer.php'; ?>
