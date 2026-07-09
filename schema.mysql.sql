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
-- Houses
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS Houses (
    house_id       INT AUTO_INCREMENT PRIMARY KEY,
    house_number   VARCHAR(15) NOT NULL,
    ward           VARCHAR(10) NOT NULL,
    owner_name     VARCHAR(60) NOT NULL,
    meter_number   VARCHAR(20) NOT NULL UNIQUE,
    address        VARCHAR(100) NULL
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

INSERT IGNORE INTO Houses (house_number, ward, owner_name, meter_number, address) VALUES
    ('142', 'Ward 3', 'T. Moyo',   'MTR-0142', '142 Beitbridge North'),
    ('087', 'Ward 1', 'S. Ncube',  'MTR-0087', '87 Dulivhadzimu'),
    ('215', 'Ward 3', 'P. Dube',   'MTR-0215', '215 Beitbridge North');
