-- Migration 045: multiple portal logins per client organization.
--
-- client_profiles.user_id stays the OWNER login (unique, unchanged). This
-- pivot adds additional customer logins ("members") that the owner creates
-- from the client portal. Every place that resolves "which client profile
-- does this customer login belong to" goes through client_profile_for_user(),
-- which checks the owner column first and then this table.
--
-- member_role drives accounting rights inside the client's books company
-- (enforced in user_can_do()):
--   owner       — the primary login: full accounting incl. approve/post,
--                 plus portal user management.
--   approver    — full accounting incl. approve/post.
--   entry_maker — may view, create and edit entries but never approve/post.

CREATE TABLE IF NOT EXISTS `client_portal_users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `member_role` ENUM('owner', 'approver', 'entry_maker') NOT NULL DEFAULT 'entry_maker',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_client_portal_user` (`user_id`),
  KEY `idx_client_portal_users_client` (`client_id`),
  CONSTRAINT `fk_client_portal_users_client` FOREIGN KEY (`client_id`) REFERENCES `client_profiles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_client_portal_users_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_client_portal_users_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
