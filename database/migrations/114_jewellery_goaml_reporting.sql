-- FIU-Nepal / goAML compliance review for dealers in precious metals & stones.
-- Default TTR rule: NPR 1,000,000 per customer in a single or series of
-- transactions in one day (FIU-Nepal TTR Guidelines, updated July 2025).

CREATE TABLE IF NOT EXISTS `jewellery_aml_settings` (
  `company_id` INT UNSIGNED NOT NULL,
  `ttr_threshold` DECIMAL(18,2) NOT NULL DEFAULT 1000000.00,
  `ttr_due_days` SMALLINT UNSIGNED NOT NULL DEFAULT 15,
  `near_threshold_percent` DECIMAL(6,2) NOT NULL DEFAULT 90.00,
  `structuring_min_count` SMALLINT UNSIGNED NOT NULL DEFAULT 3,
  `missing_kyc_threshold` DECIMAL(18,2) NOT NULL DEFAULT 500000.00,
  `rule_version` VARCHAR(40) NOT NULL DEFAULT 'FIU-NP-TTR-2025-07',
  `updated_by` INT UNSIGNED DEFAULT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`company_id`),
  CONSTRAINT `fk_jw_aml_settings_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jw_aml_settings_user` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `jewellery_aml_cases` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `party_id` INT UNSIGNED DEFAULT NULL,
  `case_type` ENUM('TTR','STR','SAR','OTHER') NOT NULL,
  `candidate_kind` VARCHAR(60) NOT NULL,
  `case_date` DATE NOT NULL,
  `period_from` DATE NOT NULL,
  `period_to` DATE NOT NULL,
  `aggregate_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `transaction_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `risk_score` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `rule_code` VARCHAR(80) NOT NULL,
  `rule_version` VARCHAR(40) NOT NULL,
  `reason` TEXT NOT NULL,
  `source_of_funds` VARCHAR(255) DEFAULT NULL,
  `narrative` MEDIUMTEXT DEFAULT NULL,
  `status` ENUM('candidate','under_review','approved','dismissed','filed') NOT NULL DEFAULT 'candidate',
  `due_on` DATE DEFAULT NULL,
  `assigned_to` INT UNSIGNED DEFAULT NULL,
  `reviewed_by` INT UNSIGNED DEFAULT NULL,
  `reviewed_at` DATETIME DEFAULT NULL,
  `filed_by` INT UNSIGNED DEFAULT NULL,
  `filed_at` DATETIME DEFAULT NULL,
  `goaml_reference` VARCHAR(120) DEFAULT NULL,
  `dismissal_reason` TEXT DEFAULT NULL,
  `fingerprint` CHAR(64) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_jw_aml_fingerprint` (`company_id`, `fingerprint`),
  KEY `idx_jw_aml_queue` (`company_id`, `status`, `case_type`, `due_on`),
  KEY `idx_jw_aml_party_date` (`company_id`, `party_id`, `case_date`),
  CONSTRAINT `fk_jw_aml_case_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jw_aml_case_party` FOREIGN KEY (`party_id`) REFERENCES `accounting_parties` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_jw_aml_case_assignee` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_jw_aml_case_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_jw_aml_case_filer` FOREIGN KEY (`filed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `jewellery_aml_case_transactions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `case_id` BIGINT UNSIGNED NOT NULL,
  `company_id` INT UNSIGNED NOT NULL,
  `source_type` ENUM('sale','purchase','settlement') NOT NULL,
  `source_id` INT UNSIGNED NOT NULL,
  `transaction_date` DATE NOT NULL,
  `document_no` VARCHAR(60) NOT NULL,
  `direction` VARCHAR(30) NOT NULL,
  `payment_mode` VARCHAR(30) DEFAULT NULL,
  `amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `details_json` JSON DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_jw_aml_case_source` (`case_id`, `source_type`, `source_id`),
  KEY `idx_jw_aml_txn_source` (`company_id`, `source_type`, `source_id`),
  CONSTRAINT `fk_jw_aml_txn_case` FOREIGN KEY (`case_id`) REFERENCES `jewellery_aml_cases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jw_aml_txn_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `jewellery_aml_case_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `case_id` BIGINT UNSIGNED NOT NULL,
  `company_id` INT UNSIGNED NOT NULL,
  `event_type` VARCHAR(40) NOT NULL,
  `from_status` VARCHAR(30) DEFAULT NULL,
  `to_status` VARCHAR(30) DEFAULT NULL,
  `note` TEXT DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_jw_aml_events_case` (`case_id`, `created_at`),
  CONSTRAINT `fk_jw_aml_event_case` FOREIGN KEY (`case_id`) REFERENCES `jewellery_aml_cases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jw_aml_event_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jw_aml_event_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
