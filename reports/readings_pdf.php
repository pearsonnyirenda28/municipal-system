<?php
/**
 * reports/readings_pdf.php
 * Improvement 4: PDF export for the Meter Reading Report. Mirrors
 * reports/readings.php.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pdf_report.php';
requireRole(['Admin', 'MeterSupervisor']);

$db = getDb();
$user = currentUser();
$wardFilter = $_GET['ward_id'] ?? '';
$houseId = $_GET['house_id'] ?? '';
$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';

$sql = 'SELECT r.*, h.house_number, w.ward_name
        FROM MeterReadings r
        JOIN Houses h ON h.house_id = r.house_id
        LEFT JOIN Wards w ON w.ward_id = h.ward_id
        WHERE 1=1';
$params = [];
if ($wardFilter !== '') { $sql .= ' AND h.ward_id = :ward_id'; $params['ward_id'] = $wardFilter; }
if ($houseId !== '') { $sql .= ' AND r.house_id = :house_id'; $params['house_id'] = $houseId; }
if ($from) { $sql .= ' AND r.date_taken >= :from'; $params['from'] = $from; }
if ($to)   { $sql .= ' AND r.date_taken <= :to'; $params['to'] = $to; }
$sql .= ' ORDER BY r.date_taken DESC';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$filterParts = [];
if ($wardFilter !== '') {
    $wardName = $db->query("SELECT ward_name FROM Wards WHERE ward_id = " . (int) $wardFilter)->fetchColumn();
    if ($wardName) { $filterParts[] = 'Ward: ' . $wardName; }
}
if ($houseId !== '') {
    $houseNo = $db->query("SELECT house_number FROM Houses WHERE house_id = " . (int) $houseId)->fetchColumn();
    if ($houseNo) { $filterParts[] = 'House: ' . $houseNo; }
}
if ($from) { $filterParts[] = 'From ' . $from; }
if ($to) { $filterParts[] = 'To ' . $to; }
$filterSummary = $filterParts ? implode('  |  ', $filterParts) : 'All readings';

$pdf = new MunicipalReportPdf('Meter Reading Report', $filterSummary, $user['name']);
$pdf->AliasNbPages();
$pdf->table(
    ['House No.' => 28, 'Ward' => 35, 'Previous' => 25, 'Current' => 25, 'Consumption' => 28, 'Status' => 25, 'Date Taken' => 30],
    array_map(fn($r) => [
        $r['house_number'], $r['ward_name'] ?? '-', number_format((float) $r['previous_reading'], 2),
        number_format((float) $r['current_reading'], 2), number_format((float) $r['consumption'], 2) . ' m3',
        $r['is_abnormal'] ? 'Abnormal' : 'Normal', date('d M Y', strtotime($r['date_taken'])),
    ], $rows)
);

logActivity($user['id'], 'Generated PDF: Meter Reading Report (' . count($rows) . ' record(s))');

$pdf->Output('D', 'meter-reading-report-' . date('Ymd-His') . '.pdf');
