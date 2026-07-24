-- 066: Structured agreement drafting engine (Working Procedures methodology).
-- Additive only. Existing agreements keep structure_mode='classic' and render
-- exactly as before; new agreements are drafted as section trees with
-- versioning, workflow, templates, comments and clause-level task links.

ALTER TABLE `service_agreements`
  ADD COLUMN `structure_mode` ENUM('classic','builder') NOT NULL DEFAULT 'classic' AFTER `status`,
  ADD COLUMN `workflow_status` VARCHAR(30) NOT NULL DEFAULT 'draft' AFTER `structure_mode`,
  ADD COLUMN `language_mode` ENUM('np','en','both','both_seq') NOT NULL DEFAULT 'both' AFTER `workflow_status`,
  ADD COLUMN `prevailing_language` ENUM('np','en') NOT NULL DEFAULT 'np' AFTER `language_mode`,
  ADD COLUMN `template_id` INT UNSIGNED DEFAULT NULL AFTER `prevailing_language`,
  ADD COLUMN `owner_id` INT UNSIGNED DEFAULT NULL AFTER `template_id`,
  ADD COLUMN `reviewer_id` INT UNSIGNED DEFAULT NULL AFTER `owner_id`,
  ADD COLUMN `approver_id` INT UNSIGNED DEFAULT NULL AFTER `reviewer_id`,
  ADD COLUMN `current_version` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `approver_id`,
  ADD COLUMN `approved_version` INT UNSIGNED DEFAULT NULL AFTER `current_version`,
  ADD COLUMN `client_snapshot_json` TEXT DEFAULT NULL AFTER `approved_version`,
  ADD COLUMN `submitted_by` INT UNSIGNED DEFAULT NULL AFTER `client_snapshot_json`,
  ADD COLUMN `submitted_at` DATETIME DEFAULT NULL AFTER `submitted_by`,
  ADD COLUMN `reviewed_by` INT UNSIGNED DEFAULT NULL AFTER `submitted_at`,
  ADD COLUMN `reviewed_at` DATETIME DEFAULT NULL AFTER `reviewed_by`,
  ADD COLUMN `approved_by` INT UNSIGNED DEFAULT NULL AFTER `reviewed_at`,
  ADD COLUMN `approved_at` DATETIME DEFAULT NULL AFTER `approved_by`,
  ADD COLUMN `issued_by` INT UNSIGNED DEFAULT NULL AFTER `approved_at`,
  ADD COLUMN `issued_at` DATETIME DEFAULT NULL AFTER `issued_by`,
  ADD COLUMN `accepted_at` DATETIME DEFAULT NULL AFTER `issued_at`,
  ADD COLUMN `accepted_by_user_id` INT UNSIGNED DEFAULT NULL AFTER `accepted_at`,
  ADD COLUMN `acceptance_note` VARCHAR(255) DEFAULT NULL AFTER `accepted_by_user_id`,
  ADD COLUMN `acceptance_ip` VARCHAR(45) DEFAULT NULL AFTER `acceptance_note`,
  ADD COLUMN `signed_document_id` INT UNSIGNED DEFAULT NULL AFTER `acceptance_ip`,
  ADD COLUMN `activated_at` DATETIME DEFAULT NULL AFTER `signed_document_id`,
  ADD COLUMN `expiry_date` DATE DEFAULT NULL AFTER `activated_at`,
  ADD COLUMN `terminated_at` DATETIME DEFAULT NULL AFTER `expiry_date`,
  ADD COLUMN `termination_reason` VARCHAR(255) DEFAULT NULL AFTER `terminated_at`,
  ADD COLUMN `superseded_by_id` INT UNSIGNED DEFAULT NULL AFTER `termination_reason`,
  ADD COLUMN `archived_at` DATETIME DEFAULT NULL AFTER `superseded_by_id`,
  ADD KEY `idx_agreements_workflow` (`company_id`, `workflow_status`);

-- Bring pre-066 rows into the new lifecycle without changing their meaning.
UPDATE `service_agreements` SET `workflow_status` = 'approved' WHERE `status` = 'final' AND `workflow_status` = 'draft';

