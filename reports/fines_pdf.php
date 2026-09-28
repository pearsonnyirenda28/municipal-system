<?php
/**
 * reports/fines_pdf.php
 * Improvement 4: PDF export for the Fine Report. Mirrors reports/fines.php.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pdf_report.php';
requireRole(['Admin', 'PoliceSupervisor']);

$db = getDb();
$user = currentUser();
$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';
$status = $_GET['status'] ?? '';

$sql = 'SELECT * FROM Fines WHERE 1=1';
$params = [];
if ($from) { $sql .= ' AND date_issued >= :from'; $params['from'] = $from; }
if ($to)   { $sql .= ' AND date_issued <= :to_end'; $params['to_end'] = $to . ' 23:59:59'; }
if (in_array($status, ['Unpaid', 'Paid'], true)) { $sql .= ' AND payment_status = :status'; $params['status'] = $status; }
$sql .= ' ORDER BY date_issued DESC';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
$total = array_sum(array_column($rows, 'amount'));

$filterParts = [];
if ($from) { $filterParts[] = 'From ' . $from; }
if ($to) { $filterParts[] = 'To ' . $to; }
if ($status) { $filterParts[] = 'Status: ' . $status; }
$filterParts[] = 'Total: US$' . number_format($total, 2);
$filterSummary = implode('  |  ', $filterParts);

$pdf = new MunicipalReportPdf('Fine Report', $filterSummary, $user['name']);
$pdf->AliasNbPages();
$pdf->table(
    ['Fine No.' => 28, 'Offender' => 48, 'House No.' => 24, 'Vehicle Plate' => 28, 'Offence' => 42, 'Amount' => 22, 'Status' => 20, 'Date Issued' => 30],
    array_map(fn($f) => [
        $f['fine_number'], $f['offender_name'], $f['house_number'] ?? '-', $f['vehicle_plate'] ?? '-',
        $f['offence_type'], 'US$' . number_format((float) $f['amount'], 2), $f['payment_status'],
        date('d M Y', strtotime($f['date_issued'])),
    ], $rows)
);

logActivity($user['id'], 'Generated PDF: Fine Report (' . count($rows) . ' record(s))');

$pdf->Output('D', 'fine-report-' . date('Ymd-His') . '.pdf');
