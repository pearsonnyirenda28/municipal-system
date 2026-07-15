<?php
/**
 * houses/list.php
 * Version 2.5 - House Assignment. Lists every house with its ward and
 * street, filterable by both (the "group houses by street" requirement
 * is met here as a filter/sort rather than a separate grouped view -
 * with a handful of houses per street this is the more useful UI than
 * a giant collapsed tree).
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pagination.php';
requireRole(['Admin']);

$db = getDb();
$search = trim($_GET['q'] ?? '');
$wardFilter = $_GET['ward_id'] ?? '';
$streetFilter = $_GET['street'] ?? '';

$wards = $db->query('SELECT ward_id, ward_name FROM Wards ORDER BY ward_name')->fetchAll();
$streets = $db->query('SELECT DISTINCT street FROM Houses WHERE street IS NOT NULL AND street != "" ORDER BY street')->fetchAll(PDO::FETCH_COLUMN);

$where = [];
$params = [];
if ($search !== '') {
    $where[] = '(h.house_number LIKE :q OR h.owner_name LIKE :q OR h.meter_number LIKE :q OR h.street LIKE :q)';
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

$countSql = "SELECT COUNT(*) FROM Houses h{$whereSql}";
$pageInfo = paginate($db, $countSql, $params, 20);

$sql = "SELECT h.*, w.ward_name FROM Houses h LEFT JOIN Wards w ON w.ward_id = h.ward_id{$whereSql}
        ORDER BY h.street, h.house_number LIMIT {$pageInfo['perPage']} OFFSET {$pageInfo['offset']}";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$houses = $stmt->fetchAll();

$hasFilters = $search || $wardFilter || $streetFilter;

require __DIR__ . '/../includes/header.php';
?>

<div class="page-actions">
    <div>
        <h1>Houses</h1>
        <p class="subtitle"><?= (int) $pageInfo['total'] ?> house(s) found.</p>
    </div>
    <div>
        <a class="btn secondary" href="bulk_assign.php">Bulk Assign to Ward</a>
        <a class="btn" href="create.php">+ Add House</a>
    </div>
</div>

<div class="filter-bar">
    <form method="get" class="filter-row">
        <div class="filter-field grow">
            <label for="q">Search</label>
            <input type="text" id="q" name="q" placeholder="House no., owner, meter no. or street..." value="<?= htmlspecialchars($search) ?>">
        </div>
        <div class="filter-field">
            <label for="ward_id">Ward</label>
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
        <button type="submit" class="btn">Apply Filters</button>
        <?php if ($hasFilters): ?><a href="list.php" class="btn secondary">Clear</a><?php endif; ?>
    </form>
</div>

<div class="table-responsive">
<table class="data-table">
    <thead>
        <tr><th>House No.</th><th>Street</th><th>Ward</th><th>Owner</th><th>Meter No.</th><th></th></tr>
    </thead>
    <tbody>
        <?php if (!$houses): ?>
            <tr><td colspan="6">No matching houses.</td></tr>
        <?php endif; ?>
        <?php foreach ($houses as $h): ?>
            <tr>
                <td><?= htmlspecialchars($h['house_number']) ?></td>
                <td><?= htmlspecialchars($h['street'] ?? '-') ?></td>
                <td><?= $h['ward_name'] ? htmlspecialchars($h['ward_name']) : '<span style="color:#C0392B;">Unassigned</span>' ?></td>
                <td><?= htmlspecialchars($h['owner_name']) ?></td>
                <td><?= htmlspecialchars($h['meter_number']) ?></td>
                <td><a href="edit.php?house_id=<?= (int) $h['house_id'] ?>">Edit</a></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>

<?= renderPagination($pageInfo) ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
