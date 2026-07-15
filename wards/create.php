<?php
/**
 * wards/create.php
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
requireRole(['Admin']);

$db = getDb();
$errors = [];
$old = ['ward_name' => '', 'description' => ''];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $old = [
        'ward_name'   => trim($_POST['ward_name'] ?? ''),
        'description' => trim($_POST['description'] ?? ''),
    ];

    if ($old['ward_name'] === '') {
        $errors[] = 'Ward name is required.';
    }

    if (!$errors) {
        $check = $db->prepare('SELECT ward_id FROM Wards WHERE ward_name = :name');
        $check->execute(['name' => $old['ward_name']]);
        if ($check->fetch()) {
            $errors[] = 'A ward with that name already exists.';
        } else {
            $stmt = $db->prepare('INSERT INTO Wards (ward_name, description) VALUES (:name, :description)');
            $stmt->execute(['name' => $old['ward_name'], 'description' => $old['description'] ?: null]);
            logActivity(currentUser()['id'], "Created ward '{$old['ward_name']}'");
            $success = true;
            $old = ['ward_name' => '', 'description' => ''];
        }
    }
}

require __DIR__ . '/../includes/header.php';
?>

<h1>Create Ward</h1>

<?php if ($success): ?>
    <div class="alert success">Ward created. <a href="list.php">View all wards</a>.</div>
<?php endif; ?>
<?php if ($errors): ?>
    <div class="alert error"><?php foreach ($errors as $e): ?>&bull; <?= htmlspecialchars($e) ?><br><?php endforeach; ?></div>
<?php endif; ?>

<div class="form-panel">
    <form method="post">
        <?= csrfField() ?>
        <label for="ward_name">Ward name</label>
        <input type="text" id="ward_name" name="ward_name" required placeholder="e.g. Ward 4" value="<?= htmlspecialchars($old['ward_name']) ?>">

        <label for="description">Description (optional)</label>
        <input type="text" id="description" name="description" placeholder="e.g. Dulivhadzimu area" value="<?= htmlspecialchars($old['description']) ?>">

        <button type="submit" class="btn">Save Ward</button>
        <a href="list.php" class="btn secondary">Cancel</a>
    </form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
