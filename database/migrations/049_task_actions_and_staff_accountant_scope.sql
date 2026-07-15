-- Work Portal task actions + client-scoped staff accountants:
-- 1. client_tasks.progress_percent — manual work-progress override (NULL =
--    derive from stage completion). Editable by admin and by assigned staff.
-- 2. staff_client_accounting_access — admin grants a staff member accounting
--    access to specific clients' books. A granted staff member reaches ONLY
--    those clients' books companies, and every voucher they enter there is
--    forced to pending_approval, flagged requires_client_approval so both the
--    client (dashboard bell) and the admin (client-books bell item) are
--    notified and either can approve.

ALTER TABLE client_tasks
  ADD COLUMN `progress_percent` TINYINT UNSIGNED DEFAULT NULL AFTER `priority`;

CREATE TABLE IF NOT EXISTS `staff_client_accounting_access` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED DEFAULT NULL,
  `staff_user_id` INT UNSIGNED NOT NULL,
  `client_id` INT UNSIGNED NOT NULL,
  `granted_by` INT UNSIGNED DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_staff_client_accounting` (`staff_user_id`, `client_id`),
  KEY `idx_staff_client_accounting_company` (`company_id`),
  KEY `idx_staff_client_accounting_client` (`client_id`),
  CONSTRAINT `fk_staff_client_accounting_staff` FOREIGN KEY (`staff_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_staff_client_accounting_client` FOREIGN KEY (`client_id`) REFERENCES `client_profiles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_staff_client_accounting_granted_by` FOREIGN KEY (`granted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
