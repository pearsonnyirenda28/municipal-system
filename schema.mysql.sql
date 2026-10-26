-- ============================================================
-- Municipal Police and Meter Reading Management System
-- MySQL schema for the central Workspace Server (Version 4.5)
--
-- This is what makes MySQL the "centralized source of truth" the
-- architecture calls for - it needs to actually hold every table the
-- app knows about, not just the two that happened to need it first.
-- Import this into a fresh, empty database before running
-- migrate_to_mysql.php or pointing any field workstation's
-- CENTRAL_SERVER_URL at this server:
--   mysql -u root -p municipal_system < schema.mysql.sql
--
-- Table order follows foreign-key dependencies (a table only
-- references tables already created above it). LocalSyncQueue and
-- SyncEngineState from schema.sql are deliberately NOT here - those
-- are a field workstation's own outbox and engine-status tables
-- (see includes/local_store.php); a Workspace Server is the
-- destination of syncing, not a source, so it has no use for either.
-- ============================================================

SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- Users
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS Users (
    user_id        INT AUTO_INCREMENT PRIMARY KEY,
    username       VARCHAR(100) NOT NULL UNIQUE,
    password_hash  VARCHAR(255) NOT NULL,
    role           ENUM('Admin','Officer','MeterReader','Clerk','PoliceSupervisor','MeterSupervisor') NOT NULL,
    full_name      VARCHAR(150) NOT NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Wards
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS Wards (
    ward_id      INT AUTO_INCREMENT PRIMARY KEY,
    ward_name    VARCHAR(150) NOT NULL UNIQUE,
    description  TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Cases
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS Cases (
    case_id        INT AUTO_INCREMENT PRIMARY KEY,
    case_number    VARCHAR(50) NOT NULL UNIQUE,
    offender_name  VARCHAR(150) NOT NULL,
    id_number      VARCHAR(50) NULL,
    offence_type   VARCHAR(150) NOT NULL,
    description    TEXT NULL,
    status         ENUM('Open','In Progress','Closed') NOT NULL DEFAULT 'Open',
    officer_id     INT NULL,
    date_opened    DATE NOT NULL,
    FOREIGN KEY (officer_id) REFERENCES Users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- WardAssignments
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS WardAssignments (
    assignment_id  INT AUTO_INCREMENT PRIMARY KEY,
    ward_id        INT NOT NULL,
    user_id        INT NOT NULL,
    assigned_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ward_id) REFERENCES Wards(ward_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES Users(user_id) ON DELETE CASCADE,
    UNIQUE KEY uniq_ward_user (ward_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Houses
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS Houses (
    house_id       INT AUTO_INCREMENT PRIMARY KEY,
    house_number   VARCHAR(50) NOT NULL,
    street         VARCHAR(150) NULL,
    ward_id        INT NULL,
    owner_name     VARCHAR(150) NOT NULL,
    meter_number   VARCHAR(50) NOT NULL UNIQUE,
    address        VARCHAR(255) NULL,
    FOREIGN KEY (ward_id) REFERENCES Wards(ward_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Fines
--
-- case_id is deliberately left reachable by the sync pipeline as
-- always-NULL for now: api/save_fine.php never includes it in what it
-- pushes centrally, because Cases doesn't participate in sync yet (see
-- README) and sending a workstation's own local case_id as-is would
-- either point at the wrong case here or fail this FK outright. The
-- column, and the FK, stay in place for fines created directly at the
-- Workspace Server through the normal web UI.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS Fines (
    fine_id                INT AUTO_INCREMENT PRIMARY KEY,
    fine_number            VARCHAR(50) NOT NULL UNIQUE,
    case_id                INT NULL,
    offender_name          VARCHAR(150) NOT NULL,
    house_number           VARCHAR(50) NULL,
    vehicle_plate          VARCHAR(50) NULL,
    offence_type           VARCHAR(150) NOT NULL,
    amount                 DECIMAL(10,2) NOT NULL CHECK (amount > 0),
    payment_status         ENUM('Unpaid','Paid') NOT NULL DEFAULT 'Unpaid',
    issued_by              INT NULL,
    date_issued             DATETIME NOT NULL,
    client_ref               VARCHAR(64) NULL UNIQUE,
    is_duplicate_flagged     TINYINT(1) NOT NULL DEFAULT 0,
    duplicate_resolved_by    INT NULL,
    duplicate_resolved_at    DATETIME NULL,
    discarded_at              DATETIME NULL,
    discarded_by               INT NULL,
    discard_reason              VARCHAR(255) NULL,
    sync_id                     VARCHAR(64) NULL UNIQUE,
    device_id                   VARCHAR(100) NULL,
    sync_status                  ENUM('PENDING','SYNCING','SYNCED','SYNC_ERROR') NOT NULL DEFAULT 'SYNCED',
    synced_at                    DATETIME NULL,
    FOREIGN KEY (case_id) REFERENCES Cases(case_id) ON DELETE SET NULL,
    FOREIGN KEY (issued_by) REFERENCES Users(user_id) ON DELETE SET NULL,
    FOREIGN KEY (duplicate_resolved_by) REFERENCES Users(user_id) ON DELETE SET NULL,
    FOREIGN KEY (discarded_by) REFERENCES Users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Payments
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS Payments (
    payment_id       INT AUTO_INCREMENT PRIMARY KEY,
    fine_id          INT NOT NULL UNIQUE,
    amount_received  DECIMAL(10,2) NOT NULL,
    date_paid        DATETIME NOT NULL,
    received_by      INT NULL,
    receipt_number   VARCHAR(50) NOT NULL UNIQUE,
    FOREIGN KEY (fine_id) REFERENCES Fines(fine_id) ON DELETE CASCADE,
    FOREIGN KEY (received_by) REFERENCES Users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- MeterReadings
--
-- sync_id / device_id / sync_status / synced_at (Version 4.5): see
-- schema.sql's copy of this table for the full rationale. Here on the
-- central side, sync_status is always written as 'SYNCED' by
-- api/sync_push.php - there is no "pending" state once a row has
-- actually landed in the source of truth.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS MeterReadings (
    reading_id                INT AUTO_INCREMENT PRIMARY KEY,
    house_id                  INT NOT NULL,
    previous_reading          DECIMAL(10,2) NOT NULL,
    current_reading           DECIMAL(10,2) NOT NULL,
    consumption                DECIMAL(10,2) NOT NULL,
    is_abnormal                 TINYINT(1) NOT NULL DEFAULT 0,
    reader_id                   INT NULL,
    date_taken                  DATE NOT NULL,
    client_ref                  VARCHAR(64) NULL,
    is_duplicate_flagged        TINYINT(1) NOT NULL DEFAULT 0,
    duplicate_resolved_by       INT NULL,
    duplicate_resolved_at       DATETIME NULL,
    discarded_at                 DATETIME NULL,
    discarded_by                  INT NULL,
    discard_reason                 VARCHAR(255) NULL,
    sync_id                        VARCHAR(64) NULL UNIQUE,
    device_id                      VARCHAR(100) NULL,
    sync_status                     ENUM('PENDING','SYNCING','SYNCED','SYNC_ERROR') NOT NULL DEFAULT 'SYNCED',
    synced_at                       DATETIME NULL,
    FOREIGN KEY (house_id) REFERENCES Houses(house_id) ON DELETE CASCADE,
    FOREIGN KEY (reader_id) REFERENCES Users(user_id) ON DELETE SET NULL,
    FOREIGN KEY (duplicate_resolved_by) REFERENCES Users(user_id) ON DELETE SET NULL,
    FOREIGN KEY (discarded_by) REFERENCES Users(user_id) ON DELETE SET NULL,
    CHECK (current_reading >= previous_reading),
    UNIQUE KEY uniq_house_date_ref (house_id, date_taken, client_ref)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- DamagedMeters
-- Not yet offline-capable / not yet part of the sync pipeline (see
-- README) - kept here only so a Workspace Server used directly through
-- the normal web UI (DB_DRIVER=mysql) has the table at all.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS DamagedMeters (
    damage_id      INT AUTO_INCREMENT PRIMARY KEY,
    house_id       INT NOT NULL,
    description    TEXT NOT NULL,
    photo_path     VARCHAR(255) NULL,
    reported_by    INT NULL,
    date_reported  DATE NOT NULL,
    repair_status  ENUM('Pending','In Progress','Fixed') NOT NULL DEFAULT 'Pending',
    date_fixed     DATE NULL,
    FOREIGN KEY (house_id) REFERENCES Houses(house_id) ON DELETE CASCADE,
    FOREIGN KEY (reported_by) REFERENCES Users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- HouseVisits
-- updated_at auto-refreshes ON UPDATE - api/sync_push.php's UPSERT
-- path doesn't set it explicitly (unlike the SQLite side's
-- includes/db.php::upsertHouseVisit, which does), so MySQL handles it.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS HouseVisits (
    visit_id     INT AUTO_INCREMENT PRIMARY KEY,
    house_id     INT NOT NULL,
    reader_id    INT NOT NULL,
    visit_date   DATE NOT NULL,
    status       ENUM('Pending','Completed','Skipped','DamagedMeter','Vacant','Locked','IllegalConnection','RevisitRequired') NOT NULL DEFAULT 'Pending',
    reading_id   INT NULL,
    notes        TEXT NULL,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    sync_id      VARCHAR(64) NULL UNIQUE,
    device_id    VARCHAR(100) NULL,
    sync_status   ENUM('PENDING','SYNCING','SYNCED','SYNC_ERROR') NOT NULL DEFAULT 'SYNCED',
    synced_at    DATETIME NULL,
    FOREIGN KEY (house_id) REFERENCES Houses(house_id) ON DELETE CASCADE,
    FOREIGN KEY (reader_id) REFERENCES Users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (reading_id) REFERENCES MeterReadings(reading_id) ON DELETE SET NULL,
    UNIQUE KEY uniq_house_reader_date (house_id, reader_id, visit_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- ActivityLogs
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ActivityLogs (
    log_id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id             INT NULL,
    action_description  TEXT NOT NULL,
    ip_address          VARCHAR(45) NULL,
    date_time           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES Users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
