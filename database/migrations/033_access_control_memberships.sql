-- Migration 033: platform access control
--   * explicit user -> company memberships (replaces "any admin + 4-digit PIN")
--   * full user lifecycle statuses
--   * session revocation epoch
--   * security event log (login attempts, denied access, org switches, impersonation)
--
-- Additive and backward compatible: existing 'active'/'inactive' statuses keep
-- their meaning and every current user is backfilled into company_memberships,
-- so no one loses access when this runs.

-- ---------------------------------------------------------------------------
-- 1. User lifecycle statuses
-- ---------------------------------------------------------------------------
ALTER TABLE `users`
  MODIFY COLUMN `status` ENUM('active', 'inactive', 'invited', 'suspended', 'locked') NOT NULL DEFAULT 'active';

-- Sessions issued before this timestamp are rejected. Bumped on suspension,
-- deactivation, role/permission change and forced password reset.
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `sessions_valid_from` DATETIME DEFAULT NULL AFTER `must_change_password`;

-- ---------------------------------------------------------------------------
-- 2. Explicit membership: which user may open which company portal
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

-- Backfill: every user with a home company keeps access to it.
INSERT IGNORE INTO `company_memberships` (`user_id`, `company_id`, `access_level`, `is_primary`, `is_active`)
SELECT u.`id`, u.`company_id`, u.`access_level`, 1, 1
FROM `users` u
JOIN `companies` c ON c.`id` = u.`company_id`
WHERE u.`company_id` IS NOT NULL;

-- ---------------------------------------------------------------------------
-- 3. Security event log
-- ---------------------------------------------------------------------------
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