-- Section tree: stable IDs internally, numbers computed only for display.
CREATE TABLE IF NOT EXISTS `agreement_sections` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `agreement_id` INT UNSIGNED NOT NULL,
  `parent_id` INT UNSIGNED DEFAULT NULL,
  `section_type` ENUM('chapter','clause','schedule') NOT NULL DEFAULT 'clause',
  `sort_order` INT NOT NULL DEFAULT 0,
  `title_en` VARCHAR(255) DEFAULT NULL,
  `title_np` VARCHAR(255) DEFAULT NULL,
  `body_en` MEDIUMTEXT DEFAULT NULL,
  `body_np` MEDIUMTEXT DEFAULT NULL,
  `drafting_note` TEXT DEFAULT NULL,
  `client_note` TEXT DEFAULT NULL,
  `is_mandatory` TINYINT(1) NOT NULL DEFAULT 0,
  `is_locked` TINYINT(1) NOT NULL DEFAULT 0,
  `source_template_section_id` INT UNSIGNED DEFAULT NULL,
  `status` ENUM('draft','final') NOT NULL DEFAULT 'draft',
  `created_by` INT UNSIGNED DEFAULT NULL,
  `updated_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sections_tree` (`agreement_id`, `parent_id`, `sort_order`),
  CONSTRAINT `fk_sections_agreement` FOREIGN KEY (`agreement_id`) REFERENCES `service_agreements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Immutable version snapshots (master fields + section tree + links + client).
CREATE TABLE IF NOT EXISTS `agreement_versions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `agreement_id` INT UNSIGNED NOT NULL,
  `version_no` INT UNSIGNED NOT NULL,
  `workflow_status` VARCHAR(30) NOT NULL DEFAULT 'draft',
  `content_json` MEDIUMTEXT NOT NULL,
  `change_summary` VARCHAR(255) DEFAULT NULL,
  `change_reason` VARCHAR(255) DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `approved_by` INT UNSIGNED DEFAULT NULL,
  `approved_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_agreement_version` (`agreement_id`, `version_no`),
  CONSTRAINT `fk_versions_agreement` FOREIGN KEY (`agreement_id`) REFERENCES `service_agreements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Agreement <-> existing client_tasks links (no parallel task system).
CREATE TABLE IF NOT EXISTS `agreement_task_links` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `agreement_id` INT UNSIGNED NOT NULL,
  `task_id` INT UNSIGNED NOT NULL,
  `section_id` INT UNSIGNED DEFAULT NULL,
  `note` VARCHAR(255) DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_agreement_task` (`agreement_id`, `task_id`),
  KEY `idx_task_links_task` (`task_id`),
  CONSTRAINT `fk_task_links_agreement` FOREIGN KEY (`agreement_id`) REFERENCES `service_agreements` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_task_links_task` FOREIGN KEY (`task_id`) REFERENCES `client_tasks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_task_links_section` FOREIGN KEY (`section_id`) REFERENCES `agreement_sections` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reusable templates; instantiating copies a snapshot, so later template edits
-- never silently change existing agreements.
CREATE TABLE IF NOT EXISTS `agreement_templates` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(190) NOT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `service_type` VARCHAR(120) DEFAULT NULL,
  `sections_json` MEDIUMTEXT NOT NULL,
  `defaults_json` TEXT DEFAULT NULL,
  `is_default` TINYINT(1) NOT NULL DEFAULT 0,
  `archived` TINYINT(1) NOT NULL DEFAULT 0,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `updated_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_templates_company` (`company_id`, `archived`, `is_default`),
  CONSTRAINT `fk_templates_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Review comments: per agreement, optionally anchored to a section/version.
CREATE TABLE IF NOT EXISTS `agreement_comments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `agreement_id` INT UNSIGNED NOT NULL,
  `section_id` INT UNSIGNED DEFAULT NULL,
  `version_no` INT UNSIGNED DEFAULT NULL,
  `comment` TEXT NOT NULL,
  `status` ENUM('open','resolved') NOT NULL DEFAULT 'open',
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `resolved_by` INT UNSIGNED DEFAULT NULL,
  `resolved_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_comments_agreement` (`agreement_id`, `status`),
  CONSTRAINT `fk_comments_agreement` FOREIGN KEY (`agreement_id`) REFERENCES `service_agreements` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_comments_section` FOREIGN KEY (`section_id`) REFERENCES `agreement_sections` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
