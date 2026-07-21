-- 061: Flexible pay components with per-component accounting treatment,
-- employee-level effective-dated assignments, per-run component snapshots,
-- declared service-charge allocation (68% employees / 32% employer), and
-- Sunday-Saturday weekly overtime from attendance.
--
-- Everything here EXTENDS the 031 payroll schema — no tables are replaced.
-- The general payroll_settings ledgers stay as control/fallback accounts;
-- per-component mappings take priority in posting. Historical posted runs are
-- untouched: snapshots live per run in payroll_run_components and existing
-- runs keep posting from their stored aggregate columns and traces.

-- 1. Component master: category widened, per-component posting behaviour and
--    ledger mappings, pay-basis flags, effective dates, audit columns.
ALTER TABLE `payroll_components`
  MODIFY COLUMN `category` ENUM('allowance','overtime','benefit','deduction','employer_contribution','reimbursement','advance_recovery','tax','info') NOT NULL DEFAULT 'allowance',
  MODIFY COLUMN `calc_type` ENUM('fixed','percent_basic','manual','overtime_hours','service_charge') NOT NULL DEFAULT 'manual',
  ADD COLUMN `description` VARCHAR(255) DEFAULT NULL AFTER `name_np`,
  ADD COLUMN `posting_behaviour` ENUM('category_default','earning_expense','deduction_liability','employer_contribution','reimbursement','advance_recovery','non_posting','custom') NOT NULL DEFAULT 'category_default' AFTER `category`,
  ADD COLUMN `debit_ledger_id` INT UNSIGNED DEFAULT NULL AFTER `posting_behaviour`,
  ADD COLUMN `credit_ledger_id` INT UNSIGNED DEFAULT NULL AFTER `debit_ledger_id`,
  ADD COLUMN `employer_expense_ledger_id` INT UNSIGNED DEFAULT NULL AFTER `credit_ledger_id`,
  ADD COLUMN `contribution_payable_ledger_id` INT UNSIGNED DEFAULT NULL AFTER `employer_expense_ledger_id`,
  ADD COLUMN `include_in_gross` TINYINT(1) NOT NULL DEFAULT 1 AFTER `taxable`,
  ADD COLUMN `include_in_net` TINYINT(1) NOT NULL DEFAULT 1 AFTER `include_in_gross`,
  ADD COLUMN `retirement_basis` TINYINT(1) NOT NULL DEFAULT 0 AFTER `include_in_net`,
  ADD COLUMN `overtime_basis` TINYINT(1) NOT NULL DEFAULT 0 AFTER `retirement_basis`,
  ADD COLUMN `service_charge_basis` TINYINT(1) NOT NULL DEFAULT 0 AFTER `overtime_basis`,
  ADD COLUMN `percentage` DECIMAL(9,4) DEFAULT NULL AFTER `default_value`,
  ADD COLUMN `calc_basis` VARCHAR(40) DEFAULT NULL AFTER `percentage`,
  ADD COLUMN `allow_employee_override` TINYINT(1) NOT NULL DEFAULT 1 AFTER `service_charge_basis`,
  ADD COLUMN `allow_period_override` TINYINT(1) NOT NULL DEFAULT 1 AFTER `allow_employee_override`,
  ADD COLUMN `allow_zero` TINYINT(1) NOT NULL DEFAULT 1 AFTER `allow_period_override`,
  ADD COLUMN `effective_from` DATE DEFAULT NULL AFTER `allow_zero`,
  ADD COLUMN `effective_to` DATE DEFAULT NULL AFTER `effective_from`,
  ADD COLUMN `created_by` INT UNSIGNED DEFAULT NULL AFTER `sort_order`,
  ADD COLUMN `updated_by` INT UNSIGNED DEFAULT NULL AFTER `created_by`;

