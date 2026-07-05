-- Add business-type excise configuration and invoice excise tracking.
-- Safe to re-run on MariaDB/MySQL variants that support IF NOT EXISTS.

ALTER TABLE `company_accounting_preferences`
  ADD COLUMN IF NOT EXISTS `default_excise_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER `business_type`;

ALTER TABLE `task_invoices`
  ADD COLUMN IF NOT EXISTS `excise_rate` DECIMAL(5,2) DEFAULT 0.00 AFTER `vat_rate`;

ALTER TABLE `task_invoices`
  ADD COLUMN IF NOT EXISTS `excise_amount` DECIMAL(12,2) DEFAULT 0.00 AFTER `excise_rate`;
