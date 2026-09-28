<?php
/**
 * reports/cases_pdf.php
 * Improvement 4: PDF export for the Case Report. Mirrors the exact
 * filter logic in reports/cases.php so "Export PDF" always produces
 * what's currently on screen, not a separate unfiltered dump.
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
$officerId = $_GET['officer_id'] ?? '';

$sql = 'SELECT c.*, u.full_name AS officer_name FROM Cases c LEFT JOIN Users u ON u.user_id = c.officer_id WHERE 1=1';
$params = [];
if ($from) { $sql .= ' AND c.date_opened >= :from'; $params['from'] = $from; }
if ($to)   { $sql .= ' AND c.date_opened <= :to';   $params['to'] = $to; }
if (in_array($status, ['Open', 'In Progress', 'Closed'], true)) { $sql .= ' AND c.status = :status'; $params['status'] = $status; }
if ($officerId !== '') { $sql .= ' AND c.officer_id = :officer_id'; $params['officer_id'] = $officerId; }
$sql .= ' ORDER BY c.date_opened DESC';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$filterParts = [];
if ($from) { $filterParts[] = 'From ' . $from; }
if ($to) { $filterParts[] = 'To ' . $to; }
if ($status) { $filterParts[] = 'Status: ' . $status; }
if ($officerId !== '') {
    $officerName = $db->query("SELECT full_name FROM Users WHERE user_id = " . (int) $officerId)->fetchColumn();
    if ($officerName) { $filterParts[] = 'Officer: ' . $officerName; }
}
$filterSummary = $filterParts ? implode('  |  ', $filterParts) : 'All cases';

$pdf = new MunicipalReportPdf('Police Case Report', $filterSummary, $user['name']);
$pdf->AliasNbPages();
$pdf->table(
    ['Case No.' => 30, 'Offender' => 55, 'Offence' => 45, 'Status' => 25, 'Officer' => 50, 'Date Opened' => 30],
    array_map(fn($c) => [
        $c['case_number'], $c['offender_name'], $c['offence_type'], $c['status'],
        $c['officer_name'] ?? '-', date('d M Y', strtotime($c['date_opened'])),
    ], $rows)
);

logActivity($user['id'], 'Generated PDF: Police Case Report (' . count($rows) . ' record(s))');

$pdf->Output('D', 'case-report-' . date('Ymd-His') . '.pdf');