-- RESTRICT: a ledger referenced by a pay component cannot be deleted.
ALTER TABLE `payroll_components`
  ADD CONSTRAINT `fk_payroll_components_dr` FOREIGN KEY (`debit_ledger_id`) REFERENCES `ledgers` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_payroll_components_cr` FOREIGN KEY (`credit_ledger_id`) REFERENCES `ledgers` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_payroll_components_er_exp` FOREIGN KEY (`employer_expense_ledger_id`) REFERENCES `ledgers` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_payroll_components_er_pay` FOREIGN KEY (`contribution_payable_ledger_id`) REFERENCES `ledgers` (`id`) ON DELETE RESTRICT;

-- 2. Employee pay structure: effective dates, percentage suggestion, taxable
--    override, active flag, remarks, audit.
ALTER TABLE `payroll_employee_components`
  ADD COLUMN `effective_from` DATE DEFAULT NULL AFTER `amount`,
  ADD COLUMN `effective_to` DATE DEFAULT NULL AFTER `effective_from`,
  ADD COLUMN `percentage` DECIMAL(9,4) DEFAULT NULL AFTER `effective_to`,
  ADD COLUMN `taxable_override` TINYINT(1) DEFAULT NULL AFTER `percentage`,
  ADD COLUMN `active` TINYINT(1) NOT NULL DEFAULT 1 AFTER `taxable_override`,
  ADD COLUMN `remarks` VARCHAR(255) DEFAULT NULL AFTER `active`,
  ADD COLUMN `created_by` INT UNSIGNED DEFAULT NULL AFTER `remarks`,
  ADD COLUMN `updated_by` INT UNSIGNED DEFAULT NULL AFTER `created_by`;

-- 3. Per-run component lines: the ACTUAL approved amount plus a full snapshot
--    of the component's identity, tax status and ledger mapping at pay time.
--    Later master/settings edits never change history. component_id is
--    SET NULL on delete: the snapshot columns keep the identity.
CREATE TABLE IF NOT EXISTS `payroll_run_components` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `run_id` INT UNSIGNED NOT NULL,
  `payroll_employee_id` INT UNSIGNED NOT NULL,
  `component_id` INT UNSIGNED DEFAULT NULL,
  `component_code` VARCHAR(40) NOT NULL,
  `component_name` VARCHAR(120) NOT NULL,
  `category` VARCHAR(30) NOT NULL,
  `posting_behaviour` VARCHAR(30) NOT NULL DEFAULT 'category_default',
  `taxable` TINYINT(1) NOT NULL DEFAULT 1,
  `include_in_gross` TINYINT(1) NOT NULL DEFAULT 1,
  `include_in_net` TINYINT(1) NOT NULL DEFAULT 1,
  `calc_method` VARCHAR(30) NOT NULL DEFAULT 'manual',
  `debit_ledger_id` INT UNSIGNED DEFAULT NULL,
  `credit_ledger_id` INT UNSIGNED DEFAULT NULL,
  `employer_expense_ledger_id` INT UNSIGNED DEFAULT NULL,
  `contribution_payable_ledger_id` INT UNSIGNED DEFAULT NULL,
  `suggested_amount` DECIMAL(14,2) DEFAULT NULL,
  `amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `override_reason` VARCHAR(255) DEFAULT NULL,
  `source` ENUM('standard','one_time','overtime','service_charge') NOT NULL DEFAULT 'standard',
  `created_by` INT UNSIGNED DEFAULT NULL,
  `updated_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_run_component_line` (`run_id`, `payroll_employee_id`, `component_code`),
  KEY `idx_prc_component` (`component_id`),
  KEY `idx_prc_employee` (`payroll_employee_id`),
  CONSTRAINT `fk_prc_run` FOREIGN KEY (`run_id`) REFERENCES `payroll_runs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_prc_employee` FOREIGN KEY (`payroll_employee_id`) REFERENCES `payroll_employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_prc_component` FOREIGN KEY (`component_id`) REFERENCES `payroll_components` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Service charge: employer-declared total per payroll run; the employee
--    pool (68% by default) is distributed to eligible employees, the employer
--    share (32%) is stored for reporting only and never enters Salary Payable.
CREATE TABLE IF NOT EXISTS `payroll_service_charge_runs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `run_id` INT UNSIGNED NOT NULL,
  `declared_total` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `employee_pct` DECIMAL(5,2) NOT NULL DEFAULT 68.00,
  `employer_pct` DECIMAL(5,2) NOT NULL DEFAULT 32.00,
  `employee_pool` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `employer_share` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `allocation_method` ENUM('equal','days_worked','manual') NOT NULL DEFAULT 'equal',
  `component_id` INT UNSIGNED DEFAULT NULL,
  `status` ENUM('draft','approved') NOT NULL DEFAULT 'draft',
  `notes` VARCHAR(255) DEFAULT NULL,
  `approved_by` INT UNSIGNED DEFAULT NULL,
  `approved_at` TIMESTAMP NULL DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `updated_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_sc_run` (`run_id`),
  KEY `idx_sc_company` (`company_id`),
  CONSTRAINT `fk_sc_runs_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sc_runs_run` FOREIGN KEY (`run_id`) REFERENCES `payroll_runs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sc_runs_component` FOREIGN KEY (`component_id`) REFERENCES `payroll_components` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payroll_service_charge_allocations` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sc_run_id` INT UNSIGNED NOT NULL,
  `payroll_employee_id` INT UNSIGNED NOT NULL,
  `eligible_days` DECIMAL(6,2) DEFAULT NULL,
  `amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_sc_alloc_employee` (`sc_run_id`, `payroll_employee_id`),
  CONSTRAINT `fk_sc_alloc_run` FOREIGN KEY (`sc_run_id`) REFERENCES `payroll_service_charge_runs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sc_alloc_employee` FOREIGN KEY (`payroll_employee_id`) REFERENCES `payroll_employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Weekly overtime (Sunday-Saturday, threshold in settings): week summary
