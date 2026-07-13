-- Migration 034: granular per-staff module/action permissions.
--
-- Complements the coarse access_level capability matrix (user_can()) with
-- fine-grained grants an admin assigns to each staff member.
--
-- Backward-compatibility rule enforced in code (user_can_do): a staff user with
-- ZERO rows here keeps full legacy access; the moment an admin ticks any box the
-- user switches to strict mode and may only do exactly what is granted. So this
-- migration is inert until an admin opts a user into granular control — no
-- existing staff loses access when it runs.

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
