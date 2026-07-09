<?php
/**
 * fines/pay.php
 * "Record Fine Payment" use case (Activity 4, Section 6.1).
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
requireRole(['Admin', 'Officer', 'Clerk']);

$db = getDb();
$fineId = (int) ($_GET['fine_id'] ?? $_POST['fine_id'] ?? 0);

$stmt = $db->prepare('SELECT * FROM Fines WHERE fine_id = :id');
$stmt->execute(['id' => $fineId]);
$fine = $stmt->fetch();

if (!$fine) {
    require __DIR__ . '/../includes/header.php';
    echo '<div class="alert error">Fine not found.</div>';
    require __DIR__ . '/../includes/footer.php';
    exit;
}

$error = '';

if ($fine['payment_status'] === 'Paid') {
    $error = 'This fine has already been paid.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    verifyCsrf();

    $amountReceived = trim($_POST['amount_received'] ?? '');

    if (!is_numeric($amountReceived) || (float) $amountReceived <= 0) {
        $error = 'Please enter a valid amount received.';
    } elseif (abs((float) $amountReceived - (float) $fine['amount']) > 0.01) {
        $error = 'Amount received must equal the outstanding fine amount (US$'
               . number_format((float) $fine['amount'], 2) . ').';
    } else {
        try {
            $db->beginTransaction();

            $receiptNumber = 'RCPT-' . date('Y') . '-' . str_pad((string) $fineId, 5, '0', STR_PAD_LEFT);

            $stmt = $db->prepare(
                'INSERT INTO Payments (fine_id, amount_received, date_paid, received_by, receipt_number)
                 VALUES (:fine_id, :amount, CURRENT_TIMESTAMP, :received_by, :receipt)'
            );
            $stmt->execute([
                'fine_id'     => $fineId,
                'amount'      => $amountReceived,
                'received_by' => currentUser()['id'],
                'receipt'     => $receiptNumber,
            ]);

            $stmt = $db->prepare("UPDATE Fines SET payment_status = 'Paid' WHERE fine_id = :id");
            $stmt->execute(['id' => $fineId]);

            $db->commit();
            logActivity(currentUser()['id'], "Recorded payment for fine {$fine['fine_number']}");

            header('Location: receipt.php?fine_id=' . $fineId);
            exit;

        } catch (PDOException $e) {
            $db->rollBack();
            error_log('Payment failed: ' . $e->getMessage());
            $error = 'Could not record the payment. Please try again.';
        }
    }
}

require __DIR__ . '/../includes/header.php';
?>

<h1>Record Payment</h1>
<p class="subtitle">Fine <?= htmlspecialchars($fine['fine_number']) ?> &mdash; <?= htmlspecialchars($fine['offender_name']) ?></p>

<?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<?php if ($fine['payment_status'] !== 'Paid'): ?>
<div class="form-panel">
    <p><strong>Offence:</strong> <?= htmlspecialchars($fine['offence_type']) ?></p>
    <p><strong>Amount due:</strong> US$<?= number_format((float) $fine['amount'], 2) ?></p>

    <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="fine_id" value="<?= $fineId ?>">
        <label for="amount_received">Amount received (US$)</label>
        <input type="number" id="amount_received" name="amount_received" step="0.01"
               value="<?= htmlspecialchars((string) $fine['amount']) ?>">
        <button type="submit" class="btn">Record Payment &amp; Print Receipt</button>
        <a href="list.php" class="btn secondary">Cancel</a>
    </form>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
