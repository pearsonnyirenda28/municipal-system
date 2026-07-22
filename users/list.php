<?php
/**
 * users/list.php
 * "Manage User Accounts" use case (Activity 4, Section 6.1).
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
    "SELECT user_id, username, full_name, role, is_active, created_at FROM Users{$whereSql}
     ORDER BY created_at DESC LIMIT {$pageInfo['perPage']} OFFSET {$pageInfo['offset']}"
);
$stmt->execute($params);
$users = $stmt->fetchAll();

$hasFilters = $search || $roleFilter;
$currentUserId = (int) currentUser()['id'];
$flashError = $_SESSION['user_action_flash'] ?? '';
unset($_SESSION['user_action_flash']);

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

<?php if ($flashError): ?><div class="alert error"><?= htmlspecialchars($flashError) ?></div><?php endif; ?>

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
        <tr><th>Full Name</th><th>Username</th><th>Role</th><th>Status</th><th>Created</th><th>Actions</th></tr>
    </thead>
    <tbody>
        <?php if (!$users): ?>
            <tr><td colspan="6">No matching users.</td></tr>
        <?php endif; ?>
        <?php foreach ($users as $u): ?>
            <tr>
                <td><?= htmlspecialchars($u['full_name']) ?></td>
                <td><?= htmlspecialchars($u['username']) ?></td>
                <td><span class="badge normal"><?= htmlspecialchars($u['role']) ?></span></td>
                <td><span class="badge <?= $u['is_active'] ? 'paid' : 'unpaid' ?>"><?= $u['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                <td><?= htmlspecialchars(date('d M Y', strtotime($u['created_at']))) ?></td>
                <td style="white-space:nowrap;">
                    <a href="reset_password.php?id=<?= (int) $u['user_id'] ?>" style="font-size:0.85rem;">Reset Password</a>
                    <?php if ((int) $u['user_id'] !== $currentUserId): ?>
                        &nbsp;|&nbsp;
                        <form method="post" action="toggle_active.php" style="display:inline;"
                              onsubmit="return confirm('<?= $u['is_active'] ? 'Deactivate' : 'Activate' ?> <?= htmlspecialchars(addslashes($u['full_name'])) ?>\'s account?');">
                            <?= csrfField() ?>
                            <input type="hidden" name="id" value="<?= (int) $u['user_id'] ?>">
                            <input type="hidden" name="return_qs" value="<?= htmlspecialchars(http_build_query($_GET)) ?>">
                            <button type="submit" class="link-btn" style="font-size:0.85rem;color:<?= $u['is_active'] ? '#C0392B' : '#2C7873' ?>;">
                                <?= $u['is_active'] ? 'Deactivate' : 'Activate' ?>
                            </button>
                        </form>
                    <?php else: ?>
                        &nbsp;|&nbsp;<span style="font-size:0.85rem;color:#8a94a3;">(you)</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>

<?= renderPagination($pageInfo) ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
