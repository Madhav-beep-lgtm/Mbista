
-- Complete application schema for fresh installs.
-- Works for cPanel/phpMyAdmin and local blank databases after selecting or creating the database first.
-- This file intentionally does not run CREATE DATABASE or USE, because many cPanel database users cannot run those commands.

CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(120) NOT NULL,
  `email` VARCHAR(190) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('admin', 'staff', 'customer') NOT NULL DEFAULT 'customer',
  `access_level` ENUM('super_admin', 'parent_admin', 'subsidiary_admin', 'accountant', 'approver', 'viewer', 'support') NOT NULL DEFAULT 'accountant',
  `status` ENUM('active', 'inactive', 'invited', 'suspended', 'locked') NOT NULL DEFAULT 'active',
  `must_change_password` TINYINT(1) NOT NULL DEFAULT 0,
  `sessions_valid_from` DATETIME DEFAULT NULL,
  `company_id` INT UNSIGNED DEFAULT NULL,
  `phone` VARCHAR(30) DEFAULT NULL,
  `company` VARCHAR(120) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_users_email` (`email`),
  KEY `idx_users_company` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `plans` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(120) NOT NULL,
  `slug` VARCHAR(140) NOT NULL,
  `billing_cycle` ENUM('monthly', 'yearly') NOT NULL DEFAULT 'monthly',
  `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'USD',
  `disk_space_gb` INT UNSIGNED NOT NULL DEFAULT 0,
  `bandwidth_gb` INT UNSIGNED NOT NULL DEFAULT 0,
  `email_accounts` INT UNSIGNED NOT NULL DEFAULT 0,
  `databases_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `domains_allowed` INT UNSIGNED NOT NULL DEFAULT 1,
  `features` TEXT DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_plans_slug` (`slug`),
  KEY `idx_plans_active_sort` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `orders` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED DEFAULT NULL,
  `user_id` INT UNSIGNED DEFAULT NULL,
  `plan_id` INT UNSIGNED NOT NULL,
  `full_name` VARCHAR(120) NOT NULL,
  `email` VARCHAR(190) NOT NULL,
  `phone` VARCHAR(30) DEFAULT NULL,
  `domain_name` VARCHAR(190) NOT NULL,
  `billing_cycle` ENUM('monthly', 'yearly') NOT NULL DEFAULT 'monthly',
  `amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `payment_method` VARCHAR(40) NOT NULL DEFAULT 'manual',
  `payment_status` ENUM('pending', 'paid', 'failed', 'refunded') NOT NULL DEFAULT 'pending',
  `transaction_id` VARCHAR(190) DEFAULT NULL,
  `status` ENUM('pending', 'confirmed', 'processing', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
  `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_orders_company_id` (`company_id`),
  KEY `idx_orders_user_id` (`user_id`),
  KEY `idx_orders_plan_id` (`plan_id`),
  KEY `idx_orders_status_created` (`status`, `created_at`),
  CONSTRAINT `fk_orders_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_orders_plan` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `contacts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED DEFAULT NULL,
  `name` VARCHAR(120) NOT NULL,
  `email` VARCHAR(190) NOT NULL,
  `subject` VARCHAR(190) NOT NULL,
  `message` TEXT NOT NULL,
  `status` ENUM('new', 'read', 'replied') NOT NULL DEFAULT 'new',
  `attachment_path` VARCHAR(255) DEFAULT NULL,
  `attachment_name` VARCHAR(190) DEFAULT NULL,
  `priority` ENUM('low', 'normal', 'high', 'urgent') NOT NULL DEFAULT 'normal',
  `assigned_admin_id` INT UNSIGNED DEFAULT NULL,
  `replied_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_contacts_company_id` (`company_id`),
  KEY `idx_contacts_status_created` (`status`, `created_at`),
  KEY `idx_contacts_assigned_admin` (`assigned_admin_id`),
  CONSTRAINT `fk_contacts_assigned_admin` FOREIGN KEY (`assigned_admin_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `activity_logs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `entity_type` VARCHAR(50) NOT NULL,
  `entity_id` INT UNSIGNED NOT NULL,
  `action` VARCHAR(80) NOT NULL,
  `details` TEXT DEFAULT NULL,
  `actor_id` INT UNSIGNED DEFAULT NULL,
  `ip_address` VARCHAR(60) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_activity_entity` (`entity_type`, `entity_id`, `created_at`),
  KEY `idx_activity_actor` (`actor_id`),
  CONSTRAINT `fk_activity_actor` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `password_resets` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `used_at` DATETIME DEFAULT NULL,
  `requested_ip` VARCHAR(45) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_password_resets_token_hash` (`token_hash`),
  KEY `idx_password_resets_user` (`user_id`),
  KEY `idx_password_resets_expiry` (`expires_at`),
  CONSTRAINT `fk_password_resets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `companies` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(150) NOT NULL,
  `code` VARCHAR(30) NOT NULL,
  `parent_company_id` INT UNSIGNED DEFAULT NULL,
  `admin_pin_hash` VARCHAR(255) DEFAULT NULL,
  `admin_pin_reset_token_hash` CHAR(64) DEFAULT NULL,
  `admin_pin_reset_expires_at` DATETIME DEFAULT NULL,
  `admin_pin_reset_used_at` DATETIME DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `is_client_company` TINYINT(1) NOT NULL DEFAULT 0,
  `logo_path` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_companies_code` (`code`),
  KEY `idx_companies_active` (`is_active`),
  KEY `idx_companies_parent` (`parent_company_id`),
  CONSTRAINT `fk_companies_parent` FOREIGN KEY (`parent_company_id`) REFERENCES `companies` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fiscal_years` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `label` VARCHAR(80) NOT NULL,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `is_default` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_fiscal_years_company` (`company_id`),
  KEY `idx_fiscal_years_active` (`is_active`),
  CONSTRAINT `fk_fiscal_years_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `company_shareholdings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `investor_company_id` INT UNSIGNED NOT NULL,
  `investee_company_id` INT UNSIGNED NOT NULL,
  `ownership_percent` DECIMAL(5,2) NOT NULL DEFAULT 100.00,
  `relationship_type` ENUM('subsidiary', 'associate', 'joint_venture', 'investment') NOT NULL DEFAULT 'subsidiary',
  `consolidation_method` ENUM('full', 'equity', 'proportionate', 'cost') NOT NULL DEFAULT 'full',
  `effective_from` DATE DEFAULT NULL,
  `effective_to` DATE DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_company_shareholding_pair` (`investor_company_id`, `investee_company_id`),
  KEY `idx_company_shareholdings_investor` (`investor_company_id`),
  KEY `idx_company_shareholdings_investee` (`investee_company_id`),
  CONSTRAINT `fk_company_shareholdings_investor` FOREIGN KEY (`investor_company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_company_shareholdings_investee` FOREIGN KEY (`investee_company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ledger_groups` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `master_key` ENUM(
      'equity', 'non_current_liability', 'current_liability',
      'non_current_asset', 'current_asset',
      'direct_income', 'indirect_income', 'direct_expense', 'indirect_expense'
  ) NOT NULL,
  `parent_group_id` INT UNSIGNED DEFAULT NULL,
  `code` VARCHAR(40) NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `is_cash_or_bank` TINYINT(1) NOT NULL DEFAULT 0,
  `is_system` TINYINT(1) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_ledger_groups_company_code` (`company_id`, `code`),
  KEY `idx_ledger_groups_company_master` (`company_id`, `master_key`),
  KEY `idx_ledger_groups_cash_bank` (`company_id`, `is_cash_or_bank`),
  KEY `idx_ledger_groups_parent` (`parent_group_id`),
  CONSTRAINT `fk_ledger_groups_parent` FOREIGN KEY (`parent_group_id`) REFERENCES `ledger_groups`(`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_ledger_groups_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ledgers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `group_id` INT UNSIGNED DEFAULT NULL,
  `code` VARCHAR(40) NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `bank_name` VARCHAR(120) DEFAULT NULL,
  `bank_account_no` VARCHAR(40) DEFAULT NULL,
  `type` ENUM('asset', 'liability', 'equity', 'revenue', 'expense') NOT NULL,
  `is_system` TINYINT(1) NOT NULL DEFAULT 0,
  `status` ENUM('active', 'suspended') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_ledgers_company_code` (`company_id`, `code`),
  KEY `idx_ledgers_company` (`company_id`),
  KEY `idx_ledgers_group` (`group_id`),
  CONSTRAINT `fk_ledgers_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ledgers_group` FOREIGN KEY (`group_id`) REFERENCES `ledger_groups` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `company_ledger_mappings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `map_key` VARCHAR(80) NOT NULL COMMENT 'e.g., default_accounts_receivable, default_vat_payable',
  `ledger_id` INT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_company_ledger_mapping` (`company_id`, `map_key`),
  KEY `idx_company_ledger_mappings_ledger` (`ledger_id`),
  CONSTRAINT `fk_company_ledger_mappings_company` FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_company_ledger_mappings_ledger` FOREIGN KEY (`ledger_id`) REFERENCES `ledgers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `company_accounting_preferences` (
  `company_id` INT UNSIGNED NOT NULL,
  `business_type` ENUM('service', 'trading', 'manufacturing') NOT NULL DEFAULT 'service',
  `default_excise_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `updated_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`company_id`),
  KEY `idx_company_accounting_preferences_updated_by` (`updated_by`),
  CONSTRAINT `fk_company_accounting_preferences_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_company_accounting_preferences_user` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `report_schedules` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `report_key` VARCHAR(60) NOT NULL,
  `recipient_email` VARCHAR(190) NOT NULL,
  `frequency` ENUM('daily', 'weekly', 'monthly') NOT NULL DEFAULT 'monthly',
  `export_format` ENUM('csv', 'html', 'both') NOT NULL DEFAULT 'both',
  `filters` TEXT DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `last_run_at` DATETIME DEFAULT NULL,
  `last_run_status` VARCHAR(255) DEFAULT NULL,
  `next_run_on` DATE NOT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_report_schedules_company` (`company_id`),
  KEY `idx_report_schedules_due` (`is_active`, `next_run_on`),
  CONSTRAINT `fk_report_schedules_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_report_schedules_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `vouchers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `fiscal_year_id` INT UNSIGNED NOT NULL,
  `voucher_no` VARCHAR(80) NOT NULL,
  `voucher_date` DATE DEFAULT NULL,
  `posting_date` DATE DEFAULT NULL,
  `due_date` DATE DEFAULT NULL,
  `payment_terms` VARCHAR(40) DEFAULT NULL,
  `exchange_rate` DECIMAL(12,4) NOT NULL DEFAULT 1.0000,
  `tax_category` VARCHAR(40) DEFAULT NULL,
  `voucher_type` ENUM('payment', 'receipt', 'journal', 'sales', 'purchase', 'contra', 'debit_note', 'credit_note') NOT NULL DEFAULT 'journal',
  `source_type` VARCHAR(80) DEFAULT NULL,
  `source_id` INT UNSIGNED DEFAULT NULL,
  `party_id` INT UNSIGNED DEFAULT NULL,
  `reference_no` VARCHAR(120) DEFAULT NULL,
  `narration` TEXT DEFAULT NULL,
  `priority` ENUM('low', 'medium', 'high') NOT NULL DEFAULT 'medium',
  `department` VARCHAR(80) DEFAULT NULL,
  `location` VARCHAR(80) DEFAULT NULL,
  `cost_centre` VARCHAR(80) DEFAULT NULL,
  `total_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('draft', 'posted', 'cancelled') NOT NULL DEFAULT 'posted',
  `approval_state` ENUM('draft', 'pending_approval', 'approved', 'rejected') NOT NULL DEFAULT 'approved',
  `requires_client_approval` TINYINT(1) NOT NULL DEFAULT 0,
  `client_approved_by` INT UNSIGNED DEFAULT NULL,
  `client_approved_at` DATETIME DEFAULT NULL,
  `submitted_by` INT UNSIGNED DEFAULT NULL,
  `approved_by` INT UNSIGNED DEFAULT NULL,
  `approved_at` DATETIME DEFAULT NULL,
  `rejection_reason` VARCHAR(255) DEFAULT NULL,
  `posted_by` INT UNSIGNED DEFAULT NULL,
  `posted_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_vouchers_no_company` (`company_id`, `voucher_no`),
  UNIQUE KEY `uniq_vouchers_source` (`source_type`, `source_id`),
  KEY `idx_vouchers_company_fiscal` (`company_id`, `fiscal_year_id`),
  KEY `idx_vouchers_date` (`company_id`, `fiscal_year_id`, `voucher_date`),
  KEY `idx_vouchers_party` (`party_id`),
  CONSTRAINT `fk_vouchers_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_vouchers_fiscal_year` FOREIGN KEY (`fiscal_year_id`) REFERENCES `fiscal_years` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_vouchers_posted_by` FOREIGN KEY (`posted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `voucher_entries` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `voucher_id` INT UNSIGNED NOT NULL,
  `ledger_id` INT UNSIGNED NOT NULL,
  `entry_type` ENUM('debit', 'credit') NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `memo` VARCHAR(255) DEFAULT NULL,
  `cost_centre` VARCHAR(80) DEFAULT NULL,
  `tax_code` VARCHAR(40) DEFAULT NULL,
  `line_reference` VARCHAR(120) DEFAULT NULL,
  `reconciled_at` DATETIME DEFAULT NULL,
  `reconciled_by` INT UNSIGNED DEFAULT NULL,
  `statement_date` DATE DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_voucher_entries_voucher` (`voucher_id`),
  KEY `idx_voucher_entries_ledger` (`ledger_id`),
  KEY `idx_voucher_entries_reconciled` (`ledger_id`, `reconciled_at`),
  CONSTRAINT `fk_voucher_entries_voucher` FOREIGN KEY (`voucher_id`) REFERENCES `vouchers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_voucher_entries_ledger` FOREIGN KEY (`ledger_id`) REFERENCES `ledgers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `voucher_attachments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `voucher_id` INT UNSIGNED NOT NULL,
  `file_path` VARCHAR(255) NOT NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `file_size` INT UNSIGNED NOT NULL DEFAULT 0,
  `uploaded_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_voucher_attachments_voucher` (`voucher_id`),
  CONSTRAINT `fk_voucher_attachments_voucher` FOREIGN KEY (`voucher_id`) REFERENCES `vouchers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `settings` (
  `setting_key` VARCHAR(100) NOT NULL,
  `setting_value` LONGTEXT DEFAULT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `teams` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED DEFAULT NULL,
  `name` VARCHAR(140) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `leader_user_id` INT UNSIGNED NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_teams_name` (`name`),
  KEY `idx_teams_company` (`company_id`),
  KEY `idx_teams_leader` (`leader_user_id`),
  CONSTRAINT `fk_teams_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_teams_leader` FOREIGN KEY (`leader_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `team_members` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `team_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `member_role` VARCHAR(80) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_team_member` (`team_id`, `user_id`),
  KEY `idx_team_members_team` (`team_id`),
  KEY `idx_team_members_user` (`user_id`),
  CONSTRAINT `fk_team_members_team` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_team_members_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `industries` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(160) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_industries_name` (`name`),
  KEY `idx_industries_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `service_provider_entities` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED DEFAULT NULL,
  `name` VARCHAR(190) NOT NULL,
  `code` VARCHAR(60) DEFAULT NULL,
  `logo_path` VARCHAR(255) DEFAULT NULL,
  `contact_email` VARCHAR(190) DEFAULT NULL,
  `contact_number` VARCHAR(60) DEFAULT NULL,
  `address` TEXT DEFAULT NULL,
  `website` VARCHAR(255) DEFAULT NULL,
  `authorized_signatory_name` VARCHAR(190) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_service_provider_entities_code` (`code`),
  KEY `idx_service_provider_entities_company` (`company_id`),
  KEY `idx_service_provider_entities_active` (`is_active`),
  CONSTRAINT `fk_service_provider_entities_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `client_profiles` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `company_id` INT UNSIGNED DEFAULT NULL,
  `books_company_id` INT UNSIGNED DEFAULT NULL,
  `organization_name` VARCHAR(190) NOT NULL,
  `client_code` VARCHAR(60) DEFAULT NULL,
  `registration_no` VARCHAR(80) DEFAULT NULL,
  `industry_id` INT UNSIGNED DEFAULT NULL,
  `assigned_staff_user_id` INT UNSIGNED DEFAULT NULL,
  `assigned_team_id` INT UNSIGNED DEFAULT NULL,
  `address` VARCHAR(255) DEFAULT NULL,
  `pan_no` VARCHAR(60) DEFAULT NULL,
  `authorized_person_position` VARCHAR(140) DEFAULT NULL,
  `authorized_signatory_name` VARCHAR(190) DEFAULT NULL,
  `contact_number` VARCHAR(60) DEFAULT NULL,
  `website` VARCHAR(255) DEFAULT NULL,
  `company_logo_path` VARCHAR(255) DEFAULT NULL,
  `client_status` ENUM('active', 'suspended', 'inactive') NOT NULL DEFAULT 'active',
  `notes` TEXT DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_client_profiles_user` (`user_id`),
  UNIQUE KEY `uniq_client_profiles_code` (`client_code`),
  KEY `idx_client_profiles_company` (`company_id`),
  KEY `idx_client_profiles_staff` (`assigned_staff_user_id`),
  KEY `idx_client_profiles_team` (`assigned_team_id`),
  KEY `idx_client_profiles_industry` (`industry_id`),
  KEY `idx_client_profiles_status` (`client_status`),
  KEY `idx_client_profiles_registration` (`registration_no`),
  KEY `idx_client_profiles_pan` (`pan_no`),
  CONSTRAINT `fk_client_profiles_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_client_profiles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_client_profiles_staff` FOREIGN KEY (`assigned_staff_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_client_profiles_team` FOREIGN KEY (`assigned_team_id`) REFERENCES `teams` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `client_service_provider_entities` (
  `client_id` INT UNSIGNED NOT NULL,
  `service_provider_entity_id` INT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`client_id`, `service_provider_entity_id`),
  KEY `idx_client_service_provider_entity` (`service_provider_entity_id`),
  CONSTRAINT `fk_client_service_provider_client` FOREIGN KEY (`client_id`) REFERENCES `client_profiles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_client_service_provider_entity` FOREIGN KEY (`service_provider_entity_id`) REFERENCES `service_provider_entities` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `service_contracts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED DEFAULT NULL,
  `client_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(190) NOT NULL,
  `contract_no` VARCHAR(100) NOT NULL,
  `start_date` DATE DEFAULT NULL,
  `end_date` DATE DEFAULT NULL,
  `total_value` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `billing_cycle` VARCHAR(40) DEFAULT NULL,
  `status` ENUM('draft', 'active', 'completed', 'terminated') NOT NULL DEFAULT 'draft',
  `terms` TEXT DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_service_contract_no` (`contract_no`),
  KEY `idx_service_contract_company` (`company_id`),
  KEY `idx_service_contract_client` (`client_id`),
  KEY `idx_service_contract_created_by` (`created_by`),
  CONSTRAINT `fk_service_contract_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_service_contract_client` FOREIGN KEY (`client_id`) REFERENCES `client_profiles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_service_contract_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `client_tasks` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED DEFAULT NULL,
  `client_id` INT UNSIGNED NOT NULL,
  `service_provider_entity_id` INT UNSIGNED DEFAULT NULL,
  `contract_id` INT UNSIGNED DEFAULT NULL,
  `team_id` INT UNSIGNED DEFAULT NULL,
  `assigned_staff_user_id` INT UNSIGNED DEFAULT NULL,
  `title` VARCHAR(190) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `quoted_fee` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('new', 'in_progress', 'on_hold', 'completed', 'cancelled') NOT NULL DEFAULT 'new',
  `priority` ENUM('low', 'normal', 'high', 'urgent') NOT NULL DEFAULT 'normal',
  `start_date` DATE DEFAULT NULL,
  `due_date` DATE DEFAULT NULL,
  `completed_at` DATETIME DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_client_tasks_company` (`company_id`),
  KEY `idx_client_tasks_client` (`client_id`),
  KEY `idx_client_tasks_team` (`team_id`),
  KEY `idx_client_tasks_contract` (`contract_id`),
  KEY `idx_client_tasks_status` (`status`),
  KEY `idx_client_tasks_assigned_staff` (`assigned_staff_user_id`),
  KEY `idx_client_tasks_service_provider_entity` (`service_provider_entity_id`),
  CONSTRAINT `fk_client_tasks_service_provider_entity` FOREIGN KEY (`service_provider_entity_id`) REFERENCES `service_provider_entities` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_client_tasks_assigned_staff` FOREIGN KEY (`assigned_staff_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_client_tasks_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_client_tasks_client` FOREIGN KEY (`client_id`) REFERENCES `client_profiles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_client_tasks_team` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_client_tasks_contract` FOREIGN KEY (`contract_id`) REFERENCES `service_contracts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_client_tasks_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `task_stages` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED DEFAULT NULL,
  `task_id` INT UNSIGNED NOT NULL,
  `stage_name` VARCHAR(190) NOT NULL,
  `sequence_no` INT NOT NULL DEFAULT 1,
  `stage_fee` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `assigned_staff_user_id` INT UNSIGNED DEFAULT NULL,
  `status` ENUM('pending', 'in_progress', 'completed') NOT NULL DEFAULT 'pending',
  `completed_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_task_stages_company` (`company_id`),
  KEY `idx_task_stages_task` (`task_id`),
  KEY `idx_task_stages_status` (`status`),
  KEY `idx_task_stages_assigned_staff` (`assigned_staff_user_id`),
  CONSTRAINT `fk_task_stages_assigned_staff` FOREIGN KEY (`assigned_staff_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_task_stages_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_task_stages_task` FOREIGN KEY (`task_id`) REFERENCES `client_tasks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `task_invoices` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED DEFAULT NULL,
  `task_id` INT UNSIGNED DEFAULT NULL,
  `stage_id` INT UNSIGNED DEFAULT NULL,
  `invoice_no` VARCHAR(100) NOT NULL,
  `invoice_type` ENUM('stage', 'task', 'inventory', 'manufacturing', 'other') NOT NULL DEFAULT 'task',
  `invoice_source_type` ENUM('task', 'inventory', 'manufacturing', 'other') NOT NULL DEFAULT 'task',
  `source_id` INT UNSIGNED DEFAULT NULL,
  `party_id` INT UNSIGNED DEFAULT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `invoice_category` ENUM('proforma', 'tax') DEFAULT 'proforma',
  `tax_invoice_id` INT UNSIGNED DEFAULT NULL,
  `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `vat_rate` DECIMAL(5,2) DEFAULT 13.00,
  `excise_rate` DECIMAL(5,2) DEFAULT 0.00,
  `excise_amount` DECIMAL(12,2) DEFAULT 0.00,
  `vat_amount` DECIMAL(12,2) DEFAULT 0.00,
  `taxable_amount` DECIMAL(12,2) DEFAULT 0.00,
  `total_amount` DECIMAL(12,2) DEFAULT 0.00,
  `discount_type` ENUM('none', 'percent', 'fixed') NOT NULL DEFAULT 'none',
  `discount_value` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `discount_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `adjusted_on` TIMESTAMP NULL DEFAULT NULL,
  `adjusted_by` INT UNSIGNED DEFAULT NULL,
  `tax_invoice_date` DATE DEFAULT NULL,
  `pan_number` VARCHAR(20) DEFAULT NULL,
  `vat_reg_number` VARCHAR(20) DEFAULT NULL,
  `converted_to_tax_on` TIMESTAMP NULL DEFAULT NULL,
  `converted_by` INT UNSIGNED DEFAULT NULL,
  `status` ENUM('draft', 'issued', 'paid', 'cancelled') NOT NULL DEFAULT 'issued',
  `issued_on` DATE NOT NULL,
  `due_on` DATE DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `issued_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_task_invoice_no` (`invoice_no`),
  KEY `idx_task_invoices_company` (`company_id`),
  KEY `idx_task_invoices_task` (`task_id`),
  KEY `idx_task_invoices_stage` (`stage_id`),
  KEY `idx_task_invoices_category` (`invoice_category`),
  KEY `idx_task_invoices_tax_invoice` (`tax_invoice_id`),
  KEY `idx_task_invoices_discount_type` (`discount_type`),
  KEY `idx_task_invoices_source` (`company_id`, `invoice_source_type`, `source_id`),
  KEY `idx_task_invoices_party` (`party_id`),
  CONSTRAINT `fk_task_invoices_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_task_invoices_task` FOREIGN KEY (`task_id`) REFERENCES `client_tasks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_task_invoices_stage` FOREIGN KEY (`stage_id`) REFERENCES `task_stages` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_task_invoices_issued_by` FOREIGN KEY (`issued_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_task_invoices_tax_invoice` FOREIGN KEY (`tax_invoice_id`) REFERENCES `task_invoices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_task_invoices_converted_by` FOREIGN KEY (`converted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_task_invoices_adjusted_by` FOREIGN KEY (`adjusted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `invoice_payment_requests` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `invoice_id` INT UNSIGNED NOT NULL,
  `company_id` INT UNSIGNED DEFAULT NULL,
  `requested_by` INT UNSIGNED NOT NULL,
  `amount_requested` DECIMAL(12,2) NOT NULL,
  `payment_method` VARCHAR(100) DEFAULT NULL,
  `status` ENUM('pending', 'paid', 'partial', 'cancelled') DEFAULT 'pending',
  `payment_received_on` DATE DEFAULT NULL,
  `payment_amount` DECIMAL(12,2) DEFAULT 0.00,
  `notes` TEXT DEFAULT NULL,
  `client_declared_status` ENUM('none', 'partial', 'complete') NOT NULL DEFAULT 'none',
  `client_declared_amount` DECIMAL(12,2) DEFAULT NULL,
  `client_declared_method` VARCHAR(100) DEFAULT NULL,
  `client_declared_reference` VARCHAR(190) DEFAULT NULL,
  `client_declared_on` DATE DEFAULT NULL,
  `client_declared_note` TEXT DEFAULT NULL,
  `client_declared_at` TIMESTAMP NULL DEFAULT NULL,
  `requested_on` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_invoice_payment_requests_invoice` (`invoice_id`),
  KEY `idx_invoice_payment_requests_company` (`company_id`),
  KEY `idx_invoice_payment_requests_status` (`status`),
  KEY `idx_invoice_payment_requests_declared` (`client_declared_status`),
  CONSTRAINT `fk_invoice_payment_requests_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `task_invoices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_invoice_payment_requests_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `invoice_payment_receipts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `payment_request_id` INT UNSIGNED NOT NULL,
  `invoice_id` INT UNSIGNED NOT NULL,
  `company_id` INT UNSIGNED NOT NULL,
  `client_id` INT UNSIGNED DEFAULT NULL,
  `receipt_no` VARCHAR(60) NOT NULL,
  `amount_received` DECIMAL(12,2) NOT NULL,
  `payment_method` VARCHAR(100) DEFAULT NULL,
  `payment_reference` VARCHAR(190) DEFAULT NULL,
  `received_on` DATE NOT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_invoice_payment_receipts_no` (`receipt_no`),
  KEY `idx_invoice_payment_receipts_request` (`payment_request_id`),
  KEY `idx_invoice_payment_receipts_invoice` (`invoice_id`),
  KEY `idx_invoice_payment_receipts_company` (`company_id`),
  CONSTRAINT `fk_invoice_payment_receipts_request` FOREIGN KEY (`payment_request_id`) REFERENCES `invoice_payment_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_invoice_payment_receipts_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `task_invoices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_invoice_payment_receipts_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_invoice_payment_receipts_client` FOREIGN KEY (`client_id`) REFERENCES `client_profiles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_invoice_payment_receipts_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `documents` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `client_id` INT UNSIGNED NOT NULL,
  `task_id` INT UNSIGNED DEFAULT NULL,
  `parent_document_id` INT UNSIGNED DEFAULT NULL,
  `version_number` INT UNSIGNED NOT NULL DEFAULT 1,
  `category` ENUM(
      'registration_kyc', 'accounting_records', 'tax_documents', 'audit_documents',
      'reports', 'certificates', 'invoices_receipts', 'correspondence', 'other'
  ) NOT NULL DEFAULT 'other',
  `title` VARCHAR(190) NOT NULL,
  `original_file_name` VARCHAR(255) NOT NULL,
  `stored_file_name` VARCHAR(255) NOT NULL,
  `file_path` VARCHAR(255) NOT NULL,
  `file_type` VARCHAR(100) DEFAULT NULL,
  `file_size` INT UNSIGNED NOT NULL DEFAULT 0,
  `visibility` ENUM('internal', 'client') NOT NULL DEFAULT 'internal',
  `approval_status` ENUM('draft', 'under_review', 'approved', 'rejected') NOT NULL DEFAULT 'approved',
  `approved_by` INT UNSIGNED DEFAULT NULL,
  `approved_at` DATETIME DEFAULT NULL,
  `description` TEXT DEFAULT NULL,
  `uploaded_by` INT UNSIGNED DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_documents_company` (`company_id`),
  KEY `idx_documents_client` (`client_id`),
  KEY `idx_documents_task` (`task_id`),
  KEY `idx_documents_parent` (`parent_document_id`),
  CONSTRAINT `fk_documents_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_documents_client` FOREIGN KEY (`client_id`) REFERENCES `client_profiles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_documents_task` FOREIGN KEY (`task_id`) REFERENCES `client_tasks` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_documents_parent` FOREIGN KEY (`parent_document_id`) REFERENCES `documents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_documents_uploaded_by` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `document_requests` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `client_id` INT UNSIGNED NOT NULL,
  `task_id` INT UNSIGNED DEFAULT NULL,
  `document_id` INT UNSIGNED DEFAULT NULL,
  `title` VARCHAR(190) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `priority` ENUM('low', 'normal', 'high', 'urgent') NOT NULL DEFAULT 'normal',
  `due_date` DATE DEFAULT NULL,
  `status` ENUM('requested', 'uploaded', 'under_review', 'accepted', 'rejected', 'waived') NOT NULL DEFAULT 'requested',
  `requested_by` INT UNSIGNED DEFAULT NULL,
  `staff_comment` TEXT DEFAULT NULL,
  `client_comment` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_doc_requests_company` (`company_id`),
  KEY `idx_doc_requests_client` (`client_id`),
  KEY `idx_doc_requests_status` (`status`),
  CONSTRAINT `fk_doc_requests_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_doc_requests_client` FOREIGN KEY (`client_id`) REFERENCES `client_profiles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_doc_requests_task` FOREIGN KEY (`task_id`) REFERENCES `client_tasks` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_doc_requests_document` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_doc_requests_requested_by` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `compliance_types` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `is_system` TINYINT(1) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_compliance_types_company_name` (`company_id`, `name`),
  CONSTRAINT `fk_compliance_types_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `compliance_deadlines` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `client_id` INT UNSIGNED NOT NULL,
  `compliance_type_id` INT UNSIGNED NOT NULL,
  `task_id` INT UNSIGNED DEFAULT NULL,
  `document_id` INT UNSIGNED DEFAULT NULL,
  `applicable_period` VARCHAR(100) NOT NULL,
  `statutory_due_date` DATE NOT NULL,
  `internal_due_date` DATE DEFAULT NULL,
  `assigned_staff_user_id` INT UNSIGNED DEFAULT NULL,
  `reviewer_user_id` INT UNSIGNED DEFAULT NULL,
  `status` ENUM(
      'not_started', 'upcoming', 'in_progress', 'waiting_for_client',
      'filed', 'completed', 'overdue', 'not_applicable'
  ) NOT NULL DEFAULT 'not_started',
  `filing_date` DATE DEFAULT NULL,
  `filing_reference` VARCHAR(150) DEFAULT NULL,
  `remarks` TEXT DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_compliance_deadlines_company` (`company_id`),
  KEY `idx_compliance_deadlines_client` (`client_id`),
  KEY `idx_compliance_deadlines_due` (`statutory_due_date`),
  KEY `idx_compliance_deadlines_status` (`status`),
  CONSTRAINT `fk_compliance_deadlines_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_compliance_deadlines_client` FOREIGN KEY (`client_id`) REFERENCES `client_profiles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_compliance_deadlines_type` FOREIGN KEY (`compliance_type_id`) REFERENCES `compliance_types` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_compliance_deadlines_task` FOREIGN KEY (`task_id`) REFERENCES `client_tasks` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_compliance_deadlines_document` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_compliance_deadlines_staff` FOREIGN KEY (`assigned_staff_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_compliance_deadlines_reviewer` FOREIGN KEY (`reviewer_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `compliance_types` (`company_id`, `name`, `is_system`)
SELECT c.id, seed.name, 1
FROM `companies` c
JOIN (
    SELECT 'VAT Return' AS name
    UNION ALL SELECT 'TDS Return'
    UNION ALL SELECT 'Income Tax Instalment'
    UNION ALL SELECT 'Annual Income Tax Return'
    UNION ALL SELECT 'Company Annual Return'
    UNION ALL SELECT 'AGM'
    UNION ALL SELECT 'Audit Completion'
    UNION ALL SELECT 'Social Security Fund Payment'
    UNION ALL SELECT 'Payroll Processing'
    UNION ALL SELECT 'Internal Audit Report'
    UNION ALL SELECT 'Engagement Renewal'
) seed
WHERE c.is_active = 1
  AND NOT EXISTS (
      SELECT 1 FROM `compliance_types` existing
      WHERE existing.company_id = c.id AND existing.name = seed.name
  );

CREATE TABLE IF NOT EXISTS `message_threads` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `client_id` INT UNSIGNED DEFAULT NULL,
  `subject` VARCHAR(190) NOT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `is_archived` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_message_threads_company` (`company_id`),
  KEY `idx_message_threads_client` (`client_id`),
  CONSTRAINT `fk_message_threads_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_message_threads_client` FOREIGN KEY (`client_id`) REFERENCES `client_profiles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_message_threads_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `message_thread_participants` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `thread_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `last_read_at` DATETIME DEFAULT NULL,
  `joined_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_thread_participant` (`thread_id`, `user_id`),
  KEY `idx_thread_participants_user` (`user_id`),
  CONSTRAINT `fk_thread_participants_thread` FOREIGN KEY (`thread_id`) REFERENCES `message_threads` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_thread_participants_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `messages` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `thread_id` INT UNSIGNED NOT NULL,
  `sender_id` INT UNSIGNED NOT NULL,
  `body` TEXT NOT NULL,
  `attachment_path` VARCHAR(255) DEFAULT NULL,
  `attachment_name` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_messages_thread` (`thread_id`),
  CONSTRAINT `fk_messages_thread` FOREIGN KEY (`thread_id`) REFERENCES `message_threads` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_messages_sender` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `support_tickets` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `client_id` INT UNSIGNED NOT NULL,
  `ticket_no` VARCHAR(40) NOT NULL,
  `subject` VARCHAR(190) NOT NULL,
  `category` ENUM('general', 'technical', 'billing', 'document', 'other') NOT NULL DEFAULT 'general',
  `priority` ENUM('low', 'normal', 'high', 'urgent') NOT NULL DEFAULT 'normal',
  `description` TEXT NOT NULL,
  `status` ENUM('open', 'assigned', 'in_progress', 'waiting_for_client', 'resolved', 'closed') NOT NULL DEFAULT 'open',
  `sla_due_at` DATETIME DEFAULT NULL,
  `resolved_at` DATETIME DEFAULT NULL,
  `assigned_staff_user_id` INT UNSIGNED DEFAULT NULL,
  `resolution` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_support_tickets_no` (`ticket_no`),
  KEY `idx_support_tickets_company` (`company_id`),
  KEY `idx_support_tickets_client` (`client_id`),
  KEY `idx_support_tickets_status` (`status`),
  CONSTRAINT `fk_support_tickets_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_support_tickets_client` FOREIGN KEY (`client_id`) REFERENCES `client_profiles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_support_tickets_staff` FOREIGN KEY (`assigned_staff_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `support_ticket_messages` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id` INT UNSIGNED NOT NULL,
  `sender_id` INT UNSIGNED NOT NULL,
  `body` TEXT NOT NULL,
  `attachment_path` VARCHAR(255) DEFAULT NULL,
  `attachment_name` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ticket_messages_ticket` (`ticket_id`),
  CONSTRAINT `fk_ticket_messages_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `support_tickets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ticket_messages_sender` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `support_ticket_requests` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id` INT UNSIGNED NOT NULL,
  `company_id` INT UNSIGNED NOT NULL,
  `client_id` INT UNSIGNED NOT NULL,
  `request_type` ENUM('invoice_request', 'discount_request', 'credit_period_request', 'advance_payment_request', 'partial_payment_request') NOT NULL,
  `target_task_id` INT UNSIGNED DEFAULT NULL,
  `target_stage_id` INT UNSIGNED DEFAULT NULL,
  `target_invoice_id` INT UNSIGNED DEFAULT NULL,
  `requested_amount` DECIMAL(12,2) DEFAULT NULL,
  `requested_percent` DECIMAL(6,2) DEFAULT NULL,
  `requested_due_on` DATE DEFAULT NULL,
  `decision_status` ENUM('pending', 'approved', 'rejected', 'negotiation', 'deferred') NOT NULL DEFAULT 'pending',
  `approved_amount` DECIMAL(12,2) DEFAULT NULL,
  `approved_percent` DECIMAL(6,2) DEFAULT NULL,
  `approved_due_on` DATE DEFAULT NULL,
  `applied_invoice_id` INT UNSIGNED DEFAULT NULL,
  `admin_note` TEXT DEFAULT NULL,
  `processed_by` INT UNSIGNED DEFAULT NULL,
  `processed_on` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_support_ticket_request_ticket` (`ticket_id`),
  KEY `idx_support_ticket_requests_company` (`company_id`),
  KEY `idx_support_ticket_requests_client` (`client_id`),
  KEY `idx_support_ticket_requests_type` (`request_type`),
  KEY `idx_support_ticket_requests_target_invoice` (`target_invoice_id`),
  CONSTRAINT `fk_support_ticket_requests_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `support_tickets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_support_ticket_requests_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_support_ticket_requests_client` FOREIGN KEY (`client_id`) REFERENCES `client_profiles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_support_ticket_requests_task` FOREIGN KEY (`target_task_id`) REFERENCES `client_tasks` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_support_ticket_requests_stage` FOREIGN KEY (`target_stage_id`) REFERENCES `task_stages` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_support_ticket_requests_invoice` FOREIGN KEY (`target_invoice_id`) REFERENCES `task_invoices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_support_ticket_requests_applied_invoice` FOREIGN KEY (`applied_invoice_id`) REFERENCES `task_invoices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_support_ticket_requests_processed_by` FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `attendance` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `staff_user_id` INT UNSIGNED NOT NULL,
  `attendance_date` DATE NOT NULL,
  `check_in_time` DATETIME DEFAULT NULL,
  `check_out_time` DATETIME DEFAULT NULL,
  `work_location` VARCHAR(100) DEFAULT NULL,
  `remarks` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_attendance_staff_date` (`staff_user_id`, `attendance_date`),
  KEY `idx_attendance_company` (`company_id`),
  CONSTRAINT `fk_attendance_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_attendance_staff` FOREIGN KEY (`staff_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `attendance_correction_requests` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `staff_user_id` INT UNSIGNED NOT NULL,
  `attendance_date` DATE NOT NULL,
  `requested_check_in` DATETIME DEFAULT NULL,
  `requested_check_out` DATETIME DEFAULT NULL,
  `reason` TEXT NOT NULL,
  `status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
  `reviewer_remarks` TEXT DEFAULT NULL,
  `reviewed_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_attendance_corrections_company` (`company_id`),
  KEY `idx_attendance_corrections_staff` (`staff_user_id`),
  CONSTRAINT `fk_attendance_corrections_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_attendance_corrections_staff` FOREIGN KEY (`staff_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_attendance_corrections_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `leave_types` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `default_days_per_year` INT UNSIGNED NOT NULL DEFAULT 0,
  `is_system` TINYINT(1) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_leave_types_company_name` (`company_id`, `name`),
  CONSTRAINT `fk_leave_types_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `leave_requests` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `staff_user_id` INT UNSIGNED NOT NULL,
  `leave_type_id` INT UNSIGNED NOT NULL,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `total_days` INT UNSIGNED NOT NULL DEFAULT 1,
  `reason` TEXT NOT NULL,
  `attachment_path` VARCHAR(255) DEFAULT NULL,
  `attachment_name` VARCHAR(255) DEFAULT NULL,
  `status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
  `reviewer_remarks` TEXT DEFAULT NULL,
  `reviewed_by` INT UNSIGNED DEFAULT NULL,
  `reviewed_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_leave_requests_company` (`company_id`),
  KEY `idx_leave_requests_staff` (`staff_user_id`),
  CONSTRAINT `fk_leave_requests_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_leave_requests_staff` FOREIGN KEY (`staff_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_leave_requests_type` FOREIGN KEY (`leave_type_id`) REFERENCES `leave_types` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_leave_requests_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `timesheet_entries` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `staff_user_id` INT UNSIGNED NOT NULL,
  `client_id` INT UNSIGNED DEFAULT NULL,
  `task_id` INT UNSIGNED DEFAULT NULL,
  `entry_date` DATE NOT NULL,
  `description` VARCHAR(255) NOT NULL,
  `start_time` TIME NOT NULL,
  `end_time` TIME NOT NULL,
  `total_hours` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `is_billable` TINYINT(1) NOT NULL DEFAULT 1,
  `work_location` VARCHAR(100) DEFAULT NULL,
  `status` ENUM('draft', 'submitted', 'approved', 'rejected') NOT NULL DEFAULT 'draft',
  `reviewer_remarks` TEXT DEFAULT NULL,
  `reviewed_by` INT UNSIGNED DEFAULT NULL,
  `reviewed_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_timesheet_entries_company` (`company_id`),
  KEY `idx_timesheet_entries_staff` (`staff_user_id`),
  KEY `idx_timesheet_entries_client` (`client_id`),
  KEY `idx_timesheet_entries_status` (`status`),
  CONSTRAINT `fk_timesheet_entries_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_timesheet_entries_staff` FOREIGN KEY (`staff_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_timesheet_entries_client` FOREIGN KEY (`client_id`) REFERENCES `client_profiles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_timesheet_entries_task` FOREIGN KEY (`task_id`) REFERENCES `client_tasks` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_timesheet_entries_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `staff_profiles` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `job_title` VARCHAR(150) DEFAULT NULL,
  `department` VARCHAR(150) DEFAULT NULL,
  `education` VARCHAR(255) DEFAULT NULL,
  `qualifications` VARCHAR(255) DEFAULT NULL,
  `expertise` VARCHAR(255) DEFAULT NULL,
  `bio` TEXT DEFAULT NULL,
  `photo_path` VARCHAR(255) DEFAULT NULL,
  `team_category` ENUM('leadership', 'management', 'professional') NOT NULL DEFAULT 'professional',
  `show_on_public_team` TINYINT(1) NOT NULL DEFAULT 0,
  `display_order` INT NOT NULL DEFAULT 0,
  `employment_status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_staff_profiles_user` (`user_id`),
  KEY `idx_staff_profiles_public` (`show_on_public_team`, `display_order`),
  CONSTRAINT `fk_staff_profiles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `staff_kyc_documents` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `staff_user_id` INT UNSIGNED NOT NULL,
  `document_type` ENUM('citizenship', 'national_id', 'passport', 'pan') NOT NULL,
  `stored_filename` VARCHAR(190) NOT NULL,
  `original_filename` VARCHAR(255) DEFAULT NULL,
  `mime_type` VARCHAR(100) DEFAULT NULL,
  `file_size` INT UNSIGNED DEFAULT NULL,
  `verification_status` ENUM('submitted', 'verified', 'rejected', 'requires_update') NOT NULL DEFAULT 'submitted',
  `verified_by` INT UNSIGNED DEFAULT NULL,
  `verified_at` DATETIME DEFAULT NULL,
  `remarks` TEXT DEFAULT NULL,
  `uploaded_by` INT UNSIGNED DEFAULT NULL,
  `uploaded_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_staff_kyc_stored_filename` (`stored_filename`),
  KEY `idx_staff_kyc_staff` (`staff_user_id`),
  KEY `idx_staff_kyc_type` (`document_type`),
  KEY `idx_staff_kyc_status` (`verification_status`),
  CONSTRAINT `fk_staff_kyc_staff` FOREIGN KEY (`staff_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_staff_kyc_verified_by` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_staff_kyc_uploaded_by` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `accounting_parties` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `ledger_id` INT UNSIGNED DEFAULT NULL,
  `payable_ledger_id` INT UNSIGNED DEFAULT NULL,
  `code` VARCHAR(60) NOT NULL,
  `name` VARCHAR(190) NOT NULL,
  `party_type` ENUM('customer', 'supplier', 'both', 'other') NOT NULL DEFAULT 'both',
  `pan_no` VARCHAR(60) DEFAULT NULL,
  `email` VARCHAR(190) DEFAULT NULL,
  `phone` VARCHAR(80) DEFAULT NULL,
  `billing_address` TEXT DEFAULT NULL,
  `opening_balance` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `opening_balance_type` ENUM('debit', 'credit') NOT NULL DEFAULT 'debit',
  `credit_limit` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_accounting_parties_company_code` (`company_id`, `code`),
  KEY `idx_accounting_parties_company_type` (`company_id`, `party_type`),
  KEY `idx_accounting_parties_ledger` (`ledger_id`),
  KEY `idx_accounting_parties_payable_ledger` (`payable_ledger_id`),
  CONSTRAINT `fk_accounting_parties_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_accounting_parties_ledger` FOREIGN KEY (`ledger_id`) REFERENCES `ledgers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `budgets` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `fiscal_year_id` INT UNSIGNED NOT NULL,
  `ledger_id` INT UNSIGNED NOT NULL,
  `amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_budgets_scope` (`company_id`, `fiscal_year_id`, `ledger_id`),
  KEY `idx_budgets_ledger` (`ledger_id`),
  CONSTRAINT `fk_budgets_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_budgets_fy` FOREIGN KEY (`fiscal_year_id`) REFERENCES `fiscal_years` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_budgets_ledger` FOREIGN KEY (`ledger_id`) REFERENCES `ledgers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `report_notes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `fiscal_year_id` INT UNSIGNED NOT NULL,
  `report_key` VARCHAR(60) NOT NULL,
  `note_no` VARCHAR(10) NOT NULL,
  `body` TEXT NOT NULL,
  `updated_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_report_notes_scope` (`company_id`, `fiscal_year_id`, `report_key`, `note_no`),
  CONSTRAINT `fk_report_notes_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Payroll module (migration 031)
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `payroll_settings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `salary_expense_ledger_id` INT UNSIGNED DEFAULT NULL,
  `employer_contrib_expense_ledger_id` INT UNSIGNED DEFAULT NULL,
  `tds_payable_ledger_id` INT UNSIGNED DEFAULT NULL,
  `retirement_payable_ledger_id` INT UNSIGNED DEFAULT NULL,
  `salary_payable_ledger_id` INT UNSIGNED DEFAULT NULL,
  `advance_ledger_id` INT UNSIGNED DEFAULT NULL,
  `bank_ledger_id` INT UNSIGNED DEFAULT NULL,
  `enforce_sod` TINYINT(1) NOT NULL DEFAULT 0,
  `auto_post` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_payroll_settings_company` (`company_id`),
  CONSTRAINT `fk_payroll_settings_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payroll_components` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `code` VARCHAR(40) NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `name_np` VARCHAR(120) DEFAULT NULL,
  `category` ENUM('allowance', 'overtime', 'benefit', 'deduction') NOT NULL DEFAULT 'allowance',
  `calc_type` ENUM('fixed', 'percent_basic') NOT NULL DEFAULT 'fixed',
  `default_value` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `taxable` TINYINT(1) NOT NULL DEFAULT 1,
  `employer_paid` TINYINT(1) NOT NULL DEFAULT 0,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_payroll_components_code` (`company_id`, `code`),
  CONSTRAINT `fk_payroll_components_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payroll_tax_versions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `fiscal_year_id` INT UNSIGNED NOT NULL,
  `label` VARCHAR(120) NOT NULL,
  `legal_reference` VARCHAR(255) DEFAULT NULL,
  `status` ENUM('draft', 'published', 'archived') NOT NULL DEFAULT 'draft',
  `effective_from` DATE DEFAULT NULL,
  `effective_to` DATE DEFAULT NULL,
  `retirement_limit_pct` DECIMAL(6,2) NOT NULL DEFAULT 33.33,
  `retirement_limit_cap` DECIMAL(14,2) NOT NULL DEFAULT 500000.00,
  `ssf_first_slab_exempt` TINYINT(1) NOT NULL DEFAULT 1,
  `rounding` ENUM('none', 'nearest', 'down') NOT NULL DEFAULT 'nearest',
  `created_by` INT UNSIGNED DEFAULT NULL,
  `approved_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_payroll_tax_versions_scope` (`company_id`, `fiscal_year_id`, `status`),
  CONSTRAINT `fk_payroll_tax_versions_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_payroll_tax_versions_fy` FOREIGN KEY (`fiscal_year_id`) REFERENCES `fiscal_years` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payroll_tax_slabs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `version_id` INT UNSIGNED NOT NULL,
  `category` ENUM('individual', 'couple') NOT NULL DEFAULT 'individual',
  `lower_bound` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `upper_bound` DECIMAL(14,2) DEFAULT NULL,
  `rate` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
  `sort_order` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_payroll_tax_slabs_version` (`version_id`, `category`, `sort_order`),
  CONSTRAINT `fk_payroll_tax_slabs_version` FOREIGN KEY (`version_id`) REFERENCES `payroll_tax_versions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payroll_employees` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `employee_code` VARCHAR(40) NOT NULL,
  `department` VARCHAR(120) DEFAULT NULL,
  `designation` VARCHAR(120) DEFAULT NULL,
  `pan_no` VARCHAR(40) DEFAULT NULL,
  `bank_name` VARCHAR(120) DEFAULT NULL,
  `bank_account` VARCHAR(60) DEFAULT NULL,
  `marital_status` ENUM('individual', 'couple') NOT NULL DEFAULT 'individual',
  `retirement_scheme` ENUM('none', 'ssf', 'pf', 'cit') NOT NULL DEFAULT 'none',
  `retirement_id` VARCHAR(60) DEFAULT NULL,
  `retirement_employee_rate` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
  `retirement_employer_rate` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
  `basic_salary` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  `joined_on` DATE DEFAULT NULL,
  `terminated_on` DATE DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_payroll_employees_user` (`company_id`, `user_id`),
  UNIQUE KEY `uniq_payroll_employees_code` (`company_id`, `employee_code`),
  CONSTRAINT `fk_payroll_employees_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_payroll_employees_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payroll_employee_components` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `payroll_employee_id` INT UNSIGNED NOT NULL,
  `component_id` INT UNSIGNED NOT NULL,
  `amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_payroll_emp_component` (`payroll_employee_id`, `component_id`),
  CONSTRAINT `fk_payroll_emp_components_emp` FOREIGN KEY (`payroll_employee_id`) REFERENCES `payroll_employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_payroll_emp_components_comp` FOREIGN KEY (`component_id`) REFERENCES `payroll_components` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payroll_loans` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `payroll_employee_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(160) NOT NULL DEFAULT 'Staff advance',
  `principal` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `balance` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `monthly_installment` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('active', 'paused', 'settled') NOT NULL DEFAULT 'active',
  `issued_on` DATE DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_payroll_loans_employee` (`payroll_employee_id`, `status`),
  CONSTRAINT `fk_payroll_loans_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_payroll_loans_employee` FOREIGN KEY (`payroll_employee_id`) REFERENCES `payroll_employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payroll_loan_txns` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `loan_id` INT UNSIGNED NOT NULL,
  `run_id` INT UNSIGNED DEFAULT NULL,
  `txn_type` ENUM('disbursement', 'recovery', 'adjustment') NOT NULL DEFAULT 'recovery',
  `amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `txn_date` DATE DEFAULT NULL,
  `voucher_id` INT UNSIGNED DEFAULT NULL,
  `notes` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_payroll_loan_txns_loan` (`loan_id`),
  CONSTRAINT `fk_payroll_loan_txns_loan` FOREIGN KEY (`loan_id`) REFERENCES `payroll_loans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payroll_runs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `fiscal_year_id` INT UNSIGNED NOT NULL,
  `period_no` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `period_label` VARCHAR(60) NOT NULL,
  `pay_date` DATE DEFAULT NULL,
  `status` ENUM('draft', 'calculated', 'approved', 'posted', 'paid', 'cancelled') NOT NULL DEFAULT 'draft',
  `tax_version_id` INT UNSIGNED DEFAULT NULL,
  `total_gross` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `total_tax` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `total_deductions` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `total_employer_contrib` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `total_net` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `accrual_voucher_id` INT UNSIGNED DEFAULT NULL,
  `payment_voucher_id` INT UNSIGNED DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `approved_by` INT UNSIGNED DEFAULT NULL,
  `approved_at` TIMESTAMP NULL DEFAULT NULL,
  `posted_at` TIMESTAMP NULL DEFAULT NULL,
  `paid_at` TIMESTAMP NULL DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_payroll_runs_period` (`company_id`, `fiscal_year_id`, `period_no`, `status`),
  CONSTRAINT `fk_payroll_runs_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_payroll_runs_fy` FOREIGN KEY (`fiscal_year_id`) REFERENCES `fiscal_years` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payroll_run_lines` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `run_id` INT UNSIGNED NOT NULL,
  `payroll_employee_id` INT UNSIGNED NOT NULL,
  `basic` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `allowances` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `overtime` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `benefits` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `gross` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `assessable_annual` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `retirement_deduction_annual` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `taxable_annual` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `annual_tax` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `tax_ytd_before` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `tax_month` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `retirement_employee_month` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `retirement_employer_month` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `advance_deduction` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `other_deduction` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `net_pay` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `trace` TEXT DEFAULT NULL,
  `line_status` ENUM('ok', 'warning', 'error') NOT NULL DEFAULT 'ok',
  `warnings` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_payroll_run_lines_emp` (`run_id`, `payroll_employee_id`),
  CONSTRAINT `fk_payroll_run_lines_run` FOREIGN KEY (`run_id`) REFERENCES `payroll_runs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_payroll_run_lines_emp` FOREIGN KEY (`payroll_employee_id`) REFERENCES `payroll_employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `warehouses` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `code` VARCHAR(40) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_warehouse_company_name` (`company_id`, `name`),
  KEY `idx_warehouse_company_active` (`company_id`, `is_active`),
  CONSTRAINT `fk_warehouse_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `inventory_items` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `ledger_id` INT UNSIGNED DEFAULT NULL,
  `sku` VARCHAR(80) NOT NULL,
  `name` VARCHAR(190) NOT NULL,
  `item_type` ENUM('stock', 'service', 'raw_material', 'work_in_progress', 'finished_good', 'trading_good', 'consumable', 'packing_material', 'spare_part', 'by_product', 'scrap') NOT NULL DEFAULT 'stock',
  `valuation_method` ENUM('fifo', 'weighted_average', 'specific') NOT NULL DEFAULT 'weighted_average',
  `category` VARCHAR(120) DEFAULT NULL,
  `sub_category` VARCHAR(120) DEFAULT NULL,
  `short_name` VARCHAR(120) DEFAULT NULL,
  `barcode` VARCHAR(120) DEFAULT NULL,
  `country_of_origin` VARCHAR(80) DEFAULT NULL,
  `unit` VARCHAR(40) NOT NULL DEFAULT 'pcs',
  `hs_code` VARCHAR(80) DEFAULT NULL,
  `tax_rate` DECIMAL(5,2) NOT NULL DEFAULT 13.00,
  `sales_rate` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `purchase_rate` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `opening_qty` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
  `reorder_level` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
  `min_stock` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
  `max_stock` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
  `safety_stock` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
  `allow_negative_stock` TINYINT(1) NOT NULL DEFAULT 0,
  `default_warehouse_id` INT UNSIGNED DEFAULT NULL,
  `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_inventory_items_company_sku` (`company_id`, `sku`),
  KEY `idx_inventory_items_company_type` (`company_id`, `item_type`),
  KEY `idx_inventory_items_ledger` (`ledger_id`),
  KEY `idx_inventory_items_warehouse` (`default_warehouse_id`),
  CONSTRAINT `fk_inventory_items_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_inventory_items_ledger` FOREIGN KEY (`ledger_id`) REFERENCES `ledgers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_inventory_items_warehouse` FOREIGN KEY (`default_warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `inventory_transactions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `fiscal_year_id` INT UNSIGNED DEFAULT NULL,
  `item_id` INT UNSIGNED NOT NULL,
  `warehouse_id` INT UNSIGNED DEFAULT NULL,
  `to_warehouse_id` INT UNSIGNED DEFAULT NULL,
  `voucher_id` INT UNSIGNED DEFAULT NULL,
  `transaction_type` ENUM('opening', 'purchase', 'sale', 'sales_return', 'purchase_return', 'adjustment', 'consume', 'produce') NOT NULL DEFAULT 'adjustment',
  `ref_no` VARCHAR(120) DEFAULT NULL,
  `transaction_date` DATE NOT NULL,
  `qty_in` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
  `qty_out` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
  `rate` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_inventory_transactions_company_date` (`company_id`, `transaction_date`),
  KEY `idx_inventory_transactions_item` (`item_id`),
  KEY `idx_inventory_transactions_voucher` (`voucher_id`),
  KEY `idx_inventory_transactions_warehouse` (`warehouse_id`),
  CONSTRAINT `fk_inventory_transactions_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_inventory_transactions_fiscal_year` FOREIGN KEY (`fiscal_year_id`) REFERENCES `fiscal_years` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_inventory_transactions_item` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_inventory_transactions_voucher` FOREIGN KEY (`voucher_id`) REFERENCES `vouchers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_inventory_transactions_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_inventory_transactions_to_warehouse` FOREIGN KEY (`to_warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `manufacturing_orders` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `fiscal_year_id` INT UNSIGNED DEFAULT NULL,
  `order_no` VARCHAR(80) NOT NULL,
  `finished_item_id` INT UNSIGNED NOT NULL,
  `bom_id` INT UNSIGNED DEFAULT NULL,
  `quantity` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
  `labour_cost` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `overhead_absorbed` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `byproduct_value` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `abnormal_waste_cost` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('draft', 'in_progress', 'completed', 'cancelled') NOT NULL DEFAULT 'draft',
  `started_on` DATE DEFAULT NULL,
  `completed_on` DATE DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_manufacturing_orders_company_no` (`company_id`, `order_no`),
  KEY `idx_manufacturing_orders_company_status` (`company_id`, `status`),
  KEY `idx_manufacturing_orders_finished_item` (`finished_item_id`),
  CONSTRAINT `fk_manufacturing_orders_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_manufacturing_orders_fiscal_year` FOREIGN KEY (`fiscal_year_id`) REFERENCES `fiscal_years` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_manufacturing_orders_finished_item` FOREIGN KEY (`finished_item_id`) REFERENCES `inventory_items` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `manufacturing_order_inputs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `manufacturing_order_id` INT UNSIGNED NOT NULL,
  `item_id` INT UNSIGNED NOT NULL,
  `quantity` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
  `rate` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_manufacturing_order_inputs_order` (`manufacturing_order_id`),
  KEY `idx_manufacturing_order_inputs_item` (`item_id`),
  CONSTRAINT `fk_manufacturing_order_inputs_order` FOREIGN KEY (`manufacturing_order_id`) REFERENCES `manufacturing_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_manufacturing_order_inputs_item` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `invoice_line_items` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `invoice_id` INT UNSIGNED NOT NULL,
  `item_id` INT UNSIGNED DEFAULT NULL,
  `source_type` ENUM('task', 'inventory', 'manufacturing', 'other') NOT NULL DEFAULT 'other',
  `source_id` INT UNSIGNED DEFAULT NULL,
  `description` VARCHAR(255) NOT NULL,
  `hs_code` VARCHAR(80) DEFAULT NULL,
  `unit` VARCHAR(40) NOT NULL DEFAULT 'pcs',
  `quantity` DECIMAL(14,3) NOT NULL DEFAULT 1.000,
  `rate` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `taxable_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `vat_rate` DECIMAL(5,2) NOT NULL DEFAULT 13.00,
  `vat_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `total_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_invoice_line_items_invoice` (`invoice_id`),
  KEY `idx_invoice_line_items_item` (`item_id`),
  CONSTRAINT `fk_invoice_line_items_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `task_invoices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_invoice_line_items_item` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `leave_types` (`company_id`, `name`, `default_days_per_year`, `is_system`)
SELECT c.id, seed.name, seed.default_days_per_year, 1
FROM `companies` c
JOIN (
    SELECT 'Casual Leave' AS name, 12 AS default_days_per_year
    UNION ALL SELECT 'Sick Leave', 12
    UNION ALL SELECT 'Annual Leave', 18
    UNION ALL SELECT 'Maternity/Paternity Leave', 98
    UNION ALL SELECT 'Unpaid Leave', 0
) seed
WHERE c.is_active = 1
  AND NOT EXISTS (
      SELECT 1 FROM `leave_types` existing
      WHERE existing.company_id = c.id AND existing.name = seed.name
  );

INSERT INTO `plans` (`name`, `slug`, `billing_cycle`, `price`, `currency`, `disk_space_gb`, `bandwidth_gb`, `email_accounts`, `databases_count`, `domains_allowed`, `features`, `is_active`, `sort_order`) VALUES
('Starter Hosting', 'starter-hosting', 'monthly', 9.99, 'USD', 10, 100, 5, 1, 1, 'Free SSL, One-click backups, cPanel access, Email support', 1, 1),
('Business Hosting', 'business-hosting', 'monthly', 19.99, 'USD', 25, 250, 25, 5, 3, 'Free SSL, Daily backups, Priority support, Multiple websites', 1, 2),
('Enterprise Hosting', 'enterprise-hosting', 'monthly', 49.99, 'USD', 100, 1000, 100, 20, 10, 'Dedicated resources, Priority support, Staging tools, Advanced security', 1, 3)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `billing_cycle` = VALUES(`billing_cycle`), `price` = VALUES(`price`), `currency` = VALUES(`currency`), `disk_space_gb` = VALUES(`disk_space_gb`), `bandwidth_gb` = VALUES(`bandwidth_gb`), `email_accounts` = VALUES(`email_accounts`), `databases_count` = VALUES(`databases_count`), `domains_allowed` = VALUES(`domains_allowed`), `features` = VALUES(`features`), `is_active` = VALUES(`is_active`), `sort_order` = VALUES(`sort_order`);

INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
('site_name', 'Altiora Advisory Operations'),
('site_tagline', 'Integrated audit, advisory, and compliance operations.'),
('support_email', 'support@example.com'),
('support_phone', '+977-9800000000'),
('office_address', 'Kathmandu, Nepal'),
('currency_symbol', '$'),
('payment_mode', 'manual'),
('payment_label', 'Manual payment / bank transfer'),
('bank_name', 'Your Bank Name'),
('bank_account_name', 'Altiora Advisory Operations'),
('bank_account_number', '0000000000000000'),
('payment_note', 'Share payment confirmation with finance for reconciliation.'),
('approvals_enabled', '0'),
('default_vat_rate', '13'),
('stripe_checkout_url', ''),
('paypal_checkout_url', ''),
('hero_title', 'Run multi-company audit and advisory workflows with clarity.'),
('hero_description', 'Manage entities, teams, contracts, tasks, and stage-based invoicing in one place.'),
('about_text', 'Altiora Advisory Operations supports structured professional-services delivery across group companies.')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);

INSERT INTO `companies` (`name`, `code`, `parent_company_id`, `is_active`) VALUES
('Altiora Global Holdings Private Limited', 'AGHPL', NULL, 1),
('M.B. Training and Advisory Services Private Limited', 'MBTAS', NULL, 1),
('Excel Business Consulting Private Limited', 'EBCPL', NULL, 1),
('Vyra Edupath Private limited', 'VEPL', NULL, 1),
('Passion Eduhub Private Limited', 'PEPL', NULL, 1),
('M.Bista and Associates, Chartered Accountants', 'MBAACA', NULL, 1)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `is_active` = VALUES(`is_active`);

UPDATE `companies` child
INNER JOIN `companies` parent ON parent.code = 'AGHPL'
SET child.parent_company_id = parent.id
WHERE child.code IN ('MBTAS', 'EBCPL', 'VEPL', 'PEPL');

UPDATE `companies`
SET parent_company_id = NULL
WHERE code IN ('AGHPL', 'MBAACA');

INSERT INTO `users` (`name`, `email`, `password_hash`, `role`, `status`, `company_id`, `company`) VALUES
('Super Admin', 'admin@mbista.local', '$2y$10$yeqdVHMElucdlMZWdRO8Ru9Auoymey4RY3rUVnuYcd2WjiYxgVLo.', 'admin', 'active', (SELECT `id` FROM `companies` WHERE `code` = 'AGHPL' LIMIT 1), 'Altiora Global Holdings Private Limited'),
('Excel Business Client', 'excelbusinessandtax@gmail.com', '$2y$10$ZhJ74eAtR.gMbwufkPzAiO1y2G2c9H8gWQWJ/PXC2v9KYfYtR2.WC', 'customer', 'active', (SELECT `id` FROM `companies` WHERE `code` = 'EBCPL' LIMIT 1), 'Excel Business Consulting Private Limited'),
('Test Customer', 'testcustomer@example.com', '$2y$10$HULv47NgbaGJuPGGvfZnyurg/1FUy0IExZDgG95bbo2mzhXIVRzmu', 'customer', 'active', NULL, 'Test Customer')
ON DUPLICATE KEY UPDATE
`name` = VALUES(`name`),
`password_hash` = VALUES(`password_hash`),
`role` = VALUES(`role`),
`status` = VALUES(`status`),
`company_id` = VALUES(`company_id`),
`company` = VALUES(`company`);

INSERT INTO `fiscal_years` (`company_id`, `label`, `start_date`, `end_date`, `is_active`, `is_default`)
SELECT c.id, 'FY 2026-2027', '2026-01-01', '2026-12-31', 1, 1
FROM `companies` c
WHERE c.code IN ('AGHPL', 'MBTAS', 'EBCPL', 'VEPL', 'PEPL', 'MBAACA')
  AND NOT EXISTS (
      SELECT 1
      FROM `fiscal_years` fy
      WHERE fy.company_id = c.id
        AND fy.label = 'FY 2026-2027'
  );

INSERT INTO `company_shareholdings` (`investor_company_id`, `investee_company_id`, `ownership_percent`, `relationship_type`, `consolidation_method`, `effective_from`, `notes`)
SELECT parent.id,
       child.id,
       100.00,
       'subsidiary',
       'full',
       CURRENT_DATE,
       'Default group accounting relationship.'
FROM `companies` parent
INNER JOIN `companies` child ON child.parent_company_id = parent.id
WHERE parent.code = 'AGHPL'
  AND NOT EXISTS (
      SELECT 1
      FROM `company_shareholdings` existing
      WHERE existing.investor_company_id = parent.id
        AND existing.investee_company_id = child.id
  );

INSERT INTO `ledgers` (`company_id`, `code`, `name`, `type`, `is_system`)
SELECT c.id, ledger_seed.code, ledger_seed.name, ledger_seed.type, 1
FROM `companies` c
JOIN (
    SELECT 'CASH' AS code, 'Cash and bank' AS name, 'asset' AS type
    UNION ALL SELECT 'AR', 'Accounts receivable', 'asset'
    UNION ALL SELECT 'PREPAID', 'Prepaid expenses', 'asset'
    UNION ALL SELECT 'INVESTMENTS', 'Investments', 'asset'
    UNION ALL SELECT 'AP', 'Accounts payable', 'liability'
    UNION ALL SELECT 'TAX_PAYABLE', 'Tax payable', 'liability'
    UNION ALL SELECT 'SHARE_CAPITAL', 'Share capital', 'equity'
    UNION ALL SELECT 'RETAINED_EARNINGS', 'Retained earnings', 'equity'
    UNION ALL SELECT 'SERVICE_REVENUE', 'Service revenue', 'revenue'
    UNION ALL SELECT 'OTHER_INCOME', 'Other income', 'revenue'
    UNION ALL SELECT 'SALARY_EXPENSE', 'Salary expense', 'expense'
    UNION ALL SELECT 'RENT_EXPENSE', 'Rent expense', 'expense'
    UNION ALL SELECT 'PROFESSIONAL_FEES', 'Professional fees', 'expense'
    UNION ALL SELECT 'TAX_EXPENSE', 'Tax expense', 'expense'
) ledger_seed
WHERE c.is_active = 1
  AND NOT EXISTS (
      SELECT 1
      FROM `ledgers` l
      WHERE l.company_id = c.id
        AND l.code = ledger_seed.code
  );

INSERT INTO `ledger_groups` (`company_id`, `code`, `name`, `master_key`, `is_cash_or_bank`, `is_system`)
SELECT c.id, seed.code, seed.name, seed.master_key, seed.is_cash_or_bank, 1
FROM `companies` c
JOIN (
    SELECT 'BANK' AS code, 'Bank' AS name, 'current_asset' AS master_key, 1 AS is_cash_or_bank
    UNION ALL SELECT 'CASH_GRP', 'Cash in Hand', 'current_asset', 1
    UNION ALL SELECT 'RECEIVABLE', 'Trade Receivables', 'current_asset', 0
    UNION ALL SELECT 'PREPAID_GRP', 'Prepaid Expenses', 'current_asset', 0
    UNION ALL SELECT 'INVEST_GRP', 'Investments', 'non_current_asset', 0
    UNION ALL SELECT 'PAYABLE', 'Trade Payables', 'current_liability', 0
    UNION ALL SELECT 'DUTIES_TAXES', 'Duties and Taxes', 'current_liability', 0
    UNION ALL SELECT 'SHARE_CAPITAL_GRP', 'Share Capital', 'equity', 0
    UNION ALL SELECT 'RESERVE_SURPLUS', 'Reserve & Surplus', 'equity', 0
    UNION ALL SELECT 'DIRECT_INCOME_GRP', 'Professional Fee Income', 'direct_income', 0
    UNION ALL SELECT 'INDIRECT_INCOME_GRP', 'Other Income', 'indirect_income', 0
    UNION ALL SELECT 'ADMIN_EXP', 'Administrative Expenses', 'indirect_expense', 0
    UNION ALL SELECT 'EMP_BENEFIT_EXP', 'Operating/Employee Benefit Expenses', 'indirect_expense', 0
    UNION ALL SELECT 'SALES_MKT_EXP', 'Sales and Marketing Expenses', 'indirect_expense', 0
    UNION ALL SELECT 'OTHER_NONOP_EXP', 'Other Non-operating Expenses', 'indirect_expense', 0
    UNION ALL SELECT 'DIRECT_EXP_GRP', 'Direct Expenses', 'direct_expense', 0
    UNION ALL SELECT 'TAX_EXP_GRP', 'Tax Expenses', 'indirect_expense', 0
) seed
WHERE c.is_active = 1
  AND NOT EXISTS (
      SELECT 1 FROM `ledger_groups` existing
      WHERE existing.company_id = c.id AND existing.code = seed.code
  );

UPDATE `ledgers` l
INNER JOIN `ledger_groups` g
    ON g.company_id = l.company_id
   AND g.code = CASE l.code
        WHEN 'CASH' THEN 'BANK'
        WHEN 'AR' THEN 'RECEIVABLE'
        WHEN 'PREPAID' THEN 'PREPAID_GRP'
        WHEN 'INVESTMENTS' THEN 'INVEST_GRP'
        WHEN 'AP' THEN 'PAYABLE'
        WHEN 'TAX_PAYABLE' THEN 'DUTIES_TAXES'
        WHEN 'SHARE_CAPITAL' THEN 'SHARE_CAPITAL_GRP'
        WHEN 'RETAINED_EARNINGS' THEN 'RESERVE_SURPLUS'
        WHEN 'SERVICE_REVENUE' THEN 'DIRECT_INCOME_GRP'
        WHEN 'OTHER_INCOME' THEN 'INDIRECT_INCOME_GRP'
        WHEN 'SALARY_EXPENSE' THEN 'EMP_BENEFIT_EXP'
        WHEN 'RENT_EXPENSE' THEN 'ADMIN_EXP'
        WHEN 'PROFESSIONAL_FEES' THEN 'DIRECT_EXP_GRP'
        WHEN 'TAX_EXPENSE' THEN 'TAX_EXP_GRP'
        ELSE NULL
    END
SET l.group_id = g.id
WHERE l.group_id IS NULL
  AND l.code IN ('CASH','AR','PREPAID','INVESTMENTS','AP','TAX_PAYABLE','SHARE_CAPITAL',
                 'RETAINED_EARNINGS','SERVICE_REVENUE','OTHER_INCOME','SALARY_EXPENSE',
                 'RENT_EXPENSE','PROFESSIONAL_FEES','TAX_EXPENSE');

INSERT INTO `company_ledger_mappings` (`company_id`, `map_key`, `ledger_id`)
SELECT c.id, mapping_seed.map_key, l.id
FROM `companies` c
INNER JOIN (
    SELECT 'default_cash_bank' AS map_key, 'CASH' AS ledger_code
    UNION ALL SELECT 'default_accounts_receivable', 'AR'
    UNION ALL SELECT 'default_accounts_payable', 'AP'
    UNION ALL SELECT 'default_tax_payable', 'TAX_PAYABLE'
    UNION ALL SELECT 'default_service_revenue', 'SERVICE_REVENUE'
    UNION ALL SELECT 'default_hosting_revenue', 'SERVICE_REVENUE'
) mapping_seed
INNER JOIN `ledgers` l
    ON l.company_id = c.id
   AND l.code = mapping_seed.ledger_code
WHERE c.is_active = 1
  AND NOT EXISTS (
      SELECT 1
      FROM `company_ledger_mappings` existing
      WHERE existing.company_id = c.id
        AND existing.map_key = mapping_seed.map_key
  );

INSERT INTO `industries` (`name`, `is_active`)
SELECT seed.name, 1
FROM (
  SELECT 'Accounting and Audit' AS name
  UNION ALL SELECT 'Banking and Finance'
  UNION ALL SELECT 'Cooperative'
  UNION ALL SELECT 'Education'
  UNION ALL SELECT 'Healthcare'
  UNION ALL SELECT 'Hydropower'
  UNION ALL SELECT 'Information Technology'
  UNION ALL SELECT 'Jewellery'
  UNION ALL SELECT 'Manufacturing'
  UNION ALL SELECT 'NGO/INGO'
  UNION ALL SELECT 'Real Estate'
  UNION ALL SELECT 'Retail and Trading'
  UNION ALL SELECT 'Other'
) seed
WHERE NOT EXISTS (SELECT 1 FROM `industries` i WHERE i.name = seed.name);

INSERT INTO `service_provider_entities` (`company_id`, `name`, `code`, `is_active`)
SELECT c.id, c.name, c.code, c.is_active
FROM `companies` c
WHERE NOT EXISTS (
  SELECT 1
  FROM `service_provider_entities` spe
  WHERE spe.company_id = c.id
     OR spe.code = c.code
);


CREATE TABLE IF NOT EXISTS `audit_change_history` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED DEFAULT NULL,
  `entity_type` VARCHAR(60) NOT NULL,
  `entity_id` INT UNSIGNED NOT NULL,
  `field_name` VARCHAR(80) NOT NULL,
  `old_value` TEXT DEFAULT NULL,
  `new_value` TEXT DEFAULT NULL,
  `actor_id` INT UNSIGNED DEFAULT NULL,
  `ip_address` VARCHAR(60) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_change_entity` (`entity_type`, `entity_id`, `created_at`),
  KEY `idx_audit_change_company` (`company_id`, `created_at`),
  CONSTRAINT `fk_audit_change_actor` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fiscal_period_locks` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `fiscal_year_id` INT UNSIGNED NOT NULL,
  `locked_through` DATE NOT NULL,
  `locked_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_fiscal_period_lock` (`company_id`, `fiscal_year_id`),
  CONSTRAINT `fk_fiscal_period_lock_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fiscal_period_lock_fy` FOREIGN KEY (`fiscal_year_id`) REFERENCES `fiscal_years` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fiscal_period_lock_user` FOREIGN KEY (`locked_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_company`
  FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE SET NULL;

ALTER TABLE `task_invoices`
  ADD CONSTRAINT `fk_task_invoices_accounting_party`
  FOREIGN KEY (`party_id`) REFERENCES `accounting_parties` (`id`) ON DELETE SET NULL;

-- ---------------------------------------------------------------------------
-- Insight posts (migration 032): public website Insights publishing
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `insight_posts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category` VARCHAR(40) NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `summary` VARCHAR(500) DEFAULT NULL,
  `body` MEDIUMTEXT,
  `attachment_path` VARCHAR(255) DEFAULT NULL,
  `attachment_name` VARCHAR(200) DEFAULT NULL,
  `status` ENUM('draft', 'published', 'archived') NOT NULL DEFAULT 'draft',
  `published_at` DATETIME DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_insight_posts_list` (`category`, `status`, `published_at`),
  CONSTRAINT `fk_insight_posts_author` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- Access control (migration 033): explicit memberships + security event log
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `company_memberships` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `company_id` INT UNSIGNED NOT NULL,
  `access_level` ENUM('super_admin', 'parent_admin', 'subsidiary_admin', 'accountant', 'approver', 'viewer', 'support') NOT NULL DEFAULT 'accountant',
  `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `granted_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_company_membership` (`user_id`, `company_id`),
  KEY `idx_company_memberships_company` (`company_id`, `is_active`),
  KEY `idx_company_memberships_user` (`user_id`, `is_active`),
  CONSTRAINT `fk_company_memberships_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_company_memberships_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_company_memberships_granted_by` FOREIGN KEY (`granted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `security_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED DEFAULT NULL,
  `email` VARCHAR(190) DEFAULT NULL,
  `event_type` VARCHAR(60) NOT NULL,
  `company_id` INT UNSIGNED DEFAULT NULL,
  `outcome` ENUM('success', 'denied', 'failure') NOT NULL DEFAULT 'success',
  `details` VARCHAR(500) DEFAULT NULL,
  `request_path` VARCHAR(255) DEFAULT NULL,
  `ip_address` VARCHAR(60) DEFAULT NULL,
  `user_agent` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_security_events_type_time` (`event_type`, `created_at`),
  KEY `idx_security_events_user_time` (`user_id`, `created_at`),
  KEY `idx_security_events_ip_time` (`ip_address`, `created_at`),
  KEY `idx_security_events_outcome_time` (`outcome`, `created_at`),
  CONSTRAINT `fk_security_events_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Granular per-staff permissions (migration 034)
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `staff_permissions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `module_key` VARCHAR(40) NOT NULL,
  `action_key` VARCHAR(40) NOT NULL,
  `granted_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_staff_permission` (`user_id`, `module_key`, `action_key`),
  KEY `idx_staff_permissions_user` (`user_id`),
  CONSTRAINT `fk_staff_permissions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_staff_permissions_granted_by` FOREIGN KEY (`granted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- IAS 2 inventory valuation (migration 036): cost layers, NRV, ledger mappings
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `inventory_cost_layers` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `item_id` INT UNSIGNED NOT NULL,
  `warehouse_id` INT UNSIGNED DEFAULT NULL,
  `batch_no` VARCHAR(80) DEFAULT NULL,
  `identity` VARCHAR(120) DEFAULT NULL,
  `is_specific` TINYINT(1) NOT NULL DEFAULT 0,
  `layer_date` DATE NOT NULL,
  `layer_seq` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `source_txn_id` INT UNSIGNED DEFAULT NULL,
  `unit_cost` DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  `qty_in` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `qty_remaining` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_inv_layers_item_open` (`company_id`, `item_id`, `qty_remaining`),
  KEY `idx_inv_layers_seq` (`company_id`, `item_id`, `layer_seq`),
  KEY `idx_inv_layers_identity` (`company_id`, `item_id`, `identity`),
  CONSTRAINT `fk_inv_layers_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_inv_layers_item` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `inventory_nrv_assessments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `fiscal_year_id` INT UNSIGNED DEFAULT NULL,
  `item_id` INT UNSIGNED NOT NULL,
  `assessment_date` DATE NOT NULL,
  `quantity` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `cost_per_unit` DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  `selling_price` DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  `completion_cost` DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  `selling_cost` DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  `nrv_per_unit` DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  `lower_per_unit` DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  `carrying_cost` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `prior_write_down` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `write_down` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `reversal` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `final_carrying` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `evidence` VARCHAR(255) DEFAULT NULL,
  `voucher_id` INT UNSIGNED DEFAULT NULL,
  `approved_by` INT UNSIGNED DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_inv_nrv_item_date` (`company_id`, `item_id`, `assessment_date`),
  KEY `idx_inv_nrv_voucher` (`voucher_id`),
  CONSTRAINT `fk_inv_nrv_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_inv_nrv_item` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_inv_nrv_voucher` FOREIGN KEY (`voucher_id`) REFERENCES `vouchers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `inventory_ledger_mappings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `scope` ENUM('global', 'category', 'item') NOT NULL DEFAULT 'global',
  `category` VARCHAR(120) DEFAULT NULL,
  `item_id` INT UNSIGNED DEFAULT NULL,
  `purpose` VARCHAR(60) NOT NULL,
  `ledger_id` INT UNSIGNED NOT NULL,
  `effective_date` DATE DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_inv_mapping_scope` (`company_id`, `scope`, `category`, `item_id`, `purpose`),
  KEY `idx_inv_mapping_lookup` (`company_id`, `purpose`, `scope`),
  KEY `idx_inv_mapping_ledger` (`ledger_id`),
  CONSTRAINT `fk_inv_mapping_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_inv_mapping_item` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_inv_mapping_ledger` FOREIGN KEY (`ledger_id`) REFERENCES `ledgers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Fixed Asset Register (migration 037): IAS 16 / 38 / IFRS 16 / 5 / IAS 36
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `asset_categories` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `asset_class` ENUM('ppe', 'intangible', 'rou', 'investment_property', 'cwip') NOT NULL DEFAULT 'ppe',
  `default_method` ENUM('straight_line', 'diminishing_balance', 'units') NOT NULL DEFAULT 'straight_line',
  `default_life_months` INT UNSIGNED NOT NULL DEFAULT 60,
  `default_rate_pct` DECIMAL(6,3) NOT NULL DEFAULT 0.000,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_asset_categories_company_name` (`company_id`, `name`),
  KEY `idx_asset_categories_company_class` (`company_id`, `asset_class`),
  CONSTRAINT `fk_asset_categories_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fixed_assets` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `category_id` INT UNSIGNED DEFAULT NULL,
  `parent_asset_id` INT UNSIGNED DEFAULT NULL COMMENT 'component accounting',
  `asset_code` VARCHAR(80) NOT NULL,
  `barcode` VARCHAR(120) DEFAULT NULL,
  `name` VARCHAR(190) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `asset_class` ENUM('ppe', 'intangible', 'rou', 'investment_property', 'cwip') NOT NULL DEFAULT 'ppe',
  `ifrs_class` VARCHAR(80) DEFAULT NULL,
  `intangible_life` ENUM('finite', 'indefinite') DEFAULT NULL,
  `location` VARCHAR(150) DEFAULT NULL,
  `department` VARCHAR(120) DEFAULT NULL,
  `custodian` VARCHAR(150) DEFAULT NULL,
  `supplier` VARCHAR(190) DEFAULT NULL,
  `purchase_ref` VARCHAR(120) DEFAULT NULL,
  `purchase_date` DATE DEFAULT NULL,
  `available_for_use_date` DATE DEFAULT NULL,
  `depreciation_start_date` DATE DEFAULT NULL,
  `cost` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `directly_attributable_cost` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `restoration_provision` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `residual_value` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `useful_life_months` INT UNSIGNED NOT NULL DEFAULT 60,
  `depreciation_method` ENUM('straight_line', 'diminishing_balance', 'units') NOT NULL DEFAULT 'straight_line',
  `diminishing_rate_pct` DECIMAL(6,3) NOT NULL DEFAULT 0.000,
  `total_units` DECIMAL(18,3) NOT NULL DEFAULT 0.000,
  `accumulated_depreciation` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `accumulated_impairment` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `revaluation_reserve` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `carrying_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `tax_base` DECIMAL(18,2) DEFAULT NULL,
  `status` ENUM('draft', 'active', 'held_for_sale', 'disposed', 'fully_depreciated') NOT NULL DEFAULT 'draft',
  `disposed_on` DATE DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_fixed_assets_company_code` (`company_id`, `asset_code`),
  KEY `idx_fixed_assets_company_status` (`company_id`, `status`),
  KEY `idx_fixed_assets_category` (`category_id`),
  KEY `idx_fixed_assets_parent` (`parent_asset_id`),
  CONSTRAINT `fk_fixed_assets_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fixed_assets_category` FOREIGN KEY (`category_id`) REFERENCES `asset_categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_fixed_assets_parent` FOREIGN KEY (`parent_asset_id`) REFERENCES `fixed_assets` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `asset_depreciation_schedule` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `asset_id` INT UNSIGNED NOT NULL,
  `period_no` INT UNSIGNED NOT NULL,
  `period_date` DATE NOT NULL,
  `depreciation` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `accumulated` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `carrying` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `voucher_id` INT UNSIGNED DEFAULT NULL,
  `posted` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_asset_dep_period` (`asset_id`, `period_no`),
  KEY `idx_asset_dep_company_date` (`company_id`, `period_date`),
  KEY `idx_asset_dep_voucher` (`voucher_id`),
  CONSTRAINT `fk_asset_dep_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_asset_dep_asset` FOREIGN KEY (`asset_id`) REFERENCES `fixed_assets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_asset_dep_voucher` FOREIGN KEY (`voucher_id`) REFERENCES `vouchers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lease_liabilities` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `asset_id` INT UNSIGNED DEFAULT NULL COMMENT 'linked ROU asset',
  `contract_ref` VARCHAR(120) NOT NULL,
  `commencement_date` DATE NOT NULL,
  `term_months` INT UNSIGNED NOT NULL DEFAULT 12,
  `payment` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `payment_timing` ENUM('advance', 'arrears') NOT NULL DEFAULT 'arrears',
  `discount_rate_annual` DECIMAL(8,4) NOT NULL DEFAULT 0.0000,
  `initial_liability` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `initial_direct_costs` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `prepayments` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `incentives` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `restoration` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `rou_initial` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('draft', 'active', 'closed') NOT NULL DEFAULT 'draft',
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_lease_company` (`company_id`, `status`),
  KEY `idx_lease_asset` (`asset_id`),
  CONSTRAINT `fk_lease_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_lease_asset` FOREIGN KEY (`asset_id`) REFERENCES `fixed_assets` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lease_schedule_lines` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `lease_id` INT UNSIGNED NOT NULL,
  `period_no` INT UNSIGNED NOT NULL,
  `period_date` DATE NOT NULL,
  `opening` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `interest` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `payment` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `principal` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `closing` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `voucher_id` INT UNSIGNED DEFAULT NULL,
  `posted` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_lease_line_period` (`lease_id`, `period_no`),
  KEY `idx_lease_line_voucher` (`voucher_id`),
  CONSTRAINT `fk_lease_line_lease` FOREIGN KEY (`lease_id`) REFERENCES `lease_liabilities` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `asset_impairments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `asset_id` INT UNSIGNED NOT NULL,
  `test_date` DATE NOT NULL,
  `kind` ENUM('impairment', 'reversal', 'held_for_sale', 'revaluation') NOT NULL DEFAULT 'impairment',
  `carrying_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `fair_value_less_costs` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `value_in_use` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `recoverable_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `impairment_loss` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `reversal` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `evidence` VARCHAR(255) DEFAULT NULL,
  `voucher_id` INT UNSIGNED DEFAULT NULL,
  `approved_by` INT UNSIGNED DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_asset_imp_asset_date` (`asset_id`, `test_date`),
  KEY `idx_asset_imp_voucher` (`voucher_id`),
  CONSTRAINT `fk_asset_imp_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_asset_imp_asset` FOREIGN KEY (`asset_id`) REFERENCES `fixed_assets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_asset_imp_voucher` FOREIGN KEY (`voucher_id`) REFERENCES `vouchers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `asset_ledger_mappings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `scope` ENUM('global', 'category', 'asset') NOT NULL DEFAULT 'global',
  `category_id` INT UNSIGNED DEFAULT NULL,
  `asset_id` INT UNSIGNED DEFAULT NULL,
  `purpose` VARCHAR(60) NOT NULL,
  `ledger_id` INT UNSIGNED NOT NULL,
  `effective_date` DATE DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_asset_mapping_scope` (`company_id`, `scope`, `category_id`, `asset_id`, `purpose`),
  KEY `idx_asset_mapping_lookup` (`company_id`, `purpose`, `scope`),
  KEY `idx_asset_mapping_ledger` (`ledger_id`),
  CONSTRAINT `fk_asset_mapping_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_asset_mapping_category` FOREIGN KEY (`category_id`) REFERENCES `asset_categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_asset_mapping_asset` FOREIGN KEY (`asset_id`) REFERENCES `fixed_assets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_asset_mapping_ledger` FOREIGN KEY (`ledger_id`) REFERENCES `ledgers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Manufacturing costing (migration 038): BOM, variances
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `bom_headers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `bom_no` VARCHAR(80) NOT NULL,
  `version` INT UNSIGNED NOT NULL DEFAULT 1,
  `finished_item_id` INT UNSIGNED NOT NULL,
  `output_qty` DECIMAL(14,3) NOT NULL DEFAULT 1.000,
  `std_labour_cost` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `std_overhead_cost` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('draft', 'active', 'retired') NOT NULL DEFAULT 'active',
  `notes` TEXT DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_bom_company_no_version` (`company_id`, `bom_no`, `version`),
  KEY `idx_bom_company_item` (`company_id`, `finished_item_id`),
  CONSTRAINT `fk_bom_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bom_finished_item` FOREIGN KEY (`finished_item_id`) REFERENCES `inventory_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bom_lines` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bom_id` INT UNSIGNED NOT NULL,
  `item_id` INT UNSIGNED NOT NULL,
  `std_qty` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `waste_pct` DECIMAL(6,3) NOT NULL DEFAULT 0.000,
  `std_rate` DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  PRIMARY KEY (`id`),
  KEY `idx_bom_lines_bom` (`bom_id`),
  KEY `idx_bom_lines_item` (`item_id`),
  CONSTRAINT `fk_bom_lines_bom` FOREIGN KEY (`bom_id`) REFERENCES `bom_headers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bom_lines_item` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `production_variances` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `manufacturing_order_id` INT UNSIGNED NOT NULL,
  `variance_type` VARCHAR(40) NOT NULL COMMENT 'material_price, material_usage, labour, overhead, yield',
  `standard_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `actual_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `variance` DECIMAL(18,2) NOT NULL DEFAULT 0.00 COMMENT 'positive = unfavourable (actual above standard)',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_prod_var_order` (`manufacturing_order_id`),
  KEY `idx_prod_var_company_type` (`company_id`, `variance_type`),
  CONSTRAINT `fk_prod_var_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_prod_var_order` FOREIGN KEY (`manufacturing_order_id`) REFERENCES `manufacturing_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
