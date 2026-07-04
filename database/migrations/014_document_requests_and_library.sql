-- Adds a document library (with lightweight self-referencing version history) and a
-- document-request workflow between admin/staff and clients.

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
