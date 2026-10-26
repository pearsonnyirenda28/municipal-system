<?php
/**
 * api/sync_pull.php
 * Endpoint running on the central Municipal Workspace Server. Returns a
 * full snapshot of master data - Users, Wards, Houses, WardAssignments -
 * for cli_sync_engine.php on each field workstation to pull down and
 * upsert into its own local SQLite (principle: master data flows
 * MySQL -> SQLite, the opposite direction from api/sync_push.php).
 *
 * A full snapshot every time, not an incremental diff keyed off an
 * "updated_at" column: simpler, and safe to get wrong in the direction
 * that matters - a workstation that pulls slightly more than it
 * strictly needs is fine, one that misses an update because of a
 * broken "since" filter would silently run on stale ward/house data
 * for however long nobody notices. Fine for a municipality's realistic
 * data volumes (thousands of houses, not millions); worth revisiting
 * with real incremental sync if a deployment's master data grows large
 * enough for that to matter.
 *
 * Every row keeps its central primary key exactly as-is. The puller on
 * the workstation side (cli_sync_engine.php) writes each one back with
 * that SAME id, not a fresh local autoincrement one - that's what
 * keeps a house_id (or user_id, ward_id...) meaning the same row on
 * every workstation and centrally, which MeterReadings/Fines/HouseVisits
 * rely on when they reference one by plain integer FK. It only holds if
 * master data is always created HERE, at the Workspace Server, and
 * never directly on a field workstation - see the deployment notes in
 * README.md.
 *
 * Users includes password_hash: a field workstation needs it to
 * authenticate a login locally while it has no route to the Workspace
 * Server at all (principle: the application works regardless of LAN
 * availability, and that includes logging in). This is a real
 * operational trade-off, not an oversight - see README.md's
 * "Deploying more than one workstation" section for the physical-
 * security implications of every workstation holding real password
 * hashes, and what to do about it.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/central_store.php';

requireSyncApiKey();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Use GET.']);
    exit;
}

try {
    $mysql = getCentralDb();

    echo json_encode([
        'success'          => true,
        'pulled_at'        => date('c'),
        'users'            => $mysql->query('SELECT user_id, username, password_hash, role, full_name, created_at FROM Users')->fetchAll(),
        'wards'            => $mysql->query('SELECT ward_id, ward_name, description FROM Wards')->fetchAll(),
        'houses'           => $mysql->query('SELECT house_id, house_number, street, ward_id, owner_name, meter_number, address FROM Houses')->fetchAll(),
        'ward_assignments' => $mysql->query('SELECT assignment_id, ward_id, user_id, assigned_at FROM WardAssignments')->fetchAll(),
    ], JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    error_log('sync_pull.php failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not read master data.']);
}
