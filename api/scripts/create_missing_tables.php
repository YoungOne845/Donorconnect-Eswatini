<?php
require __DIR__ . '/../bootstrap.php';
use App\Core\Database;

$db = Database::connection();

$sql = "CREATE TABLE IF NOT EXISTS `profile_update_requests` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `donor_id`      BIGINT UNSIGNED NOT NULL,
  `user_id`       BIGINT UNSIGNED NOT NULL,
  `field`         VARCHAR(80) NOT NULL,
  `new_value`     VARCHAR(255) NOT NULL,
  `reason`        TEXT DEFAULT NULL,
  `status`        ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `reviewed_by`   BIGINT UNSIGNED DEFAULT NULL,
  `review_notes`  TEXT DEFAULT NULL,
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pur_donor` (`donor_id`),
  KEY `idx_pur_status` (`status`),
  CONSTRAINT `fk_pur_donor`  FOREIGN KEY (`donor_id`)    REFERENCES `donor_profiles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pur_user`   FOREIGN KEY (`user_id`)     REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pur_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

try {
    $db->exec($sql);
    echo "SUCCESS: profile_update_requests table created.\n";

    // Verify
    $count = $db->query("SELECT COUNT(*) FROM profile_update_requests")->fetchColumn();
    echo "Table verified — $count rows.\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
