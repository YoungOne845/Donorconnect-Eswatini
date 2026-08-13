-- DonorConnect migration: national-ID-first identity and age-controlled donor registration
-- Run this only after every existing user account has been assigned a national ID with:
-- php api/scripts/assign_national_id.php --user-id=USER_ID --national-id=13_DIGIT_ID

USE donorconnect;

-- This result must be 0 before continuing with the ALTER TABLE statement.
SELECT COUNT(*) AS users_missing_national_id
FROM users
WHERE national_id_encrypted IS NULL
   OR national_id_hash IS NULL
   OR national_id_last_four IS NULL;

-- Every account must now have a protected national ID identity.
ALTER TABLE users
    MODIFY national_id_encrypted TEXT NOT NULL,
    MODIFY national_id_hash CHAR(64) NOT NULL,
    MODIFY national_id_last_four CHAR(4) NOT NULL;

-- Record the migration so administrators can confirm it was applied.
CREATE TABLE IF NOT EXISTS schema_migrations (
    migration_name VARCHAR(190) PRIMARY KEY,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO schema_migrations (migration_name)
VALUES ('2026_06_12_national_id_age_and_admin_deletion');
