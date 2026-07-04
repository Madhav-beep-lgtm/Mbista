-- Company-scoped mode used by the accounting dashboard and report links.

CREATE TABLE IF NOT EXISTS `company_accounting_preferences` (
  `company_id` INT UNSIGNED NOT NULL,
  `business_type` ENUM('service', 'trading', 'manufacturing') NOT NULL DEFAULT 'service',
  `updated_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`company_id`),
  KEY `idx_company_accounting_preferences_updated_by` (`updated_by`),
  CONSTRAINT `fk_company_accounting_preferences_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_company_accounting_preferences_user` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `company_accounting_preferences` (`company_id`, `business_type`)
SELECT c.id, 'service'
FROM `companies` c
WHERE NOT EXISTS (
    SELECT 1
    FROM `company_accounting_preferences` p
    WHERE p.company_id = c.id
);
