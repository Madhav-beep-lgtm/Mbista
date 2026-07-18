-- ============================================================================
-- Migration 053 — Formal Opening-Balance management layer
-- ----------------------------------------------------------------------------
-- The GL is perpetual: a permanent account's opening balance for a fiscal year
-- is already the cumulative net of every posted voucher dated before that year
-- (see rc_ledger_balances / opening_balance_engine.php). These tables do NOT
-- store a second copy of the balances that would double-count the GL; they are
-- the formal batch / workflow / audit / adjustment record layered ON TOP of the
-- perpetual GL (the single source of truth). Money is DECIMAL(18,2) — never float.
-- Idempotent: CREATE TABLE IF NOT EXISTS. Replayed by accounting_module_repair.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `opening_balance_batches` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `fiscal_year_id` INT UNSIGNED NOT NULL,
  `previous_fiscal_year_id` INT UNSIGNED DEFAULT NULL,
  `basis` ENUM('carried_forward','initial_manual') NOT NULL DEFAULT 'carried_forward',
  `status` ENUM('draft','generated','reviewed','finalized','locked') NOT NULL DEFAULT 'draft',
  `total_debit` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `total_credit` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `retained_earnings_transfer` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `version` INT UNSIGNED NOT NULL DEFAULT 1,
  `notes` VARCHAR(500) DEFAULT NULL,
  `generated_by` INT UNSIGNED DEFAULT NULL,
  `generated_at` DATETIME DEFAULT NULL,
  `reviewed_by` INT UNSIGNED DEFAULT NULL,
  `reviewed_at` DATETIME DEFAULT NULL,
  `finalized_by` INT UNSIGNED DEFAULT NULL,
  `finalized_at` DATETIME DEFAULT NULL,
  `locked_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_ob_batch_company_fy` (`company_id`,`fiscal_year_id`),
  KEY `idx_ob_batch_company` (`company_id`),
  KEY `idx_ob_batch_fy` (`fiscal_year_id`),
  KEY `idx_ob_batch_status` (`status`),
  CONSTRAINT `fk_ob_batch_company` FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ob_batch_fy` FOREIGN KEY (`fiscal_year_id`) REFERENCES `fiscal_years`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `opening_balance_lines` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `batch_id` INT UNSIGNED NOT NULL,
  `line_key` VARCHAR(120) NOT NULL,
  `ledger_id` INT UNSIGNED DEFAULT NULL,
  `line_label` VARCHAR(190) NOT NULL DEFAULT '',
  `account_type` VARCHAR(20) NOT NULL DEFAULT '',
  `master_key` VARCHAR(40) NOT NULL DEFAULT '',
  `is_applicable` TINYINT(1) NOT NULL DEFAULT 1,
  `subledger_type` VARCHAR(40) DEFAULT NULL,
  `subledger_id` INT UNSIGNED DEFAULT NULL,
  `dimension_data` TEXT DEFAULT NULL,
  `previous_closing_debit` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `previous_closing_credit` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `system_opening_debit` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `system_opening_credit` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `adjustment_debit` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `adjustment_credit` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `final_opening_debit` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `final_opening_credit` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `adjustment_reason` VARCHAR(500) DEFAULT NULL,
  `adjustment_reference` VARCHAR(190) DEFAULT NULL,
  `adjustment_voucher_id` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_ob_line_batch_key` (`batch_id`,`line_key`),
  KEY `idx_ob_line_batch` (`batch_id`),
  KEY `idx_ob_line_ledger` (`ledger_id`),
  KEY `idx_ob_line_subledger` (`subledger_type`,`subledger_id`),
  CONSTRAINT `fk_ob_line_batch` FOREIGN KEY (`batch_id`) REFERENCES `opening_balance_batches`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ob_line_ledger` FOREIGN KEY (`ledger_id`) REFERENCES `ledgers`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `opening_balance_audit_logs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `batch_id` INT UNSIGNED DEFAULT NULL,
  `line_id` INT UNSIGNED DEFAULT NULL,
  `fiscal_year_id` INT UNSIGNED DEFAULT NULL,
  `action` VARCHAR(40) NOT NULL,
  `old_values` TEXT DEFAULT NULL,
  `new_values` TEXT DEFAULT NULL,
  `reason` VARCHAR(500) DEFAULT NULL,
  `performed_by` INT UNSIGNED DEFAULT NULL,
  `performed_by_name` VARCHAR(190) DEFAULT NULL,
  `ip_address` VARCHAR(64) DEFAULT NULL,
  `reversal_of_id` INT UNSIGNED DEFAULT NULL,
  `performed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ob_audit_company` (`company_id`),
  KEY `idx_ob_audit_batch` (`batch_id`),
  KEY `idx_ob_audit_line` (`line_id`),
  KEY `idx_ob_audit_fy` (`fiscal_year_id`),
  CONSTRAINT `fk_ob_audit_company` FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
