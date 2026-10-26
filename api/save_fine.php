<?php
/**
 * api/save_fine.php
 * Version 3.5: offline-capable fine issuing. Mirrors the validation,
 * Fine ID generation, and transaction logic already in fines/issue.php
 * as a JSON endpoint the offline queue can call, with the same
 * idempotency (client_ref) and "flag, don't reject" duplicate handling
 * already used for meter readings.
 *
 * Duplicate handling mirrors api/save_reading.php exactly, for the same
 * reasons - see that file's header comment for the full rationale.
 */
require_once __DIR__ . '/../includes/local_store.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

requireApiLogin(['Admin', 'Officer']);   // 401 + code on an ended session, 403 on the wrong role, 409 on another user's records

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
    // client_ref, not already resolved - flag for admin review rather
    // than reject (Activity 4, Section 5.3 pattern, same as meter
    // reading sync).
    $dupStmt = $db->prepare(
        "SELECT fine_id FROM Fines
         WHERE offender_name = :name AND amount = :amount
           AND date(date_issued) = date(:date)
           AND (client_ref IS NULL OR client_ref != :ref)
           AND duplicate_resolved_by IS NULL"
    );
    $dupStmt->execute(['name' => $offenderName, 'amount' => $amount, 'date' => $dateIssued, 'ref' => $clientRef]);
    $existingDuplicateIds = array_map('intval', $dupStmt->fetchAll(PDO::FETCH_COLUMN));
    $possibleDuplicate = !empty($existingDuplicateIds);

    $db->beginTransaction();

    $year = date('Y', strtotime($dateIssued));
    $stmt = $db->prepare('SELECT fine_number FROM Fines WHERE fine_number LIKE :pattern ORDER BY fine_id DESC LIMIT 1');
    $stmt->execute(['pattern' => "FINE-{$year}-%"]);
    $last = $stmt->fetchColumn();
    $nextNumber = $last ? ((int) substr($last, -4)) + 1 : 1;
    $fineNumber = sprintf('FINE-%s-%04d', $year, $nextNumber);

    // Version 4.5: this row's idempotency key against the central
    // Workspace Server (see includes/local_store.php::resolveSyncId).
    $syncId = resolveSyncId($clientRef);

    $stmt = $db->prepare(
        'INSERT INTO Fines (fine_number, case_id, offender_name, house_number, vehicle_plate,
                             offence_type, amount, payment_status, issued_by, date_issued, client_ref,
                             is_duplicate_flagged, sync_id, device_id, sync_status)
         VALUES (:fine_number, :case_id, :offender_name, :house_number, :vehicle_plate,
                 :offence_type, :amount, "Unpaid", :issued_by, :date_issued, :client_ref,
                 :is_duplicate, :sync_id, :device_id, \'PENDING\')'
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
        'is_duplicate'  => $possibleDuplicate ? 1 : 0,
        'sync_id'       => $syncId,
        'device_id'     => DEVICE_ID,
    ]);
    $newFineId = (int) $db->lastInsertId();

    // Flag every existing unresolved sibling - same reasoning as the
    // reading endpoint: the whole duplicate group becomes visible on
    // the review screen together.
    if ($possibleDuplicate) {
        $placeholders = implode(',', array_fill(0, count($existingDuplicateIds), '?'));
        $db->prepare("UPDATE Fines SET is_duplicate_flagged = 1 WHERE fine_id IN ({$placeholders})")
           ->execute($existingDuplicateIds);
    }

    // Queue this row for the central Workspace Server - same transaction
    // as the insert above. case_id is deliberately left out of the
    // payload: it is a LOCAL autoincrement into this workstation's own
    // Cases table, and Cases doesn't participate in central sync yet
    // (see README) - pushing it as-is would either point at the wrong
    // case centrally or fail outright on the FOREIGN KEY constraint if
    // no case with that id exists there. The fine itself still syncs;
    // only its case link is deferred until Cases gets the same sync_id
    // treatment.
    enqueueForCentralSync($db, $syncId, 'Fines', 'INSERT', [
        'fine_number'          => $fineNumber,
        'offender_name'        => $offenderName,
        'house_number'         => $houseNumber ?: null,
        'vehicle_plate'        => $vehiclePlate ?: null,
        'offence_type'         => $offenceType,
        'amount'               => $amount,
        'payment_status'       => 'Unpaid',
        'issued_by'            => $_SESSION['user_id'],
        'date_issued'          => $dateIssued,
        'client_ref'           => $clientRef,
        'is_duplicate_flagged' => $possibleDuplicate ? 1 : 0,
    ]);

    $db->commit();

    $logMsg = "Issued fine {$fineNumber} to {$offenderName}";
    if ($possibleDuplicate) {
        $logMsg .= ' - POSSIBLE DUPLICATE, flagged for admin review';
    }
    logActivity($_SESSION['user_id'], $logMsg);

    echo json_encode([
        'success'            => true,
        'fine_number'        => $fineNumber,
        'duplicate_flagged'  => $possibleDuplicate,
    ]);

} catch (PDOException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('save_fine.php failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not save the fine. Please try again.']);
}