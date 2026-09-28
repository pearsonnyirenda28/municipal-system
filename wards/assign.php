<?php
/**
 * wards/assign.php
 * "Assign meter readers to wards" / "assign multiple wards if needed"
 * (Version 2.5, Ward Management). One reader can cover more than one
 * ward, and one ward can have more than one reader - shown here as a
 * simple checklist of every MeterReader against this one ward.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
requireRole(['Admin']);

$db = getDb();
$wardId = (int) ($_GET['ward_id'] ?? $_POST['ward_id'] ?? 0);

$stmt = $db->prepare('SELECT * FROM Wards WHERE ward_id = :id');
$stmt->execute(['id' => $wardId]);
$ward = $stmt->fetch();

if (!$ward) {
    require __DIR__ . '/../includes/header.php';
    echo '<div class="alert error">Ward not found.</div>';
    require __DIR__ . '/../includes/footer.php';
    exit;
}

$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $selectedReaderIds = array_map('intval', $_POST['reader_ids'] ?? []);

    try {
        $db->beginTransaction();

        // Simplest correct approach for a small reader list: clear this
        // ward's assignments and re-insert exactly what was checked.
        $db->prepare('DELETE FROM WardAssignments WHERE ward_id = :ward_id')->execute(['ward_id' => $wardId]);

        $insert = $db->prepare('INSERT INTO WardAssignments (ward_id, user_id) VALUES (:ward_id, :user_id)');
        foreach ($selectedReaderIds as $readerId) {
            $insert->execute(['ward_id' => $wardId, 'user_id' => $readerId]);
        }

        $db->commit();
        logActivity(currentUser()['id'], "Updated reader assignments for ward '{$ward['ward_name']}'");
        $success = true;
    } catch (PDOException $e) {
        $db->rollBack();
        error_log('ward assignment failed: ' . $e->getMessage());
    }
}

$readers = $db->query("SELECT user_id, full_name, username FROM Users WHERE role = 'MeterReader' ORDER BY full_name")->fetchAll();

$stmt = $db->prepare('SELECT user_id FROM WardAssignments WHERE ward_id = :ward_id');
$stmt->execute(['ward_id' => $wardId]);
$assignedIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

require __DIR__ . '/../includes/header.php';
?>

<h1>Assign Readers &mdash; <?= htmlspecialchars($ward['ward_name']) ?></h1>
<p class="subtitle">Tick every meter reader who covers this ward. A reader can be assigned to more than one ward.</p>

<?php if ($success): ?>
    <div class="alert success">Assignments updated.</div>
<?php endif; ?>

<div class="form-panel" style="max-width:520px;">
    <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="ward_id" value="<?= $wardId ?>">

        <?php if (!$readers): ?>
            <p>No meter reader accounts exist yet. <a href="/municipal-system/users/create.php">Create one</a> first.</p>
        <?php endif; ?>

        <?php foreach ($readers as $r): ?>
            <label style="font-weight:normal;display:flex;align-items:center;gap:10px;margin:10px 0;">
                <input type="checkbox" name="reader_ids[]" value="<?= (int) $r['user_id'] ?>" style="width:auto;"
                    <?= in_array($r['user_id'], $assignedIds, true) ? 'checked' : '' ?>>
                <?= htmlspecialchars($r['full_name']) ?> <span style="color:#5B6472;">(<?= htmlspecialchars($r['username']) ?>)</span>
            </label>
        <?php endforeach; ?>

        <?php if ($readers): ?>
            <button type="submit" class="btn">Save Assignments</button>
        <?php endif; ?>
        <a href="list.php" class="btn secondary">Back to Wards</a>
    </form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
