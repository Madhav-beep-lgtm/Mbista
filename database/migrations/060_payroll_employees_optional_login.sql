-- Employees can exist purely for payroll — without a portal login.
-- 1. The users link becomes optional. Multiple NULLs are fine under the
--    existing UNIQUE (company_id, user_id) key.
ALTER TABLE `payroll_employees`
  MODIFY COLUMN `user_id` INT UNSIGNED DEFAULT NULL;

-- 2. Identity fields for login-less employees live on the payroll row itself.
--    For linked employees these stay NULL and the users row remains the
--    source of truth.
ALTER TABLE `payroll_employees`
  ADD COLUMN `full_name` VARCHAR(160) DEFAULT NULL AFTER `user_id`,
  ADD COLUMN `email` VARCHAR(190) DEFAULT NULL AFTER `full_name`,
  ADD COLUMN `phone` VARCHAR(60) DEFAULT NULL AFTER `email`;
