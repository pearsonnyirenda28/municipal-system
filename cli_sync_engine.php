<?php
/**
 * cli_sync_engine.php
 * Automated background CLI worker script for field workstations.
 * Continuously pushes local SQLite changes over LAN to the central MySQL server.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Error: CLI Sync Engine must be executed from the command line interface.\n");
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/local_store.php';

$serverUrl = CENTRAL_SERVER_URL;
$deviceId  = DEVICE_ID;
$batchSize = 50;

echo "[" . date('Y-m-d H:i:s') . "] Starting Local Sync Engine for Device: {$deviceId}...\n";
echo "Target Central Server: {$serverUrl}\n";

while (true) {
    try {
        $localDb = getLocalDb();

        // 1. Retrieve pending local queue items
        $stmt = $localDb->prepare("
            SELECT * FROM LocalSyncQueue 
            WHERE status IN ('PENDING', 'RETRY') 
            ORDER BY queue_id ASC 
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $batchSize, PDO::PARAM_INT);
        $stmt->execute();
        $pendingItems = $stmt->fetchAll();

        if (empty($pendingItems)) {
            sleep(5);
            continue;
        }

        // 2. Prepare payload structure
        $batchRecords = [];
        $syncIds = [];

        foreach ($pendingItems as $item) {
            $syncIds[] = $item['sync_id'];
            $batchRecords[] = [
                'sync_id'    => $item['sync_id'],
                'table_name' => $item['table_name'],
                'data'       => json_decode($item['payload'], true, 512, JSON_THROW_ON_ERROR)
            ];
        }

        $payload = json_encode([
            'device_id' => $deviceId,
            'records'   => $batchRecords
        ], JSON_THROW_ON_ERROR);

        // 3. Set pending queue items to SYNCING state
        $inClause = implode(',', array_fill(0, count($syncIds), '?'));
        $updateSyncingStmt = $localDb->prepare("UPDATE LocalSyncQueue SET status = 'SYNCING', updated_at = CURRENT_TIMESTAMP WHERE sync_id IN ({$inClause})");
        $updateSyncingStmt->execute($syncIds);

        // 4. Send network request via cURL over LAN
        $ch = curl_init($serverUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 5
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            throw new RuntimeException("Server unreachable or returned HTTP {$httpCode}. Error: {$curlError}");
        }

        $responseData = json_decode((string)$response, true, 512, JSON_THROW_ON_ERROR);
        if (!isset($responseData['results']) || !is_array($responseData['results'])) {
            throw new RuntimeException("Malformed response payload from central server.");
        }

        // 5. Update local record statuses based on server ACKs
        $localDb->beginTransaction();

        foreach ($responseData['results'] as $res) {
            $sId = $res['sync_id'];
            $status = $res['status'];

            if ($status === 'synced' || $status === 'already_synced') {
                // Update Queue Status
                $localDb->prepare("UPDATE LocalSyncQueue SET status = 'SYNCED', updated_at = CURRENT_TIMESTAMP WHERE sync_id = ?")
                        ->execute([$sId]);

                // Update primary local store table status
                $queueItemStmt = $localDb->prepare("SELECT table_name FROM LocalSyncQueue WHERE sync_id = ?");
                $queueItemStmt->execute([$sId]);
                $tableName = $queueItemStmt->fetchColumn();

                if ($tableName) {
                    $localDb->prepare("UPDATE `{$tableName}` SET sync_status = 'SYNCED', synced_at = CURRENT_TIMESTAMP WHERE sync_id = ?")
                            ->execute([$sId]);
                }
            } else {
                $errMsg = $res['message'] ?? 'Synchronization rejected by server.';
                $localDb->prepare("UPDATE LocalSyncQueue SET status = 'SYNC_ERROR', error_message = ?, updated_at = CURRENT_TIMESTAMP WHERE sync_id = ?")
                        ->execute([$errMsg, $sId]);
            }
        }

        $localDb->commit();
        echo "[" . date('Y-m-d H:i:s') . "] Processed batch of " . count($pendingItems) . " records successfully.\n";

    } catch (Throwable $e) {
        if (isset($localDb) && $localDb->inTransaction()) {
            $localDb->rollBack();
        }

        // Reset state back to RETRY for pending attempts
        if (!empty($syncIds) && isset($localDb)) {
            $inClause = implode(',', array_fill(0, count($syncIds), '?'));
            $localDb->prepare("UPDATE LocalSyncQueue SET status = 'RETRY', updated_at = CURRENT_TIMESTAMP WHERE sync_id IN ({$inClause})")
                    ->execute($syncIds);
        }

        echo "[" . date('Y-m-d H:i:s') . "] Sync Attempt Exception: " . $e->getMessage() . ". Retrying in 10 seconds...\n";
        sleep(10);
    }
}