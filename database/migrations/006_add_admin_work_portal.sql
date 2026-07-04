CREATE TABLE IF NOT EXISTS `teams` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(140) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `leader_user_id` INT UNSIGNED NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_teams_name` (`name`),
  KEY `idx_teams_leader` (`leader_user_id`),
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

CREATE TABLE IF NOT EXISTS `client_profiles` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `organization_name` VARCHAR(190) NOT NULL,
  `client_code` VARCHAR(60) DEFAULT NULL,
  `assigned_staff_user_id` INT UNSIGNED DEFAULT NULL,
  `assigned_team_id` INT UNSIGNED DEFAULT NULL,
  `address` VARCHAR(255) DEFAULT NULL,
  `pan_no` VARCHAR(60) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_client_profiles_user` (`user_id`),
  UNIQUE KEY `uniq_client_profiles_code` (`client_code`),
  KEY `idx_client_profiles_staff` (`assigned_staff_user_id`),
  KEY `idx_client_profiles_team` (`assigned_team_id`),
  CONSTRAINT `fk_client_profiles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_client_profiles_staff` FOREIGN KEY (`assigned_staff_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_client_profiles_team` FOREIGN KEY (`assigned_team_id`) REFERENCES `teams` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `service_contracts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
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
  KEY `idx_service_contract_client` (`client_id`),
  KEY `idx_service_contract_created_by` (`created_by`),
  CONSTRAINT `fk_service_contract_client` FOREIGN KEY (`client_id`) REFERENCES `client_profiles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_service_contract_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `client_tasks` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_id` INT UNSIGNED NOT NULL,
  `contract_id` INT UNSIGNED DEFAULT NULL,
  `team_id` INT UNSIGNED DEFAULT NULL,
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
  KEY `idx_client_tasks_client` (`client_id`),
  KEY `idx_client_tasks_team` (`team_id`),
  KEY `idx_client_tasks_contract` (`contract_id`),
  KEY `idx_client_tasks_status` (`status`),
  CONSTRAINT `fk_client_tasks_client` FOREIGN KEY (`client_id`) REFERENCES `client_profiles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_client_tasks_team` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_client_tasks_contract` FOREIGN KEY (`contract_id`) REFERENCES `service_contracts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_client_tasks_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `task_stages` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_id` INT UNSIGNED NOT NULL,
  `stage_name` VARCHAR(190) NOT NULL,
  `sequence_no` INT NOT NULL DEFAULT 1,
  `stage_fee` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('pending', 'in_progress', 'completed') NOT NULL DEFAULT 'pending',
  `completed_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_task_stages_task` (`task_id`),
  KEY `idx_task_stages_status` (`status`),
  CONSTRAINT `fk_task_stages_task` FOREIGN KEY (`task_id`) REFERENCES `client_tasks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `task_invoices` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_id` INT UNSIGNED NOT NULL,
  `stage_id` INT UNSIGNED DEFAULT NULL,
  `invoice_no` VARCHAR(100) NOT NULL,
  `invoice_type` ENUM('stage', 'task') NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('draft', 'issued', 'paid', 'cancelled') NOT NULL DEFAULT 'issued',
  `issued_on` DATE NOT NULL,
  `due_on` DATE DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `issued_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_task_invoice_no` (`invoice_no`),
  KEY `idx_task_invoices_task` (`task_id`),
  KEY `idx_task_invoices_stage` (`stage_id`),
  CONSTRAINT `fk_task_invoices_task` FOREIGN KEY (`task_id`) REFERENCES `client_tasks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_task_invoices_stage` FOREIGN KEY (`stage_id`) REFERENCES `task_stages` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_task_invoices_issued_by` FOREIGN KEY (`issued_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
