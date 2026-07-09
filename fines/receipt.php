<?php
/**
 * fines/receipt.php
 * "Print PDF Receipt" use case. Rather than requiring a PDF library
 * (composer/network access is not guaranteed on every XAMPP install),
 * this produces a clean, print-styled HTML receipt - the browser's own
 * "Print to PDF" option (Ctrl+P) saves it as a real PDF file.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
requireRole(['Admin', 'Officer', 'Clerk']);

$db = getDb();
$fineId = (int) ($_GET['fine_id'] ?? 0);

$stmt = $db->prepare(
    'SELECT f.*, p.receipt_number, p.date_paid, p.amount_received, u.full_name AS issued_by_name
     FROM Fines f
     LEFT JOIN Payments p ON p.fine_id = f.fine_id
     LEFT JOIN Users u ON u.user_id = f.issued_by
     WHERE f.fine_id = :id'
);
$stmt->execute(['id' => $fineId]);
$fine = $stmt->fetch();

if (!$fine) {
    require __DIR__ . '/../includes/header.php';
    echo '<div class="alert error">Fine not found.</div>';
    require __DIR__ . '/../includes/footer.php';
    exit;
}

require __DIR__ . '/../includes/header.php';
?>

<div class="no-print" style="margin-bottom:16px;">
    <button onclick="window.print()" class="btn">Print / Save as PDF</button>
    <a href="list.php" class="btn secondary">Back to Fines</a>
</div>

<div class="receipt-box">
    <h1>Beitbridge Municipality<br>Official Fine Receipt</h1>
    <div class="receipt-row"><span>Fine No.</span><span><?= htmlspecialchars($fine['fine_number']) ?></span></div>
    <div class="receipt-row"><span>Receipt No.</span><span><?= htmlspecialchars($fine['receipt_number'] ?? 'Not yet paid') ?></span></div>
    <div class="receipt-row"><span>Offender</span><span><?= htmlspecialchars($fine['offender_name']) ?></span></div>
    <div class="receipt-row"><span>Offence</span><span><?= htmlspecialchars($fine['offence_type']) ?></span></div>
    <div class="receipt-row"><span>House / Plate</span><span><?= htmlspecialchars($fine['house_number'] ?: $fine['vehicle_plate'] ?: '-') ?></span></div>
    <div class="receipt-row"><span>Date Issued</span><span><?= htmlspecialchars(date('d M Y', strtotime($fine['date_issued']))) ?></span></div>
    <div class="receipt-row"><span>Issued By</span><span><?= htmlspecialchars($fine['issued_by_name'] ?? '-') ?></span></div>
    <?php if ($fine['date_paid']): ?>
        <div class="receipt-row"><span>Date Paid</span><span><?= htmlspecialchars(date('d M Y H:i', strtotime($fine['date_paid']))) ?></span></div>
    <?php endif; ?>
    <div class="receipt-row receipt-total"><span>Amount</span><span>US$<?= number_format((float) $fine['amount'], 2) ?></span></div>

    <p style="text-align:center;margin-top:24px;font-size:0.8rem;color:#5B6472;">
        Municipality Stamp: ___________________________
    </p>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
