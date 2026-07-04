-- Adds a compliance calendar: admin-managed compliance types and a per-client deadline
-- tracker with assignment, status, and filing details.

CREATE TABLE IF NOT EXISTS `compliance_types` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `is_system` TINYINT(1) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_compliance_types_company_name` (`company_id`, `name`),
  CONSTRAINT `fk_compliance_types_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `compliance_deadlines` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `client_id` INT UNSIGNED NOT NULL,
  `compliance_type_id` INT UNSIGNED NOT NULL,
  `task_id` INT UNSIGNED DEFAULT NULL,
  `document_id` INT UNSIGNED DEFAULT NULL,
  `applicable_period` VARCHAR(100) NOT NULL,
  `statutory_due_date` DATE NOT NULL,
  `internal_due_date` DATE DEFAULT NULL,
  `assigned_staff_user_id` INT UNSIGNED DEFAULT NULL,
  `reviewer_user_id` INT UNSIGNED DEFAULT NULL,
  `status` ENUM(
      'not_started', 'upcoming', 'in_progress', 'waiting_for_client',
      'filed', 'completed', 'overdue', 'not_applicable'
  ) NOT NULL DEFAULT 'not_started',
  `filing_date` DATE DEFAULT NULL,
  `filing_reference` VARCHAR(150) DEFAULT NULL,
  `remarks` TEXT DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_compliance_deadlines_company` (`company_id`),
  KEY `idx_compliance_deadlines_client` (`client_id`),
  KEY `idx_compliance_deadlines_due` (`statutory_due_date`),
  KEY `idx_compliance_deadlines_status` (`status`),
  CONSTRAINT `fk_compliance_deadlines_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_compliance_deadlines_client` FOREIGN KEY (`client_id`) REFERENCES `client_profiles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_compliance_deadlines_type` FOREIGN KEY (`compliance_type_id`) REFERENCES `compliance_types` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_compliance_deadlines_task` FOREIGN KEY (`task_id`) REFERENCES `client_tasks` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_compliance_deadlines_document` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_compliance_deadlines_staff` FOREIGN KEY (`assigned_staff_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_compliance_deadlines_reviewer` FOREIGN KEY (`reviewer_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `compliance_types` (`company_id`, `name`, `is_system`)
SELECT c.id, seed.name, 1
FROM `companies` c
JOIN (
    SELECT 'VAT Return' AS name
    UNION ALL SELECT 'TDS Return'
    UNION ALL SELECT 'Income Tax Instalment'
    UNION ALL SELECT 'Annual Income Tax Return'
    UNION ALL SELECT 'Company Annual Return'
    UNION ALL SELECT 'AGM'
    UNION ALL SELECT 'Audit Completion'
    UNION ALL SELECT 'Social Security Fund Payment'
    UNION ALL SELECT 'Payroll Processing'
    UNION ALL SELECT 'Internal Audit Report'
    UNION ALL SELECT 'Engagement Renewal'
) seed
WHERE c.is_active = 1
  AND NOT EXISTS (
      SELECT 1 FROM `compliance_types` existing
      WHERE existing.company_id = c.id AND existing.name = seed.name
  );
