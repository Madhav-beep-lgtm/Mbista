<?php
declare(strict_types=1);

function accounting_repair_table_exists(string $tableName): bool
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = :db_name AND table_name = :table_name');
    $stmt->execute(['db_name' => DB_NAME, 'table_name' => $tableName]);

    return (int) $stmt->fetchColumn() > 0;
}

function accounting_repair_column_exists(string $tableName, string $columnName): bool
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = :db_name AND table_name = :table_name AND column_name = :column_name');
    $stmt->execute(['db_name' => DB_NAME, 'table_name' => $tableName, 'column_name' => $columnName]);

    return (int) $stmt->fetchColumn() > 0;
}

function accounting_repair_index_exists(string $tableName, string $indexName): bool
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = :db_name AND table_name = :table_name AND index_name = :index_name');
    $stmt->execute(['db_name' => DB_NAME, 'table_name' => $tableName, 'index_name' => $indexName]);

    return (int) $stmt->fetchColumn() > 0;
}

function accounting_repair_constraint_exists(string $tableName, string $constraintName): bool
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema = :db_name AND table_name = :table_name AND constraint_name = :constraint_name');
    $stmt->execute(['db_name' => DB_NAME, 'table_name' => $tableName, 'constraint_name' => $constraintName]);

    return (int) $stmt->fetchColumn() > 0;
}

function accounting_repair_add_column(string $tableName, string $columnName, string $definition): void
{
    if (accounting_repair_table_exists($tableName) && !accounting_repair_column_exists($tableName, $columnName)) {
        db()->exec('ALTER TABLE `' . $tableName . '` ADD COLUMN ' . $definition);
    }
}

function accounting_repair_add_index(string $tableName, string $indexName, string $definition): void
{
    if (accounting_repair_table_exists($tableName) && !accounting_repair_index_exists($tableName, $indexName)) {
        db()->exec('ALTER TABLE `' . $tableName . '` ADD ' . $definition);
    }
}

function accounting_repair_add_constraint(string $tableName, string $constraintName, string $definition): void
{
    if (accounting_repair_table_exists($tableName) && !accounting_repair_constraint_exists($tableName, $constraintName)) {
        db()->exec('ALTER TABLE `' . $tableName . '` ADD CONSTRAINT ' . $definition);
    }
}

function accounting_module_required_tables(): array
{
    return [
        'accounting_parties',
        'inventory_items',
        'inventory_transactions',
        'manufacturing_orders',
        'manufacturing_order_inputs',
    ];
}

function accounting_module_missing_tables(): array
{
    return array_values(array_filter(
        accounting_module_required_tables(),
        static fn (string $tableName): bool => !accounting_repair_table_exists($tableName)
    ));
}

