-- Migration 042: Editable access-level capability matrix.
-- Each company can define the baseline capabilities for its access levels.
-- Super Admin capabilities remain protected by the application.

CREATE TABLE IF NOT EXISTS `role_capability_overrides` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `access_level` VARCHAR(40) NOT NULL,
  `capability` VARCHAR(40) NOT NULL,
  `is_allowed` TINYINT(1) NOT NULL DEFAULT 0,
  `updated_by` INT UNSIGNED DEFAULT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_role_capability_company_level_action`
    (`company_id`, `access_level`, `capability`),
  KEY `idx_role_capability_company` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;