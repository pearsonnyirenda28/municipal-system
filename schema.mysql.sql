-- ============================================================
-- Municipal Police and Meter Reading Management System
-- MySQL database schema (production deployment)
--
-- This is the MySQL equivalent of schema.sql (which is SQLite, used for
-- local development). Import this into MySQL - via phpMyAdmin's Import
-- tab, or `mysql -u root -p municipal_system < schema.mysql.sql` - then
-- use migrate_to_mysql.php to copy across anything you already captured
-- in SQLite during development. See README.md, "Moving to MySQL for
-- production".
-- ============================================================

CREATE DATABASE IF NOT EXISTS municipal_system
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE municipal_system;

-- ------------------------------------------------------------
-- Users
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS Users (
    user_id        INT AUTO_INCREMENT PRIMARY KEY,
    username       VARCHAR(30) NOT NULL UNIQUE,
    password_hash  VARCHAR(255) NOT NULL,
    role           ENUM('Admin','Officer','MeterReader','Clerk','PoliceSupervisor','MeterSupervisor') NOT NULL,
    full_name      VARCHAR(60) NOT NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Cases
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS Cases (
    case_id        INT AUTO_INCREMENT PRIMARY KEY,
    case_number    VARCHAR(20) NOT NULL UNIQUE,
    offender_name  VARCHAR(60) NOT NULL,
    id_number      VARCHAR(20) NULL,
    offence_type   VARCHAR(50) NOT NULL,
    description    TEXT NULL,
    status         ENUM('Open','In Progress','Closed') NOT NULL DEFAULT 'Open',
    officer_id     INT NULL,
    date_opened    DATE NOT NULL,
    FOREIGN KEY (officer_id) REFERENCES Users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Fines
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS Fines (
    fine_id         INT AUTO_INCREMENT PRIMARY KEY,
    fine_number     VARCHAR(20) NOT NULL UNIQUE,
    case_id         INT NULL,
    offender_name   VARCHAR(60) NOT NULL,
    house_number    VARCHAR(15) NULL,
    vehicle_plate   VARCHAR(15) NULL,
    offence_type    VARCHAR(50) NOT NULL,
    amount          DECIMAL(10,2) NOT NULL,
    payment_status  ENUM('Unpaid','Paid') NOT NULL DEFAULT 'Unpaid',
    issued_by       INT NULL,
    date_issued     DATETIME NOT NULL,
    client_ref      VARCHAR(40) NULL UNIQUE,
    FOREIGN KEY (case_id) REFERENCES Cases(case_id) ON DELETE SET NULL,
    FOREIGN KEY (issued_by) REFERENCES Users(user_id) ON DELETE SET NULL,
    CONSTRAINT chk_fine_amount CHECK (amount > 0)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Payments
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS Payments (
    payment_id       INT AUTO_INCREMENT PRIMARY KEY,
    fine_id          INT NOT NULL UNIQUE,
    amount_received  DECIMAL(10,2) NOT NULL,
    date_paid        DATETIME NOT NULL,
    received_by      INT NULL,
    receipt_number   VARCHAR(20) NOT NULL UNIQUE,
    FOREIGN KEY (fine_id) REFERENCES Fines(fine_id) ON DELETE CASCADE,
    FOREIGN KEY (received_by) REFERENCES Users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Wards
-- Version 2.5: wards are now a real managed entity, not a free-text
-- field on Houses.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS Wards (
    ward_id      INT AUTO_INCREMENT PRIMARY KEY,
    ward_name    VARCHAR(30) NOT NULL UNIQUE,
    description  VARCHAR(150) NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- WardAssignments
-- Many-to-many: a meter reader can cover more than one ward, and a
-- ward can have more than one reader assigned to it.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS WardAssignments (
    assignment_id  INT AUTO_INCREMENT PRIMARY KEY,
    ward_id        INT NOT NULL,
    user_id        INT NOT NULL,
    assigned_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ward_id) REFERENCES Wards(ward_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES Users(user_id) ON DELETE CASCADE,
    UNIQUE KEY uniq_ward_user (ward_id, user_id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Houses
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS Houses (
    house_id       INT AUTO_INCREMENT PRIMARY KEY,
    house_number   VARCHAR(15) NOT NULL,
    street         VARCHAR(80) NULL,
    ward_id        INT NULL,
    owner_name     VARCHAR(60) NOT NULL,
    meter_number   VARCHAR(20) NOT NULL UNIQUE,
    address        VARCHAR(100) NULL,
    FOREIGN KEY (ward_id) REFERENCES Wards(ward_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- MeterReadings
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS MeterReadings (
    reading_id        INT AUTO_INCREMENT PRIMARY KEY,
    house_id          INT NOT NULL,
    previous_reading  DECIMAL(10,2) NOT NULL,
    current_reading   DECIMAL(10,2) NOT NULL,
    consumption       DECIMAL(10,2) NOT NULL,
    is_abnormal       BOOLEAN NOT NULL DEFAULT FALSE,
    reader_id         INT NULL,
    date_taken        DATE NOT NULL,
    client_ref        VARCHAR(40) NULL,
    FOREIGN KEY (house_id) REFERENCES Houses(house_id) ON DELETE CASCADE,
    FOREIGN KEY (reader_id) REFERENCES Users(user_id) ON DELETE SET NULL,
    CONSTRAINT chk_reading_order CHECK (current_reading >= previous_reading),
    UNIQUE KEY uniq_house_date (house_id, date_taken, client_ref)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- DamagedMeters
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS DamagedMeters (
    damage_id      INT AUTO_INCREMENT PRIMARY KEY,
    house_id       INT NOT NULL,
    description    VARCHAR(200) NOT NULL,
    photo_path     VARCHAR(150) NULL,
    reported_by    INT NULL,
    date_reported  DATE NOT NULL,
    repair_status  ENUM('Pending','In Progress','Fixed') NOT NULL DEFAULT 'Pending',
    date_fixed     DATE NULL,
    FOREIGN KEY (house_id) REFERENCES Houses(house_id) ON DELETE CASCADE,
    FOREIGN KEY (reported_by) REFERENCES Users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- HouseVisits
-- Version 3.0 - Guided Field Operations. One row per house per reader
-- per day, tracking where that house stands in today's round.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS HouseVisits (
    visit_id     INT AUTO_INCREMENT PRIMARY KEY,
    house_id     INT NOT NULL,
    reader_id    INT NOT NULL,
    visit_date   DATE NOT NULL,
    status       ENUM('Pending','Completed','Skipped','DamagedMeter','Vacant',
                       'Locked','IllegalConnection','RevisitRequired') NOT NULL DEFAULT 'Pending',
    reading_id   INT NULL,
    notes        VARCHAR(200) NULL,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (house_id) REFERENCES Houses(house_id) ON DELETE CASCADE,
    FOREIGN KEY (reader_id) REFERENCES Users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (reading_id) REFERENCES MeterReadings(reading_id) ON DELETE SET NULL,
    UNIQUE KEY uniq_house_reader_date (house_id, reader_id, visit_date)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- ActivityLogs
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ActivityLogs (
    log_id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id             INT NULL,
    action_description  VARCHAR(150) NOT NULL,
    ip_address          VARCHAR(45) NULL,
    date_time           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES Users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- Seed data - only useful if you are setting up MySQL from scratch
-- without ever having used SQLite. If you're migrating existing SQLite
-- data with migrate_to_mysql.php, skip this - the real houses will come
-- across with it, and inserting these first would create duplicates.
-- ============================================================

INSERT IGNORE INTO Wards (ward_name, description) VALUES
    ('Ward 1', 'Dulivhadzimu area'),
    ('Ward 3', 'Beitbridge North area');

INSERT IGNORE INTO Houses (house_number, street, ward_id, owner_name, meter_number, address)
SELECT '142', 'North Road', ward_id, 'T. Moyo', 'MTR-0142', '142 Beitbridge North' FROM Wards WHERE ward_name = 'Ward 3'
UNION ALL
SELECT '087', 'Dulivhadzimu Street', ward_id, 'S. Ncube', 'MTR-0087', '87 Dulivhadzimu' FROM Wards WHERE ward_name = 'Ward 1'
UNION ALL
SELECT '215', 'North Road', ward_id, 'P. Dube', 'MTR-0215', '215 Beitbridge North' FROM Wards WHERE ward_name = 'Ward 3';
