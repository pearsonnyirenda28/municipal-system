<?php
/**
 * fines/issue.php
 * Real implementation of the validateFineInput() and generateFineId()
 * functions documented in Chapter 5, Sections 2.2 and 2.3.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
requireRole(['Admin', 'Officer']);

$offenceTypes = ['Littering', 'Illegal Vending', 'Reckless Driving', 'Noise Violation', 'Other'];
$errors = [];
$caseId = (int) ($_GET['case_id'] ?? $_POST['case_id'] ?? 0);
$linkedCase = null;
if ($caseId) {
    $caseStmt = getDb()->prepare('SELECT * FROM Cases WHERE case_id = :id');
    $caseStmt->execute(['id' => $caseId]);
    $linkedCase = $caseStmt->fetch();
}
$old = [
    'offender_name' => $linkedCase['offender_name'] ?? '',
    'offence_type'  => $linkedCase['offence_type'] ?? '',
    'house_number'  => '',
    'vehicle_plate' => '',
    'amount'        => '',
];

/** Matches Chapter 5, Section 2.2 exactly, using real $_POST data. */
function validateFineInput(array $data): array
{
    $errors = [];
    if (empty($data['offender_name'])) {
        $errors[] = 'Offender name is required.';
    }
    if (empty($data['offence_type'])) {
        $errors[] = 'Please select an offence type.';
    }
    if (!isset($data['amount']) || !is_numeric($data['amount']) || $data['amount'] <= 0) {
        $errors[] = 'Fine amount must be a number greater than zero.';
    }
    if (empty($data['house_number']) && empty($data['vehicle_plate'])) {
        $errors[] = 'Provide either a house number or a vehicle plate.';
    }
    return $errors;
}

/** Matches Chapter 5, Section 2.3 exactly. */
function generateFineId(PDO $db): string
{
    $year = date('Y');
    $stmt = $db->prepare(
        'SELECT fine_number FROM Fines WHERE fine_number LIKE :pattern ORDER BY fine_id DESC LIMIT 1'
    );
    $stmt->execute(['pattern' => "FINE-$year-%"]);
    $last = $stmt->fetchColumn();

    $nextNumber = $last ? ((int) substr($last, -4)) + 1 : 1;
    return sprintf('FINE-%s-%04d', $year, $nextNumber);
}

/** Matches Chapter 5, Section 3.1 - transaction with rollback on failure. */
function saveFine(PDO $db, array $data, int $issuedBy, ?int $caseId): array
{
    try {
        $db->beginTransaction();

        $fineId = generateFineId($db);
        $stmt = $db->prepare(
            'INSERT INTO Fines (fine_number, case_id, offender_name, house_number, vehicle_plate,
                                 offence_type, amount, payment_status, issued_by, date_issued)
             VALUES (:fine_number, :case_id, :offender_name, :house_number, :vehicle_plate,
                     :offence_type, :amount, "Unpaid", :issued_by, CURRENT_TIMESTAMP)'
        );
        $stmt->execute([
            'fine_number'   => $fineId,
            'case_id'       => $caseId,
            'offender_name' => $data['offender_name'],
            'house_number'  => $data['house_number'] ?: null,
            'vehicle_plate' => $data['vehicle_plate'] ?: null,
            'offence_type'  => $data['offence_type'],
            'amount'        => $data['amount'],
            'issued_by'     => $issuedBy,
        ]);

        $db->commit();
        logActivity($issuedBy, "Issued fine {$fineId} to {$data['offender_name']}");
        return ['success' => true, 'fine_id' => $fineId];

    } catch (PDOException $e) {
        $db->rollBack();
        error_log('saveFine failed: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Could not save the fine. Please try again.'];
    }
}

$successFineId = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $old = [
        'offender_name' => trim($_POST['offender_name'] ?? ''),
        'offence_type'  => trim($_POST['offence_type'] ?? ''),
        'house_number'  => trim($_POST['house_number'] ?? ''),
        'vehicle_plate' => trim($_POST['vehicle_plate'] ?? ''),
        'amount'        => trim($_POST['amount'] ?? ''),
    ];

    $errors = validateFineInput($old);

    if (!$errors) {
        $result = saveFine(getDb(), $old, currentUser()['id'], $caseId ?: null);
        if ($result['success']) {
            $successFineId = $result['fine_id'];
            $old = ['offender_name' => '', 'offence_type' => '', 'house_number' => '', 'vehicle_plate' => '', 'amount' => ''];
        } else {
            $errors[] = $result['message'];
        }
    }
}

require __DIR__ . '/../includes/header.php';
?>

<h1>Issue a Fine</h1>
<?php if ($linkedCase): ?>
    <p class="subtitle">Linked to case <strong><?= htmlspecialchars($linkedCase['case_number']) ?></strong>.</p>
<?php else: ?>
<p class="subtitle">Fields marked with a house number or vehicle plate - at least one is required.</p>
<?php endif; ?>

<?php if ($successFineId): ?>
    <div class="alert success">
        Fine <strong><?= htmlspecialchars($successFineId) ?></strong> saved successfully.
        <a href="list.php">View all fines</a>.
    </div>
<?php endif; ?>

<?php if ($errors): ?>
    <div class="alert error">
        <?php foreach ($errors as $e): ?>&bull; <?= htmlspecialchars($e) ?><br><?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="form-panel">
    <form method="post" novalidate>
        <?= csrfField() ?>
        <?php if ($caseId): ?><input type="hidden" name="case_id" value="<?= $caseId ?>"><?php endif; ?>
        <label for="offender_name">Offender name</label>
        <input type="text" id="offender_name" name="offender_name" required
               value="<?= htmlspecialchars($old['offender_name']) ?>">

        <label for="offence_type">Offence type</label>
        <select id="offence_type" name="offence_type" required>
            <option value="">-- Select --</option>
            <?php foreach ($offenceTypes as $type): ?>
                <option value="<?= htmlspecialchars($type) ?>" <?= $old['offence_type'] === $type ? 'selected' : '' ?>>
                    <?= htmlspecialchars($type) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="house_number">House number</label>
        <input type="text" id="house_number" name="house_number" placeholder="e.g. 142"
               value="<?= htmlspecialchars($old['house_number']) ?>">

        <label for="vehicle_plate">Vehicle plate</label>
        <input type="text" id="vehicle_plate" name="vehicle_plate" placeholder="e.g. ABC 1234"
               value="<?= htmlspecialchars($old['vehicle_plate']) ?>">

        <label for="amount">Amount (US$)</label>
        <input type="number" id="amount" name="amount" step="0.01" min="0.01"
               value="<?= htmlspecialchars($old['amount']) ?>">

        <button type="submit" class="btn">Save Fine</button>
        <a href="list.php" class="btn secondary">Cancel</a>
    </form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
