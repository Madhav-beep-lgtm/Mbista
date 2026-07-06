-- Client accounting books: each client gets a dedicated books space
-- (a flagged companies row), staff edits require client approval.

ALTER TABLE `companies`
  ADD COLUMN `is_client_company` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_active`;

ALTER TABLE `client_profiles`
  ADD COLUMN `books_company_id` INT UNSIGNED DEFAULT NULL AFTER `company_id`;

ALTER TABLE `vouchers`
  ADD COLUMN `requires_client_approval` TINYINT(1) NOT NULL DEFAULT 0 AFTER `approval_state`,
  ADD COLUMN `client_approved_by` INT UNSIGNED DEFAULT NULL AFTER `requires_client_approval`,
  ADD COLUMN `client_approved_at` DATETIME DEFAULT NULL AFTER `client_approved_by`;
