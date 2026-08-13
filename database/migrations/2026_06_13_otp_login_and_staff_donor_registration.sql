-- Adds donor OTP login support for existing DonorConnect ENBTS databases.
-- Run this in phpMyAdmin if you already imported the schema before this update.

USE donorconnect;

CREATE TABLE IF NOT EXISTS login_otps (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    national_id_hash CHAR(64) NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME NULL,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    request_ip VARCHAR(64) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_login_otp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_login_otp_lookup (national_id_hash, consumed_at, expires_at),
    INDEX idx_login_otp_user_created (user_id, created_at)
) ENGINE=InnoDB;
