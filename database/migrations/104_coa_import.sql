-- 104: Chart of accounts from a spreadsheet — STAGED, not applied on upload.
--
-- A chart is the one thing a book is built ON. Everything else in the system
-- points at it: every voucher entry, every mapping, every report column. That
-- makes a bulk upload worth having — nobody wants to type two hundred accounts
-- one screen at a time — and it makes an unreviewed bulk upload dangerous, so
-- this follows the same staged shape as the opening-stock import (081):
--
--   upload  →  every row parsed and validated, NOTHING created
--   preview →  the sheet as the system understood it, row by row, with the
--              problems named against the rows that have them
--   commit  →  only then do groups, ledgers and openings exist
--
-- A row that will not import is KEPT with its reason rather than dropped. The
-- old importer counted "4 rows skipped" and left the user to guess which four
-- and why; a staged row can say "row 13: group XX not found" and be looked at.
--
-- Groups and ledgers share this one table because they arrive in one sheet and
-- their order matters: a ledger naming a group that appears LATER in the file
-- must still resolve, so commit creates every group row before any ledger row.
-- `level` is what tells the two apart.
--
-- On opening balances: the general ledger here is perpetual, so an opening is
-- not a column on the ledger — it is a posted journal against Opening Balance
-- Adjustments, made by post_ledger_opening_balance(). The sheet therefore
-- carries Dr/Cr only as an INSTRUCTION, staged as typed and posted on commit.
-- The batch must balance before it may be committed: an unbalanced sheet would
-- otherwise dump its difference into the adjustments ledger, which is exactly
-- the silent mess the opening-balance engine exists to prevent.

CREATE TABLE IF NOT EXISTS `coa_imports` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `fiscal_year_id` INT UNSIGNED DEFAULT NULL,
  `original_name` VARCHAR(255) NOT NULL DEFAULT '',
  `row_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `ready_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `skipped_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `error_count` INT UNSIGNED NOT NULL DEFAULT 0,
  -- Totals of what the sheet ASKS for, so the preview can show the balance test
  -- without re-adding every row on each page load.
  `opening_dr_total` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `opening_cr_total` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('staged','committed','discarded') NOT NULL DEFAULT 'staged',
  `committed_groups` INT UNSIGNED NOT NULL DEFAULT 0,
  `committed_ledgers` INT UNSIGNED NOT NULL DEFAULT 0,
  `committed_openings` INT UNSIGNED NOT NULL DEFAULT 0,
  `notes` VARCHAR(500) DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `committed_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_coa_imports_company` (`company_id`, `status`, `id`),
  KEY `idx_coa_imports_fy` (`fiscal_year_id`),
  CONSTRAINT `fk_coa_imports_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_coa_imports_fy` FOREIGN KEY (`fiscal_year_id`) REFERENCES `fiscal_years` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `coa_import_rows` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `import_id` INT UNSIGNED NOT NULL,
  `company_id` INT UNSIGNED NOT NULL,
  -- The row number in the FILE the user is looking at. "Row 63 is wrong" is
  -- only useful if it means row 63 of their sheet, not row 63 of what survived.
  `source_row_no` INT UNSIGNED NOT NULL DEFAULT 0,
  `level` ENUM('group','ledger','unknown') NOT NULL DEFAULT 'unknown',
  -- What the sheet said, kept verbatim so the preview can show the user their
  -- own words beside whatever the system made of them.
  `raw_level` VARCHAR(60) NOT NULL DEFAULT '',
  `raw_code` VARCHAR(120) NOT NULL DEFAULT '',
  `raw_name` VARCHAR(255) NOT NULL DEFAULT '',
  `raw_master` VARCHAR(120) NOT NULL DEFAULT '',
  `raw_type` VARCHAR(60) NOT NULL DEFAULT '',
  `raw_group_code` VARCHAR(120) NOT NULL DEFAULT '',
  `raw_opening_dr` VARCHAR(60) NOT NULL DEFAULT '',
  `raw_opening_cr` VARCHAR(60) NOT NULL DEFAULT '',
  -- What it resolved to. group_id on a ledger row is the group it will sit
  -- under when that group already exists; a group being created by an earlier
  -- row in the same sheet resolves at commit instead, by code.
  `group_id` INT UNSIGNED DEFAULT NULL,
  `ledger_id` INT UNSIGNED DEFAULT NULL,
  `code` VARCHAR(40) NOT NULL DEFAULT '',
  `name` VARCHAR(150) NOT NULL DEFAULT '',
  `master_key` VARCHAR(40) NOT NULL DEFAULT '',
  `ledger_type` VARCHAR(20) NOT NULL DEFAULT '',
  -- Wide enough for a group NAME, not just a code: a sheet written by a person
  -- says "Current Assets" here, and truncating that to a code's width would
  -- lose the only handle commit has for resolving the group.
  `parent_code` VARCHAR(150) NOT NULL DEFAULT '',
  `opening_dr` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `opening_cr` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  -- ready   → will be created on commit
  -- skipped → the code already exists; left alone, reported, never rewritten
  -- error   → cannot be created; the reason is in error_text
  `status` ENUM('ready','skipped','error','committed') NOT NULL DEFAULT 'ready',
  `error_text` VARCHAR(500) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_coa_import_rows_import` (`import_id`, `level`, `status`),
  KEY `idx_coa_import_rows_company` (`company_id`),
  KEY `idx_coa_import_rows_group` (`group_id`),
  KEY `idx_coa_import_rows_ledger` (`ledger_id`),
  CONSTRAINT `fk_coa_import_rows_import` FOREIGN KEY (`import_id`) REFERENCES `coa_imports` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_coa_import_rows_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
