<?php
declare(strict_types=1);

function admin_work_portal_repair_table_exists(string $tableName): bool
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = :db_name AND table_name = :table_name');
    $stmt->execute(['db_name' => DB_NAME, 'table_name' => $tableName]);

    return (int) $stmt->fetchColumn() > 0;
}

function admin_work_portal_repair_column_exists(string $tableName, string $columnName): bool
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = :db_name AND table_name = :table_name AND column_name = :column_name');
    $stmt->execute(['db_name' => DB_NAME, 'table_name' => $tableName, 'column_name' => $columnName]);

    return (int) $stmt->fetchColumn() > 0;
}

function admin_work_portal_repair_index_exists(string $tableName, string $indexName): bool
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = :db_name AND table_name = :table_name AND index_name = :index_name');
    $stmt->execute(['db_name' => DB_NAME, 'table_name' => $tableName, 'index_name' => $indexName]);

    return (int) $stmt->fetchColumn() > 0;
}

function admin_work_portal_repair_constraint_exists(string $tableName, string $constraintName): bool
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema = :db_name AND table_name = :table_name AND constraint_name = :constraint_name');
    $stmt->execute(['db_name' => DB_NAME, 'table_name' => $tableName, 'constraint_name' => $constraintName]);

    return (int) $stmt->fetchColumn() > 0;
}

function admin_work_portal_repair_enum_has(string $tableName, string $columnName, string $value): bool
{
    $stmt = db()->prepare('SELECT COLUMN_TYPE FROM information_schema.columns WHERE table_schema = :db_name AND table_name = :table_name AND column_name = :column_name');
    $stmt->execute(['db_name' => DB_NAME, 'table_name' => $tableName, 'column_name' => $columnName]);
    $columnType = (string) $stmt->fetchColumn();

    return stripos($columnType, "'" . $value . "'") !== false;
}

function admin_work_portal_repair_add_column(string $tableName, string $columnName, string $definition): void
{
    if (admin_work_portal_repair_table_exists($tableName) && !admin_work_portal_repair_column_exists($tableName, $columnName)) {
        db()->exec('ALTER TABLE `' . $tableName . '` ADD COLUMN ' . $definition);
    }
}

function admin_work_portal_repair_add_index(string $tableName, string $indexName, string $definition): void
{
    if (admin_work_portal_repair_table_exists($tableName) && !admin_work_portal_repair_index_exists($tableName, $indexName)) {
        db()->exec('ALTER TABLE `' . $tableName . '` ADD ' . $definition);
    }
}

function admin_work_portal_repair_add_constraint(string $tableName, string $constraintName, string $definition): void
{
    if (admin_work_portal_repair_table_exists($tableName) && !admin_work_portal_repair_constraint_exists($tableName, $constraintName)) {
        db()->exec('ALTER TABLE `' . $tableName . '` ADD CONSTRAINT ' . $definition);
    }
}

function admin_work_portal_repair_required_tables(): array
{
    return ['industries', 'service_provider_entities', 'client_service_provider_entities'];
}

function admin_work_portal_missing_repair_tables(): array
{
    return array_values(array_filter(
        admin_work_portal_repair_required_tables(),
        static fn (string $table): bool => !admin_work_portal_repair_table_exists($table)
    ));
}

