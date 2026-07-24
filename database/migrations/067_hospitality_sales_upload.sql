-- 067: Hospitality daily sales upload — Excel day-sheets posted to accounting.
--
-- Extends the hospitality module (063) with a POSTING path: a daywise Excel /
-- CSV sales report (Date, Category, Item, Qty, Total Sales Amount, optional
-- Discount / VAT) is uploaded, previewed, and auto-posted as one balanced
-- sales voucher per sale date:
--     Dr  Receivable ledger        (gross - discount)
--     Dr  Discount ledger          (discount, when any)
--     Cr  Sales ledger(s)          (taxable, split per category/item mapping)
--     Cr  VAT payable ledger       (VAT, when any)
-- Ledger mapping lives inside Hospitality settings: default Sales / VAT /
-- Discount / Receivable ledgers plus per-CATEGORY (and per-item override)
-- sales ledgers, so each category posts to its own ledger.

-- 1. Posting configuration on the tenant's hospitality settings.
ALTER TABLE `hospitality_settings`
  ADD COLUMN `post_sales_ledger_id` INT UNSIGNED DEFAULT NULL AFTER `dashboard_range`,
  ADD COLUMN `post_vat_ledger_id` INT UNSIGNED DEFAULT NULL AFTER `post_sales_ledger_id`,
  ADD COLUMN `post_discount_ledger_id` INT UNSIGNED DEFAULT NULL AFTER `post_vat_ledger_id`,
  ADD COLUMN `post_receivable_ledger_id` INT UNSIGNED DEFAULT NULL AFTER `post_discount_ledger_id`,
  ADD COLUMN `post_vat_rate` DECIMAL(6,2) NOT NULL DEFAULT 13.00 AFTER `post_receivable_ledger_id`,
  ADD COLUMN `post_amount_includes_vat` TINYINT(1) NOT NULL DEFAULT 1 AFTER `post_vat_rate`;

-- 2. Category / item -> sales ledger mapping (item override wins over its
--    category; anything unmapped falls back to the default sales ledger).
CREATE TABLE IF NOT EXISTS `hospitality_sales_ledger_maps` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `map_type` ENUM('category','item') NOT NULL DEFAULT 'category',
  `match_value` VARCHAR(160) NOT NULL,
  `display_value` VARCHAR(160) NOT NULL,
  `sales_ledger_id` INT UNSIGNED NOT NULL,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `notes` VARCHAR(255) DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_hosp_ledger_map` (`company_id`, `map_type`, `match_value`),
  KEY `idx_hosp_ledger_map_ledger` (`sales_ledger_id`),
  CONSTRAINT `fk_hosp_ledger_map_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_hosp_ledger_map_ledger` FOREIGN KEY (`sales_ledger_id`) REFERENCES `ledgers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Upload batches (one per posted sheet).
CREATE TABLE IF NOT EXISTS `hospitality_sales_uploads` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `fiscal_year_id` INT UNSIGNED NOT NULL,
  `file_name` VARCHAR(255) DEFAULT NULL,
  `date_from` DATE NOT NULL,
  `date_to` DATE NOT NULL,
  `row_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `voucher_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `gross_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `discount_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `vat_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `taxable_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `receivable_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('posted','pending_approval') NOT NULL DEFAULT 'posted',
  `posted_by` INT UNSIGNED DEFAULT NULL,
  `posted_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_hosp_sales_uploads` (`company_id`, `date_from`, `date_to`),
  CONSTRAINT `fk_hosp_sales_uploads_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_hosp_sales_uploads_fy` FOREIGN KEY (`fiscal_year_id`) REFERENCES `fiscal_years` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Uploaded rows, each linked to the voucher its date posted into.
CREATE TABLE IF NOT EXISTS `hospitality_sales_upload_lines` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `upload_id` INT UNSIGNED NOT NULL,
  `company_id` INT UNSIGNED NOT NULL,
  `sale_date` DATE NOT NULL,
  `category` VARCHAR(160) NOT NULL,
  `item_name` VARCHAR(255) NOT NULL,
  `qty` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
  `gross_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `discount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `vat_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `taxable_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `sales_ledger_id` INT UNSIGNED DEFAULT NULL,
  `ledger_source` VARCHAR(20) DEFAULT NULL,
  `voucher_id` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_hosp_upload_lines_date` (`company_id`, `sale_date`),
  KEY `idx_hosp_upload_lines_upload` (`upload_id`),
  CONSTRAINT `fk_hosp_upload_lines_upload` FOREIGN KEY (`upload_id`) REFERENCES `hospitality_sales_uploads` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_hosp_upload_lines_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
