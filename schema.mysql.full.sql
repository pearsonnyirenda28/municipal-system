-- ============================================================
-- Municipal Police and Meter Reading Management System
-- COMPLETE MySQL / MariaDB schema (all 11 tables)
--
-- HOW TO USE (phpMyAdmin):
--   1. Open the "SQL" tab at SERVER level (not inside a database) and
--      run this whole file, OR import it from the Import tab.
--   2. The first two statements create and SELECT the database, so you
--      will not get "#1046 No database selected".
--   3. Do NOT import schema.sql into MySQL - that file is SQLite only
--      (it contains PRAGMA, which MySQL rejects).
-- ============================================================

CREATE DATABASE IF NOT EXISTS municipal_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE municipal_system;

CREATE TABLE IF NOT EXISTS Users (
    user_id        INT AUTO_INCREMENT PRIMARY KEY,
    username       VARCHAR(50)  NOT NULL UNIQUE,
    password_hash  VARCHAR(255) NOT NULL,
    role           ENUM('Admin','Officer','MeterReader','Clerk','PoliceSupervisor','MeterSupervisor') NOT NULL,
    full_name      VARCHAR(100) NOT NULL,
    is_active      TINYINT(1)   NOT NULL DEFAULT 1,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS Wards (
    ward_id      INT AUTO_INCREMENT PRIMARY KEY,
    ward_name    VARCHAR(50)  NOT NULL UNIQUE,
    description  VARCHAR(200) NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS Cases (
    case_id        INT AUTO_INCREMENT PRIMARY KEY,
    case_number    VARCHAR(20) NOT NULL UNIQUE,
    offender_name  VARCHAR(60) NOT NULL,
    id_number      VARCHAR(20) NULL,
    offence_type   VARCHAR(50) NOT NULL,
    description    TEXT NULL,
    status         ENUM('Open','In Progress','Closed') NOT NULL DEFAULT 'Open',
    officer_id     INT NULL,
    date_opened    DATETIME NOT NULL,
    FOREIGN KEY (officer_id) REFERENCES Users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS Fines (
    fine_id                 INT AUTO_INCREMENT PRIMARY KEY,
    fine_number             VARCHAR(20) NOT NULL UNIQUE,
    case_id                 INT NULL,
    offender_name           VARCHAR(60) NOT NULL,
    house_number            VARCHAR(15) NULL,
    vehicle_plate           VARCHAR(15) NULL,
    offence_type            VARCHAR(50) NOT NULL,
    amount                  DECIMAL(10,2) NOT NULL,
    payment_status          ENUM('Unpaid','Paid') NOT NULL DEFAULT 'Unpaid',
    issued_by               INT NULL,
    date_issued             DATETIME NOT NULL,
    client_ref              VARCHAR(40) NULL UNIQUE,
    is_duplicate_flagged    TINYINT(1) NOT NULL DEFAULT 0,
    duplicate_resolved_by   INT NULL,
    duplicate_resolved_at   DATETIME NULL,
    discarded_at            DATETIME NULL,
    discarded_by            INT NULL,
    discard_reason          VARCHAR(200) NULL,
    FOREIGN KEY (case_id) REFERENCES Cases(case_id) ON DELETE SET NULL,
    FOREIGN KEY (issued_by) REFERENCES Users(user_id) ON DELETE SET NULL,
    FOREIGN KEY (duplicate_resolved_by) REFERENCES Users(user_id) ON DELETE SET NULL,
    FOREIGN KEY (discarded_by) REFERENCES Users(user_id) ON DELETE SET NULL,
    CONSTRAINT chk_fine_amount CHECK (amount > 0)
) ENGINE=InnoDB;

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

CREATE TABLE IF NOT EXISTS WardAssignments (
    assignment_id  INT AUTO_INCREMENT PRIMARY KEY,
    ward_id        INT NOT NULL,
    user_id        INT NOT NULL,
    assigned_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ward_id) REFERENCES Wards(ward_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES Users(user_id) ON DELETE CASCADE,
    UNIQUE KEY uniq_ward_user (ward_id, user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS Houses (
    house_id       INT AUTO_INCREMENT PRIMARY KEY,
    house_number   VARCHAR(15)  NOT NULL,
    street         VARCHAR(80)  NULL,
    ward_id        INT NULL,
    owner_name     VARCHAR(80)  NOT NULL,
    meter_number   VARCHAR(20)  NOT NULL UNIQUE,
    address        VARCHAR(150) NULL,
    FOREIGN KEY (ward_id) REFERENCES Wards(ward_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS MeterReadings (
    reading_id              INT AUTO_INCREMENT PRIMARY KEY,
    house_id                INT NOT NULL,
    previous_reading        DECIMAL(10,2) NOT NULL,
    current_reading         DECIMAL(10,2) NOT NULL,
    consumption             DECIMAL(10,2) NOT NULL,
    is_abnormal             BOOLEAN NOT NULL DEFAULT FALSE,
    reader_id               INT NULL,
    date_taken              DATE NOT NULL,
    client_ref              VARCHAR(40) NULL,
    is_duplicate_flagged    TINYINT(1) NOT NULL DEFAULT 0,
    duplicate_resolved_by   INT NULL,
    duplicate_resolved_at   DATETIME NULL,
    discarded_at            DATETIME NULL,
    discarded_by            INT NULL,
    discard_reason          VARCHAR(200) NULL,
    FOREIGN KEY (house_id) REFERENCES Houses(house_id) ON DELETE CASCADE,
    FOREIGN KEY (reader_id) REFERENCES Users(user_id) ON DELETE SET NULL,
    FOREIGN KEY (duplicate_resolved_by) REFERENCES Users(user_id) ON DELETE SET NULL,
    FOREIGN KEY (discarded_by) REFERENCES Users(user_id) ON DELETE SET NULL,
    CONSTRAINT chk_reading_order CHECK (current_reading >= previous_reading),
    UNIQUE KEY uniq_house_date (house_id, date_taken, client_ref)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS DamagedMeters (
    damage_id      INT AUTO_INCREMENT PRIMARY KEY,
    house_id       INT NOT NULL,
    description    TEXT NOT NULL,
    photo_path     VARCHAR(255) NULL,
    reported_by    INT NULL,
    date_reported  DATETIME NOT NULL,
    repair_status  ENUM('Pending','In Progress','Fixed') NOT NULL DEFAULT 'Pending',
    date_fixed     DATETIME NULL,
    FOREIGN KEY (house_id) REFERENCES Houses(house_id) ON DELETE CASCADE,
    FOREIGN KEY (reported_by) REFERENCES Users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS HouseVisits (
    visit_id     INT AUTO_INCREMENT PRIMARY KEY,
    house_id     INT NOT NULL,
    reader_id    INT NOT NULL,
    visit_date   DATE NOT NULL,
    status       ENUM('Pending','Completed','Skipped','DamagedMeter','Vacant',
                      'Locked','IllegalConnection','RevisitRequired') NOT NULL DEFAULT 'Pending',
    reading_id   INT NULL,
    notes        TEXT NULL,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (house_id) REFERENCES Houses(house_id) ON DELETE CASCADE,
    FOREIGN KEY (reader_id) REFERENCES Users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (reading_id) REFERENCES MeterReadings(reading_id) ON DELETE SET NULL,
    UNIQUE KEY uniq_visit (house_id, reader_id, visit_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ActivityLogs (
    log_id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id             INT NULL,
    action_description  VARCHAR(500) NOT NULL,
    ip_address          VARCHAR(45) NULL,
    date_time           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES Users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- App user for the PHP code (run AFTER the tables exist).
-- Replace CHOOSE_A_STRONG_PASSWORD, and put the SAME value in .env as DB_PASS.
-- Least privilege: the app does not need GRANT ALL.
-- ------------------------------------------------------------
-- CREATE USER 'sync_app'@'localhost' IDENTIFIED BY 'CHOOSE_A_STRONG_PASSWORD';
-- GRANT SELECT, INSERT, UPDATE, DELETE ON municipal_system.* TO 'sync_app'@'localhost';
-- FLUSH PRIVILEGES;
