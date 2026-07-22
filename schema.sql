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
    is_active      INTEGER NOT NULL DEFAULT 1,
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
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS Fines (
    fine_id         INTEGER PRIMARY KEY AUTOINCREMENT,
    fine_number     TEXT NOT NULL UNIQUE,
    case_id         INTEGER NULL,
    offender_name   TEXT NOT NULL,
    house_number    TEXT NULL,
    vehicle_plate   TEXT NULL,
    offence_type    TEXT NOT NULL,
    amount          NUMERIC NOT NULL CHECK (amount > 0),
    payment_status  TEXT NOT NULL DEFAULT 'Unpaid' CHECK (payment_status IN ('Unpaid','Paid')),
    issued_by       INTEGER NULL,
    date_issued     TEXT NOT NULL,
    FOREIGN KEY (case_id) REFERENCES Cases(case_id) ON DELETE SET NULL,
    FOREIGN KEY (issued_by) REFERENCES Users(user_id) ON DELETE SET NULL
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
-- Version 2.5: wards are now a real managed entity, not a free-text
-- field on Houses.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS Wards (
    ward_id      INTEGER PRIMARY KEY AUTOINCREMENT,
    ward_name    TEXT NOT NULL UNIQUE,
    description  TEXT NULL
);

-- ------------------------------------------------------------
-- WardAssignments
-- Many-to-many: a meter reader can cover more than one ward, and a
-- ward can have more than one reader assigned to it.
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
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS MeterReadings (
    reading_id        INTEGER PRIMARY KEY AUTOINCREMENT,
    house_id          INTEGER NOT NULL,
    previous_reading  NUMERIC NOT NULL,
    current_reading   NUMERIC NOT NULL,
    consumption       NUMERIC NOT NULL,
    is_abnormal       INTEGER NOT NULL DEFAULT 0,
    reader_id         INTEGER NULL,
    date_taken        TEXT NOT NULL,
    client_ref        TEXT NULL,
    is_duplicate_flagged INTEGER NOT NULL DEFAULT 0,
    duplicate_resolved_by INTEGER NULL,
    duplicate_resolved_at TEXT NULL,
    FOREIGN KEY (house_id) REFERENCES Houses(house_id) ON DELETE CASCADE,
    FOREIGN KEY (reader_id) REFERENCES Users(user_id) ON DELETE SET NULL,
    FOREIGN KEY (duplicate_resolved_by) REFERENCES Users(user_id) ON DELETE SET NULL,
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
-- Version 3.0 - Guided Field Operations. One row per house per reader
-- per day, tracking where that house stands in today's round -
-- independent of whether a meter reading was actually captured (a
-- locked gate or a vacant plot is still a completed *visit*, just not
-- a completed *reading*).
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
    FOREIGN KEY (house_id) REFERENCES Houses(house_id) ON DELETE CASCADE,
    FOREIGN KEY (reader_id) REFERENCES Users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (reading_id) REFERENCES MeterReadings(reading_id) ON DELETE SET NULL,
    UNIQUE (house_id, reader_id, visit_date)
);

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
-- Indexes (Improvement 15: Database Performance)
-- SQLite does NOT automatically index foreign key columns (unlike
-- InnoDB, which creates one implicitly for every FK) - these cover the
-- FK columns plus the other columns list/report pages filter or sort
-- by most often (see reports/*.php, */list.php).
-- ============================================================
CREATE INDEX IF NOT EXISTS idx_cases_status        ON Cases(status);
CREATE INDEX IF NOT EXISTS idx_cases_officer        ON Cases(officer_id);
CREATE INDEX IF NOT EXISTS idx_cases_date_opened    ON Cases(date_opened);

CREATE INDEX IF NOT EXISTS idx_fines_status         ON Fines(payment_status);
CREATE INDEX IF NOT EXISTS idx_fines_date_issued    ON Fines(date_issued);
CREATE INDEX IF NOT EXISTS idx_fines_case           ON Fines(case_id);

CREATE INDEX IF NOT EXISTS idx_payments_fine        ON Payments(fine_id);

CREATE INDEX IF NOT EXISTS idx_wardassign_ward      ON WardAssignments(ward_id);
CREATE INDEX IF NOT EXISTS idx_wardassign_user      ON WardAssignments(user_id);

CREATE INDEX IF NOT EXISTS idx_houses_ward          ON Houses(ward_id);
CREATE INDEX IF NOT EXISTS idx_houses_number        ON Houses(house_number);

CREATE INDEX IF NOT EXISTS idx_readings_house       ON MeterReadings(house_id);
CREATE INDEX IF NOT EXISTS idx_readings_date        ON MeterReadings(date_taken);
CREATE INDEX IF NOT EXISTS idx_readings_reader      ON MeterReadings(reader_id);
CREATE INDEX IF NOT EXISTS idx_readings_abnormal    ON MeterReadings(is_abnormal);
CREATE INDEX IF NOT EXISTS idx_readings_dupflag     ON MeterReadings(is_duplicate_flagged);

CREATE INDEX IF NOT EXISTS idx_damaged_house        ON DamagedMeters(house_id);
CREATE INDEX IF NOT EXISTS idx_damaged_status       ON DamagedMeters(repair_status);
CREATE INDEX IF NOT EXISTS idx_damaged_reporter     ON DamagedMeters(reported_by);

CREATE INDEX IF NOT EXISTS idx_visits_house         ON HouseVisits(house_id);
CREATE INDEX IF NOT EXISTS idx_visits_reader         ON HouseVisits(reader_id);
CREATE INDEX IF NOT EXISTS idx_visits_date          ON HouseVisits(visit_date);

CREATE INDEX IF NOT EXISTS idx_activitylogs_user    ON ActivityLogs(user_id);
CREATE INDEX IF NOT EXISTS idx_activitylogs_time    ON ActivityLogs(date_time);

CREATE INDEX IF NOT EXISTS idx_users_role           ON Users(role);
CREATE INDEX IF NOT EXISTS idx_users_active         ON Users(is_active);

-- ============================================================
-- Seed data - sample wards and houses so Wards, Houses and Meter
-- Readings all have something to work with out of the box. No Users
-- are seeded here; run setup_admin.php once in the browser to create
-- the first Administrator account (see README.md).
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

-- Give the sample houses a starting reading so consumption can be
-- calculated the first time each is read through the system.
INSERT OR IGNORE INTO MeterReadings (house_id, previous_reading, current_reading, consumption, is_abnormal, reader_id, date_taken, client_ref)
SELECT house_id, 1240.00, 1240.00, 0, 0, NULL, CURRENT_DATE, 'seed-' || house_id
FROM Houses;
