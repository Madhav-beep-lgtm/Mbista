-- Work Portal task upgrades:
-- 1. One task can be assigned to multiple staff (client_task_assignees).
-- 2. Staff can be replaced on an existing task with a recorded handoff
--    (task_assignment_events keeps the history and the progress summary
--    delivered to the replacement staff).
-- 3. Tasks can be terminated mid-way and settled: completed stages stay
--    invoiceable and a termination/compensation invoice can be issued.

CREATE TABLE IF NOT EXISTS `client_task_assignees` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED DEFAULT NULL,
  `task_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `is_lead` TINYINT(1) NOT NULL DEFAULT 0,
  `assigned_by` INT UNSIGNED DEFAULT NULL,
  `assigned_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_task_assignee` (`task_id`, `user_id`),
  KEY `idx_task_assignees_company` (`company_id`),
  KEY `idx_task_assignees_user` (`user_id`),
  CONSTRAINT `fk_task_assignees_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_task_assignees_task` FOREIGN KEY (`task_id`) REFERENCES `client_tasks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_task_assignees_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_task_assignees_assigner` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `task_assignment_events` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED DEFAULT NULL,
  `task_id` INT UNSIGNED NOT NULL,
  `event_type` ENUM('assigned', 'replaced', 'removed') NOT NULL,
  `from_user_id` INT UNSIGNED DEFAULT NULL,
  `to_user_id` INT UNSIGNED DEFAULT NULL,
  `note` TEXT DEFAULT NULL,
  `progress_summary` TEXT DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_task_assignment_events_task` (`task_id`),
  KEY `idx_task_assignment_events_company` (`company_id`),
  KEY `idx_task_assignment_events_to_user` (`to_user_id`),
  CONSTRAINT `fk_task_assignment_events_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_task_assignment_events_task` FOREIGN KEY (`task_id`) REFERENCES `client_tasks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_task_assignment_events_from_user` FOREIGN KEY (`from_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_task_assignment_events_to_user` FOREIGN KEY (`to_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_task_assignment_events_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE client_tasks
  MODIFY COLUMN `status` ENUM('new', 'in_progress', 'on_hold', 'completed', 'cancelled', 'terminated') NOT NULL DEFAULT 'new',
  ADD COLUMN `terminated_at` DATETIME DEFAULT NULL AFTER `completed_at`,
  ADD COLUMN `terminated_by` INT UNSIGNED DEFAULT NULL AFTER `terminated_at`,
  ADD COLUMN `termination_reason` TEXT DEFAULT NULL AFTER `terminated_by`,
  ADD COLUMN `termination_compensation` DECIMAL(12,2) DEFAULT NULL AFTER `termination_reason`,
  ADD KEY `idx_client_tasks_terminated_by` (`terminated_by`),
  ADD CONSTRAINT `fk_client_tasks_terminated_by` FOREIGN KEY (`terminated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

-- Superset of the accounting-module enum so 'inventory'/'manufacturing'/'other'
-- values introduced by the invoice-module upgrade are preserved.
ALTER TABLE task_invoices
  MODIFY COLUMN `invoice_type` ENUM('stage', 'task', 'inventory', 'manufacturing', 'other', 'termination') NOT NULL DEFAULT 'task';

-- Backfill: existing single-staff assignments become the lead assignee row.
INSERT IGNORE INTO client_task_assignees (company_id, task_id, user_id, is_lead, assigned_at)
SELECT t.company_id, t.id, t.assigned_staff_user_id, 1, t.updated_at
FROM client_tasks t
WHERE t.assigned_staff_user_id IS NOT NULL;
