-- 064: Per-company bank accounts on invoices + bilingual service agreements.
--
-- 1. company_bank_accounts: each company keeps its OWN list of bank accounts;
--    invoices issued by that company print its accounts marked
--    show_on_invoice (legacy single-account settings remain the fallback).
CREATE TABLE IF NOT EXISTS `company_bank_accounts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `bank_name` VARCHAR(160) NOT NULL,
  `account_name` VARCHAR(190) NOT NULL,
  `account_number` VARCHAR(80) NOT NULL,
  `branch` VARCHAR(160) DEFAULT NULL,
  `swift_code` VARCHAR(40) DEFAULT NULL,
  `is_default` TINYINT(1) NOT NULL DEFAULT 0,
  `show_on_invoice` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 0,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bank_accounts_company` (`company_id`, `active`, `show_on_invoice`),
  CONSTRAINT `fk_bank_accounts_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. service_agreements: customizable bilingual (Nepali + English) service
--    agreements per client, print-ready in the firm's standard format
--    (cover page, TOC, 10 chapters, Annex-1 scope table, Annex-2 fee table,
--    signature + witness blocks). Purpose is free: bookkeeping, internal
--    audit, consulting, training, or anything else.
CREATE TABLE IF NOT EXISTS `service_agreements` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `client_id` INT UNSIGNED DEFAULT NULL,
  `agreement_no` VARCHAR(60) DEFAULT NULL,
  `purpose_en` VARCHAR(190) NOT NULL DEFAULT 'Accounting and Advisory Services',
  `purpose_np` VARCHAR(190) NOT NULL DEFAULT 'लेखा तथा परामर्श सेवा',
  `first_party_name_en` VARCHAR(190) NOT NULL,
  `first_party_name_np` VARCHAR(190) DEFAULT NULL,
  `first_party_address` VARCHAR(255) DEFAULT NULL,
  `first_party_reg_no` VARCHAR(80) DEFAULT NULL,
  `first_party_signatory` VARCHAR(160) DEFAULT NULL,
  `first_party_position` VARCHAR(160) DEFAULT NULL,
  `second_party_name_en` VARCHAR(190) NOT NULL,
  `second_party_name_np` VARCHAR(190) DEFAULT NULL,
  `second_party_address` VARCHAR(255) DEFAULT NULL,
  `second_party_reg_no` VARCHAR(80) DEFAULT NULL,
  `second_party_signatory` VARCHAR(160) DEFAULT NULL,
  `second_party_position` VARCHAR(160) DEFAULT NULL,
  `agreement_date_bs` VARCHAR(30) DEFAULT NULL,
  `effective_date` DATE DEFAULT NULL,
  `effective_date_bs` VARCHAR(30) DEFAULT NULL,
  `duration_months` INT UNSIGNED NOT NULL DEFAULT 24,
  `trial_months` INT UNSIGNED NOT NULL DEFAULT 1,
  `fee_trial` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `fee_monthly` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `payment_days` INT UNSIGNED NOT NULL DEFAULT 7,
  `termination_notice_days` INT UNSIGNED NOT NULL DEFAULT 3,
  `cure_days` INT UNSIGNED NOT NULL DEFAULT 7,
  `jurisdiction_en` VARCHAR(120) NOT NULL DEFAULT 'the competent court of Kathmandu District',
  `jurisdiction_np` VARCHAR(120) NOT NULL DEFAULT 'काठमाडौँ जिल्लाको सम्बन्धित अदालत',
  `staffing_np` TEXT DEFAULT NULL,
  `staffing_en` TEXT DEFAULT NULL,
  `services_json` TEXT DEFAULT NULL,
  `fee_rows_json` TEXT DEFAULT NULL,
  `witnesses_json` TEXT DEFAULT NULL,
  `custom_clauses_np` TEXT DEFAULT NULL,
  `custom_clauses_en` TEXT DEFAULT NULL,
  `status` ENUM('draft','final') NOT NULL DEFAULT 'draft',
  `created_by` INT UNSIGNED DEFAULT NULL,
  `updated_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_agreements_company` (`company_id`, `status`),
  KEY `idx_agreements_client` (`client_id`),
  CONSTRAINT `fk_agreements_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_agreements_client` FOREIGN KEY (`client_id`) REFERENCES `client_profiles` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
