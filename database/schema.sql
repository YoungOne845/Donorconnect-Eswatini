-- FRESH INSTALL: this recreates the DonorConnect database. Back up existing data first.
DROP DATABASE IF EXISTS donorconnect;
CREATE DATABASE donorconnect
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE donorconnect;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP VIEW IF EXISTS request_performance_view;
DROP VIEW IF EXISTS donor_summary_view;

CREATE TABLE IF NOT EXISTS institutions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(180) NOT NULL,
    institution_type ENUM('hospital','blood_service','school','university','church','workplace','community_organisation','other') NOT NULL,
    phone VARCHAR(30) NULL,
    email VARCHAR(180) NULL,
    region ENUM('Hhohho','Manzini','Lubombo','Shiselweni') NOT NULL,
    town VARCHAR(120) NOT NULL,
    address TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_institution_type (institution_type),
    INDEX idx_institution_location (region, town),
    INDEX idx_institution_active (is_active)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    institution_id BIGINT UNSIGNED NULL,
    full_name VARCHAR(180) NOT NULL,
    national_id_encrypted TEXT NOT NULL,
    national_id_hash CHAR(64) NOT NULL,
    national_id_last_four CHAR(4) NOT NULL,
    email VARCHAR(180) NULL,
    phone VARCHAR(20) NOT NULL,
    phone_secondary VARCHAR(20) NULL,
    password_hash VARCHAR(255) NULL,
    password_status ENUM('unset','set') NOT NULL DEFAULT 'set',
    role ENUM('donor','hospital','staff','admin') NOT NULL DEFAULT 'donor',
    account_status ENUM('active','inactive','suspended','pending') NOT NULL DEFAULT 'active',
    email_verified_at DATETIME NULL,
    phone_verified_at DATETIME NULL,
    failed_login_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until DATETIME NULL,
    last_login_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_users_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE SET NULL ON UPDATE CASCADE,
    UNIQUE KEY uq_users_email (email),
    UNIQUE KEY uq_users_phone (phone),
    UNIQUE KEY uq_users_national_id_hash (national_id_hash),
    INDEX idx_users_role_status (role, account_status),
    INDEX idx_users_institution (institution_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS donor_profiles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    donor_code VARCHAR(32) NOT NULL,
    blood_type ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-','Unknown') NOT NULL DEFAULT 'Unknown',
    blood_type_verified_at DATETIME NULL,
    date_of_birth DATE NOT NULL,
    gender ENUM('male','female','other','prefer_not_to_say') NOT NULL,
    region ENUM('Hhohho','Manzini','Lubombo','Shiselweni') NOT NULL,
    town VARCHAR(120) NOT NULL,
    address TEXT NULL,
    availability_status ENUM('available','not_available') NOT NULL DEFAULT 'available',
    verification_status ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
    eligibility_status ENUM('eligible','temporarily_deferred','permanently_deferred','not_assessed') NOT NULL DEFAULT 'not_assessed',
    last_donation_date DATE NULL,
    next_eligible_date DATE NULL,
    eligibility_days INT UNSIGNED NOT NULL DEFAULT 90,
    total_donations INT UNSIGNED NOT NULL DEFAULT 0,
    preferred_contact_method ENUM('sms','phone','email','web') NOT NULL DEFAULT 'sms',
    recruitment_source ENUM('school','university','church','workplace','community_campaign','hospital','social_media','referral','walk_in','other') NOT NULL,
    recruitment_institution_id BIGINT UNSIGNED NULL,
    recruitment_campaign_id BIGINT UNSIGNED NULL,
    referral_code VARCHAR(50) NULL,
    emergency_contact_name VARCHAR(180) NULL,
    emergency_contact_phone VARCHAR(20) NULL,
    consent_to_notifications TINYINT(1) NOT NULL DEFAULT 1,
    profile_completion_score TINYINT UNSIGNED NOT NULL DEFAULT 60,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_donor_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_donor_recruitment_institution FOREIGN KEY (recruitment_institution_id) REFERENCES institutions(id) ON DELETE SET NULL ON UPDATE CASCADE,
    UNIQUE KEY uq_donor_user (user_id),
    UNIQUE KEY uq_donor_code (donor_code),
    INDEX idx_donor_matching (verification_status, eligibility_status, availability_status, blood_type),
    INDEX idx_donor_location (region, town),
    INDEX idx_donor_next_eligible (next_eligible_date),
    INDEX idx_donor_recruitment (recruitment_source, recruitment_institution_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS campaigns (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    institution_id BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT NOT NULL,
    campaign_type ENUM('recruitment','donation_drive','awareness','retention','emergency') NOT NULL DEFAULT 'recruitment',
    target_region ENUM('Hhohho','Manzini','Lubombo','Shiselweni') NULL,
    target_town VARCHAR(120) NULL,
    target_blood_type ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-','All') NOT NULL DEFAULT 'All',
    venue VARCHAR(200) NOT NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NULL,
    capacity INT UNSIGNED NULL,
    status ENUM('draft','scheduled','active','completed','cancelled') NOT NULL DEFAULT 'draft',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_campaign_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_campaign_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX idx_campaign_status_dates (status, starts_at),
    INDEX idx_campaign_location (target_region, target_town)
) ENGINE=InnoDB;

ALTER TABLE donor_profiles
    ADD CONSTRAINT fk_donor_recruitment_campaign FOREIGN KEY (recruitment_campaign_id) REFERENCES campaigns(id) ON DELETE SET NULL ON UPDATE CASCADE;

CREATE TABLE IF NOT EXISTS campaign_participants (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id BIGINT UNSIGNED NOT NULL,
    donor_id BIGINT UNSIGNED NOT NULL,
    participation_status ENUM('invited','interested','registered','attended','donated','declined','absent') NOT NULL DEFAULT 'interested',
    registered_at DATETIME NULL,
    attended_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_campaign_participant_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_campaign_participant_donor FOREIGN KEY (donor_id) REFERENCES donor_profiles(id) ON DELETE CASCADE ON UPDATE CASCADE,
    UNIQUE KEY uq_campaign_donor (campaign_id, donor_id),
    INDEX idx_campaign_participant_status (campaign_id, participation_status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS eligibility_assessments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    donor_id BIGINT UNSIGNED NOT NULL,
    assessed_by BIGINT UNSIGNED NOT NULL,
    assessment_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    outcome ENUM('eligible','temporarily_deferred','permanently_deferred') NOT NULL,
    next_eligible_date DATE NULL,
    reason VARCHAR(255) NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_assessment_donor FOREIGN KEY (donor_id) REFERENCES donor_profiles(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_assessment_staff FOREIGN KEY (assessed_by) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX idx_assessment_donor_date (donor_id, assessment_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS donor_deferrals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    donor_id BIGINT UNSIGNED NOT NULL,
    recorded_by BIGINT UNSIGNED NOT NULL,
    deferral_type ENUM('temporary','permanent') NOT NULL,
    reason VARCHAR(255) NOT NULL,
    starts_on DATE NOT NULL,
    ends_on DATE NULL,
    status ENUM('active','completed','cancelled') NOT NULL DEFAULT 'active',
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_deferral_donor FOREIGN KEY (donor_id) REFERENCES donor_profiles(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_deferral_staff FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX idx_deferral_active (donor_id, status, ends_on)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS donation_records (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    donor_id BIGINT UNSIGNED NOT NULL,
    institution_id BIGINT UNSIGNED NULL,
    campaign_id BIGINT UNSIGNED NULL,
    recorded_by BIGINT UNSIGNED NOT NULL,
    donation_date DATE NOT NULL,
    donation_type ENUM('whole_blood','plasma','platelets','other') NOT NULL DEFAULT 'whole_blood',
    units DECIMAL(5,2) NOT NULL DEFAULT 1.00,
    region ENUM('Hhohho','Manzini','Lubombo','Shiselweni') NOT NULL,
    town VARCHAR(120) NOT NULL,
    next_eligible_date DATE NOT NULL,
    screening_status ENUM('pending','passed','failed') NOT NULL DEFAULT 'passed',
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_donation_donor FOREIGN KEY (donor_id) REFERENCES donor_profiles(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_donation_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_donation_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_donation_staff FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX idx_donation_donor_date (donor_id, donation_date),
    INDEX idx_donation_campaign (campaign_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS blood_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_code VARCHAR(32) NOT NULL,
    hospital_id BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    blood_type_needed ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-') NOT NULL,
    units_required INT UNSIGNED NOT NULL,
    units_fulfilled INT UNSIGNED NOT NULL DEFAULT 0,
    urgency_level ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
    hospital_name VARCHAR(180) NOT NULL,
    region ENUM('Hhohho','Manzini','Lubombo','Shiselweni') NOT NULL,
    town VARCHAR(120) NOT NULL,
    needed_by DATETIME NULL,
    status ENUM('draft','active','partially_fulfilled','fulfilled','cancelled','expired') NOT NULL DEFAULT 'active',
    clinical_reference VARCHAR(100) NULL,
    description TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_request_hospital FOREIGN KEY (hospital_id) REFERENCES institutions(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_request_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    UNIQUE KEY uq_request_code (request_code),
    INDEX idx_request_status_urgency (status, urgency_level),
    INDEX idx_request_location_blood (region, town, blood_type_needed)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS request_matches (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id BIGINT UNSIGNED NOT NULL,
    donor_id BIGINT UNSIGNED NOT NULL,
    compatibility_score DECIMAL(5,2) NOT NULL,
    location_score DECIMAL(5,2) NOT NULL,
    availability_score DECIMAL(5,2) NOT NULL,
    eligibility_score DECIMAL(5,2) NOT NULL,
    responsiveness_score DECIMAL(5,2) NOT NULL,
    total_match_score DECIMAL(6,2) NOT NULL,
    notification_status ENUM('not_sent','sent','seen','failed') NOT NULL DEFAULT 'not_sent',
    donor_response ENUM('pending','accepted','declined','no_response') NOT NULL DEFAULT 'pending',
    response_message VARCHAR(255) NULL,
    notified_at DATETIME NULL,
    responded_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_match_request FOREIGN KEY (request_id) REFERENCES blood_requests(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_match_donor FOREIGN KEY (donor_id) REFERENCES donor_profiles(id) ON DELETE CASCADE ON UPDATE CASCADE,
    UNIQUE KEY uq_request_donor (request_id, donor_id),
    INDEX idx_match_request_score (request_id, total_match_score),
    INDEX idx_match_donor_response (donor_id, donor_response)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    request_id BIGINT UNSIGNED NULL,
    campaign_id BIGINT UNSIGNED NULL,
    notification_type ENUM('blood_request','eligibility_reminder','donation_reminder','thank_you','milestone','campaign','impact_update','retention','birthday','account','general') NOT NULL DEFAULT 'general',
    title VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    action_url VARCHAR(255) NULL,
    delivery_channel ENUM('web','sms','email') NOT NULL DEFAULT 'web',
    delivery_status ENUM('pending','sent','failed') NOT NULL DEFAULT 'sent',
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    sent_at DATETIME NULL,
    read_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notification_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_notification_request FOREIGN KEY (request_id) REFERENCES blood_requests(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_notification_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_notification_user_read (user_id, is_read, created_at)
) ENGINE=InnoDB;


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

CREATE TABLE IF NOT EXISTS sms_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    phone VARCHAR(20) NOT NULL,
    message TEXT NOT NULL,
    provider VARCHAR(50) NOT NULL,
    provider_message_id VARCHAR(120) NULL,
    status ENUM('queued','sent','failed') NOT NULL DEFAULT 'queued',
    error_message VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at DATETIME NULL,
    CONSTRAINT fk_sms_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_sms_status_created (status, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS donor_activity_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    donor_id BIGINT UNSIGNED NOT NULL,
    activity_type ENUM('registered','profile_updated','availability_updated','verified','eligibility_assessed','donation_recorded','deferred','eligibility_restored','notification_sent','notification_read','request_accepted','request_declined','campaign_joined','login','milestone_reached') NOT NULL,
    description VARCHAR(255) NOT NULL,
    metadata JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_donor_activity_donor FOREIGN KEY (donor_id) REFERENCES donor_profiles(id) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_donor_activity_date (donor_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(80) NULL,
    entity_id BIGINT UNSIGNED NULL,
    description VARCHAR(255) NOT NULL,
    old_values JSON NULL,
    new_values JSON NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_audit_entity (entity_type, entity_id),
    INDEX idx_audit_user_date (user_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS rate_limit_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    action_key VARCHAR(80) NOT NULL,
    identity_key VARCHAR(128) NOT NULL,
    occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rate_limit_lookup (action_key, identity_key, occurred_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
    UNIQUE KEY uq_reset_token_hash (token_hash),
    INDEX idx_reset_user_expiry (user_id, expires_at)
) ENGINE=InnoDB;



CREATE TABLE IF NOT EXISTS system_settings (
    `key` VARCHAR(120) PRIMARY KEY,
    `value` TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS blood_inventory (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    institution_id BIGINT UNSIGNED NOT NULL,
    blood_type ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-') NOT NULL,
    available_units INT UNSIGNED NOT NULL DEFAULT 0,
    reserved_units INT UNSIGNED NOT NULL DEFAULT 0,
    expired_units INT UNSIGNED NOT NULL DEFAULT 0,
    critical_threshold INT UNSIGNED NOT NULL DEFAULT 0,
    last_updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_inventory_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_inventory_updated_by FOREIGN KEY (last_updated_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
    UNIQUE KEY uq_inventory_bank_type (institution_id, blood_type),
    INDEX idx_inventory_blood_type (blood_type),
    INDEX idx_inventory_low_stock (available_units, critical_threshold)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS dispatch_assignments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id BIGINT UNSIGNED NOT NULL,
    assigned_bank_id BIGINT UNSIGNED NOT NULL,
    assigned_by BIGINT UNSIGNED NOT NULL,
    blood_type ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-') NOT NULL,
    units_assigned INT UNSIGNED NOT NULL,
    status ENUM('assigned','accepted','packed','in_transit','delivered','rejected','cancelled') NOT NULL DEFAULT 'assigned',
    dispatch_notes TEXT NULL,
    accepted_at DATETIME NULL,
    packed_at DATETIME NULL,
    in_transit_at DATETIME NULL,
    delivered_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_dispatch_request FOREIGN KEY (request_id) REFERENCES blood_requests(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_dispatch_bank FOREIGN KEY (assigned_bank_id) REFERENCES institutions(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_dispatch_assigned_by FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX idx_dispatch_bank_status (assigned_bank_id, status),
    INDEX idx_dispatch_request_status (request_id, status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS branch_campaign_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    requested_by BIGINT UNSIGNED NOT NULL,
    institution_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT NOT NULL,
    campaign_type ENUM('recruitment','donation_drive','awareness','retention','emergency') NOT NULL DEFAULT 'recruitment',
    target_region ENUM('Hhohho','Manzini','Lubombo','Shiselweni') NULL,
    target_town VARCHAR(120) NULL,
    target_blood_type ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-','All') NOT NULL DEFAULT 'All',
    venue VARCHAR(200) NOT NULL,
    starts_at DATETIME NOT NULL,
    capacity INT UNSIGNED NULL,
    status ENUM('pending','approved','rejected','converted') NOT NULL DEFAULT 'pending',
    reviewed_by BIGINT UNSIGNED NULL,
    review_notes TEXT NULL,
    campaign_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_branch_campaign_user FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_branch_campaign_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_branch_campaign_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_branch_campaign_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_branch_campaign_status (status, created_at),
    INDEX idx_branch_campaign_bank (institution_id, status)
) ENGINE=InnoDB;

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

CREATE OR REPLACE VIEW donor_summary_view AS
SELECT
    dp.id AS donor_id,
    dp.donor_code,
    u.full_name,
    u.phone,
    u.email,
    u.account_status,
    dp.blood_type,
    dp.region,
    dp.town,
    dp.availability_status,
    dp.verification_status,
    dp.eligibility_status,
    dp.last_donation_date,
    dp.next_eligible_date,
    dp.total_donations,
    dp.recruitment_source,
    dp.created_at
FROM donor_profiles dp
JOIN users u ON u.id = dp.user_id;

CREATE OR REPLACE VIEW request_performance_view AS
SELECT
    br.id AS request_id,
    br.request_code,
    br.blood_type_needed,
    br.units_required,
    br.units_fulfilled,
    br.urgency_level,
    br.hospital_name,
    br.region,
    br.town,
    br.status,
    br.created_at,
    COUNT(rm.id) AS donors_matched,
    SUM(CASE WHEN rm.notification_status IN ('sent','seen') THEN 1 ELSE 0 END) AS donors_notified,
    SUM(CASE WHEN rm.donor_response = 'accepted' THEN 1 ELSE 0 END) AS donors_accepted,
    SUM(CASE WHEN rm.donor_response = 'declined' THEN 1 ELSE 0 END) AS donors_declined,
    SUM(CASE WHEN rm.donor_response = 'pending' THEN 1 ELSE 0 END) AS responses_pending
FROM blood_requests br
LEFT JOIN request_matches rm ON rm.request_id = br.id
GROUP BY br.id, br.request_code, br.blood_type_needed, br.units_required, br.units_fulfilled,
         br.urgency_level, br.hospital_name, br.region, br.town, br.status, br.created_at;

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

SET FOREIGN_KEY_CHECKS = 1;
