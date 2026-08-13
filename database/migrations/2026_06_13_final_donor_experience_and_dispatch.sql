-- Final DonorConnect ENBTS polish migration.
-- Run this once in phpMyAdmin after the OTP/admin donor registration migration.

USE donorconnect;

-- Allow staff-created donors to start with no password, then create one after OTP login.
ALTER TABLE users
    MODIFY password_hash VARCHAR(255) NULL;

SET @password_status_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'password_status'
);
SET @password_status_sql := IF(
    @password_status_exists = 0,
    "ALTER TABLE users ADD COLUMN password_status ENUM('unset','set') NOT NULL DEFAULT 'set' AFTER password_hash",
    "SELECT 'password_status already exists' AS info"
);
PREPARE password_status_stmt FROM @password_status_sql;
EXECUTE password_status_stmt;
DEALLOCATE PREPARE password_status_stmt;

UPDATE users
SET password_status = CASE
    WHEN role = 'donor' AND (password_hash IS NULL OR password_hash = '') THEN 'unset'
    ELSE 'set'
END;

-- Add donor engagement categories used by admin retention/birthday/impact messaging.
ALTER TABLE notifications
    MODIFY notification_type ENUM(
        'blood_request',
        'eligibility_reminder',
        'donation_reminder',
        'thank_you',
        'milestone',
        'campaign',
        'impact_update',
        'retention',
        'birthday',
        'account',
        'general'
    ) NOT NULL DEFAULT 'general';

-- Make sure donor appointment requests can be created by donors and reviewed later.
CREATE TABLE IF NOT EXISTS appointment_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    requested_by BIGINT UNSIGNED NOT NULL,
    institution_id BIGINT UNSIGNED NOT NULL,
    donor_id BIGINT UNSIGNED NULL,
    title VARCHAR(200) NOT NULL,
    appointment_at DATETIME NOT NULL,
    reason TEXT NULL,
    status ENUM('pending','approved','rejected','notified','completed','cancelled') NOT NULL DEFAULT 'pending',
    reviewed_by BIGINT UNSIGNED NULL,
    review_notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_appt_user FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_appt_bank FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_appt_donor FOREIGN KEY (donor_id) REFERENCES donor_profiles(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_appt_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_appt_bank_status (institution_id, status),
    INDEX idx_appt_donor_time (donor_id, appointment_at)
) ENGINE=InnoDB;
