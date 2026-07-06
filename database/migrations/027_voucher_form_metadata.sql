-- Voucher form template metadata: organization context, financial details,
-- per-line dimensions, and attachments for the reusable entry form.

ALTER TABLE `vouchers`
  ADD COLUMN `priority` ENUM('low', 'medium', 'high') NOT NULL DEFAULT 'medium' AFTER `narration`,
  ADD COLUMN `department` VARCHAR(80) DEFAULT NULL AFTER `priority`,
  ADD COLUMN `location` VARCHAR(80) DEFAULT NULL AFTER `department`,
  ADD COLUMN `cost_centre` VARCHAR(80) DEFAULT NULL AFTER `location`,
  ADD COLUMN `posting_date` DATE DEFAULT NULL AFTER `voucher_date`,
  ADD COLUMN `due_date` DATE DEFAULT NULL AFTER `posting_date`,
  ADD COLUMN `payment_terms` VARCHAR(40) DEFAULT NULL AFTER `due_date`,
  ADD COLUMN `exchange_rate` DECIMAL(12,4) NOT NULL DEFAULT 1.0000 AFTER `payment_terms`,
  ADD COLUMN `tax_category` VARCHAR(40) DEFAULT NULL AFTER `exchange_rate`;

ALTER TABLE `voucher_entries`
  ADD COLUMN `cost_centre` VARCHAR(80) DEFAULT NULL AFTER `memo`,
  ADD COLUMN `tax_code` VARCHAR(40) DEFAULT NULL AFTER `cost_centre`,
  ADD COLUMN `line_reference` VARCHAR(120) DEFAULT NULL AFTER `tax_code`;

CREATE TABLE IF NOT EXISTS `voucher_attachments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `voucher_id` INT UNSIGNED NOT NULL,
  `file_path` VARCHAR(255) NOT NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `file_size` INT UNSIGNED NOT NULL DEFAULT 0,
  `uploaded_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_voucher_attachments_voucher` (`voucher_id`),
  CONSTRAINT `fk_voucher_attachments_voucher` FOREIGN KEY (`voucher_id`) REFERENCES `vouchers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
