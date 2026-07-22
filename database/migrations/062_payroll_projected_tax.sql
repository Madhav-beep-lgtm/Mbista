-- 062: Fair projected annual payroll tax.
--
-- Replaces the "current month x 12" annualization with a fiscal-year-to-date
-- projection: actual taxable income earned so far + predictable REGULAR income
-- for the remaining employment months only. Irregular income (overtime,
-- service charge, bonuses, one-time payments) counts only when actually
-- earned. Joining/leaving dates bound the projection window; the remaining-
-- period divisor includes the current period; the final period settles the
-- whole balance. Every calculation stores an immutable snapshot.

-- 1. Component tax projection classification. NULL = automatic:
--    overtime/service-charge/manual components -> actual_only,
--    fixed / percent-of-basic components      -> regular.
ALTER TABLE `payroll_components`
  ADD COLUMN `tax_projection_method` ENUM('regular','actual_only','guaranteed','manual','excluded') DEFAULT NULL AFTER `taxable`;

-- Explicit backfill so existing components carry a visible classification.
UPDATE `payroll_components`
   SET `tax_projection_method` = CASE
        WHEN `calc_type` IN ('overtime_hours','service_charge','manual') THEN 'actual_only'
        ELSE 'regular'
   END
 WHERE `tax_projection_method` IS NULL;

-- 1b. Snapshot the classification on every stored run-component line so a
--     later master edit never changes historical tax maths.
ALTER TABLE `payroll_run_components`
  ADD COLUMN `tax_projection_method` VARCHAR(20) DEFAULT NULL AFTER `taxable`;

-- 2. Current-period taxable splits + tax override on each stored line.
ALTER TABLE `payroll_run_lines`
  ADD COLUMN `assessable_month` DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER `gross`,
  ADD COLUMN `regular_month` DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER `assessable_month`,
  ADD COLUMN `irregular_month` DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER `regular_month`,
  ADD COLUMN `tax_override` DECIMAL(14,2) DEFAULT NULL AFTER `tax_month`,
  ADD COLUMN `tax_override_reason` VARCHAR(255) DEFAULT NULL AFTER `tax_override`,
  ADD COLUMN `tax_override_by` INT UNSIGNED DEFAULT NULL AFTER `tax_override_reason`;

-- 3. Known contract end bounds the projection window.
ALTER TABLE `payroll_employees`
  ADD COLUMN `contract_end_date` DATE DEFAULT NULL AFTER `terminated_on`;

-- 4. Excess-withholding treatment is configurable, never silent.
ALTER TABLE `payroll_settings`
  ADD COLUMN `excess_tax_treatment` ENUM('offset','refund','carry_forward','manual') NOT NULL DEFAULT 'offset';

-- 5. Per-employee per-fiscal-year tax profile: approved prior-employment
--    figures and opening adjustments (never payroll expense here).
CREATE TABLE IF NOT EXISTS `payroll_employee_tax_profiles` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `payroll_employee_id` INT UNSIGNED NOT NULL,
  `fiscal_year_id` INT UNSIGNED NOT NULL,
  `prior_employment_income` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `prior_tax_withheld` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `prior_employer_details` VARCHAR(255) DEFAULT NULL,
  `document_reference` VARCHAR(255) DEFAULT NULL,
  `opening_income_adjustment` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `opening_tax_adjustment` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `remarks` VARCHAR(255) DEFAULT NULL,
  `entered_by` INT UNSIGNED DEFAULT NULL,
  `entered_at` TIMESTAMP NULL DEFAULT NULL,
  `approved_by` INT UNSIGNED DEFAULT NULL,
  `approved_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_tax_profile` (`payroll_employee_id`, `fiscal_year_id`),
  KEY `idx_tax_profiles_company` (`company_id`),
  CONSTRAINT `fk_tax_profiles_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tax_profiles_employee` FOREIGN KEY (`payroll_employee_id`) REFERENCES `payroll_employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tax_profiles_fy` FOREIGN KEY (`fiscal_year_id`) REFERENCES `fiscal_years` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Effective-dated basic-salary revisions: future periods project with the
--    salary that will actually be in force, not today's amount.
CREATE TABLE IF NOT EXISTS `payroll_salary_revisions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `payroll_employee_id` INT UNSIGNED NOT NULL,
  `effective_from` DATE NOT NULL,
  `basic_salary` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `reason` VARCHAR(255) DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_salary_revision` (`payroll_employee_id`, `effective_from`),
  KEY `idx_salary_revisions_company` (`company_id`),
  CONSTRAINT `fk_salary_revisions_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_salary_revisions_employee` FOREIGN KEY (`payroll_employee_id`) REFERENCES `payroll_employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Manual (explicitly approved) irregular-income projections.
CREATE TABLE IF NOT EXISTS `payroll_manual_projections` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `payroll_employee_id` INT UNSIGNED NOT NULL,
  `fiscal_year_id` INT UNSIGNED NOT NULL,
  `component_id` INT UNSIGNED DEFAULT NULL,
  `label` VARCHAR(120) NOT NULL,
  `amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `period_from` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `period_to` TINYINT UNSIGNED NOT NULL DEFAULT 12,
  `reason` VARCHAR(255) DEFAULT NULL,
  `prepared_by` INT UNSIGNED DEFAULT NULL,
  `approved_by` INT UNSIGNED DEFAULT NULL,
  `approved_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_manual_projections_employee` (`payroll_employee_id`, `fiscal_year_id`),
  CONSTRAINT `fk_manual_projections_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_manual_projections_employee` FOREIGN KEY (`payroll_employee_id`) REFERENCES `payroll_employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_manual_projections_fy` FOREIGN KEY (`fiscal_year_id`) REFERENCES `fiscal_years` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_manual_projections_component` FOREIGN KEY (`component_id`) REFERENCES `payroll_components` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Immutable per-line tax calculation snapshot.
CREATE TABLE IF NOT EXISTS `payroll_tax_calculations` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `run_id` INT UNSIGNED NOT NULL,
  `payroll_employee_id` INT UNSIGNED NOT NULL,
  `fiscal_year_id` INT UNSIGNED NOT NULL,
  `period_no` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `start_period` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `end_period` TINYINT UNSIGNED NOT NULL DEFAULT 12,
  `remaining_periods` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `employment_start_used` DATE DEFAULT NULL,
  `employment_end_used` DATE DEFAULT NULL,
  `actual_regular_to_date` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `actual_irregular_to_date` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `current_regular` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `current_irregular` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `projected_regular_income` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `manual_projected_income` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `prior_employment_income` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `estimated_annual_taxable_income` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `retirement_deduction` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `taxable_annual` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `estimated_annual_tax` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `tax_withheld_before_period` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `prior_employer_tax` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `remaining_tax` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `system_tax` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `tax_override` DECIMAL(14,2) DEFAULT NULL,
  `current_tax` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `excess_tax` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `calculation_version` TINYINT UNSIGNED NOT NULL DEFAULT 2,
  `snapshot_json` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_tax_calc_line` (`run_id`, `payroll_employee_id`),
  KEY `idx_tax_calcs_employee` (`payroll_employee_id`, `fiscal_year_id`),
  KEY `idx_tax_calcs_company` (`company_id`),
  CONSTRAINT `fk_tax_calcs_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tax_calcs_run` FOREIGN KEY (`run_id`) REFERENCES `payroll_runs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tax_calcs_employee` FOREIGN KEY (`payroll_employee_id`) REFERENCES `payroll_employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
