-- ============================================================
-- Municipal Police and Meter Reading Management System
-- SQLite database schema
--
-- You do NOT need to run this file yourself - visit
-- setup_database.php in your browser once and it will create the
-- SQLite file and run everything in here automatically. This file is
-- kept for reference and for anyone who prefers the sqlite3 CLI:
--   sqlite3 data/municipal_system.sqlite < schema.sql
-- ============================================================

PRAGMA foreign_keys = ON;

-- ------------------------------------------------------------
-- Users
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS Users (
    user_id        INTEGER PRIMARY KEY AUTOINCREMENT,
    username       TEXT NOT NULL UNIQUE,
    password_hash  TEXT NOT NULL,
    role           TEXT NOT NULL CHECK (role IN ('Admin','Officer','MeterReader','Clerk','PoliceSupervisor','MeterSupervisor')),
    full_name      TEXT NOT NULL,
    created_at     TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
-- Cases
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS Cases (
    case_id        INTEGER PRIMARY KEY AUTOINCREMENT,
    case_number    TEXT NOT NULL UNIQUE,
    offender_name  TEXT NOT NULL,
    id_number      TEXT NULL,
    offence_type   TEXT NOT NULL,
    description    TEXT NULL,
    status         TEXT NOT NULL DEFAULT 'Open' CHECK (status IN ('Open','In Progress','Closed')),
    officer_id     INTEGER NULL,
    date_opened    TEXT NOT NULL,
    FOREIGN KEY (officer_id) REFERENCES Users(user_id) ON DELETE SET NULL
);

-- ------------------------------------------------------------
-- Fines
--
-- is_duplicate_flagged / duplicate_resolved_by / duplicate_resolved_at:
-- set when a supervisor reviews a possible-duplicate group on
-- meter-readings/duplicates.php. Once resolved, the flag is cleared
-- and those two columns record which supervisor decided and when.
--
-- discarded_at / discarded_by / discard_reason: SOFT DELETE. When a
-- supervisor chooses one duplicate to keep, the losing rows are NOT
-- deleted - they are marked as discarded and filtered out of every
-- live view. The row (and its foreign-key links) survives for audit.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS Fines (
    fine_id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    fine_number              TEXT NOT NULL UNIQUE,
    case_id                  INTEGER NULL,
    offender_name            TEXT NOT NULL,
    house_number             TEXT NULL,
    vehicle_plate            TEXT NULL,
    offence_type             TEXT NOT NULL,
    amount                   NUMERIC NOT NULL CHECK (amount > 0),
    payment_status           TEXT NOT NULL DEFAULT 'Unpaid' CHECK (payment_status IN ('Unpaid','Paid')),
    issued_by                INTEGER NULL,
    date_issued              TEXT NOT NULL,
    client_ref               TEXT NULL UNIQUE,
    is_duplicate_flagged     INTEGER NOT NULL DEFAULT 0,
    duplicate_resolved_by    INTEGER NULL,
    duplicate_resolved_at    TEXT NULL,
    discarded_at             TEXT NULL,
    discarded_by             INTEGER NULL,
    discard_reason           TEXT NULL,
    sync_id                  TEXT NULL UNIQUE,
    device_id                TEXT NULL,
    sync_status               TEXT NOT NULL DEFAULT 'SYNCED' CHECK (sync_status IN ('PENDING','SYNCING','SYNCED','SYNC_ERROR')),
    synced_at                TEXT NULL,
    FOREIGN KEY (case_id) REFERENCES Cases(case_id) ON DELETE SET NULL,
    FOREIGN KEY (issued_by) REFERENCES Users(user_id) ON DELETE SET NULL,
    FOREIGN KEY (duplicate_resolved_by) REFERENCES Users(user_id) ON DELETE SET NULL,
    FOREIGN KEY (discarded_by) REFERENCES Users(user_id) ON DELETE SET NULL
);

-- ------------------------------------------------------------
-- Payments
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS Payments (
    payment_id       INTEGER PRIMARY KEY AUTOINCREMENT,
    fine_id          INTEGER NOT NULL UNIQUE,
    amount_received  NUMERIC NOT NULL,
    date_paid        TEXT NOT NULL,
    received_by      INTEGER NULL,
    receipt_number   TEXT NOT NULL UNIQUE,
    FOREIGN KEY (fine_id) REFERENCES Fines(fine_id) ON DELETE CASCADE,
    FOREIGN KEY (received_by) REFERENCES Users(user_id) ON DELETE SET NULL
);

-- ------------------------------------------------------------
-- Wards
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS Wards (
    ward_id      INTEGER PRIMARY KEY AUTOINCREMENT,
    ward_name    TEXT NOT NULL UNIQUE,
    description  TEXT NULL
);

-- ------------------------------------------------------------
-- WardAssignments
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS WardAssignments (
    assignment_id  INTEGER PRIMARY KEY AUTOINCREMENT,
    ward_id        INTEGER NOT NULL,
    user_id        INTEGER NOT NULL,
    assigned_at    TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ward_id) REFERENCES Wards(ward_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES Users(user_id) ON DELETE CASCADE,
    UNIQUE (ward_id, user_id)
);

-- ------------------------------------------------------------
-- Houses
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS Houses (
    house_id       INTEGER PRIMARY KEY AUTOINCREMENT,
    house_number   TEXT NOT NULL,
    street         TEXT NULL,
    ward_id        INTEGER NULL,
    owner_name     TEXT NOT NULL,
    meter_number   TEXT NOT NULL UNIQUE,
    address        TEXT NULL,
    FOREIGN KEY (ward_id) REFERENCES Wards(ward_id) ON DELETE SET NULL
);

-- ------------------------------------------------------------
-- MeterReadings
--
-- sync_id / device_id / sync_status / synced_at (Version 4.5): the
-- workstation -> central-MySQL resilience layer. client_ref is this
-- row's idempotency key against THIS workstation's own web server
-- (set by the browser's offline queue); sync_id is the idempotency
-- key this same row is pushed to the central Workspace Server under
-- (set server-side, defaulting to client_ref when the browser already
-- supplied one). See includes/local_store.php.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS MeterReadings (
    reading_id                INTEGER PRIMARY KEY AUTOINCREMENT,
    house_id                  INTEGER NOT NULL,
    previous_reading          NUMERIC NOT NULL,
    current_reading           NUMERIC NOT NULL,
    consumption                NUMERIC NOT NULL,
    is_abnormal               INTEGER NOT NULL DEFAULT 0,
    reader_id                 INTEGER NULL,
    date_taken                TEXT NOT NULL,
    client_ref                TEXT NULL,
    is_duplicate_flagged      INTEGER NOT NULL DEFAULT 0,
    duplicate_resolved_by     INTEGER NULL,
    duplicate_resolved_at     TEXT NULL,
    discarded_at              TEXT NULL,
    discarded_by              INTEGER NULL,
    discard_reason            TEXT NULL,
    sync_id                   TEXT NULL UNIQUE,
    device_id                 TEXT NULL,
    sync_status                TEXT NOT NULL DEFAULT 'SYNCED' CHECK (sync_status IN ('PENDING','SYNCING','SYNCED','SYNC_ERROR')),
    synced_at                 TEXT NULL,
    FOREIGN KEY (house_id) REFERENCES Houses(house_id) ON DELETE CASCADE,
    FOREIGN KEY (reader_id) REFERENCES Users(user_id) ON DELETE SET NULL,
    FOREIGN KEY (duplicate_resolved_by) REFERENCES Users(user_id) ON DELETE SET NULL,
    FOREIGN KEY (discarded_by) REFERENCES Users(user_id) ON DELETE SET NULL,
    CHECK (current_reading >= previous_reading),
    UNIQUE (house_id, date_taken, client_ref)
);

-- ------------------------------------------------------------
-- DamagedMeters
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS DamagedMeters (
    damage_id      INTEGER PRIMARY KEY AUTOINCREMENT,
    house_id       INTEGER NOT NULL,
    description    TEXT NOT NULL,
    photo_path     TEXT NULL,
    reported_by    INTEGER NULL,
    date_reported  TEXT NOT NULL,
    repair_status  TEXT NOT NULL DEFAULT 'Pending' CHECK (repair_status IN ('Pending','In Progress','Fixed')),
    date_fixed     TEXT NULL,
    FOREIGN KEY (house_id) REFERENCES Houses(house_id) ON DELETE CASCADE,
    FOREIGN KEY (reported_by) REFERENCES Users(user_id) ON DELETE SET NULL
);

-- ------------------------------------------------------------
-- HouseVisits
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS HouseVisits (
    visit_id     INTEGER PRIMARY KEY AUTOINCREMENT,
    house_id     INTEGER NOT NULL,
    reader_id    INTEGER NOT NULL,
    visit_date   TEXT NOT NULL,
    status       TEXT NOT NULL DEFAULT 'Pending' CHECK (status IN
                     ('Pending','Completed','Skipped','DamagedMeter','Vacant',
                      'Locked','IllegalConnection','RevisitRequired')),
    reading_id   INTEGER NULL,
    notes        TEXT NULL,
    updated_at   TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sync_id      TEXT NULL UNIQUE,
    device_id    TEXT NULL,
    sync_status   TEXT NOT NULL DEFAULT 'SYNCED' CHECK (sync_status IN ('PENDING','SYNCING','SYNCED','SYNC_ERROR')),
    synced_at    TEXT NULL,
    FOREIGN KEY (house_id) REFERENCES Houses(house_id) ON DELETE CASCADE,
    FOREIGN KEY (reader_id) REFERENCES Users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (reading_id) REFERENCES MeterReadings(reading_id) ON DELETE SET NULL,
    UNIQUE (house_id, reader_id, visit_date)
);

-- ------------------------------------------------------------
-- LocalSyncQueue (Version 4.5)
--
-- The workstation's outbox. Every locally-committed transaction that
-- needs to reach the central Workspace Server's MySQL gets one row
-- here, written in the SAME database transaction as the business-table
-- insert/update (see includes/local_store.php::enqueueForCentralSync).
-- cli_sync_engine.php polls this table and drains it over the LAN.
--
-- operation: 'INSERT' for append-only tables (MeterReadings, Fines -
-- once written, never changed by this app), 'UPSERT' for mutable ones
-- (HouseVisits - the same visit row is updated in place as a reader's
-- day progresses). api/sync_push.php applies the two differently on
-- the central side.
--
-- status: PENDING (waiting) -> SYNCING (a push attempt is in flight -
-- lets a crashed engine tell "was mid-send" apart from "never tried")
-- -> SYNCED, or back to PENDING with attempts+1 and error_message set
-- on failure. After MAX attempts (cli_sync_engine.php) it is left in
-- SYNC_ERROR instead of retried forever - visible on sync/status.php,
-- not silently stuck.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS LocalSyncQueue (
    queue_id       INTEGER PRIMARY KEY AUTOINCREMENT,
    sync_id        TEXT NOT NULL UNIQUE,
    table_name     TEXT NOT NULL,
    operation      TEXT NOT NULL DEFAULT 'INSERT' CHECK (operation IN ('INSERT','UPSERT')),
    payload        TEXT NOT NULL,
    status         TEXT NOT NULL DEFAULT 'PENDING' CHECK (status IN ('PENDING','SYNCING','SYNCED','SYNC_ERROR')),
    attempts       INTEGER NOT NULL DEFAULT 0,
    error_message  TEXT NULL,
    created_at     TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_syncqueue_status ON LocalSyncQueue(status);

-- ------------------------------------------------------------
-- SyncEngineState (Version 4.5)
--
-- Single-row status record cli_sync_engine.php keeps up to date as it
-- runs, so sync/status.php (or anything else) can show the current
-- ONLINE / WORKSPACE_UNAVAILABLE / SYNCING / SYNCHRONIZED / SYNC_ERROR
-- state without shelling out to the running process. See
-- includes/local_store.php::setSyncEngineState().
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS SyncEngineState (
    id                     INTEGER PRIMARY KEY CHECK (id = 1),
    state                  TEXT NOT NULL DEFAULT 'WORKSPACE_UNAVAILABLE',
    last_push_attempt_at   TEXT NULL,
    last_push_success_at   TEXT NULL,
    last_pull_attempt_at   TEXT NULL,
    last_pull_success_at   TEXT NULL,
    last_error             TEXT NULL,
    updated_at             TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
INSERT OR IGNORE INTO SyncEngineState (id, state) VALUES (1, 'WORKSPACE_UNAVAILABLE');

-- ------------------------------------------------------------
-- ActivityLogs
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ActivityLogs (
    log_id              INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id             INTEGER NULL,
    action_description  TEXT NOT NULL,
    ip_address          TEXT NULL,
    date_time           TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES Users(user_id) ON DELETE SET NULL
);

-- ============================================================
-- Seed data
-- ============================================================

INSERT OR IGNORE INTO Wards (ward_name, description) VALUES
    ('Ward 1', 'Dulivhadzimu area'),
    ('Ward 3', 'Beitbridge North area');

INSERT OR IGNORE INTO Houses (house_number, street, ward_id, owner_name, meter_number, address)
SELECT '142', 'North Road', ward_id, 'T. Moyo', 'MTR-0142', '142 Beitbridge North' FROM Wards WHERE ward_name = 'Ward 3'
UNION ALL
SELECT '087', 'Dulivhadzimu Street', ward_id, 'S. Ncube', 'MTR-0087', '87 Dulivhadzimu' FROM Wards WHERE ward_name = 'Ward 1'
UNION ALL
SELECT '215', 'North Road', ward_id, 'P. Dube', 'MTR-0215', '215 Beitbridge North' FROM Wards WHERE ward_name = 'Ward 3';

-- date_taken uses a fixed date, not CURRENT_DATE - the UNIQUE constraint
-- this OR IGNORE relies on includes date_taken, so a value that changes
-- with the day this file happens to run would defeat the "IGNORE" part
-- of "INSERT OR IGNORE" entirely: every fresh day would insert a whole
-- new set of "seed" rows alongside the previous day's, forever.
INSERT OR IGNORE INTO MeterReadings (house_id, previous_reading, current_reading, consumption, is_abnormal, reader_id, date_taken, client_ref)
SELECT house_id, 1240.00, 1240.00, 0, 0, NULL, '2026-08-16', 'seed-' || house_id
FROM Houses;