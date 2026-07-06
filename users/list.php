<?php
/**
 * users/list.php
 * "Manage User Accounts" use case (Activity 4, Section 6.1).
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
requireRole(['Admin']);

$db = getDb();
$users = $db->query('SELECT user_id, username, full_name, role, created_at FROM Users ORDER BY created_at DESC')->fetchAll();

require __DIR__ . '/../includes/header.php';
?>

<div class="page-actions">
    <div>
        <h1>User Accounts</h1>
        <p class="subtitle">Everyone with access to the system.</p>
    </div>
    <a class="btn" href="create.php">+ Create User</a>
</div>

<table class="data-table">
    <thead>
        <tr><th>Full Name</th><th>Username</th><th>Role</th><th>Created</th></tr>
    </thead>
    <tbody>
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

<?php require __DIR__ . '/../includes/footer.php'; ?>
