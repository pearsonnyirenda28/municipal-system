<?php
/**
 * cases/update_status.php
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
requireRole(['Admin', 'Officer']);

$db = getDb();
$caseId = (int) ($_GET['case_id'] ?? $_POST['case_id'] ?? 0);

$stmt = $db->prepare('SELECT * FROM Cases WHERE case_id = :id');
$stmt->execute(['id' => $caseId]);
$case = $stmt->fetch();

if (!$case) {
    require __DIR__ . '/../includes/header.php';
    echo '<div class="alert error">Case not found.</div>';
    require __DIR__ . '/../includes/footer.php';
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newStatus = $_POST['status'] ?? '';
    if (!in_array($newStatus, ['Open', 'In Progress', 'Closed'], true)) {
        $error = 'Please choose a valid status.';
    } else {
        $stmt = $db->prepare('UPDATE Cases SET status = :status WHERE case_id = :id');
        $stmt->execute(['status' => $newStatus, 'id' => $caseId]);
        logActivity(currentUser()['id'], "Updated case {$case['case_number']} status to {$newStatus}");
        header('Location: list.php');
        exit;
    }
}

require __DIR__ . '/../includes/header.php';
?>

<h1>Update Case Status</h1>
<p class="subtitle">Case <?= htmlspecialchars($case['case_number']) ?> &mdash; <?= htmlspecialchars($case['offender_name']) ?></p>

<?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="form-panel">
    <form method="post">
        <input type="hidden" name="case_id" value="<?= $caseId ?>">
        <label for="status">Status</label>
        <select id="status" name="status">
            <?php foreach (['Open', 'In Progress', 'Closed'] as $s): ?>
                <option value="<?= $s ?>" <?= $case['status'] === $s ? 'selected' : '' ?>><?= $s ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn">Update Status</button>
        <a href="list.php" class="btn secondary">Cancel</a>
    </form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
