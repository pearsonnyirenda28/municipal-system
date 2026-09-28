<?php
/**
 * users/list.php
 * "Manage User Accounts" use case (Activity 4, Section 6.1).
 * Admin-only. Each row links to edit.php (name/username/role) and
 * reset_password.php (new temporary password).
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pagination.php';
requireRole(['Admin']);

$db = getDb();
$search = trim($_GET['q'] ?? '');
$roleFilter = $_GET['role'] ?? '';
$allRoles = ['Officer', 'MeterReader', 'Clerk', 'PoliceSupervisor', 'MeterSupervisor', 'Admin'];

$where = [];
$params = [];
if ($search !== '') {
    $where[] = '(full_name LIKE :q OR username LIKE :q)';
    $params['q'] = "%{$search}%";
}
if (in_array($roleFilter, $allRoles, true)) {
    $where[] = 'role = :role';
    $params['role'] = $roleFilter;
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$pageInfo = paginate($db, "SELECT COUNT(*) FROM Users{$whereSql}", $params, 20);

$stmt = $db->prepare(
    "SELECT user_id, username, full_name, role, created_at FROM Users{$whereSql}
     ORDER BY created_at DESC LIMIT {$pageInfo['perPage']} OFFSET {$pageInfo['offset']}"
);
$stmt->execute($params);
$users = $stmt->fetchAll();

$hasFilters = $search || $roleFilter;

require __DIR__ . '/../includes/header.php';
?>

<div class="page-actions">
    <div>
        <h1>User Accounts</h1>
        <p class="subtitle"><?= (int) $pageInfo['total'] ?> account(s) with access to the system.</p>
    </div>
    <div>
        <a class="btn secondary" href="login_history.php">Login History</a>
        <a class="btn" href="create.php">+ Create User</a>
    </div>
</div>

<div class="filter-bar">
    <form method="get" class="filter-row">
        <div class="filter-field grow">
            <label for="q">Search</label>
            <input type="text" id="q" name="q" placeholder="Name or username..." value="<?= htmlspecialchars($search) ?>">
        </div>
        <div class="filter-field">
            <label for="role">Role</label>
            <select id="role" name="role">
                <option value="">All</option>
                <?php foreach ($allRoles as $r): ?>
                    <option value="<?= $r ?>" <?= $roleFilter === $r ? 'selected' : '' ?>><?= $r ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn">Apply Filters</button>
        <?php if ($hasFilters): ?><a href="list.php" class="btn secondary">Clear</a><?php endif; ?>
    </form>
</div>

<div class="table-responsive">
<table class="data-table">
    <thead>
        <tr><th>Full Name</th><th>Username</th><th>Role</th><th>Created</th><th>Actions</th></tr>
    </thead>
    <tbody>
        <?php if (!$users): ?>
            <tr><td colspan="5">No matching users.</td></tr>
        <?php endif; ?>
        <?php foreach ($users as $u): ?>
            <tr>
                <td><?= htmlspecialchars($u['full_name']) ?></td>
                <td><?= htmlspecialchars($u['username']) ?></td>
                <td><span class="badge normal"><?= htmlspecialchars($u['role']) ?></span></td>
                <td><?= htmlspecialchars(date('d M Y', strtotime($u['created_at']))) ?></td>
                <td style="white-space:nowrap;">
                    <a href="edit.php?id=<?= (int) $u['user_id'] ?>">Edit Details</a>
                    &nbsp;|&nbsp;
                    <a href="reset_password.php?id=<?= (int) $u['user_id'] ?>">Reset Password</a>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>

<?= renderPagination($pageInfo) ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>