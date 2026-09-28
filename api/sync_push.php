<?php
/**
 * api/sync_push.php
 * Endpoint running on the central Municipal Workspace Server.
 * Processes incoming batch transactions and inserts into MySQL using idempotent sync checks.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';

/**
 * Returns central MySQL database connection.
 *
 * @return PDO
 */
function getCentralDb(): PDO {
    static $mysqlPdo = null;
    if ($mysqlPdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $mysqlPdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $mysqlPdo;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Use POST.']);
    exit;
}

$rawInput = file_get_contents('php://input');
if (!$rawInput) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Empty request body.']);
    exit;
}

try {
    $payload = json_decode($rawInput, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Malformed JSON payload.']);
    exit;
}

if (!isset($payload['records']) || !is_array($payload['records'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing or invalid "records" array.']);
    exit;
}

$mysql = getCentralDb();
$records = $payload['records'];
$results = [];
$allowedTables = ['MeterReadings', 'Fines', 'HouseVisits'];

$mysql->beginTransaction();

try {
    foreach ($records as $item) {
        $syncId = $item['sync_id'] ?? null;
        $tableName = $item['table_name'] ?? null;
        $data = $item['data'] ?? [];

        if (!$syncId || !$tableName || !in_array($tableName, $allowedTables, true) || empty($data)) {
            $results[] = [
                'sync_id' => $syncId,
                'status' => 'error',
                'message' => 'Invalid table, payload parameters, or missing sync_id.'
            ];
            continue;
        }

        // Idempotency check: verify if sync_id already exists in Central MySQL
        $checkStmt = $mysql->prepare("SELECT COUNT(*) FROM `{$tableName}` WHERE `sync_id` = :sync_id");
        $checkStmt->execute(['sync_id' => $syncId]);

        if ((int)$checkStmt->fetchColumn() > 0) {
            $results[] = [
                'sync_id' => $syncId,
                'status' => 'already_synced'
            ];
            continue;
        }

        // Strip local-only tracking fields before writing to MySQL
        unset($data['sync_status']);
        $data['synced_at'] = date('Y-m-d H:i:s');

        $columns = array_keys($data);
        $colList = implode(', ', array_map(fn($c) => "`{$c}`", $columns));
        $placeholders = implode(', ', array_map(fn($c) => ":{$c}", $columns));

        $insertSql = "INSERT INTO `{$tableName}` ({$colList}) VALUES ({$placeholders})";
        $insertStmt = $mysql->prepare($insertSql);
        $insertStmt->execute($data);

        $results[] = [
            'sync_id' => $syncId,
            'status' => 'synced'
        ];
    }

    $mysql->commit();
    echo json_encode(['success' => true, 'results' => $results], JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    if ($mysql->inTransaction()) {
        $mysql->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'results' => $results
    ]);
}