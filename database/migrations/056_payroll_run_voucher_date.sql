-- Optional custom voucher/accounting date for a payroll run's postings,
-- separate from the pay date. Falls back to pay_date when NULL.
ALTER TABLE `payroll_runs`
  ADD COLUMN `voucher_date` DATE NULL DEFAULT NULL AFTER `pay_date`;
