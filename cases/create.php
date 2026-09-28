<?php
/**
 * cases/create.php
 * "Record Police Case" use case, matching the input design principles
 * from Activity 4, Section 2.1 (dropdowns over free text where possible).
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
requireRole(['Admin', 'Officer']);

$offenceTypes = ['Littering', 'Illegal Vending', 'Reckless Driving', 'Noise Violation', 'Assault', 'Theft', 'Other'];
$errors = [];
$old = ['offender_name' => '', 'id_number' => '', 'offence_type' => '', 'description' => ''];
$successCaseNo = null;

function generateCaseNumber(PDO $db): string
{
    $year = date('Y');
    $stmt = $db->prepare('SELECT case_number FROM Cases WHERE case_number LIKE :pattern ORDER BY case_id DESC LIMIT 1');
    $stmt->execute(['pattern' => "CASE-$year-%"]);
    $last = $stmt->fetchColumn();
    $next = $last ? ((int) substr($last, -4)) + 1 : 1;
    return sprintf('CASE-%s-%04d', $year, $next);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $old = [
        'offender_name' => trim($_POST['offender_name'] ?? ''),
        'id_number'     => trim($_POST['id_number'] ?? ''),
        'offence_type'  => trim($_POST['offence_type'] ?? ''),
        'description'   => trim($_POST['description'] ?? ''),
    ];

    if ($old['offender_name'] === '') {
        $errors[] = 'Offender name is required.';
    }
    if ($old['offence_type'] === '') {
        $errors[] = 'Please select an offence type.';
    }

    if (!$errors) {
        $db = getDb();
        try {
            $db->beginTransaction();
            $caseNumber = generateCaseNumber($db);
            $stmt = $db->prepare(
                'INSERT INTO Cases (case_number, offender_name, id_number, offence_type, description, status, officer_id, date_opened)
                 VALUES (:case_number, :offender_name, :id_number, :offence_type, :description, "Open", :officer_id, CURRENT_DATE)'
            );
            $stmt->execute([
                'case_number'   => $caseNumber,
                'offender_name' => $old['offender_name'],
                'id_number'     => $old['id_number'] ?: null,
                'offence_type'  => $old['offence_type'],
                'description'   => $old['description'] ?: null,
                'officer_id'    => currentUser()['id'],
            ]);
            $db->commit();
            logActivity(currentUser()['id'], "Opened case {$caseNumber} for {$old['offender_name']}");
            $successCaseNo = $caseNumber;
            $old = ['offender_name' => '', 'id_number' => '', 'offence_type' => '', 'description' => ''];
        } catch (PDOException $e) {
            $db->rollBack();
            error_log('create case failed: ' . $e->getMessage());
            $errors[] = 'Could not save the case. Please try again.';
        }
    }
}

require __DIR__ . '/../includes/header.php';
?>

<h1>Record a Police Case</h1>
<p class="subtitle">Executive summary, offender details and offence type - matching the current occurrence-book entry.</p>

<?php if ($successCaseNo): ?>
    <div class="alert success">Case <strong><?= htmlspecialchars($successCaseNo) ?></strong> opened successfully.
        <a href="list.php">View all cases</a>.</div>
<?php endif; ?>
<?php if ($errors): ?>
    <div class="alert error"><?php foreach ($errors as $e): ?>&bull; <?= htmlspecialchars($e) ?><br><?php endforeach; ?></div>
<?php endif; ?>

<div class="form-panel">
    <form method="post">
        <?= csrfField() ?>
        <label for="offender_name">Offender name</label>
        <input type="text" id="offender_name" name="offender_name" required value="<?= htmlspecialchars($old['offender_name']) ?>">

        <label for="id_number">National ID (optional)</label>
        <input type="text" id="id_number" name="id_number" placeholder="e.g. 63-123456A78" value="<?= htmlspecialchars($old['id_number']) ?>">

        <label for="offence_type">Offence type</label>
        <select id="offence_type" name="offence_type" required>
            <option value="">-- Select --</option>
            <?php foreach ($offenceTypes as $type): ?>
                <option value="<?= htmlspecialchars($type) ?>" <?= $old['offence_type'] === $type ? 'selected' : '' ?>><?= htmlspecialchars($type) ?></option>
            <?php endforeach; ?>
        </select>

        <label for="description">Description (optional)</label>
        <textarea id="description" name="description" rows="4"><?= htmlspecialchars($old['description']) ?></textarea>

        <button type="submit" class="btn">Save Case</button>
        <a href="list.php" class="btn secondary">Cancel</a>
    </form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
