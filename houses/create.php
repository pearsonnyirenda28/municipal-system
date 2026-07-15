<?php
/**
 * houses/create.php
 * "Assign houses to wards" (Version 2.5, House Assignment) - every new
 * house is assigned a ward at creation time via a dropdown, not free text.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
requireRole(['Admin']);

$db = getDb();
$wards = $db->query('SELECT ward_id, ward_name FROM Wards ORDER BY ward_name')->fetchAll();

$errors = [];
$old = ['house_number' => '', 'street' => '', 'ward_id' => '', 'owner_name' => '', 'meter_number' => '', 'address' => ''];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $old = [
        'house_number' => trim($_POST['house_number'] ?? ''),
        'street'       => trim($_POST['street'] ?? ''),
        'ward_id'      => $_POST['ward_id'] ?? '',
        'owner_name'   => trim($_POST['owner_name'] ?? ''),
        'meter_number' => trim($_POST['meter_number'] ?? ''),
        'address'      => trim($_POST['address'] ?? ''),
    ];

    if ($old['house_number'] === '') $errors[] = 'House number is required.';
    if ($old['owner_name'] === '') $errors[] = 'Owner name is required.';
    if ($old['meter_number'] === '') $errors[] = 'Meter number is required.';
    if ($old['ward_id'] === '') $errors[] = 'Please select a ward.';

    if (!$errors) {
        $check = $db->prepare('SELECT house_id FROM Houses WHERE meter_number = :m');
        $check->execute(['m' => $old['meter_number']]);
        if ($check->fetch()) {
            $errors[] = 'That meter number is already registered to another house.';
        } else {
            $stmt = $db->prepare(
                'INSERT INTO Houses (house_number, street, ward_id, owner_name, meter_number, address)
                 VALUES (:house_number, :street, :ward_id, :owner_name, :meter_number, :address)'
            );
            $stmt->execute([
                'house_number' => $old['house_number'],
                'street'       => $old['street'] ?: null,
                'ward_id'      => $old['ward_id'],
                'owner_name'   => $old['owner_name'],
                'meter_number' => $old['meter_number'],
                'address'      => $old['address'] ?: null,
            ]);
            logActivity(currentUser()['id'], "Added house {$old['house_number']}");
            $success = true;
            $old = ['house_number' => '', 'street' => '', 'ward_id' => '', 'owner_name' => '', 'meter_number' => '', 'address' => ''];
        }
    }
}

require __DIR__ . '/../includes/header.php';
?>

<h1>Add a House</h1>

<?php if ($success): ?>
    <div class="alert success">House saved. <a href="list.php">View all houses</a>.</div>
<?php endif; ?>
<?php if ($errors): ?>
    <div class="alert error"><?php foreach ($errors as $e): ?>&bull; <?= htmlspecialchars($e) ?><br><?php endforeach; ?></div>
<?php endif; ?>

<?php if (!$wards): ?>
    <div class="alert warning">No wards exist yet. <a href="/municipal-system/wards/create.php">Create a ward</a> first.</div>
<?php else: ?>
<div class="form-panel">
    <form method="post">
        <?= csrfField() ?>
        <label for="house_number">House number</label>
        <input type="text" id="house_number" name="house_number" required value="<?= htmlspecialchars($old['house_number']) ?>">

        <label for="street">Street</label>
        <input type="text" id="street" name="street" placeholder="e.g. North Road" value="<?= htmlspecialchars($old['street']) ?>">

        <label for="ward_id">Ward</label>
        <select id="ward_id" name="ward_id" required>
            <option value="">-- Select a ward --</option>
            <?php foreach ($wards as $w): ?>
                <option value="<?= (int) $w['ward_id'] ?>" <?= (string) $old['ward_id'] === (string) $w['ward_id'] ? 'selected' : '' ?>><?= htmlspecialchars($w['ward_name']) ?></option>
            <?php endforeach; ?>
        </select>

        <label for="owner_name">Owner name</label>
        <input type="text" id="owner_name" name="owner_name" required value="<?= htmlspecialchars($old['owner_name']) ?>">

        <label for="meter_number">Meter number</label>
        <input type="text" id="meter_number" name="meter_number" required placeholder="e.g. MTR-0301" value="<?= htmlspecialchars($old['meter_number']) ?>">

        <label for="address">Full address (optional)</label>
        <input type="text" id="address" name="address" value="<?= htmlspecialchars($old['address']) ?>">

        <button type="submit" class="btn">Save House</button>
        <a href="list.php" class="btn secondary">Cancel</a>
    </form>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
