-- Link payroll to attendance/leave: deduct salary for approved unpaid leave.
-- Unpaid-leave days are counted per run period and cut from basic pay so gross,
-- tax and net all fall like a real absence.

ALTER TABLE `payroll_run_lines`
  ADD COLUMN `unpaid_leave_days` DECIMAL(6,2) NOT NULL DEFAULT 0.00 AFTER `other_deduction`,
  ADD COLUMN `unpaid_leave_deduction` DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER `unpaid_leave_days`;

ALTER TABLE `payroll_settings`
  ADD COLUMN `standard_working_days` DECIMAL(5,2) NOT NULL DEFAULT 30.00,
  ADD COLUMN `deduct_unpaid_leave` TINYINT(1) NOT NULL DEFAULT 1;

ALTER TABLE `leave_types`
  ADD COLUMN `deduct_salary` TINYINT(1) NOT NULL DEFAULT 0;

UPDATE `leave_types`
   SET `deduct_salary` = 1
 WHERE `deduct_salary` = 0
   AND (LOWER(`name`) LIKE '%unpaid%' OR LOWER(`name`) LIKE '%without pay%' OR LOWER(`name`) LIKE '%lwp%');
