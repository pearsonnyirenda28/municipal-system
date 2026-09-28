<?php
/**
 * houses/bulk_assign.php
 * "Bulk assignment tools" (Version 2.5, House Assignment). Pick a target
 * ward, tick as many houses as needed (optionally narrowed down first by
 * street or current ward), and move them all in one save.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pagination.php';
requireRole(['Admin']);

$db = getDb();
$wards = $db->query('SELECT ward_id, ward_name FROM Wards ORDER BY ward_name')->fetchAll();
$streets = $db->query('SELECT DISTINCT street FROM Houses WHERE street IS NOT NULL AND street != "" ORDER BY street')->fetchAll(PDO::FETCH_COLUMN);

$success = false;
$movedCount = 0;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $targetWardId = $_POST['target_ward_id'] ?? '';
    $houseIds = array_map('intval', $_POST['house_ids'] ?? []);

    if ($targetWardId === '') {
        $errors[] = 'Please choose a ward to assign the selected houses to.';
    } elseif (!$houseIds) {
        $errors[] = 'Please tick at least one house.';
    } else {
        $placeholders = implode(',', array_fill(0, count($houseIds), '?'));
        $stmt = $db->prepare("UPDATE Houses SET ward_id = ? WHERE house_id IN ({$placeholders})");
        $stmt->execute(array_merge([$targetWardId], $houseIds));
        $movedCount = count($houseIds);
        logActivity(currentUser()['id'], "Bulk-assigned {$movedCount} house(s) to ward_id {$targetWardId}");
        $success = true;
    }
}

// Filters for narrowing down which houses to show in the checklist below.
$search = trim($_GET['q'] ?? '');
$wardFilter = $_GET['ward_id'] ?? '';
$streetFilter = $_GET['street'] ?? '';

$where = [];
$params = [];
if ($search !== '') {
    $where[] = '(h.house_number LIKE :q OR h.owner_name LIKE :q OR h.street LIKE :q)';
    $params['q'] = "%{$search}%";
}
if ($wardFilter !== '') {
    $where[] = 'h.ward_id = :ward_id';
    $params['ward_id'] = $wardFilter;
}
if ($streetFilter !== '') {
    $where[] = 'h.street = :street';
    $params['street'] = $streetFilter;
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$stmt = $db->prepare(
    "SELECT h.house_id, h.house_number, h.street, h.owner_name, w.ward_name
     FROM Houses h LEFT JOIN Wards w ON w.ward_id = h.ward_id
     {$whereSql} ORDER BY h.street, h.house_number LIMIT 200"
);
$stmt->execute($params);
$houses = $stmt->fetchAll();

require __DIR__ . '/../includes/header.php';
?>

<h1>Bulk Assign Houses to a Ward</h1>
<p class="subtitle">Narrow the list down with the filters below, tick the houses you want to move, then choose the target ward.</p>

<?php if ($success): ?>
    <div class="alert success"><?= $movedCount ?> house(s) moved successfully.</div>
<?php endif; ?>
<?php if ($errors): ?>
    <div class="alert error"><?php foreach ($errors as $e): ?>&bull; <?= htmlspecialchars($e) ?><br><?php endforeach; ?></div>
<?php endif; ?>

<div class="filter-bar">
    <form method="get" class="filter-row">
        <div class="filter-field grow">
            <label for="q">Search</label>
            <input type="text" id="q" name="q" placeholder="House no., owner or street..." value="<?= htmlspecialchars($search) ?>">
        </div>
        <div class="filter-field">
            <label for="ward_id">Current ward</label>
            <select id="ward_id" name="ward_id">
                <option value="">All</option>
                <?php foreach ($wards as $w): ?>
                    <option value="<?= (int) $w['ward_id'] ?>" <?= (string) $wardFilter === (string) $w['ward_id'] ? 'selected' : '' ?>><?= htmlspecialchars($w['ward_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-field">
            <label for="street">Street</label>
            <select id="street" name="street">
                <option value="">All</option>
                <?php foreach ($streets as $s): ?>
                    <option value="<?= htmlspecialchars($s) ?>" <?= $streetFilter === $s ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn secondary">Narrow List</button>
    </form>
</div>

<form method="post">
    <?= csrfField() ?>
    <div class="table-responsive">
    <table class="data-table">
        <thead>
            <tr><th style="width:40px;"><input type="checkbox" onclick="document.querySelectorAll('.house-check').forEach(c=>c.checked=this.checked)" style="width:auto;"></th>
                <th>House No.</th><th>Street</th><th>Owner</th><th>Current Ward</th></tr>
        </thead>
        <tbody>
            <?php if (!$houses): ?>
                <tr><td colspan="5">No houses match these filters.</td></tr>
            <?php endif; ?>
            <?php foreach ($houses as $h): ?>
                <tr>
                    <td><input type="checkbox" class="house-check" name="house_ids[]" value="<?= (int) $h['house_id'] ?>" style="width:auto;"></td>
                    <td><?= htmlspecialchars($h['house_number']) ?></td>
                    <td><?= htmlspecialchars($h['street'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($h['owner_name']) ?></td>
                    <td><?= $h['ward_name'] ? htmlspecialchars($h['ward_name']) : 'Unassigned' ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php if (count($houses) >= 200): ?>
        <p style="font-size:0.82rem;color:#5B6472;">Showing the first 200 matches - narrow the filters above to see fewer at a time.</p>
    <?php endif; ?>

    <div class="filter-bar" style="margin-top:18px;">
        <div class="filter-row">
            <div class="filter-field">
                <label for="target_ward_id">Move ticked houses to</label>
                <select id="target_ward_id" name="target_ward_id" required>
                    <option value="">-- Select target ward --</option>
                    <?php foreach ($wards as $w): ?>
                        <option value="<?= (int) $w['ward_id'] ?>"><?= htmlspecialchars($w['ward_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn">Assign Selected</button>
            <a href="list.php" class="btn secondary">Back to Houses</a>
        </div>
    </div>
</form>

<?php require __DIR__ . '/../includes/footer.php'; ?>
