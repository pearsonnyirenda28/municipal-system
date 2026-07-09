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
-- Houses
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS Houses (
    house_id       INTEGER PRIMARY KEY AUTOINCREMENT,
    house_number   TEXT NOT NULL,
    ward           TEXT NOT NULL,
    owner_name     TEXT NOT NULL,
    meter_number   TEXT NOT NULL UNIQUE,
    address        TEXT NULL
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
    FOREIGN KEY (house_id) REFERENCES Houses(house_id) ON DELETE CASCADE,
    FOREIGN KEY (reader_id) REFERENCES Users(user_id) ON DELETE SET NULL,
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
-- Seed data - sample houses so Meter Readings has something to pick
-- from. No Users are seeded here; run setup_admin.php once in the
-- browser to create the first Administrator account (see README.md).
-- ============================================================

INSERT OR IGNORE INTO Houses (house_number, ward, owner_name, meter_number, address) VALUES
    ('142', 'Ward 3', 'T. Moyo',   'MTR-0142', '142 Beitbridge North'),
    ('087', 'Ward 1', 'S. Ncube',  'MTR-0087', '87 Dulivhadzimu'),
    ('215', 'Ward 3', 'P. Dube',   'MTR-0215', '215 Beitbridge North');

-- Give the sample houses a starting reading so consumption can be
-- calculated the first time each is read through the system.
INSERT OR IGNORE INTO MeterReadings (house_id, previous_reading, current_reading, consumption, is_abnormal, reader_id, date_taken, client_ref)
SELECT house_id, 1240.00, 1240.00, 0, 0, NULL, CURRENT_DATE, 'seed-' || house_id
FROM Houses;
