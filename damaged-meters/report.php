<?php
/**
 * damaged-meters/report.php
 * "Report Damaged Meter" use case (Activity 4, Section 2.1 - photo upload,
 * image files only).
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
requireRole(['Admin', 'MeterReader']);

$db = getDb();
$user = currentUser();

// Version 2.5: a MeterReader only reports damage against houses in
// wards they're assigned to - Admin sees every house.
$where = '';
$params = [];
if ($user['role'] === 'MeterReader') {
    $wardIds = getAssignedWardIds($db, (int) $user['id']);
    if ($wardIds) {
        $placeholders = implode(',', array_fill(0, count($wardIds), '?'));
        $where = "WHERE h.ward_id IN ({$placeholders})";
        $params = $wardIds;
    } else {
        $where = 'WHERE 1=0';
    }
}
$stmt = $db->prepare(
    "SELECT h.house_id, h.house_number, h.street, w.ward_name
     FROM Houses h LEFT JOIN Wards w ON w.ward_id = h.ward_id
     {$where} ORDER BY h.house_number"
);
$stmt->execute($params);
$houses = $stmt->fetchAll();

$errors = [];
$old = ['house_id' => '', 'description' => ''];
$success = false;

const MAX_PHOTO_BYTES = 5 * 1024 * 1024; // 5MB
const ALLOWED_PHOTO_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $old = [
        'house_id'    => (int) ($_POST['house_id'] ?? 0),
        'description' => trim($_POST['description'] ?? ''),
    ];

    if (!$old['house_id']) {
        $errors[] = 'Please select a house.';
    }
    if ($old['description'] === '') {
        $errors[] = 'Please describe the damage.';
    }

    $photoPath = null;
    if (!empty($_FILES['photo']['name'])) {
        $file = $_FILES['photo'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'The photo could not be uploaded. Please try again.';
        } elseif ($file['size'] > MAX_PHOTO_BYTES) {
            $errors[] = 'Photo is too large - please keep it under 5MB.';
        } else {
            $mime = mime_content_type($file['tmp_name']);
            if (!in_array($mime, ALLOWED_PHOTO_TYPES, true)) {
                $errors[] = 'Only JPEG, PNG or WEBP photos are allowed.';
            } else {
                $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime];
                $filename = 'damage_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                $destDir = __DIR__ . '/../uploads/damage_photos/';
                if (!is_dir($destDir)) {
                    mkdir($destDir, 0755, true);
                }
                if (move_uploaded_file($file['tmp_name'], $destDir . $filename)) {
                    $photoPath = 'uploads/damage_photos/' . $filename;
                } else {
                    $errors[] = 'The photo could not be saved on the server.';
                }
            }
        }
    }

    if (!$errors) {
        try {
            $stmt = $db->prepare(
                'INSERT INTO DamagedMeters (house_id, description, photo_path, reported_by, date_reported, repair_status)
                 VALUES (:house_id, :description, :photo_path, :reported_by, CURRENT_DATE, "Pending")'
            );
            $stmt->execute([
                'house_id'    => $old['house_id'],
                'description' => $old['description'],
                'photo_path'  => $photoPath,
                'reported_by' => currentUser()['id'],
            ]);
            logActivity(currentUser()['id'], "Reported damaged meter for house_id {$old['house_id']}");
            $success = true;
            $old = ['house_id' => '', 'description' => ''];
        } catch (PDOException $e) {
            error_log('report damage failed: ' . $e->getMessage());
            $errors[] = 'Could not save the report. Please try again.';
        }
    }
}

require __DIR__ . '/../includes/header.php';
?>

<h1>Report a Damaged Meter</h1>
<p class="subtitle">A photo is optional but helps whoever schedules the repair.</p>

<?php if ($success): ?>
    <div class="alert success">Damage report saved. <a href="list.php">View all reports</a>.</div>
<?php endif; ?>
<?php if ($errors): ?>
    <div class="alert error"><?php foreach ($errors as $e): ?>&bull; <?= htmlspecialchars($e) ?><br><?php endforeach; ?></div>
<?php endif; ?>

<div class="form-panel">
    <?php if (!$houses): ?>
        <div class="alert warning">
            <?= $user['role'] === 'MeterReader'
                ? "You aren't assigned to any ward yet, so there are no houses to report against. Ask an Administrator to assign you to a ward."
                : "No houses have been added yet." ?>
        </div>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data">
        <?= csrfField() ?>
        <label for="house_id">House</label>
        <select id="house_id" name="house_id" required>
            <option value="">-- Select a house --</option>
            <?php foreach ($houses as $h): ?>
                <option value="<?= (int) $h['house_id'] ?>" <?= (string) $old['house_id'] === (string) $h['house_id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($h['house_number']) ?> (<?= htmlspecialchars($h['ward_name'] ?? 'No ward') ?>)
                </option>
            <?php endforeach; ?>
        </select>

        <label for="description">Description of the damage</label>
        <textarea id="description" name="description" rows="4" required><?= htmlspecialchars($old['description']) ?></textarea>

        <label for="photo">Photo (optional, JPEG/PNG/WEBP, max 5MB)</label>
        <input type="file" id="photo" name="photo" accept="image/jpeg,image/png,image/webp">

        <button type="submit" class="btn amber">Save Report</button>
        <a href="list.php" class="btn secondary">Cancel</a>
    </form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