--    for review/approval plus per-DATE entries so a week crossing two payroll
--    months pays each day in its own period exactly once (run_id = consumed).
CREATE TABLE IF NOT EXISTS `payroll_overtime_weeks` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `payroll_employee_id` INT UNSIGNED NOT NULL,
  `week_start` DATE NOT NULL,
  `week_end` DATE NOT NULL,
  `total_hours` DECIMAL(7,2) NOT NULL DEFAULT 0.00,
  `regular_hours` DECIMAL(7,2) NOT NULL DEFAULT 0.00,
  `overtime_hours` DECIMAL(7,2) NOT NULL DEFAULT 0.00,
  `hourly_rate` DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
  `multiplier` DECIMAL(6,3) NOT NULL DEFAULT 1.000,
  `calculated_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `approved_amount` DECIMAL(14,2) DEFAULT NULL,
  `adjust_reason` VARCHAR(255) DEFAULT NULL,
  `daily_json` TEXT DEFAULT NULL,
  `status` ENUM('calculated','approved') NOT NULL DEFAULT 'calculated',
  `approved_by` INT UNSIGNED DEFAULT NULL,
  `approved_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_ot_week` (`payroll_employee_id`, `week_start`),
  KEY `idx_ot_weeks_company` (`company_id`, `week_start`),
  CONSTRAINT `fk_ot_weeks_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ot_weeks_employee` FOREIGN KEY (`payroll_employee_id`) REFERENCES `payroll_employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payroll_overtime_entries` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `payroll_employee_id` INT UNSIGNED NOT NULL,
  `week_start` DATE NOT NULL,
  `ot_date` DATE NOT NULL,
  `hours` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
  `amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `run_id` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_ot_entry_date` (`payroll_employee_id`, `ot_date`),
  KEY `idx_ot_entries_week` (`payroll_employee_id`, `week_start`),
  KEY `idx_ot_entries_run` (`run_id`),
  CONSTRAINT `fk_ot_entries_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ot_entries_employee` FOREIGN KEY (`payroll_employee_id`) REFERENCES `payroll_employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ot_entries_run` FOREIGN KEY (`run_id`) REFERENCES `payroll_runs` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Overtime + service-charge configuration on payroll settings; employee
--    fixed hourly rate and service-charge eligibility on the roster.
ALTER TABLE `payroll_settings`
  ADD COLUMN `ot_weekly_threshold` DECIMAL(5,2) NOT NULL DEFAULT 40.00,
  ADD COLUMN `ot_week_start` TINYINT NOT NULL DEFAULT 0,
  ADD COLUMN `ot_component_id` INT UNSIGNED DEFAULT NULL,
  ADD COLUMN `ot_rate_source` ENUM('salary_derived','fixed_rate') NOT NULL DEFAULT 'salary_derived',
  ADD COLUMN `ot_monthly_hours` DECIMAL(6,2) NOT NULL DEFAULT 208.00,
  ADD COLUMN `ot_multiplier` DECIMAL(6,3) NOT NULL DEFAULT 1.500,
  ADD COLUMN `ot_rounding` ENUM('none','nearest','down') NOT NULL DEFAULT 'nearest',
  ADD COLUMN `ot_require_approval` TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN `sc_component_id` INT UNSIGNED DEFAULT NULL,
  ADD COLUMN `sc_employee_pct` DECIMAL(5,2) NOT NULL DEFAULT 68.00,
  ADD COLUMN `sc_employer_pct` DECIMAL(5,2) NOT NULL DEFAULT 32.00;

ALTER TABLE `payroll_settings`
  ADD CONSTRAINT `fk_payroll_settings_ot_comp` FOREIGN KEY (`ot_component_id`) REFERENCES `payroll_components` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_payroll_settings_sc_comp` FOREIGN KEY (`sc_component_id`) REFERENCES `payroll_components` (`id`) ON DELETE SET NULL;

ALTER TABLE `payroll_employees`
  ADD COLUMN `ot_hourly_rate` DECIMAL(14,4) DEFAULT NULL AFTER `basic_salary`,
  ADD COLUMN `sc_eligible` TINYINT(1) NOT NULL DEFAULT 1 AFTER `ot_hourly_rate`;
