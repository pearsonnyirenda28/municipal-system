<?php
/**
 * api/save_fine.php
 * Version 3.5: offline-capable fine issuing. Mirrors the validation,
 * Fine ID generation, and transaction logic already in fines/issue.php
 * (Chapter 5, Sections 2.2/2.3/3.1) as a JSON endpoint the offline queue
 * can call, with the same idempotency (client_ref) and "flag, don't
 * reject" duplicate handling already used for meter readings.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not logged in.']);
    exit;
}
if (!in_array($_SESSION['role'], ['Admin', 'Officer'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have permission to do this.']);
    exit;
}

verifyCsrfHeader();

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request body.']);
    exit;
}

$offenderName = trim((string) ($input['offenderName'] ?? ''));
$offenceType  = trim((string) ($input['offenceType'] ?? ''));
$houseNumber  = trim((string) ($input['houseNumber'] ?? ''));
$vehiclePlate = trim((string) ($input['vehiclePlate'] ?? ''));
$amount       = $input['amount'] ?? null;
$caseId       = !empty($input['caseId']) ? (int) $input['caseId'] : null;
$clientRef    = trim((string) ($input['clientRef'] ?? ''));
$dateIssued   = $input['dateIssued'] ?? date('Y-m-d H:i:s');

$errors = [];
if ($offenderName === '') $errors[] = 'Offender name is required.';
if ($offenceType === '') $errors[] = 'Please select an offence type.';
if (!is_numeric($amount) || (float) $amount <= 0) $errors[] = 'Fine amount must be a number greater than zero.';
if ($houseNumber === '' && $vehiclePlate === '') $errors[] = 'Provide either a house number or a vehicle plate.';
if ($clientRef === '') $errors[] = 'Missing client reference.';

if ($errors) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
    exit;
}

$db = getDb();

try {
    // Idempotency: this exact offline record may already have been synced
    // in an earlier attempt (e.g. the response was lost after saving but
    // before the client saw it) - succeed quietly instead of duplicating.
    $check = $db->prepare('SELECT fine_number FROM Fines WHERE client_ref = :ref');
    $check->execute(['ref' => $clientRef]);
    if ($existingFineNumber = $check->fetchColumn()) {
        echo json_encode(['success' => true, 'fine_id' => $existingFineNumber, 'already_synced' => true]);
        exit;
    }

    // Duplicate check: same offender, same amount, same day, different
    // client_ref - flag for admin review rather than reject (Activity 4,
    // Section 5.3 pattern, same as meter reading sync).
    $dupStmt = $db->prepare(
        "SELECT fine_id FROM Fines
         WHERE offender_name = :name AND amount = :amount
           AND date(date_issued) = date(:date) AND (client_ref IS NULL OR client_ref != :ref)"
    );
    $dupStmt->execute(['name' => $offenderName, 'amount' => $amount, 'date' => $dateIssued, 'ref' => $clientRef]);
    $possibleDuplicate = (bool) $dupStmt->fetch();

    $db->beginTransaction();

    $year = date('Y', strtotime($dateIssued));
    $stmt = $db->prepare('SELECT fine_number FROM Fines WHERE fine_number LIKE :pattern ORDER BY fine_id DESC LIMIT 1');
    $stmt->execute(['pattern' => "FINE-{$year}-%"]);
    $last = $stmt->fetchColumn();
    $nextNumber = $last ? ((int) substr($last, -4)) + 1 : 1;
    $fineNumber = sprintf('FINE-%s-%04d', $year, $nextNumber);

    $stmt = $db->prepare(
        'INSERT INTO Fines (fine_number, case_id, offender_name, house_number, vehicle_plate,
                             offence_type, amount, payment_status, issued_by, date_issued, client_ref)
         VALUES (:fine_number, :case_id, :offender_name, :house_number, :vehicle_plate,
                 :offence_type, :amount, "Unpaid", :issued_by, :date_issued, :client_ref)'
    );
    $stmt->execute([
        'fine_number'   => $fineNumber,
        'case_id'       => $caseId,
        'offender_name' => $offenderName,
        'house_number'  => $houseNumber ?: null,
        'vehicle_plate' => $vehiclePlate ?: null,
        'offence_type'  => $offenceType,
        'amount'        => $amount,
        'issued_by'     => $_SESSION['user_id'],
        'date_issued'   => $dateIssued,
        'client_ref'    => $clientRef,
    ]);

    $db->commit();

    $logMsg = "Issued fine {$fineNumber} to {$offenderName}";
    if ($possibleDuplicate) {
        $logMsg .= ' - POSSIBLE DUPLICATE, flagged for admin review';
    }
    logActivity($_SESSION['user_id'], $logMsg);

    echo json_encode(['success' => true, 'fine_number' => $fineNumber, 'duplicate_flagged' => $possibleDuplicate]);

} catch (PDOException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('save_fine.php failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not save the fine. Please try again.']);
}
