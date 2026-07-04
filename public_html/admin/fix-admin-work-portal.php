<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_admin();

$pageTitle = 'Fix Admin Work Portal Database';
$steps = [];

function repair_step(string $label, callable $callback): void
{
    global $steps;

    try {
        $callback();
        $steps[] = ['ok' => true, 'label' => $label, 'message' => 'Done'];
    } catch (Throwable $exception) {
        $steps[] = ['ok' => false, 'label' => $label, 'message' => $exception->getMessage()];
    }
}

function repair_table_exists(string $tableName): bool
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = :db_name AND table_name = :table_name');
    $stmt->execute(['db_name' => DB_NAME, 'table_name' => $tableName]);

    return (int) $stmt->fetchColumn() > 0;
}

function repair_column_exists(string $tableName, string $columnName): bool
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = :db_name AND table_name = :table_name AND column_name = :column_name');
    $stmt->execute(['db_name' => DB_NAME, 'table_name' => $tableName, 'column_name' => $columnName]);

    return (int) $stmt->fetchColumn() > 0;
}

function repair_index_exists(string $tableName, string $indexName): bool
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = :db_name AND table_name = :table_name AND index_name = :index_name');
    $stmt->execute(['db_name' => DB_NAME, 'table_name' => $tableName, 'index_name' => $indexName]);

    return (int) $stmt->fetchColumn() > 0;
}

function repair_constraint_exists(string $tableName, string $constraintName): bool
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema = :db_name AND table_name = :table_name AND constraint_name = :constraint_name');
    $stmt->execute(['db_name' => DB_NAME, 'table_name' => $tableName, 'constraint_name' => $constraintName]);

    return (int) $stmt->fetchColumn() > 0;
}

function repair_add_column(string $tableName, string $columnName, string $definition): void
{
    if (repair_table_exists($tableName) && !repair_column_exists($tableName, $columnName)) {
        db()->exec('ALTER TABLE `' . $tableName . '` ADD COLUMN ' . $definition);
    }
}

function repair_add_index(string $tableName, string $indexName, string $definition): void
{
    if (repair_table_exists($tableName) && !repair_index_exists($tableName, $indexName)) {
        db()->exec('ALTER TABLE `' . $tableName . '` ADD ' . $definition);
    }
}

