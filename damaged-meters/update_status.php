<?php
/**
 * damaged-meters/update_status.php
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
requireRole(['Admin']);

$db = getDb();
$damageId = (int) ($_GET['damage_id'] ?? $_POST['damage_id'] ?? 0);

$stmt = $db->prepare('SELECT d.*, h.house_number FROM DamagedMeters d JOIN Houses h ON h.house_id = d.house_id WHERE d.damage_id = :id');
$stmt->execute(['id' => $damageId]);
$report = $stmt->fetch();

if (!$report) {
    require __DIR__ . '/../includes/header.php';
    echo '<div class="alert error">Report not found.</div>';
    require __DIR__ . '/../includes/footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newStatus = $_POST['repair_status'] ?? '';
    if (in_array($newStatus, ['Pending', 'In Progress', 'Fixed'], true)) {
        $dateFixed = $newStatus === 'Fixed' ? date('Y-m-d') : null;
        $stmt = $db->prepare('UPDATE DamagedMeters SET repair_status = :status, date_fixed = :date_fixed WHERE damage_id = :id');
        $stmt->execute(['status' => $newStatus, 'date_fixed' => $dateFixed, 'id' => $damageId]);
        logActivity(currentUser()['id'], "Updated damage report for house {$report['house_number']} to {$newStatus}");
        header('Location: list.php');
        exit;
    }
}

require __DIR__ . '/../includes/header.php';
?>

<h1>Update Repair Status</h1>
<p class="subtitle">House <?= htmlspecialchars($report['house_number']) ?> &mdash; <?= htmlspecialchars($report['description']) ?></p>

<div class="form-panel">
    <form method="post">
        <input type="hidden" name="damage_id" value="<?= $damageId ?>">
        <label for="repair_status">Repair status</label>
        <select id="repair_status" name="repair_status">
            <?php foreach (['Pending', 'In Progress', 'Fixed'] as $s): ?>
                <option value="<?= $s ?>" <?= $report['repair_status'] === $s ? 'selected' : '' ?>><?= $s ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn">Update Status</button>
        <a href="list.php" class="btn secondary">Cancel</a>
    </form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
