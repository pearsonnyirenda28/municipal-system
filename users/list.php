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

$where = [];
$params = [];
if ($search !== '') {
    $where[] = '(full_name LIKE :q OR username LIKE :q OR role LIKE :q)';
    $params['q'] = "%{$search}%";
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$pageInfo = paginate($db, "SELECT COUNT(*) FROM Users{$whereSql}", $params, 20);

$stmt = $db->prepare(
    "SELECT user_id, username, full_name, role, created_at FROM Users{$whereSql}
     ORDER BY created_at DESC LIMIT {$pageInfo['perPage']} OFFSET {$pageInfo['offset']}"
);
$stmt->execute($params);
$users = $stmt->fetchAll();

require __DIR__ . '/../includes/header.php';
?>

<div class="page-actions">
    <div>
        <h1>User Accounts</h1>
        <p class="subtitle"><?= (int) $pageInfo['total'] ?> account(s) with access to the system.</p>
    </div>
    <a class="btn" href="create.php">+ Create User</a>
</div>

<?= renderSearchBox('Search by name, username or role...') ?>

<div class="table-responsive">
<table class="data-table">
    <thead>
        <tr><th>Full Name</th><th>Username</th><th>Role</th><th>Created</th></tr>
    </thead>
    <tbody>
        <?php if (!$users): ?>
            <tr><td colspan="4">No matching users.</td></tr>
        <?php endif; ?>
        <?php foreach ($users as $u): ?>
            <tr>
                <td><?= htmlspecialchars($u['full_name']) ?></td>
                <td><?= htmlspecialchars($u['username']) ?></td>
                <td><span class="badge normal"><?= htmlspecialchars($u['role']) ?></span></td>
                <td><?= htmlspecialchars(date('d M Y', strtotime($u['created_at']))) ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>

<?= renderPagination($pageInfo) ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
