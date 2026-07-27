-- 081: Opening stock from a spreadsheet — STAGED, not applied on upload.
--
-- Opening stock is the one thing a shop types in bulk, from a sheet the owner
-- has already been keeping, and it is also the one thing that is painful to
-- unpick once it has reached the books. Those two facts together are the whole
-- reason this is a staging table and not a direct import:
--
--   upload  →  every row parsed and validated, NOTHING posted
--   preview →  the sheet as the system understood it, row by row, with the
--              problems named against the rows that have them
--   edit    →  fix a row here rather than in Excel and upload again
--   delete  →  drop rows that should not be there
--   commit  →  only then does anything become opening stock
--
-- A row that will not import is kept with its error rather than dropped, so
-- "47 rows uploaded, 44 imported" can always be explained. And the original
-- file's row number travels with it, because "row 63 is wrong" is only useful
-- if it means row 63 of the sheet the user is looking at.
--
-- The batch is deliberately per (company, fiscal year, user): two people
-- preparing openings at once must not overwrite each other's staged work.

CREATE TABLE IF NOT EXISTS `inventory_opening_imports` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `fiscal_year_id` INT UNSIGNED DEFAULT NULL,
  `module` ENUM('inventory','jewellery','hospitality') NOT NULL DEFAULT 'inventory',
  `original_name` VARCHAR(255) NOT NULL DEFAULT '',
  `row_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `valid_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `status` ENUM('staged','committed','discarded') NOT NULL DEFAULT 'staged',
  `committed_rows` INT UNSIGNED NOT NULL DEFAULT 0,
  `notes` VARCHAR(500) DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `committed_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_inv_opimp_company` (`company_id`, `status`, `id`),
  KEY `idx_inv_opimp_fy` (`fiscal_year_id`),
  CONSTRAINT `fk_inv_opimp_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_inv_opimp_fy` FOREIGN KEY (`fiscal_year_id`) REFERENCES `fiscal_years` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `inventory_opening_import_rows` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `import_id` INT UNSIGNED NOT NULL,
  `company_id` INT UNSIGNED NOT NULL,
  `source_row_no` INT UNSIGNED NOT NULL DEFAULT 0,
  -- What the sheet said, kept verbatim so the preview can show the user their
  -- own words next to whatever the system matched them to.
  `raw_code` VARCHAR(120) NOT NULL DEFAULT '',
  `raw_name` VARCHAR(255) NOT NULL DEFAULT '',
  `raw_unit` VARCHAR(60) NOT NULL DEFAULT '',
  `raw_purity` VARCHAR(60) NOT NULL DEFAULT '',
  -- What it resolved to.
  `item_id` INT UNSIGNED DEFAULT NULL,
  `purity_id` INT UNSIGNED DEFAULT NULL,
  `unit_id` INT UNSIGNED DEFAULT NULL,
  `qty_pieces` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
  `gross_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `rate` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('ready','error','skipped','committed') NOT NULL DEFAULT 'ready',
  `error_text` VARCHAR(500) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_inv_opimprow_import` (`import_id`, `status`),
  KEY `idx_inv_opimprow_company` (`company_id`),
  KEY `idx_inv_opimprow_item` (`item_id`),
  CONSTRAINT `fk_inv_opimprow_import` FOREIGN KEY (`import_id`) REFERENCES `inventory_opening_imports` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_inv_opimprow_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
