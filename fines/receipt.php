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

<style>
    .receipt-card {
        max-width: 500px;
        background: #ffffff;
        margin: 20px auto;
        padding: 30px;
        border-radius: 12px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
        border-top: 5px solid #16223E;
        color: #333;
    }
    .receipt-header {
        text-align: center;
        border-bottom: 2px solid #eaeaea;
        padding-bottom: 15px;
        margin-bottom: 20px;
    }
    .receipt-header h2 {
        margin: 0;
        color: #16223E;
        font-size: 20px;
        text-transform: uppercase;
        letter-spacing: 1px;
    }
    .receipt-header p {
        margin: 5px 0 0;
        font-size: 13px;
        color: #777;
    }
    .meta-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
        font-size: 13px;
        margin-bottom: 20px;
        background: #f9fbfb;
        padding: 12px;
        border-radius: 6px;
    }
    .meta-grid div span {
        display: block;
        color: #888;
        font-size: 11px;
        text-transform: uppercase;
    }
    .section-title {
        font-size: 12px;
        text-transform: uppercase;
        color: #888;
        margin-bottom: 8px;
        letter-spacing: 0.5px;
    }
    .details-box {
        font-size: 14px;
        margin-bottom: 20px;
    }
    .details-box p {
        margin: 6px 0;
        display: flex;
        justify-content: space-between;
    }
    .details-box span.label {
        color: #555;
    }
    .details-box span.value {
        font-weight: 600;
        color: #111;
    }
    .table-breakdown {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 20px;
    }
    .table-breakdown th, .table-breakdown td {
        padding: 10px;
        text-align: left;
        font-size: 13px;
        border-bottom: 1px solid #eee;
    }
    .table-breakdown th {
        background-color: #f8fafc;
        color: #475569;
        font-weight: 600;
    }
    .table-breakdown td.amount-col {
        text-align: right;
        font-weight: 600;
    }
    .total-section {
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: #16223E;
        color: #fff;
        padding: 12px 15px;
        border-radius: 6px;
        margin-bottom: 20px;
        font-size: 15px;
        font-weight: bold;
    }
    .status-badge {
        display: inline-block;
        padding: 4px 10px;
        font-size: 11px;
        font-weight: bold;
        text-transform: uppercase;
        border-radius: 4px;
        background: <?= ($fine['payment_status'] ?? '') === 'Paid' ? '#d1fae5' : '#fef3c7' ?>;
        color: <?= ($fine['payment_status'] ?? '') === 'Paid' ? '#065f46' : '#d97706' ?>;
    }
    .receipt-footer {
        text-align: center;
        font-size: 11px;
        color: #999;
        border-top: 1px dashed #eaeaea;
        padding-top: 15px;
    }

    /* Print isolation: hide everything except the receipt card */
    @media print {
        body * {
            visibility: hidden;
        }
        .receipt-card, .receipt-card * {
            visibility: visible;
        }
        .receipt-card {
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
            margin: 0;
            padding: 10px;
            box-shadow: none;
            border-top: none;
        }
        .no-print {
            display: none !important;
        }
    }
</style>

<div class="no-print" style="margin-bottom:16px; text-align: center;">
    <button id="btnPrintReceipt" class="btn">Print / Save as PDF</button>
    <a href="list.php" class="btn secondary">Back to Fines</a>
</div>

<div class="receipt-card">
    <div class="receipt-header">
        <h2>Beitbridge Municipality</h2>
        <p>Official Municipal Fine Assessment Notice</p>
    </div>

    <div class="meta-grid">
        <div>
            <span>Fine Number</span>
            <strong><?= htmlspecialchars($fine['fine_number']) ?></strong>
        </div>
        <div>
            <span>Receipt No.</span>
            <strong><?= htmlspecialchars($fine['receipt_number'] ?? 'Not yet paid') ?></strong>
        </div>
        <div>
            <span>Date Issued</span>
            <strong><?= htmlspecialchars(date('d M Y', strtotime($fine['date_issued']))) ?></strong>
        </div>
        <div>
            <span>Status</span>
            <span class="status-badge"><?= htmlspecialchars($fine['payment_status'] ?? 'Unpaid') ?></span>
        </div>
    </div>

    <div class="section-title">Offender Information</div>
    <div class="details-box">
        <p><span class="label">Offender Name:</span> <span class="value"><?= htmlspecialchars($fine['offender_name']) ?></span></p>
        <p><span class="label">Property / Vehicle:</span> <span class="value"><?= htmlspecialchars($fine['house_number'] ?: $fine['vehicle_plate'] ?: '-') ?></span></p>
        <p><span class="label">Issuing Officer:</span> <span class="value"><?= htmlspecialchars($fine['issued_by_name'] ?? '-') ?></span></p>
        <?php if (!empty($fine['date_paid'])): ?>
            <p><span class="label">Date Paid:</span> <span class="value"><?= htmlspecialchars(date('d M Y H:i', strtotime($fine['date_paid']))) ?></span></p>
        <?php endif; ?>
    </div>

    <div class="section-title">Infraction Breakdown</div>
    <table class="table-breakdown">
        <thead>
            <tr>
                <th>Violation Type</th>
                <th class="amount-col">Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><?= htmlspecialchars($fine['offence_type']) ?></td>
                <td class="amount-col">US$<?= number_format((float) $fine['amount'], 2) ?></td>
            </tr>
        </tbody>
    </table>

    <div class="total-section">
        <span>Total Balance Due</span>
        <span>US$ <?= number_format((float) $fine['amount'], 2) ?></span>
    </div>

    <div class="receipt-footer">
        <p>Municipality Stamp: ___________________________</p>
        <p style="margin-top: 8px;">Please present this notice or fine reference during settlement.</p>
        <p>Generated via BeitDesk Municipal Management System</p>
    </div>
</div>

<!-- Load external script to satisfy CSP script-src 'self' rules -->
<script src="/municipal-system/assets/js/receipt-print.js"></script>

<?php require __DIR__ . '/../includes/footer.php'; ?>