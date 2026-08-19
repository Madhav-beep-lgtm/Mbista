-- Payroll: how many hours a working day contains.
--
-- Overtime typed on the salary sheet is paid at
--     basic / working days per month / HOURS PER DAY x overtime multiplier
-- and the divisor was missing, so a day's pay was being charged for one hour:
-- 18,000 / 30 x 1.5 = 900 an hour instead of 18,000 / 30 / 8 x 1.5 = 112.50.
--
-- DEFAULT 8 is the value the code already falls back to when this column does
-- not exist, so applying this migration changes no figure by itself - it only
-- makes the eight editable for a company whose working day is not eight hours.
--
-- Re-runnable: IF NOT EXISTS.

ALTER TABLE payroll_settings
    ADD COLUMN IF NOT EXISTS standard_hours_per_day DECIMAL(5,2) NOT NULL DEFAULT 8.00 AFTER standard_working_days;