function repair_add_constraint(string $tableName, string $constraintName, string $definition): void
{
    if (repair_table_exists($tableName) && !repair_constraint_exists($tableName, $constraintName)) {
        db()->exec('ALTER TABLE `' . $tableName . '` ADD CONSTRAINT ' . $definition);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    repair_step('Add user password-change flag', static function (): void {
        repair_add_column('users', 'must_change_password', '`must_change_password` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`');
    });

    repair_step('Upgrade client profile columns', static function (): void {
        repair_add_column('client_profiles', 'registration_no', '`registration_no` VARCHAR(80) DEFAULT NULL AFTER `client_code`');
        repair_add_column('client_profiles', 'industry_id', '`industry_id` INT UNSIGNED DEFAULT NULL AFTER `registration_no`');
        repair_add_column('client_profiles', 'authorized_person_position', '`authorized_person_position` VARCHAR(140) DEFAULT NULL AFTER `pan_no`');
        repair_add_column('client_profiles', 'authorized_signatory_name', '`authorized_signatory_name` VARCHAR(190) DEFAULT NULL AFTER `authorized_person_position`');
        repair_add_column('client_profiles', 'contact_number', '`contact_number` VARCHAR(60) DEFAULT NULL AFTER `authorized_signatory_name`');
        repair_add_column('client_profiles', 'website', '`website` VARCHAR(255) DEFAULT NULL AFTER `contact_number`');
        repair_add_column('client_profiles', 'company_logo_path', '`company_logo_path` VARCHAR(255) DEFAULT NULL AFTER `website`');
        repair_add_column('client_profiles', 'client_status', "`client_status` ENUM('active', 'suspended', 'inactive') NOT NULL DEFAULT 'active' AFTER `company_logo_path`");
        repair_add_index('client_profiles', 'idx_client_profiles_industry', 'KEY `idx_client_profiles_industry` (`industry_id`)');
        repair_add_index('client_profiles', 'idx_client_profiles_status', 'KEY `idx_client_profiles_status` (`client_status`)');
        repair_add_index('client_profiles', 'idx_client_profiles_registration', 'KEY `idx_client_profiles_registration` (`registration_no`)');
        repair_add_index('client_profiles', 'idx_client_profiles_pan', 'KEY `idx_client_profiles_pan` (`pan_no`)');
    });

    repair_step('Create industries table', static function (): void {
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

    repair_step('Create service provider entities table', static function (): void {
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
        repair_add_constraint('service_provider_entities', 'fk_service_provider_entities_company', '`fk_service_provider_entities_company` FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE SET NULL');
    });

    repair_step('Create client service provider mapping table', static function (): void {
        db()->exec("
            CREATE TABLE IF NOT EXISTS `client_service_provider_entities` (
              `client_id` INT UNSIGNED NOT NULL,
              `service_provider_entity_id` INT UNSIGNED NOT NULL,
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`client_id`, `service_provider_entity_id`),
              KEY `idx_client_service_provider_entity` (`service_provider_entity_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        repair_add_constraint('client_service_provider_entities', 'fk_client_service_provider_client', '`fk_client_service_provider_client` FOREIGN KEY (`client_id`) REFERENCES `client_profiles`(`id`) ON DELETE CASCADE');
        repair_add_constraint('client_service_provider_entities', 'fk_client_service_provider_entity', '`fk_client_service_provider_entity` FOREIGN KEY (`service_provider_entity_id`) REFERENCES `service_provider_entities`(`id`) ON DELETE CASCADE');
    });

    repair_step('Upgrade client task service-provider fields', static function (): void {
        repair_add_column('client_tasks', 'service_provider_entity_id', '`service_provider_entity_id` INT UNSIGNED DEFAULT NULL AFTER `client_id`');
        repair_add_index('client_tasks', 'idx_client_tasks_service_provider_entity', 'KEY `idx_client_tasks_service_provider_entity` (`service_provider_entity_id`)');
        repair_add_constraint('client_tasks', 'fk_client_tasks_service_provider_entity', '`fk_client_tasks_service_provider_entity` FOREIGN KEY (`service_provider_entity_id`) REFERENCES `service_provider_entities`(`id`) ON DELETE SET NULL');
    });

    repair_step('Seed default industries', static function (): void {
        $industries = [
            'Accounting and Audit',
            'Banking and Finance',
            'Cooperative',
            'Education',
            'Healthcare',
            'Hydropower',
            'Information Technology',
            'Jewellery',
            'Manufacturing',
            'NGO/INGO',
            'Real Estate',
            'Retail and Trading',
            'Other',
        ];
        $stmt = db()->prepare('INSERT IGNORE INTO industries (`name`, `is_active`) VALUES (:name, 1)');
        foreach ($industries as $industry) {
            $stmt->execute(['name' => $industry]);
        }
    });

    repair_step('Seed service-provider entities from companies', static function (): void {
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

    repair_step('Backfill client status values', static function (): void {
        if (repair_column_exists('client_profiles', 'client_status') && repair_column_exists('client_profiles', 'is_active')) {
            db()->exec("UPDATE client_profiles SET client_status = CASE WHEN is_active = 1 THEN 'active' ELSE 'suspended' END WHERE client_status IS NULL OR client_status = ''");
        }
    });
}

$requiredTables = ['industries', 'service_provider_entities', 'client_service_provider_entities'];
$missingTables = array_values(array_filter($requiredTables, static fn (string $table): bool => !repair_table_exists($table)));

include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
<div class="admin-page">
    <div class="table-card">
        <h1>Fix Admin Work Portal Database</h1>
        <p>This repair applies the client-management database upgrade required by the Admin Work Portal.</p>

        <?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
            <div class="alert <?= array_filter($steps, static fn (array $step): bool => !$step['ok']) === [] ? 'success' : 'error' ?>">
                <?= array_filter($steps, static fn (array $step): bool => !$step['ok']) === [] ? 'Repair completed.' : 'Repair completed with errors. Review the failed rows below.' ?>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Step</th>
                        <th>Status</th>
                        <th>Message</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($steps as $step): ?>
                        <tr>
                            <td><?= e($step['label']) ?></td>
                            <td><?= $step['ok'] ? 'OK' : 'Failed' ?></td>
                            <td><?= e($step['message']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if ($missingTables === []): ?>
            <p><strong>Current status:</strong> required Admin Work Portal tables are present.</p>
            <p><a class="button" href="<?= e(url('admin/workspace.php')) ?>">Open Admin Work Portal</a></p>
        <?php else: ?>
            <p><strong>Current status:</strong> missing <?= e(implode(', ', $missingTables)) ?>.</p>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <button type="submit">Run Database Repair</button>
            </form>
        <?php endif; ?>
    </div>
</div>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
