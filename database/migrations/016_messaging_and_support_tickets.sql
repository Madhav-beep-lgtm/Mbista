-- Adds secure threaded messaging (with per-participant read tracking) and a support ticket
-- system with its own reply thread.

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
