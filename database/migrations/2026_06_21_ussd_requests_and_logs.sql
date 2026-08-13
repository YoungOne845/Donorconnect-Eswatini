-- Migration: Add ussd_logs and ussd_requests tables
-- Run this against the donorconnect database.

USE donorconnect;

CREATE TABLE IF NOT EXISTS ussd_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id VARCHAR(120) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    input_text VARCHAR(255) NOT NULL,
    response_text TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ussd_logs_phone (phone),
    INDEX idx_ussd_logs_session (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ussd_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    phone VARCHAR(20) NOT NULL,
    request_type ENUM('registration_request', 'callback_request') NOT NULL,
    status ENUM('pending', 'resolved') DEFAULT 'pending' NOT NULL,
    notes VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ussd_requests_phone (phone),
    INDEX idx_ussd_requests_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
