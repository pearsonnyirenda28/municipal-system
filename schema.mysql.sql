-- ------------------------------------------------------------
-- Fines
-- ------------------------------------------------------------
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

-- ------------------------------------------------------------
-- MeterReadings
-- ------------------------------------------------------------
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