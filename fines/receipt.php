<?php
/**
 * fines/receipt.php
 * Print-ready official fine receipt. Fully CSP-compliant.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
requireRole(['Admin', 'Officer', 'Clerk']);

$db = getDb();
$fineId = (int) ($_GET['fine_id'] ?? 0);

$stmt = $db->prepare(
    'SELECT f.*, p.receipt_number, p.date_paid, p.amount_received,
            u.full_name AS issued_by_name, ru.full_name AS received_by_name
     FROM Fines f
     LEFT JOIN Payments p ON p.fine_id = f.fine_id
     LEFT JOIN Users u  ON u.user_id  = f.issued_by
     LEFT JOIN Users ru ON ru.user_id = p.received_by
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

$isPaid = ($fine['payment_status'] === 'Paid');

require __DIR__ . '/../includes/header.php';
?>

<!-- ── Screen-only action bar ─────────────────────────────────── -->
<div class="receipt-actions no-print">
    <button type="button" class="btn btn-print" data-print>
        🖨 Print / Save as PDF
    </button>
    <?php if (!$isPaid): ?>
        <a href="pay.php?fine_id=<?= (int) $fine['fine_id'] ?>" class="btn">Record Payment</a>
    <?php endif; ?>
    <a href="list.php" class="btn secondary">Back to Fines</a>
</div>

<!-- ── Official receipt ───────────────────────────────────────── -->
<article class="receipt-box print-area">

    <!-- Header / letterhead -->
    <header class="receipt-header">
        <div class="receipt-brand">
            <div class="receipt-crest" aria-hidden="true">BB</div>
            <div class="receipt-brand-text">
                <h1>Beitbridge Municipality</h1>
                <p>Department of Municipal Police &amp; Revenue</p>
            </div>
        </div>
        <div class="receipt-meta">
            <span class="receipt-type">Official Fine Receipt</span>
            <span class="receipt-status <?= $isPaid ? 'is-paid' : 'is-unpaid' ?>">
                <?= $isPaid ? 'PAID' : 'UNPAID' ?>
            </span>
        </div>
    </header>

    <hr class="receipt-divider">

    <!-- Identification block -->
    <section class="receipt-ids">
        <div class="receipt-id">
            <span class="receipt-id-label">Fine No.</span>
            <span class="receipt-id-value"><?= htmlspecialchars($fine['fine_number']) ?></span>
        </div>
        <div class="receipt-id">
            <span class="receipt-id-label">Receipt No.</span>
            <span class="receipt-id-value">
                <?= htmlspecialchars($fine['receipt_number'] ?? '—') ?>
            </span>
        </div>
        <div class="receipt-id">
            <span class="receipt-id-label">Date Issued</span>
            <span class="receipt-id-value">
                <?= htmlspecialchars(date('d M Y', strtotime($fine['date_issued']))) ?>
            </span>
        </div>
        <?php if ($isPaid && !empty($fine['date_paid'])): ?>
            <div class="receipt-id">
                <span class="receipt-id-label">Date Paid</span>
                <span class="receipt-id-value">
                    <?= htmlspecialchars(date('d M Y H:i', strtotime($fine['date_paid']))) ?>
                </span>
            </div>
        <?php endif; ?>
    </section>

    <!-- Offender details -->
    <section class="receipt-section">
        <h2 class="receipt-section-title">Issued To</h2>
        <dl class="receipt-details">
            <div>
                <dt>Full Name</dt>
                <dd><?= htmlspecialchars($fine['offender_name']) ?></dd>
            </div>
            <?php if (!empty($fine['house_number'])): ?>
                <div>
                    <dt>House No.</dt>
                    <dd><?= htmlspecialchars($fine['house_number']) ?></dd>
                </div>
            <?php endif; ?>
            <?php if (!empty($fine['vehicle_plate'])): ?>
                <div>
                    <dt>Vehicle Plate</dt>
                    <dd><?= htmlspecialchars($fine['vehicle_plate']) ?></dd>
                </div>
            <?php endif; ?>
        </dl>
    </section>

    <!-- Offence and amount -->
    <section class="receipt-section">
        <h2 class="receipt-section-title">Offence Details</h2>
        <table class="receipt-table">
            <thead>
                <tr>
                    <th>Offence</th>
                    <th class="amount-col">Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><?= htmlspecialchars($fine['offence_type']) ?></td>
                    <td class="amount-col">
                        US$<?= number_format((float) $fine['amount'], 2) ?>
                    </td>
                </tr>
            </tbody>
            <tfoot>
                <tr class="receipt-total-row">
                    <th>Total Due</th>
                    <th class="amount-col">
                        US$<?= number_format((float) $fine['amount'], 2) ?>
                    </th>
                </tr>
                <?php if ($isPaid && isset($fine['amount_received'])): ?>
                    <tr class="receipt-paid-row">
                        <th>Amount Received</th>
                        <th class="amount-col">
                            US$<?= number_format((float) $fine['amount_received'], 2) ?>
                        </th>
                    </tr>
                <?php endif; ?>
            </tfoot>
        </table>
    </section>

    <!-- Signatures -->
    <section class="receipt-signatures">
        <div class="receipt-signature">
            <div class="signature-line"></div>
            <span class="signature-label">Issued By</span>
            <span class="signature-name">
                <?= htmlspecialchars($fine['issued_by_name'] ?? '—') ?>
            </span>
        </div>
        <div class="receipt-signature">
            <div class="signature-line"></div>
            <span class="signature-label">
                <?= $isPaid ? 'Received By' : 'Municipal Stamp' ?>
            </span>
            <span class="signature-name">
                <?= $isPaid
                    ? htmlspecialchars($fine['received_by_name'] ?? '—')
                    : '' ?>
            </span>
        </div>
    </section>

    <!-- Footer note -->
    <footer class="receipt-footer">
        <p>
            This receipt is computer-generated and is only valid with the municipal stamp.
            Please retain for your records.
        </p>
        <p class="receipt-footer-small">
            Printed on <?= date('d M Y \a\t H:i') ?>
            &middot; <?= htmlspecialchars(APP_NAME) ?>
        </p>
    </footer>

</article>

<?php require __DIR__ . '/../includes/footer.php'; ?>