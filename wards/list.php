<?php
/**
 * wards/list.php
 * Version 2.5 - Ward Management. Admin-only: create/view wards and see,
 * at a glance, how many houses and readers each one has.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
requireRole(['Admin']);

$db = getDb();
$wards = $db->query(
    "SELECT w.*,
            (SELECT COUNT(*) FROM Houses h WHERE h.ward_id = w.ward_id) AS house_count,
            (SELECT COUNT(*) FROM WardAssignments wa WHERE wa.ward_id = w.ward_id) AS reader_count
     FROM Wards w ORDER BY w.ward_name"
)->fetchAll();

require __DIR__ . '/../includes/header.php';
?>

<div class="page-actions">
    <div>
        <h1>Wards</h1>
        <p class="subtitle"><?= count($wards) ?> ward(s) configured.</p>
    </div>
    <a class="btn" href="create.php">+ Create Ward</a>
</div>

<div class="table-responsive">
<table class="data-table">
    <thead>
        <tr><th>Ward</th><th>Description</th><th>Houses</th><th>Assigned Readers</th><th></th></tr>
    </thead>
    <tbody>
        <?php if (!$wards): ?>
            <tr><td colspan="5">No wards yet - create one to start assigning houses and readers to it.</td></tr>
        <?php endif; ?>
        <?php foreach ($wards as $w): ?>
            <tr>
                <td><?= htmlspecialchars($w['ward_name']) ?></td>
                <td><?= htmlspecialchars($w['description'] ?? '') ?></td>
                <td><?= (int) $w['house_count'] ?></td>
                <td><?= (int) $w['reader_count'] ?></td>
                <td>
                    <a href="assign.php?ward_id=<?= (int) $w['ward_id'] ?>">Assign Readers</a>
                    &nbsp;|&nbsp;
                    <a href="/municipal-system/houses/list.php?ward_id=<?= (int) $w['ward_id'] ?>">View Houses</a>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