function admin_work_portal_repair_database(): array
{
    $errors = [];

    $run = static function (string $label, callable $callback) use (&$errors): void {
        try {
            $callback();
        } catch (Throwable $exception) {
            $errors[] = $label . ': ' . $exception->getMessage();
        }
    };

    $run('Add user password-change flag', static function (): void {
        admin_work_portal_repair_add_column('users', 'must_change_password', '`must_change_password` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`');
    });

    $run('Upgrade client profile columns', static function (): void {
        admin_work_portal_repair_add_column('client_profiles', 'registration_no', '`registration_no` VARCHAR(80) DEFAULT NULL AFTER `client_code`');
        admin_work_portal_repair_add_column('client_profiles', 'industry_id', '`industry_id` INT UNSIGNED DEFAULT NULL AFTER `registration_no`');
        admin_work_portal_repair_add_column('client_profiles', 'authorized_person_position', '`authorized_person_position` VARCHAR(140) DEFAULT NULL AFTER `pan_no`');
        admin_work_portal_repair_add_column('client_profiles', 'authorized_signatory_name', '`authorized_signatory_name` VARCHAR(190) DEFAULT NULL AFTER `authorized_person_position`');
        admin_work_portal_repair_add_column('client_profiles', 'contact_number', '`contact_number` VARCHAR(60) DEFAULT NULL AFTER `authorized_signatory_name`');
        admin_work_portal_repair_add_column('client_profiles', 'website', '`website` VARCHAR(255) DEFAULT NULL AFTER `contact_number`');
        admin_work_portal_repair_add_column('client_profiles', 'company_logo_path', '`company_logo_path` VARCHAR(255) DEFAULT NULL AFTER `website`');
        admin_work_portal_repair_add_column('client_profiles', 'client_status', "`client_status` ENUM('active', 'suspended', 'inactive') NOT NULL DEFAULT 'active' AFTER `company_logo_path`");
        admin_work_portal_repair_add_index('client_profiles', 'idx_client_profiles_industry', 'KEY `idx_client_profiles_industry` (`industry_id`)');
        admin_work_portal_repair_add_index('client_profiles', 'idx_client_profiles_status', 'KEY `idx_client_profiles_status` (`client_status`)');
        admin_work_portal_repair_add_index('client_profiles', 'idx_client_profiles_registration', 'KEY `idx_client_profiles_registration` (`registration_no`)');
        admin_work_portal_repair_add_index('client_profiles', 'idx_client_profiles_pan', 'KEY `idx_client_profiles_pan` (`pan_no`)');
    });

    $run('Create industries table', static function (): void {
        db()->exec("
            CREATE TABLE IF NOT EXISTS `industries` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `name` VARCHAR(160) NOT NULL,
              `is_active` TINYINT(1) NOT NULL DEFAULT 1,
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uniq_industries_name` (`name`),
              KEY `idx_industries_active` (`is_active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    });

    $run('Create service provider entities table', static function (): void {
        db()->exec("
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
              KEY `idx_service_provider_entities_active` (`is_active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        admin_work_portal_repair_add_constraint('service_provider_entities', 'fk_service_provider_entities_company', '`fk_service_provider_entities_company` FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE SET NULL');
    });

    $run('Create client service provider mapping table', static function (): void {
        db()->exec("
            CREATE TABLE IF NOT EXISTS `client_service_provider_entities` (
              `client_id` INT UNSIGNED NOT NULL,
              `service_provider_entity_id` INT UNSIGNED NOT NULL,
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`client_id`, `service_provider_entity_id`),
              KEY `idx_client_service_provider_entity` (`service_provider_entity_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        admin_work_portal_repair_add_constraint('client_service_provider_entities', 'fk_client_service_provider_client', '`fk_client_service_provider_client` FOREIGN KEY (`client_id`) REFERENCES `client_profiles`(`id`) ON DELETE CASCADE');
        admin_work_portal_repair_add_constraint('client_service_provider_entities', 'fk_client_service_provider_entity', '`fk_client_service_provider_entity` FOREIGN KEY (`service_provider_entity_id`) REFERENCES `service_provider_entities`(`id`) ON DELETE CASCADE');
    });

    $run('Upgrade client task service-provider fields', static function (): void {
        admin_work_portal_repair_add_column('client_tasks', 'service_provider_entity_id', '`service_provider_entity_id` INT UNSIGNED DEFAULT NULL AFTER `client_id`');
        admin_work_portal_repair_add_index('client_tasks', 'idx_client_tasks_service_provider_entity', 'KEY `idx_client_tasks_service_provider_entity` (`service_provider_entity_id`)');
        admin_work_portal_repair_add_constraint('client_tasks', 'fk_client_tasks_service_provider_entity', '`fk_client_tasks_service_provider_entity` FOREIGN KEY (`service_provider_entity_id`) REFERENCES `service_provider_entities`(`id`) ON DELETE SET NULL');
    });

    $run('Seed default industries', static function (): void {
        $stmt = db()->prepare('INSERT IGNORE INTO industries (`name`, `is_active`) VALUES (:name, 1)');
        foreach (['Accounting and Audit', 'Banking and Finance', 'Cooperative', 'Education', 'Healthcare', 'Hydropower', 'Information Technology', 'Jewellery', 'Manufacturing', 'NGO/INGO', 'Real Estate', 'Retail and Trading', 'Other'] as $industry) {
            $stmt->execute(['name' => $industry]);
        }
    });

    $run('Seed service-provider entities from companies', static function (): void {
        db()->exec("
            INSERT INTO service_provider_entities (`company_id`, `name`, `code`, `is_active`)
            SELECT c.id, c.name, c.code, c.is_active
            FROM companies c
            WHERE NOT EXISTS (
              SELECT 1
              FROM service_provider_entities spe
              WHERE spe.company_id = c.id
                 OR spe.code = c.code
            )
        ");
    });

    $run('Create task multi-assignee table', static function (): void {
        db()->exec("
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
              KEY `idx_task_assignees_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        admin_work_portal_repair_add_constraint('client_task_assignees', 'fk_task_assignees_task', '`fk_task_assignees_task` FOREIGN KEY (`task_id`) REFERENCES `client_tasks`(`id`) ON DELETE CASCADE');
        admin_work_portal_repair_add_constraint('client_task_assignees', 'fk_task_assignees_user', '`fk_task_assignees_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE');
        if (admin_work_portal_repair_column_exists('client_tasks', 'assigned_staff_user_id')) {
            db()->exec('
                INSERT IGNORE INTO client_task_assignees (company_id, task_id, user_id, is_lead, assigned_at)
                SELECT t.company_id, t.id, t.assigned_staff_user_id, 1, t.updated_at
                FROM client_tasks t
                WHERE t.assigned_staff_user_id IS NOT NULL
            ');
        }
    });

    $run('Create task assignment event table', static function (): void {
        db()->exec("
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
              KEY `idx_task_assignment_events_to_user` (`to_user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        admin_work_portal_repair_add_constraint('task_assignment_events', 'fk_task_assignment_events_task', '`fk_task_assignment_events_task` FOREIGN KEY (`task_id`) REFERENCES `client_tasks`(`id`) ON DELETE CASCADE');
    });

    $run('Add task progress override column', static function (): void {
        admin_work_portal_repair_add_column('client_tasks', 'progress_percent', '`progress_percent` TINYINT UNSIGNED DEFAULT NULL AFTER `priority`');
    });

    $run('Add task termination support', static function (): void {
        if (!admin_work_portal_repair_enum_has('client_tasks', 'status', 'terminated')) {
            db()->exec("ALTER TABLE client_tasks MODIFY COLUMN `status` ENUM('new', 'in_progress', 'on_hold', 'completed', 'cancelled', 'terminated') NOT NULL DEFAULT 'new'");
        }
        admin_work_portal_repair_add_column('client_tasks', 'terminated_at', '`terminated_at` DATETIME DEFAULT NULL AFTER `completed_at`');
        admin_work_portal_repair_add_column('client_tasks', 'terminated_by', '`terminated_by` INT UNSIGNED DEFAULT NULL AFTER `terminated_at`');
        admin_work_portal_repair_add_column('client_tasks', 'termination_reason', '`termination_reason` TEXT DEFAULT NULL AFTER `terminated_by`');
        admin_work_portal_repair_add_column('client_tasks', 'termination_compensation', '`termination_compensation` DECIMAL(12,2) DEFAULT NULL AFTER `termination_reason`');
        if (!admin_work_portal_repair_enum_has('task_invoices', 'invoice_type', 'termination')) {
            db()->exec("ALTER TABLE task_invoices MODIFY COLUMN `invoice_type` ENUM('stage', 'task', 'inventory', 'manufacturing', 'other', 'termination') NOT NULL DEFAULT 'task'");
        }
    });

    $run('Backfill client status values', static function (): void {
        if (admin_work_portal_repair_column_exists('client_profiles', 'client_status') && admin_work_portal_repair_column_exists('client_profiles', 'is_active')) {
            db()->exec("UPDATE client_profiles SET client_status = CASE WHEN is_active = 1 THEN 'active' ELSE 'suspended' END WHERE client_status IS NULL OR client_status = ''");
        }
    });

    return $errors;
}
