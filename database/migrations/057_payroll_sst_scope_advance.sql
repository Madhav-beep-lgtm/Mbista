-- Social Security Tax split, single-staff run scope.
-- 1. Each salary line stores the SST portion (first 1% slab) of its monthly
--    withholding so the accrual can credit SST and Remuneration Tax separately.
ALTER TABLE `payroll_run_lines`
  ADD COLUMN `sst_month` DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER `tax_month`;

-- 2. Separate payable ledger for SST (falls back to the TDS ledger when NULL).
ALTER TABLE `payroll_settings`
  ADD COLUMN `sst_payable_ledger_id` INT UNSIGNED DEFAULT NULL AFTER `tds_payable_ledger_id`;

-- 3. A payroll run may be scoped to specific employees (single-staff runs):
--    JSON array of payroll_employees ids; NULL/empty = every active employee.
ALTER TABLE `payroll_runs`
  ADD COLUMN `employee_scope` TEXT DEFAULT NULL AFTER `voucher_date`;