function accounting_module_repair_database(): array
{
    $errors = [];
    $run = static function (string $label, callable $callback) use (&$errors): void {
        try {
            $callback();
        } catch (Throwable $exception) {
            $errors[] = $label . ': ' . $exception->getMessage();
        }
    };

    $run('Upgrade voucher metadata', static function (): void {
        accounting_repair_add_column('vouchers', 'voucher_date', '`voucher_date` DATE DEFAULT NULL AFTER `voucher_no`');
        accounting_repair_add_column('vouchers', 'party_id', '`party_id` INT UNSIGNED DEFAULT NULL AFTER `source_id`');
        accounting_repair_add_column('vouchers', 'reference_no', '`reference_no` VARCHAR(120) DEFAULT NULL AFTER `party_id`');
        accounting_repair_add_index('vouchers', 'idx_vouchers_date', 'KEY `idx_vouchers_date` (`company_id`, `fiscal_year_id`, `voucher_date`)');
        accounting_repair_add_index('vouchers', 'idx_vouchers_party', 'KEY `idx_vouchers_party` (`party_id`)');
    });

    $run('Provision access-control schema (migration 033)', static function (): void {
        // company_memberships (+ backfill), security_events, users.sessions_valid_from.
        if (function_exists('access_control_ensure_schema')) {
            access_control_ensure_schema();
        }
        // Widen the user status ENUM to the full lifecycle (idempotent).
        try {
            db()->exec("ALTER TABLE users MODIFY COLUMN status ENUM('active','inactive','invited','suspended','locked') NOT NULL DEFAULT 'active'");
        } catch (Throwable $e) {
            // ignore if already widened or table absent
        }
    });

    $run('Upgrade client accounting books metadata', static function (): void {
        accounting_repair_add_column('companies', 'is_client_company', '`is_client_company` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_active`');
        accounting_repair_add_column('companies', 'logo_path', '`logo_path` VARCHAR(255) DEFAULT NULL AFTER `is_client_company`');
        accounting_repair_add_column('client_profiles', 'books_company_id', '`books_company_id` INT UNSIGNED DEFAULT NULL AFTER `company_id`');
        accounting_repair_add_column('vouchers', 'requires_client_approval', '`requires_client_approval` TINYINT(1) NOT NULL DEFAULT 0 AFTER `approval_state`');
        accounting_repair_add_column('vouchers', 'client_approved_by', '`client_approved_by` INT UNSIGNED DEFAULT NULL AFTER `requires_client_approval`');
        accounting_repair_add_column('vouchers', 'client_approved_at', '`client_approved_at` DATETIME DEFAULT NULL AFTER `client_approved_by`');
    });

    $run('Upgrade party ledgers (receivable/payable sides)', static function (): void {
        // Suppliers get their own ledger under Trade Payables; the
        // receivable side reuses accounting_parties.ledger_id.
        accounting_repair_add_column('accounting_parties', 'payable_ledger_id', '`payable_ledger_id` INT UNSIGNED DEFAULT NULL AFTER `ledger_id`');
        accounting_repair_add_index('accounting_parties', 'idx_accounting_parties_payable_ledger', 'KEY `idx_accounting_parties_payable_ledger` (`payable_ledger_id`)');
    });

    $run('Create budgets and report notes', static function (): void {
        db()->exec("
            CREATE TABLE IF NOT EXISTS `budgets` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `company_id` INT UNSIGNED NOT NULL,
              `fiscal_year_id` INT UNSIGNED NOT NULL,
              `ledger_id` INT UNSIGNED NOT NULL,
              `amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
              `created_by` INT UNSIGNED DEFAULT NULL,
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uniq_budgets_scope` (`company_id`, `fiscal_year_id`, `ledger_id`),
              KEY `idx_budgets_ledger` (`ledger_id`),
              CONSTRAINT `fk_budgets_company` FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_budgets_fy` FOREIGN KEY (`fiscal_year_id`) REFERENCES `fiscal_years`(`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_budgets_ledger` FOREIGN KEY (`ledger_id`) REFERENCES `ledgers`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        db()->exec("
            CREATE TABLE IF NOT EXISTS `report_notes` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `company_id` INT UNSIGNED NOT NULL,
              `fiscal_year_id` INT UNSIGNED NOT NULL,
              `report_key` VARCHAR(60) NOT NULL,
              `note_no` VARCHAR(10) NOT NULL,
              `body` TEXT NOT NULL,
              `updated_by` INT UNSIGNED DEFAULT NULL,
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uniq_report_notes_scope` (`company_id`, `fiscal_year_id`, `report_key`, `note_no`),
              CONSTRAINT `fk_report_notes_company` FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    });

    $run('Create payroll tables', static function (): void {
        // Ten payroll tables; the migration is the single source of truth and
        // every statement is CREATE TABLE IF NOT EXISTS, so re-running is safe.
        $migrationFile = dirname(__DIR__) . '/database/migrations/031_payroll.sql';
        if (!is_file($migrationFile)) {
            throw new RuntimeException('031_payroll.sql not found beside the app.');
        }
        // Drop `--` comment lines BEFORE splitting on ';' — comments may
        // themselves contain semicolons, which would shear a statement apart.
        $sqlLines = array_filter(
            preg_split('/\R/', (string) file_get_contents($migrationFile)) ?: [],
            static fn (string $line): bool => !str_starts_with(ltrim($line), '--')
        );
        foreach (array_filter(array_map('trim', explode(';', implode("\n", $sqlLines)))) as $statement) {
            if (stripos($statement, 'CREATE TABLE') === 0) {
                db()->exec($statement);
            }
        }
    });

    $run('Provision manufacturing costing (migration 038)', static function (): void {
        $migrationFile = dirname(__DIR__) . '/database/migrations/038_manufacturing_costing.sql';
        if (is_file($migrationFile)) {
            $sqlLines = array_filter(
                preg_split('/\R/', (string) file_get_contents($migrationFile)) ?: [],
                static fn (string $line): bool => !str_starts_with(ltrim($line), '--')
            );
            foreach (array_filter(array_map('trim', explode(';', implode("\n", $sqlLines)))) as $statement) {
                if (stripos($statement, 'CREATE TABLE') === 0) {
                    db()->exec($statement);
                }
            }
        }
        accounting_repair_add_column('manufacturing_orders', 'bom_id', '`bom_id` INT UNSIGNED DEFAULT NULL AFTER `finished_item_id`');
        accounting_repair_add_column('manufacturing_orders', 'labour_cost', '`labour_cost` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `quantity`');
        accounting_repair_add_column('manufacturing_orders', 'overhead_absorbed', '`overhead_absorbed` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `labour_cost`');
        accounting_repair_add_column('manufacturing_orders', 'byproduct_value', '`byproduct_value` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `overhead_absorbed`');
        accounting_repair_add_column('manufacturing_orders', 'abnormal_waste_cost', '`abnormal_waste_cost` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `byproduct_value`');
    });

    $run('Create fixed-asset register (migration 037)', static function (): void {
        // Seven asset tables; every statement is CREATE TABLE IF NOT EXISTS so
        // re-running is safe. Replayed from the migration (single source).
        $migrationFile = dirname(__DIR__) . '/database/migrations/037_fixed_assets.sql';
        if (!is_file($migrationFile)) {
            return;
        }
        $sqlLines = array_filter(
            preg_split('/\R/', (string) file_get_contents($migrationFile)) ?: [],
            static fn (string $line): bool => !str_starts_with(ltrim($line), '--')
        );
        foreach (array_filter(array_map('trim', explode(';', implode("\n", $sqlLines)))) as $statement) {
            if (stripos($statement, 'CREATE TABLE') === 0) {
                db()->exec($statement);
            }
        }
    });

    $run('Re-home payroll ledgers', static function (): void {
        // Auto-created payroll ledgers once landed in wrong groups (expense
        // under Prepaid Expenses, advances under Bank), which unbalanced the
        // balance sheet and corrupted the cash flow. payroll_fix_ledger_groups
        // is idempotent and only moves misclassified ledgers.
        require_once __DIR__ . '/payroll_engine.php';
        $companies = db()->query("SELECT DISTINCT company_id FROM ledgers
                WHERE code IN ('SSF-EXP', 'SAL-EXP', 'TDS-PAY', 'RET-PAY', 'SAL-PAY', 'EMP-ADV')")
            ->fetchAll(PDO::FETCH_COLUMN);
        foreach ($companies as $repairCompanyId) {
            payroll_fix_ledger_groups((int) $repairCompanyId);
        }
    });

    $run('Upgrade voucher form metadata', static function (): void {
        accounting_repair_add_column('vouchers', 'priority', "`priority` ENUM('low', 'medium', 'high') NOT NULL DEFAULT 'medium' AFTER `narration`");
        accounting_repair_add_column('vouchers', 'department', '`department` VARCHAR(80) DEFAULT NULL AFTER `priority`');
        accounting_repair_add_column('vouchers', 'location', '`location` VARCHAR(80) DEFAULT NULL AFTER `department`');
        accounting_repair_add_column('vouchers', 'cost_centre', '`cost_centre` VARCHAR(80) DEFAULT NULL AFTER `location`');
        accounting_repair_add_column('vouchers', 'posting_date', '`posting_date` DATE DEFAULT NULL AFTER `voucher_date`');
        accounting_repair_add_column('vouchers', 'due_date', '`due_date` DATE DEFAULT NULL AFTER `posting_date`');
        accounting_repair_add_column('vouchers', 'payment_terms', '`payment_terms` VARCHAR(40) DEFAULT NULL AFTER `due_date`');
        accounting_repair_add_column('vouchers', 'exchange_rate', '`exchange_rate` DECIMAL(12,4) NOT NULL DEFAULT 1.0000 AFTER `payment_terms`');
        accounting_repair_add_column('vouchers', 'tax_category', '`tax_category` VARCHAR(40) DEFAULT NULL AFTER `exchange_rate`');
        accounting_repair_add_column('voucher_entries', 'cost_centre', '`cost_centre` VARCHAR(80) DEFAULT NULL AFTER `memo`');
        accounting_repair_add_column('voucher_entries', 'tax_code', '`tax_code` VARCHAR(40) DEFAULT NULL AFTER `cost_centre`');
        accounting_repair_add_column('voucher_entries', 'line_reference', '`line_reference` VARCHAR(120) DEFAULT NULL AFTER `tax_code`');
        db()->exec("
            CREATE TABLE IF NOT EXISTS `voucher_attachments` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `voucher_id` INT UNSIGNED NOT NULL,
              `file_path` VARCHAR(255) NOT NULL,
              `original_name` VARCHAR(255) NOT NULL,
              `file_size` INT UNSIGNED NOT NULL DEFAULT 0,
              `uploaded_by` INT UNSIGNED DEFAULT NULL,
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_voucher_attachments_voucher` (`voucher_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    });

    $run('Upgrade banking and reconciliation metadata', static function (): void {
        accounting_repair_add_column('ledgers', 'bank_name', '`bank_name` VARCHAR(120) DEFAULT NULL AFTER `name`');
        accounting_repair_add_column('ledgers', 'bank_account_no', '`bank_account_no` VARCHAR(40) DEFAULT NULL AFTER `bank_name`');
        accounting_repair_add_column('voucher_entries', 'reconciled_at', '`reconciled_at` DATETIME DEFAULT NULL AFTER `memo`');
        accounting_repair_add_column('voucher_entries', 'reconciled_by', '`reconciled_by` INT UNSIGNED DEFAULT NULL AFTER `reconciled_at`');
        accounting_repair_add_column('voucher_entries', 'statement_date', '`statement_date` DATE DEFAULT NULL AFTER `reconciled_by`');
        accounting_repair_add_index('voucher_entries', 'idx_voucher_entries_reconciled', 'KEY `idx_voucher_entries_reconciled` (`ledger_id`, `reconciled_at`)');
    });

    $run('Create accounting parties', static function (): void {
        db()->exec("
            CREATE TABLE IF NOT EXISTS `accounting_parties` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `company_id` INT UNSIGNED NOT NULL,
              `ledger_id` INT UNSIGNED DEFAULT NULL,
              `code` VARCHAR(60) NOT NULL,
              `name` VARCHAR(190) NOT NULL,
              `party_type` ENUM('customer', 'supplier', 'both', 'other') NOT NULL DEFAULT 'both',
              `pan_no` VARCHAR(60) DEFAULT NULL,
              `email` VARCHAR(190) DEFAULT NULL,
              `phone` VARCHAR(80) DEFAULT NULL,
              `billing_address` TEXT DEFAULT NULL,
              `opening_balance` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
              `opening_balance_type` ENUM('debit', 'credit') NOT NULL DEFAULT 'debit',
              `credit_limit` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
              `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
              `notes` TEXT DEFAULT NULL,
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uniq_accounting_parties_company_code` (`company_id`, `code`),
              KEY `idx_accounting_parties_company_type` (`company_id`, `party_type`),
              KEY `idx_accounting_parties_ledger` (`ledger_id`),
              CONSTRAINT `fk_accounting_parties_company` FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_accounting_parties_ledger` FOREIGN KEY (`ledger_id`) REFERENCES `ledgers`(`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    });

    $run('Create inventory items', static function (): void {
        db()->exec("
            CREATE TABLE IF NOT EXISTS `inventory_items` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `company_id` INT UNSIGNED NOT NULL,
              `ledger_id` INT UNSIGNED DEFAULT NULL,
              `sku` VARCHAR(80) NOT NULL,
              `name` VARCHAR(190) NOT NULL,
              `item_type` ENUM('stock', 'service', 'raw_material', 'finished_good', 'consumable') NOT NULL DEFAULT 'stock',
              `unit` VARCHAR(40) NOT NULL DEFAULT 'pcs',
              `hs_code` VARCHAR(80) DEFAULT NULL,
              `tax_rate` DECIMAL(5,2) NOT NULL DEFAULT 13.00,
              `sales_rate` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
              `purchase_rate` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
              `opening_qty` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
              `reorder_level` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
              `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
              `notes` TEXT DEFAULT NULL,
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uniq_inventory_items_company_sku` (`company_id`, `sku`),
              KEY `idx_inventory_items_company_type` (`company_id`, `item_type`),
              KEY `idx_inventory_items_ledger` (`ledger_id`),
              CONSTRAINT `fk_inventory_items_company` FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_inventory_items_ledger` FOREIGN KEY (`ledger_id`) REFERENCES `ledgers`(`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    });

    $run('Provision IAS 2 valuation schema (migration 036)', static function (): void {
        // Item master: valuation method, classification, stock-control fields.
        try {
            db()->exec("ALTER TABLE inventory_items MODIFY COLUMN item_type ENUM('stock','service','raw_material','work_in_progress','finished_good','trading_good','consumable','packing_material','spare_part','by_product','scrap') NOT NULL DEFAULT 'stock'");
        } catch (Throwable $e) { /* already widened */ }
        accounting_repair_add_column('inventory_items', 'valuation_method', "`valuation_method` ENUM('fifo','weighted_average','specific') NOT NULL DEFAULT 'weighted_average' AFTER `item_type`");
        accounting_repair_add_column('inventory_items', 'category', '`category` VARCHAR(120) DEFAULT NULL AFTER `name`');
        accounting_repair_add_column('inventory_items', 'sub_category', '`sub_category` VARCHAR(120) DEFAULT NULL AFTER `category`');
        accounting_repair_add_column('inventory_items', 'short_name', '`short_name` VARCHAR(120) DEFAULT NULL AFTER `name`');
        accounting_repair_add_column('inventory_items', 'barcode', '`barcode` VARCHAR(120) DEFAULT NULL AFTER `hs_code`');
        accounting_repair_add_column('inventory_items', 'country_of_origin', '`country_of_origin` VARCHAR(80) DEFAULT NULL AFTER `barcode`');
        accounting_repair_add_column('inventory_items', 'min_stock', '`min_stock` DECIMAL(14,3) NOT NULL DEFAULT 0.000 AFTER `reorder_level`');
        accounting_repair_add_column('inventory_items', 'max_stock', '`max_stock` DECIMAL(14,3) NOT NULL DEFAULT 0.000 AFTER `min_stock`');
        accounting_repair_add_column('inventory_items', 'safety_stock', '`safety_stock` DECIMAL(14,3) NOT NULL DEFAULT 0.000 AFTER `max_stock`');
        accounting_repair_add_column('inventory_items', 'allow_negative_stock', '`allow_negative_stock` TINYINT(1) NOT NULL DEFAULT 0 AFTER `safety_stock`');

        db()->exec("CREATE TABLE IF NOT EXISTS `inventory_cost_layers` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `company_id` INT UNSIGNED NOT NULL,
            `item_id` INT UNSIGNED NOT NULL,
            `warehouse_id` INT UNSIGNED DEFAULT NULL,
            `batch_no` VARCHAR(80) DEFAULT NULL,
            `identity` VARCHAR(120) DEFAULT NULL,
            `is_specific` TINYINT(1) NOT NULL DEFAULT 0,
            `layer_date` DATE NOT NULL,
            `layer_seq` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `source_txn_id` INT UNSIGNED DEFAULT NULL,
            `unit_cost` DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            `qty_in` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
            `qty_remaining` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_inv_layers_item_open` (`company_id`, `item_id`, `qty_remaining`),
            KEY `idx_inv_layers_seq` (`company_id`, `item_id`, `layer_seq`),
            KEY `idx_inv_layers_identity` (`company_id`, `item_id`, `identity`),
            CONSTRAINT `fk_inv_layers_company` FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_inv_layers_item` FOREIGN KEY (`item_id`) REFERENCES `inventory_items`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        db()->exec("CREATE TABLE IF NOT EXISTS `inventory_nrv_assessments` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `company_id` INT UNSIGNED NOT NULL,
            `fiscal_year_id` INT UNSIGNED DEFAULT NULL,
            `item_id` INT UNSIGNED NOT NULL,
            `assessment_date` DATE NOT NULL,
            `quantity` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
            `cost_per_unit` DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            `selling_price` DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            `completion_cost` DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            `selling_cost` DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            `nrv_per_unit` DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            `lower_per_unit` DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            `carrying_cost` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            `prior_write_down` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            `write_down` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            `reversal` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            `final_carrying` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            `evidence` VARCHAR(255) DEFAULT NULL,
            `voucher_id` INT UNSIGNED DEFAULT NULL,
            `approved_by` INT UNSIGNED DEFAULT NULL,
            `created_by` INT UNSIGNED DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_inv_nrv_item_date` (`company_id`, `item_id`, `assessment_date`),
            KEY `idx_inv_nrv_voucher` (`voucher_id`),
            CONSTRAINT `fk_inv_nrv_company` FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_inv_nrv_item` FOREIGN KEY (`item_id`) REFERENCES `inventory_items`(`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_inv_nrv_voucher` FOREIGN KEY (`voucher_id`) REFERENCES `vouchers`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        db()->exec("CREATE TABLE IF NOT EXISTS `inventory_ledger_mappings` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `company_id` INT UNSIGNED NOT NULL,
            `scope` ENUM('global','category','item') NOT NULL DEFAULT 'global',
            `category` VARCHAR(120) DEFAULT NULL,
            `item_id` INT UNSIGNED DEFAULT NULL,
            `purpose` VARCHAR(60) NOT NULL,
            `ledger_id` INT UNSIGNED NOT NULL,
            `effective_date` DATE DEFAULT NULL,
            `created_by` INT UNSIGNED DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_inv_mapping_scope` (`company_id`, `scope`, `category`, `item_id`, `purpose`),
            KEY `idx_inv_mapping_lookup` (`company_id`, `purpose`, `scope`),
            KEY `idx_inv_mapping_ledger` (`ledger_id`),
            CONSTRAINT `fk_inv_mapping_company` FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_inv_mapping_item` FOREIGN KEY (`item_id`) REFERENCES `inventory_items`(`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_inv_mapping_ledger` FOREIGN KEY (`ledger_id`) REFERENCES `ledgers`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    });

    $run('Create inventory transactions', static function (): void {
        db()->exec("
            CREATE TABLE IF NOT EXISTS `inventory_transactions` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `company_id` INT UNSIGNED NOT NULL,
              `fiscal_year_id` INT UNSIGNED DEFAULT NULL,
              `item_id` INT UNSIGNED NOT NULL,
              `voucher_id` INT UNSIGNED DEFAULT NULL,
              `transaction_type` ENUM('opening', 'purchase', 'sale', 'sales_return', 'purchase_return', 'adjustment', 'consume', 'produce') NOT NULL DEFAULT 'adjustment',
              `ref_no` VARCHAR(120) DEFAULT NULL,
              `transaction_date` DATE NOT NULL,
              `qty_in` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
              `qty_out` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
              `rate` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
              `amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
              `notes` TEXT DEFAULT NULL,
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_inventory_transactions_company_date` (`company_id`, `transaction_date`),
              KEY `idx_inventory_transactions_item` (`item_id`),
              KEY `idx_inventory_transactions_voucher` (`voucher_id`),
              CONSTRAINT `fk_inventory_transactions_company` FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_inventory_transactions_fiscal_year` FOREIGN KEY (`fiscal_year_id`) REFERENCES `fiscal_years`(`id`) ON DELETE SET NULL,
              CONSTRAINT `fk_inventory_transactions_item` FOREIGN KEY (`item_id`) REFERENCES `inventory_items`(`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_inventory_transactions_voucher` FOREIGN KEY (`voucher_id`) REFERENCES `vouchers`(`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    });

    $run('Create manufacturing orders', static function (): void {
        db()->exec("
            CREATE TABLE IF NOT EXISTS `manufacturing_orders` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `company_id` INT UNSIGNED NOT NULL,
              `fiscal_year_id` INT UNSIGNED DEFAULT NULL,
              `order_no` VARCHAR(80) NOT NULL,
              `finished_item_id` INT UNSIGNED NOT NULL,
              `quantity` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
              `status` ENUM('draft', 'in_progress', 'completed', 'cancelled') NOT NULL DEFAULT 'draft',
              `started_on` DATE DEFAULT NULL,
              `completed_on` DATE DEFAULT NULL,
              `notes` TEXT DEFAULT NULL,
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uniq_manufacturing_orders_company_no` (`company_id`, `order_no`),
              KEY `idx_manufacturing_orders_company_status` (`company_id`, `status`),
              KEY `idx_manufacturing_orders_finished_item` (`finished_item_id`),
              CONSTRAINT `fk_manufacturing_orders_company` FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_manufacturing_orders_fiscal_year` FOREIGN KEY (`fiscal_year_id`) REFERENCES `fiscal_years`(`id`) ON DELETE SET NULL,
              CONSTRAINT `fk_manufacturing_orders_finished_item` FOREIGN KEY (`finished_item_id`) REFERENCES `inventory_items`(`id`) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    });

    $run('Create manufacturing inputs', static function (): void {
        db()->exec("
            CREATE TABLE IF NOT EXISTS `manufacturing_order_inputs` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `manufacturing_order_id` INT UNSIGNED NOT NULL,
              `item_id` INT UNSIGNED NOT NULL,
              `quantity` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
              `rate` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_manufacturing_order_inputs_order` (`manufacturing_order_id`),
              KEY `idx_manufacturing_order_inputs_item` (`item_id`),
              CONSTRAINT `fk_manufacturing_order_inputs_order` FOREIGN KEY (`manufacturing_order_id`) REFERENCES `manufacturing_orders`(`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_manufacturing_order_inputs_item` FOREIGN KEY (`item_id`) REFERENCES `inventory_items`(`id`) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    });

    $run('Upgrade accounting preferences and excise support', static function (): void {
        if (accounting_repair_table_exists('company_accounting_preferences')) {
            accounting_repair_add_column('company_accounting_preferences', 'default_excise_rate', '`default_excise_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER `business_type`');
        }

        if (!accounting_repair_table_exists('task_invoices')) {
            return;
        }

        accounting_repair_add_column('task_invoices', 'excise_rate', '`excise_rate` DECIMAL(5,2) DEFAULT 0.00 AFTER `vat_rate`');
        accounting_repair_add_column('task_invoices', 'excise_amount', '`excise_amount` DECIMAL(12,2) DEFAULT 0.00 AFTER `excise_rate`');
    });

    $run('Upgrade invoice source and lines', static function (): void {
        if (!accounting_repair_table_exists('task_invoices')) {
            return;
        }

        if (accounting_repair_column_exists('task_invoices', 'task_id')) {
            db()->exec('ALTER TABLE `task_invoices` MODIFY COLUMN `task_id` INT UNSIGNED NULL');
        }
        if (accounting_repair_column_exists('task_invoices', 'invoice_type')) {
            db()->exec("ALTER TABLE `task_invoices` MODIFY COLUMN `invoice_type` ENUM('stage', 'task', 'inventory', 'manufacturing', 'other') NOT NULL DEFAULT 'task'");
        }
        accounting_repair_add_column('task_invoices', 'invoice_source_type', "`invoice_source_type` ENUM('task', 'inventory', 'manufacturing', 'other') NOT NULL DEFAULT 'task' AFTER `invoice_type`");
        accounting_repair_add_column('task_invoices', 'source_id', '`source_id` INT UNSIGNED DEFAULT NULL AFTER `invoice_source_type`');
        accounting_repair_add_column('task_invoices', 'party_id', '`party_id` INT UNSIGNED DEFAULT NULL AFTER `source_id`');
        accounting_repair_add_column('task_invoices', 'description', '`description` VARCHAR(255) DEFAULT NULL AFTER `party_id`');
        accounting_repair_add_index('task_invoices', 'idx_task_invoices_source', 'KEY `idx_task_invoices_source` (`company_id`, `invoice_source_type`, `source_id`)');
        accounting_repair_add_index('task_invoices', 'idx_task_invoices_party', 'KEY `idx_task_invoices_party` (`party_id`)');
        accounting_repair_add_constraint('task_invoices', 'fk_task_invoices_accounting_party', '`fk_task_invoices_accounting_party` FOREIGN KEY (`party_id`) REFERENCES `accounting_parties`(`id`) ON DELETE SET NULL');

        db()->exec("
            CREATE TABLE IF NOT EXISTS `invoice_line_items` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `invoice_id` INT UNSIGNED NOT NULL,
              `item_id` INT UNSIGNED DEFAULT NULL,
              `source_type` ENUM('task', 'inventory', 'manufacturing', 'other') NOT NULL DEFAULT 'other',
              `source_id` INT UNSIGNED DEFAULT NULL,
              `description` VARCHAR(255) NOT NULL,
              `hs_code` VARCHAR(80) DEFAULT NULL,
              `unit` VARCHAR(40) NOT NULL DEFAULT 'pcs',
              `quantity` DECIMAL(14,3) NOT NULL DEFAULT 1.000,
              `rate` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
              `taxable_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
              `vat_rate` DECIMAL(5,2) NOT NULL DEFAULT 13.00,
              `vat_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
              `total_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_invoice_line_items_invoice` (`invoice_id`),
              KEY `idx_invoice_line_items_item` (`item_id`),
              CONSTRAINT `fk_invoice_line_items_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `task_invoices`(`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_invoice_line_items_item` FOREIGN KEY (`item_id`) REFERENCES `inventory_items`(`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    });

    $run('Add inventory warehouse dimension (migration 039)', static function (): void {
        db()->exec("
            CREATE TABLE IF NOT EXISTS `warehouses` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `company_id` INT UNSIGNED NOT NULL,
              `name` VARCHAR(120) NOT NULL,
              `code` VARCHAR(40) DEFAULT NULL,
              `is_active` TINYINT(1) NOT NULL DEFAULT 1,
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uniq_warehouse_company_name` (`company_id`, `name`),
              KEY `idx_warehouse_company_active` (`company_id`, `is_active`),
              CONSTRAINT `fk_warehouse_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        accounting_repair_add_column('inventory_items', 'default_warehouse_id', '`default_warehouse_id` INT UNSIGNED DEFAULT NULL AFTER `allow_negative_stock`');
        accounting_repair_add_column('inventory_transactions', 'warehouse_id', '`warehouse_id` INT UNSIGNED DEFAULT NULL AFTER `item_id`');
        accounting_repair_add_column('inventory_transactions', 'to_warehouse_id', '`to_warehouse_id` INT UNSIGNED DEFAULT NULL AFTER `warehouse_id`');
    });

    return $errors;
}
