-- 031: Payroll module (Nepal-style, configuration-driven).
--
-- Effective-dated, versioned tax configuration (slabs per marital category,
-- retirement deduction limits); payroll employees linked to existing users
-- (staff/clients across companies); components; loans/advances; monthly
-- payroll runs with per-employee calculation snapshots; balanced accrual
-- and payment vouchers post through the existing voucher engine
-- (source_type payroll_run / payroll_payment keeps posting idempotent).
-- No tax amounts are hard-coded in application code: all slabs and limits
-- live in payroll_tax_versions / payroll_tax_slabs, admin-editable and
-- immutable once published.

CREATE TABLE IF NOT EXISTS `payroll_settings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `salary_expense_ledger_id` INT UNSIGNED DEFAULT NULL,
  `employer_contrib_expense_ledger_id` INT UNSIGNED DEFAULT NULL,
  `tds_payable_ledger_id` INT UNSIGNED DEFAULT NULL,
  `retirement_payable_ledger_id` INT UNSIGNED DEFAULT NULL,
  `salary_payable_ledger_id` INT UNSIGNED DEFAULT NULL,
  `advance_ledger_id` INT UNSIGNED DEFAULT NULL,
  `bank_ledger_id` INT UNSIGNED DEFAULT NULL,
  `enforce_sod` TINYINT(1) NOT NULL DEFAULT 0,
  `auto_post` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_payroll_settings_company` (`company_id`),
  CONSTRAINT `fk_payroll_settings_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payroll_components` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `code` VARCHAR(40) NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `name_np` VARCHAR(120) DEFAULT NULL,
  `category` ENUM('allowance', 'overtime', 'benefit', 'deduction') NOT NULL DEFAULT 'allowance',
  `calc_type` ENUM('fixed', 'percent_basic') NOT NULL DEFAULT 'fixed',
  `default_value` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `taxable` TINYINT(1) NOT NULL DEFAULT 1,
  `employer_paid` TINYINT(1) NOT NULL DEFAULT 0,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_payroll_components_code` (`company_id`, `code`),
  CONSTRAINT `fk_payroll_components_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payroll_tax_versions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `fiscal_year_id` INT UNSIGNED NOT NULL,
  `label` VARCHAR(120) NOT NULL,
  `legal_reference` VARCHAR(255) DEFAULT NULL,
  `status` ENUM('draft', 'published', 'archived') NOT NULL DEFAULT 'draft',
  `effective_from` DATE DEFAULT NULL,
  `effective_to` DATE DEFAULT NULL,
  `retirement_limit_pct` DECIMAL(6,2) NOT NULL DEFAULT 33.33,
  `retirement_limit_cap` DECIMAL(14,2) NOT NULL DEFAULT 500000.00,
  `ssf_first_slab_exempt` TINYINT(1) NOT NULL DEFAULT 1,
  `rounding` ENUM('none', 'nearest', 'down') NOT NULL DEFAULT 'nearest',
  `created_by` INT UNSIGNED DEFAULT NULL,
  `approved_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_payroll_tax_versions_scope` (`company_id`, `fiscal_year_id`, `status`),
  CONSTRAINT `fk_payroll_tax_versions_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_payroll_tax_versions_fy` FOREIGN KEY (`fiscal_year_id`) REFERENCES `fiscal_years` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payroll_tax_slabs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `version_id` INT UNSIGNED NOT NULL,
  `category` ENUM('individual', 'couple') NOT NULL DEFAULT 'individual',
  `lower_bound` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `upper_bound` DECIMAL(14,2) DEFAULT NULL,
  `rate` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
  `sort_order` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_payroll_tax_slabs_version` (`version_id`, `category`, `sort_order`),
  CONSTRAINT `fk_payroll_tax_slabs_version` FOREIGN KEY (`version_id`) REFERENCES `payroll_tax_versions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payroll_employees` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `employee_code` VARCHAR(40) NOT NULL,
  `department` VARCHAR(120) DEFAULT NULL,
  `designation` VARCHAR(120) DEFAULT NULL,
  `pan_no` VARCHAR(40) DEFAULT NULL,
  `bank_name` VARCHAR(120) DEFAULT NULL,
  `bank_account` VARCHAR(60) DEFAULT NULL,
  `marital_status` ENUM('individual', 'couple') NOT NULL DEFAULT 'individual',
  `retirement_scheme` ENUM('none', 'ssf', 'pf', 'cit') NOT NULL DEFAULT 'none',
  `retirement_id` VARCHAR(60) DEFAULT NULL,
  `retirement_employee_rate` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
  `retirement_employer_rate` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
  `basic_salary` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  `joined_on` DATE DEFAULT NULL,
  `terminated_on` DATE DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_payroll_employees_user` (`company_id`, `user_id`),
  UNIQUE KEY `uniq_payroll_employees_code` (`company_id`, `employee_code`),
  CONSTRAINT `fk_payroll_employees_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_payroll_employees_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payroll_employee_components` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `payroll_employee_id` INT UNSIGNED NOT NULL,
  `component_id` INT UNSIGNED NOT NULL,
  `amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_payroll_emp_component` (`payroll_employee_id`, `component_id`),
  CONSTRAINT `fk_payroll_emp_components_emp` FOREIGN KEY (`payroll_employee_id`) REFERENCES `payroll_employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_payroll_emp_components_comp` FOREIGN KEY (`component_id`) REFERENCES `payroll_components` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payroll_loans` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `payroll_employee_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(160) NOT NULL DEFAULT 'Staff advance',
  `principal` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `balance` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `monthly_installment` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('active', 'paused', 'settled') NOT NULL DEFAULT 'active',
  `issued_on` DATE DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_payroll_loans_employee` (`payroll_employee_id`, `status`),
  CONSTRAINT `fk_payroll_loans_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_payroll_loans_employee` FOREIGN KEY (`payroll_employee_id`) REFERENCES `payroll_employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payroll_loan_txns` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `loan_id` INT UNSIGNED NOT NULL,
  `run_id` INT UNSIGNED DEFAULT NULL,
  `txn_type` ENUM('disbursement', 'recovery', 'adjustment') NOT NULL DEFAULT 'recovery',
  `amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `txn_date` DATE DEFAULT NULL,
  `voucher_id` INT UNSIGNED DEFAULT NULL,
  `notes` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_payroll_loan_txns_loan` (`loan_id`),
  CONSTRAINT `fk_payroll_loan_txns_loan` FOREIGN KEY (`loan_id`) REFERENCES `payroll_loans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payroll_runs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `fiscal_year_id` INT UNSIGNED NOT NULL,
  `period_no` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `period_label` VARCHAR(60) NOT NULL,
  `pay_date` DATE DEFAULT NULL,
  `status` ENUM('draft', 'calculated', 'approved', 'posted', 'paid', 'cancelled') NOT NULL DEFAULT 'draft',
  `tax_version_id` INT UNSIGNED DEFAULT NULL,
  `total_gross` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `total_tax` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `total_deductions` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `total_employer_contrib` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `total_net` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `accrual_voucher_id` INT UNSIGNED DEFAULT NULL,
  `payment_voucher_id` INT UNSIGNED DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `approved_by` INT UNSIGNED DEFAULT NULL,
  `approved_at` TIMESTAMP NULL DEFAULT NULL,
  `posted_at` TIMESTAMP NULL DEFAULT NULL,
  `paid_at` TIMESTAMP NULL DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_payroll_runs_period` (`company_id`, `fiscal_year_id`, `period_no`, `status`),
  CONSTRAINT `fk_payroll_runs_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_payroll_runs_fy` FOREIGN KEY (`fiscal_year_id`) REFERENCES `fiscal_years` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payroll_run_lines` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `run_id` INT UNSIGNED NOT NULL,
  `payroll_employee_id` INT UNSIGNED NOT NULL,
  `basic` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `allowances` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `overtime` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `benefits` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `gross` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `assessable_annual` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `retirement_deduction_annual` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `taxable_annual` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `annual_tax` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `tax_ytd_before` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `tax_month` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `retirement_employee_month` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `retirement_employer_month` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `advance_deduction` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `other_deduction` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `net_pay` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `trace` TEXT DEFAULT NULL,
  `line_status` ENUM('ok', 'warning', 'error') NOT NULL DEFAULT 'ok',
  `warnings` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_payroll_run_lines_emp` (`run_id`, `payroll_employee_id`),
  CONSTRAINT `fk_payroll_run_lines_run` FOREIGN KEY (`run_id`) REFERENCES `payroll_runs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_payroll_run_lines_emp` FOREIGN KEY (`payroll_employee_id`) REFERENCES `payroll_employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
