-- Adds staff attendance (with correction requests), leave management, and timesheets.
-- Purely internal (admin + staff); no client portal surface.

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
