<?php
/**
 * houses/edit.php
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
requireRole(['Admin']);

$db = getDb();
$houseId = (int) ($_GET['house_id'] ?? $_POST['house_id'] ?? 0);

$stmt = $db->prepare('SELECT * FROM Houses WHERE house_id = :id');
$stmt->execute(['id' => $houseId]);
$house = $stmt->fetch();

if (!$house) {
    require __DIR__ . '/../includes/header.php';
    echo '<div class="alert error">House not found.</div>';
    require __DIR__ . '/../includes/footer.php';
    exit;
}

$wards = $db->query('SELECT ward_id, ward_name FROM Wards ORDER BY ward_name')->fetchAll();
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $houseNumber = trim($_POST['house_number'] ?? '');
    $street = trim($_POST['street'] ?? '');
    $wardId = $_POST['ward_id'] ?? '';
    $ownerName = trim($_POST['owner_name'] ?? '');
    $meterNumber = trim($_POST['meter_number'] ?? '');
    $address = trim($_POST['address'] ?? '');

    if ($houseNumber === '') $errors[] = 'House number is required.';
    if ($ownerName === '') $errors[] = 'Owner name is required.';
    if ($meterNumber === '') $errors[] = 'Meter number is required.';
    if ($wardId === '') $errors[] = 'Please select a ward.';

    if (!$errors) {
        $check = $db->prepare('SELECT house_id FROM Houses WHERE meter_number = :m AND house_id != :id');
        $check->execute(['m' => $meterNumber, 'id' => $houseId]);
        if ($check->fetch()) {
            $errors[] = 'That meter number is already registered to another house.';
        } else {
            $stmt = $db->prepare(
                'UPDATE Houses SET house_number = :house_number, street = :street, ward_id = :ward_id,
                                    owner_name = :owner_name, meter_number = :meter_number, address = :address
                 WHERE house_id = :id'
            );
            $stmt->execute([
                'house_number' => $houseNumber, 'street' => $street ?: null, 'ward_id' => $wardId,
                'owner_name' => $ownerName, 'meter_number' => $meterNumber, 'address' => $address ?: null,
                'id' => $houseId,
            ]);
            logActivity(currentUser()['id'], "Updated house {$houseNumber}");
            $success = true;
            $house['house_number'] = $houseNumber;
            $house['street'] = $street;
            $house['ward_id'] = $wardId;
            $house['owner_name'] = $ownerName;
            $house['meter_number'] = $meterNumber;
            $house['address'] = $address;
        }
    }
}

require __DIR__ . '/../includes/header.php';
?>

<h1>Edit House</h1>

<?php if ($success): ?>
    <div class="alert success">House updated. <a href="list.php">Back to houses</a>.</div>
<?php endif; ?>
<?php if ($errors): ?>
    <div class="alert error"><?php foreach ($errors as $e): ?>&bull; <?= htmlspecialchars($e) ?><br><?php endforeach; ?></div>
<?php endif; ?>

<div class="form-panel">
    <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="house_id" value="<?= $houseId ?>">

        <label for="house_number">House number</label>
        <input type="text" id="house_number" name="house_number" required value="<?= htmlspecialchars($house['house_number']) ?>">

        <label for="street">Street</label>
        <input type="text" id="street" name="street" value="<?= htmlspecialchars($house['street'] ?? '') ?>">

        <label for="ward_id">Ward</label>
        <select id="ward_id" name="ward_id" required>
            <option value="">-- Select a ward --</option>
            <?php foreach ($wards as $w): ?>
                <option value="<?= (int) $w['ward_id'] ?>" <?= (string) $house['ward_id'] === (string) $w['ward_id'] ? 'selected' : '' ?>><?= htmlspecialchars($w['ward_name']) ?></option>
            <?php endforeach; ?>
        </select>

        <label for="owner_name">Owner name</label>
        <input type="text" id="owner_name" name="owner_name" required value="<?= htmlspecialchars($house['owner_name']) ?>">

        <label for="meter_number">Meter number</label>
        <input type="text" id="meter_number" name="meter_number" required value="<?= htmlspecialchars($house['meter_number']) ?>">

        <label for="address">Full address (optional)</label>
        <input type="text" id="address" name="address" value="<?= htmlspecialchars($house['address'] ?? '') ?>">

        <button type="submit" class="btn">Save Changes</button>
        <a href="list.php" class="btn secondary">Back to Houses</a>
    </form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
