-- Migration 035: per-company portal logo.
-- Lets each organization show its own logo inside its own portal (alongside the
-- discreet "Powered by M.B. World" chrome). Distinct from the settings-level
-- company_logo_path (which is the firm's invoice/document logo) and from the
-- platform_logo_path (the M.B. World brand). Additive and nullable.

ALTER TABLE `companies`
  ADD COLUMN IF NOT EXISTS `logo_path` VARCHAR(255) DEFAULT NULL AFTER `is_client_company`;
