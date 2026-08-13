-- Migration: add_profile_update_requests
-- Run this once in phpMyAdmin or via MySQL CLI after pulling the latest code.

USE donorconnect;

CREATE TABLE IF NOT EXISTS profile_update_requests (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    donor_id     BIGINT UNSIGNED NOT NULL,          -- donor_profiles.id
    user_id      BIGINT UNSIGNED NOT NULL,          -- users.id (the donor's user account)
    field        VARCHAR(80) NOT NULL,              -- e.g. 'phone', 'emergency_contact_name'
    new_value    VARCHAR(255) NOT NULL,             -- requested new value
    reason       TEXT NULL,                         -- optional explanation
    status       ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    reviewed_by  BIGINT UNSIGNED NULL,              -- staff/admin user id
    review_notes VARCHAR(255) NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_pur_donor    FOREIGN KEY (donor_id)   REFERENCES donor_profiles(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_pur_user     FOREIGN KEY (user_id)    REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_pur_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_pur_donor_status (donor_id, status),
    INDEX idx_pur_status_created (status, created_at)
) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
