<?php
/**
 * reports/damage_pdf.php
 * Improvement 4: PDF export for the Damaged Meter Report. Mirrors
 * reports/damage.php.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pdf_report.php';
requireRole(['Admin', 'MeterSupervisor']);

$db = getDb();
$user = currentUser();
$status = $_GET['status'] ?? '';
$houseId = $_GET['house_id'] ?? '';

$sql = 'SELECT d.*, h.house_number, w.ward_name FROM DamagedMeters d JOIN Houses h ON h.house_id = d.house_id LEFT JOIN Wards w ON w.ward_id = h.ward_id WHERE 1=1';
$params = [];
if (in_array($status, ['Pending', 'In Progress', 'Fixed'], true)) { $sql .= ' AND d.repair_status = :status'; $params['status'] = $status; }
if ($houseId !== '') { $sql .= ' AND d.house_id = :house_id'; $params['house_id'] = $houseId; }
$sql .= ' ORDER BY d.date_reported DESC';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$filterParts = [];
if ($status) { $filterParts[] = 'Status: ' . $status; }
if ($houseId !== '') {
    $houseNo = $db->query("SELECT house_number FROM Houses WHERE house_id = " . (int) $houseId)->fetchColumn();
    if ($houseNo) { $filterParts[] = 'House: ' . $houseNo; }
}
$filterSummary = $filterParts ? implode('  |  ', $filterParts) : 'All damaged meter reports';

$pdf = new MunicipalReportPdf('Damaged Meter Report', $filterSummary, $user['name']);
$pdf->AliasNbPages();
$pdf->table(
    ['House No.' => 28, 'Ward' => 35, 'Description' => 75, 'Status' => 28, 'Date Reported' => 30, 'Date Fixed' => 30],
    array_map(fn($d) => [
        $d['house_number'], $d['ward_name'] ?? '-', $d['description'] ?? '-', $d['repair_status'],
        date('d M Y', strtotime($d['date_reported'])), $d['date_fixed'] ? date('d M Y', strtotime($d['date_fixed'])) : '-',
    ], $rows)
);

logActivity($user['id'], 'Generated PDF: Damaged Meter Report (' . count($rows) . ' record(s))');

$pdf->Output('D', 'damaged-meter-report-' . date('Ymd-His') . '.pdf');
