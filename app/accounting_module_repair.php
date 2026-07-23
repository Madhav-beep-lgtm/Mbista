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

    $run('Upgrade fiscal year lifecycle', static function (): void {
        // Migration 051: status lifecycle + authorship. Cutoff stays in
        // fiscal_period_locks; overlap protection lives in create_fiscal_year().
        accounting_repair_add_column('fiscal_years', 'status', "`status` ENUM('upcoming','open','closed','locked') NOT NULL DEFAULT 'open' AFTER `is_default`");
        accounting_repair_add_column('fiscal_years', 'created_by', '`created_by` INT UNSIGNED DEFAULT NULL AFTER `status`');
        accounting_repair_add_column('fiscal_years', 'updated_by', '`updated_by` INT UNSIGNED DEFAULT NULL AFTER `created_by`');
    });

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
        // Migration 046: optional link party -> client portal profile, so
        // party-based invoices (inventory/manufacturing/other) reach the
        // client's My Invoices.
        accounting_repair_add_column('accounting_parties', 'client_profile_id', '`client_profile_id` INT UNSIGNED DEFAULT NULL AFTER `payable_ledger_id`');
        accounting_repair_add_index('accounting_parties', 'idx_accounting_parties_client_profile', 'KEY `idx_accounting_parties_client_profile` (`client_profile_id`)');
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
        // Per-period salary-sheet adjustments (extra taxable earning + post-tax
        // deduction + remark) editable on a draft/calculated run.
        accounting_repair_add_column('payroll_run_lines', 'adj_earning', '`adj_earning` DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER `other_deduction`');
        accounting_repair_add_column('payroll_run_lines', 'adj_deduction', '`adj_deduction` DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER `adj_earning`');
        accounting_repair_add_column('payroll_run_lines', 'adj_remark', '`adj_remark` VARCHAR(255) DEFAULT NULL AFTER `adj_deduction`');
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

    $run('Create opening-balance tables (migration 053)', static function (): void {
        // Formal opening-balance batch/line/audit tables layered over the
        // perpetual GL. Every statement is CREATE TABLE IF NOT EXISTS so
        // re-running is safe. Replayed from the migration (single source).
        $migrationFile = dirname(__DIR__) . '/database/migrations/053_opening_balances.sql';
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
            // 'termination' must survive this repair; see migration 048.
            db()->exec("ALTER TABLE `task_invoices` MODIFY COLUMN `invoice_type` ENUM('stage', 'task', 'inventory', 'manufacturing', 'other', 'termination') NOT NULL DEFAULT 'task'");
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

    $run('Add lease lessor party (migration 047)', static function (): void {
        accounting_repair_add_column('lease_liabilities', 'lessor_party_id', '`lessor_party_id` INT UNSIGNED DEFAULT NULL AFTER `asset_id`');
        accounting_repair_add_index('lease_liabilities', 'idx_lease_liabilities_lessor', 'KEY `idx_lease_liabilities_lessor` (`lessor_party_id`)');
    });

    $run('Dedupe ledger mapping rows', static function (): void {
        // The unique keys on the mapping tables treat NULL scope columns as
        // distinct, so the old save (INSERT .. ON DUPLICATE KEY) piled up a
        // full duplicate set on every save. Resolution reads LIMIT 1 so it
        // still worked, but the stale older rows shadowed newer choices.
        // Keep only the NEWEST row per logical key.
        foreach (['asset_ledger_mappings' => 'category_id', 'inventory_ledger_mappings' => 'category'] as $table => $categoryColumn) {
            if (!accounting_repair_table_exists($table)) {
                continue;
            }
            db()->exec("DELETE m1 FROM `$table` m1 JOIN `$table` m2
                ON m2.company_id = m1.company_id AND m2.scope = m1.scope
                AND COALESCE(m2.`$categoryColumn`, '') = COALESCE(m1.`$categoryColumn`, '')
                AND COALESCE(m2." . ($table === 'asset_ledger_mappings' ? 'asset_id' : 'item_id') . ", 0) = COALESCE(m1." . ($table === 'asset_ledger_mappings' ? 'asset_id' : 'item_id') . ", 0)
                AND m2.purpose = m1.purpose AND m2.id > m1.id");
        }
    });

    $run('Add NRV allowance lifecycle (migration 041)', static function (): void {
        accounting_repair_add_column('inventory_nrv_assessments', 'release_amount', '`release_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `reversal`');
        accounting_repair_add_column('inventory_nrv_assessments', 'source_txn_id', '`source_txn_id` INT UNSIGNED DEFAULT NULL AFTER `voucher_id`');
        accounting_repair_add_column('inventory_nrv_assessments', 'status', "`status` ENUM('active', 'reversed') NOT NULL DEFAULT 'active' AFTER `source_txn_id`");
        accounting_repair_add_index('inventory_nrv_assessments', 'idx_inv_nrv_source_txn', 'KEY `idx_inv_nrv_source_txn` (`source_txn_id`)');
        accounting_repair_add_index('inventory_nrv_assessments', 'idx_inv_nrv_item_status', 'KEY `idx_inv_nrv_item_status` (`company_id`, `item_id`, `status`)');
    });

    $run('Widen inventory transaction types (migration 040)', static function (): void {
        if (!accounting_repair_table_exists('inventory_transactions')) {
            return;
        }
        db()->exec("ALTER TABLE `inventory_transactions` MODIFY COLUMN `transaction_type` ENUM(
            'opening', 'purchase', 'sale', 'sales_return', 'purchase_return', 'adjustment',
            'consume', 'produce', 'write_off', 'damage', 'expiry',
            'warehouse_transfer', 'departmental_transfer', 'nrv_write_down', 'nrv_reversal'
        ) NOT NULL DEFAULT 'adjustment'");
    });

    $run('Add online payment gateways (migration 054)', static function (): void {
        db()->exec("CREATE TABLE IF NOT EXISTS `payment_gateways` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `company_id` INT UNSIGNED NOT NULL,
            `provider` ENUM('esewa','khalti','fonepay','stripe') NOT NULL,
            `mode` ENUM('test','live') NOT NULL DEFAULT 'test',
            `enabled` TINYINT(1) NOT NULL DEFAULT 0,
            `merchant_code` VARCHAR(190) DEFAULT NULL,
            `secret_key` VARCHAR(255) DEFAULT NULL,
            `public_key` VARCHAR(255) DEFAULT NULL,
            `extra_config` TEXT DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_payment_gateway` (`company_id`, `provider`),
            CONSTRAINT `fk_payment_gateways_company` FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        db()->exec("CREATE TABLE IF NOT EXISTS `payment_intents` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `company_id` INT UNSIGNED NOT NULL,
            `invoice_id` INT UNSIGNED NOT NULL,
            `provider` VARCHAR(30) NOT NULL,
            `mode` ENUM('test','live') NOT NULL DEFAULT 'test',
            `amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            `currency` VARCHAR(10) NOT NULL DEFAULT 'NPR',
            `token` VARCHAR(80) NOT NULL,
            `provider_ref` VARCHAR(190) DEFAULT NULL,
            `status` ENUM('pending','paid','failed','cancelled') NOT NULL DEFAULT 'pending',
            `client_user_id` INT UNSIGNED DEFAULT NULL,
            `payment_request_id` INT UNSIGNED DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `paid_at` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_payment_intent_token` (`token`),
            KEY `idx_payment_intents_invoice` (`invoice_id`),
            KEY `idx_payment_intents_company` (`company_id`, `status`),
            CONSTRAINT `fk_payment_intents_company` FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_payment_intents_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `task_invoices`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    });

    $run('Payroll unpaid-leave deduction (migration 055)', static function (): void {
        if (accounting_repair_table_exists('payroll_run_lines')) {
            accounting_repair_add_column('payroll_run_lines', 'unpaid_leave_days', "`unpaid_leave_days` DECIMAL(6,2) NOT NULL DEFAULT 0.00 AFTER `other_deduction`");
            accounting_repair_add_column('payroll_run_lines', 'unpaid_leave_deduction', "`unpaid_leave_deduction` DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER `unpaid_leave_days`");
        }
        if (accounting_repair_table_exists('payroll_settings')) {
            accounting_repair_add_column('payroll_settings', 'standard_working_days', "`standard_working_days` DECIMAL(5,2) NOT NULL DEFAULT 30.00");
            accounting_repair_add_column('payroll_settings', 'deduct_unpaid_leave', "`deduct_unpaid_leave` TINYINT(1) NOT NULL DEFAULT 1");
        }
        if (accounting_repair_table_exists('leave_types')) {
            accounting_repair_add_column('leave_types', 'deduct_salary', "`deduct_salary` TINYINT(1) NOT NULL DEFAULT 0");
            db()->exec("UPDATE leave_types SET deduct_salary = 1 WHERE deduct_salary = 0 AND (LOWER(name) LIKE '%unpaid%' OR LOWER(name) LIKE '%without pay%' OR LOWER(name) LIKE '%lwp%')");
        }
    });

    $run('Payroll run custom voucher date (migration 056)', static function (): void {
        if (accounting_repair_table_exists('payroll_runs')) {
            accounting_repair_add_column('payroll_runs', 'voucher_date', "`voucher_date` DATE NULL DEFAULT NULL AFTER `pay_date`");
        }
    });

    $run('Inventory opening = qty + frozen amount, per-FY rows (migration 059)', static function (): void {
        if (accounting_repair_table_exists('inventory_items')) {
            $columnIsNew = !accounting_repair_column_exists('inventory_items', 'opening_amount');
            accounting_repair_add_column('inventory_items', 'opening_amount', "`opening_amount` DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER `opening_qty`");
            if ($columnIsNew) {
                // Freeze existing openings at their present valuation EXACTLY
                // once, on first creation — a later deliberate zero-value
                // opening must never be silently re-priced by the repair.
                db()->exec('UPDATE inventory_items SET opening_amount = ROUND(opening_qty * purchase_rate, 2) WHERE opening_amount = 0 AND opening_qty > 0');
            }
        }
        if (!accounting_repair_table_exists('inventory_opening_balances') && accounting_repair_table_exists('inventory_items')) {
            db()->exec("CREATE TABLE IF NOT EXISTS `inventory_opening_balances` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL,
                `fiscal_year_id` INT UNSIGNED NOT NULL,
                `item_id` INT UNSIGNED NOT NULL,
                `qty` DECIMAL(14,3) NOT NULL DEFAULT 0,
                `amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
                `source` ENUM('carried','initial','adjusted') NOT NULL DEFAULT 'carried',
                `adjust_reason` VARCHAR(255) DEFAULT NULL,
                `adjusted_by` INT UNSIGNED DEFAULT NULL,
                `adjusted_at` TIMESTAMP NULL DEFAULT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_inv_ob_fy_item` (`fiscal_year_id`, `item_id`),
                KEY `idx_inv_ob_company` (`company_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
    });

    $run('Stock summary: location-specific item types (migration 058)', static function (): void {
        if (!accounting_repair_table_exists('inventory_item_location_types') && accounting_repair_table_exists('inventory_items')) {
            db()->exec("CREATE TABLE IF NOT EXISTS `inventory_item_location_types` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL,
                `item_id` INT UNSIGNED NOT NULL,
                `warehouse_id` INT UNSIGNED NOT NULL,
                `item_type` VARCHAR(30) NOT NULL,
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_by` INT UNSIGNED DEFAULT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_item_location_type` (`item_id`, `warehouse_id`),
                KEY `idx_item_location_types_company` (`company_id`),
                KEY `idx_item_location_types_warehouse` (`warehouse_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
    });

    $run('Payroll SST split + single-staff run scope (migration 057)', static function (): void {
        if (accounting_repair_table_exists('payroll_run_lines')) {
            accounting_repair_add_column('payroll_run_lines', 'sst_month', "`sst_month` DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER `tax_month`");
        }
        if (accounting_repair_table_exists('payroll_settings')) {
            accounting_repair_add_column('payroll_settings', 'sst_payable_ledger_id', "`sst_payable_ledger_id` INT UNSIGNED DEFAULT NULL AFTER `tds_payable_ledger_id`");
        }
        if (accounting_repair_table_exists('payroll_runs')) {
            accounting_repair_add_column('payroll_runs', 'employee_scope', "`employee_scope` TEXT DEFAULT NULL AFTER `voucher_date`");
        }
    });

    $run('Payroll employees without a login (migration 060)', static function (): void {
        // An employee may exist purely for payroll — no user account. The link
        // becomes optional and identity fields live on the payroll row itself.
        if (!accounting_repair_table_exists('payroll_employees')) {
            return;
        }
        $nullableStmt = db()->prepare("SELECT IS_NULLABLE FROM information_schema.columns
            WHERE table_schema = :db_name AND table_name = 'payroll_employees' AND column_name = 'user_id'");
        $nullableStmt->execute(['db_name' => DB_NAME]);
        if ((string) $nullableStmt->fetchColumn() === 'NO') {
            db()->exec('ALTER TABLE `payroll_employees` MODIFY COLUMN `user_id` INT UNSIGNED DEFAULT NULL');
        }
        accounting_repair_add_column('payroll_employees', 'full_name', '`full_name` VARCHAR(160) DEFAULT NULL AFTER `user_id`');
        accounting_repair_add_column('payroll_employees', 'email', '`email` VARCHAR(190) DEFAULT NULL AFTER `full_name`');
        accounting_repair_add_column('payroll_employees', 'phone', '`phone` VARCHAR(60) DEFAULT NULL AFTER `email`');
    });

    $run('Flexible pay components + service charge + weekly overtime (migration 061)', static function (): void {
        if (!accounting_repair_table_exists('payroll_components')) {
            return; // payroll module not provisioned yet (031 creates it on demand)
        }

        // Widen the category / calc_type enums exactly once.
        $columnType = static function (string $table, string $column): string {
            $stmt = db()->prepare('SELECT COLUMN_TYPE FROM information_schema.columns
                WHERE table_schema = :db_name AND table_name = :table_name AND column_name = :column_name');
            $stmt->execute(['db_name' => DB_NAME, 'table_name' => $table, 'column_name' => $column]);
            return (string) $stmt->fetchColumn();
        };
        if (!str_contains($columnType('payroll_components', 'category'), 'employer_contribution')) {
            db()->exec("ALTER TABLE `payroll_components` MODIFY COLUMN `category` ENUM('allowance','overtime','benefit','deduction','employer_contribution','reimbursement','advance_recovery','tax','info') NOT NULL DEFAULT 'allowance'");
        }
        if (!str_contains($columnType('payroll_components', 'calc_type'), 'overtime_hours')) {
            db()->exec("ALTER TABLE `payroll_components` MODIFY COLUMN `calc_type` ENUM('fixed','percent_basic','manual','overtime_hours','service_charge') NOT NULL DEFAULT 'manual'");
        }

        accounting_repair_add_column('payroll_components', 'description', "`description` VARCHAR(255) DEFAULT NULL AFTER `name_np`");
        accounting_repair_add_column('payroll_components', 'posting_behaviour', "`posting_behaviour` ENUM('category_default','earning_expense','deduction_liability','employer_contribution','reimbursement','advance_recovery','non_posting','custom') NOT NULL DEFAULT 'category_default' AFTER `category`");
        accounting_repair_add_column('payroll_components', 'debit_ledger_id', '`debit_ledger_id` INT UNSIGNED DEFAULT NULL AFTER `posting_behaviour`');
        accounting_repair_add_column('payroll_components', 'credit_ledger_id', '`credit_ledger_id` INT UNSIGNED DEFAULT NULL AFTER `debit_ledger_id`');
        accounting_repair_add_column('payroll_components', 'employer_expense_ledger_id', '`employer_expense_ledger_id` INT UNSIGNED DEFAULT NULL AFTER `credit_ledger_id`');
        accounting_repair_add_column('payroll_components', 'contribution_payable_ledger_id', '`contribution_payable_ledger_id` INT UNSIGNED DEFAULT NULL AFTER `employer_expense_ledger_id`');
        accounting_repair_add_column('payroll_components', 'include_in_gross', '`include_in_gross` TINYINT(1) NOT NULL DEFAULT 1 AFTER `taxable`');
        accounting_repair_add_column('payroll_components', 'include_in_net', '`include_in_net` TINYINT(1) NOT NULL DEFAULT 1 AFTER `include_in_gross`');
        accounting_repair_add_column('payroll_components', 'retirement_basis', '`retirement_basis` TINYINT(1) NOT NULL DEFAULT 0 AFTER `include_in_net`');
        accounting_repair_add_column('payroll_components', 'overtime_basis', '`overtime_basis` TINYINT(1) NOT NULL DEFAULT 0 AFTER `retirement_basis`');
        accounting_repair_add_column('payroll_components', 'service_charge_basis', '`service_charge_basis` TINYINT(1) NOT NULL DEFAULT 0 AFTER `overtime_basis`');
        accounting_repair_add_column('payroll_components', 'percentage', '`percentage` DECIMAL(9,4) DEFAULT NULL AFTER `default_value`');
        accounting_repair_add_column('payroll_components', 'calc_basis', '`calc_basis` VARCHAR(40) DEFAULT NULL AFTER `percentage`');
        accounting_repair_add_column('payroll_components', 'allow_employee_override', '`allow_employee_override` TINYINT(1) NOT NULL DEFAULT 1 AFTER `service_charge_basis`');
        accounting_repair_add_column('payroll_components', 'allow_period_override', '`allow_period_override` TINYINT(1) NOT NULL DEFAULT 1 AFTER `allow_employee_override`');
        accounting_repair_add_column('payroll_components', 'allow_zero', '`allow_zero` TINYINT(1) NOT NULL DEFAULT 1 AFTER `allow_period_override`');
        accounting_repair_add_column('payroll_components', 'effective_from', '`effective_from` DATE DEFAULT NULL AFTER `allow_zero`');
        accounting_repair_add_column('payroll_components', 'effective_to', '`effective_to` DATE DEFAULT NULL AFTER `effective_from`');
        accounting_repair_add_column('payroll_components', 'created_by', '`created_by` INT UNSIGNED DEFAULT NULL AFTER `sort_order`');
        accounting_repair_add_column('payroll_components', 'updated_by', '`updated_by` INT UNSIGNED DEFAULT NULL AFTER `created_by`');
        accounting_repair_add_constraint('payroll_components', 'fk_payroll_components_dr', '`fk_payroll_components_dr` FOREIGN KEY (`debit_ledger_id`) REFERENCES `ledgers` (`id`) ON DELETE RESTRICT');
        accounting_repair_add_constraint('payroll_components', 'fk_payroll_components_cr', '`fk_payroll_components_cr` FOREIGN KEY (`credit_ledger_id`) REFERENCES `ledgers` (`id`) ON DELETE RESTRICT');
        accounting_repair_add_constraint('payroll_components', 'fk_payroll_components_er_exp', '`fk_payroll_components_er_exp` FOREIGN KEY (`employer_expense_ledger_id`) REFERENCES `ledgers` (`id`) ON DELETE RESTRICT');
        accounting_repair_add_constraint('payroll_components', 'fk_payroll_components_er_pay', '`fk_payroll_components_er_pay` FOREIGN KEY (`contribution_payable_ledger_id`) REFERENCES `ledgers` (`id`) ON DELETE RESTRICT');

        accounting_repair_add_column('payroll_employee_components', 'effective_from', '`effective_from` DATE DEFAULT NULL AFTER `amount`');
        accounting_repair_add_column('payroll_employee_components', 'effective_to', '`effective_to` DATE DEFAULT NULL AFTER `effective_from`');
        accounting_repair_add_column('payroll_employee_components', 'percentage', '`percentage` DECIMAL(9,4) DEFAULT NULL AFTER `effective_to`');
        accounting_repair_add_column('payroll_employee_components', 'taxable_override', '`taxable_override` TINYINT(1) DEFAULT NULL AFTER `percentage`');
        accounting_repair_add_column('payroll_employee_components', 'active', '`active` TINYINT(1) NOT NULL DEFAULT 1 AFTER `taxable_override`');
        accounting_repair_add_column('payroll_employee_components', 'remarks', '`remarks` VARCHAR(255) DEFAULT NULL AFTER `active`');
        accounting_repair_add_column('payroll_employee_components', 'created_by', '`created_by` INT UNSIGNED DEFAULT NULL AFTER `remarks`');
        accounting_repair_add_column('payroll_employee_components', 'updated_by', '`updated_by` INT UNSIGNED DEFAULT NULL AFTER `created_by`');

        if (!accounting_repair_table_exists('payroll_run_components')) {
            db()->exec("CREATE TABLE IF NOT EXISTS `payroll_run_components` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `run_id` INT UNSIGNED NOT NULL,
                `payroll_employee_id` INT UNSIGNED NOT NULL,
                `component_id` INT UNSIGNED DEFAULT NULL,
                `component_code` VARCHAR(40) NOT NULL,
                `component_name` VARCHAR(120) NOT NULL,
                `category` VARCHAR(30) NOT NULL,
                `posting_behaviour` VARCHAR(30) NOT NULL DEFAULT 'category_default',
                `taxable` TINYINT(1) NOT NULL DEFAULT 1,
                `include_in_gross` TINYINT(1) NOT NULL DEFAULT 1,
                `include_in_net` TINYINT(1) NOT NULL DEFAULT 1,
                `calc_method` VARCHAR(30) NOT NULL DEFAULT 'manual',
                `debit_ledger_id` INT UNSIGNED DEFAULT NULL,
                `credit_ledger_id` INT UNSIGNED DEFAULT NULL,
                `employer_expense_ledger_id` INT UNSIGNED DEFAULT NULL,
                `contribution_payable_ledger_id` INT UNSIGNED DEFAULT NULL,
                `suggested_amount` DECIMAL(14,2) DEFAULT NULL,
                `amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `override_reason` VARCHAR(255) DEFAULT NULL,
                `source` ENUM('standard','one_time','overtime','service_charge') NOT NULL DEFAULT 'standard',
                `created_by` INT UNSIGNED DEFAULT NULL,
                `updated_by` INT UNSIGNED DEFAULT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_run_component_line` (`run_id`, `payroll_employee_id`, `component_code`),
                KEY `idx_prc_component` (`component_id`),
                KEY `idx_prc_employee` (`payroll_employee_id`),
                CONSTRAINT `fk_prc_run` FOREIGN KEY (`run_id`) REFERENCES `payroll_runs` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_prc_employee` FOREIGN KEY (`payroll_employee_id`) REFERENCES `payroll_employees` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_prc_component` FOREIGN KEY (`component_id`) REFERENCES `payroll_components` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }

        if (!accounting_repair_table_exists('payroll_service_charge_runs')) {
            db()->exec("CREATE TABLE IF NOT EXISTS `payroll_service_charge_runs` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL,
                `run_id` INT UNSIGNED NOT NULL,
                `declared_total` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `employee_pct` DECIMAL(5,2) NOT NULL DEFAULT 68.00,
                `employer_pct` DECIMAL(5,2) NOT NULL DEFAULT 32.00,
                `employee_pool` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `employer_share` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `allocation_method` ENUM('equal','days_worked','manual') NOT NULL DEFAULT 'equal',
                `component_id` INT UNSIGNED DEFAULT NULL,
                `status` ENUM('draft','approved') NOT NULL DEFAULT 'draft',
                `notes` VARCHAR(255) DEFAULT NULL,
                `approved_by` INT UNSIGNED DEFAULT NULL,
                `approved_at` TIMESTAMP NULL DEFAULT NULL,
                `created_by` INT UNSIGNED DEFAULT NULL,
                `updated_by` INT UNSIGNED DEFAULT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_sc_run` (`run_id`),
                KEY `idx_sc_company` (`company_id`),
                CONSTRAINT `fk_sc_runs_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_sc_runs_run` FOREIGN KEY (`run_id`) REFERENCES `payroll_runs` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_sc_runs_component` FOREIGN KEY (`component_id`) REFERENCES `payroll_components` (`id`) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        if (!accounting_repair_table_exists('payroll_service_charge_allocations')) {
            db()->exec("CREATE TABLE IF NOT EXISTS `payroll_service_charge_allocations` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `sc_run_id` INT UNSIGNED NOT NULL,
                `payroll_employee_id` INT UNSIGNED NOT NULL,
                `eligible_days` DECIMAL(6,2) DEFAULT NULL,
                `amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_sc_alloc_employee` (`sc_run_id`, `payroll_employee_id`),
                CONSTRAINT `fk_sc_alloc_run` FOREIGN KEY (`sc_run_id`) REFERENCES `payroll_service_charge_runs` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_sc_alloc_employee` FOREIGN KEY (`payroll_employee_id`) REFERENCES `payroll_employees` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }

        if (!accounting_repair_table_exists('payroll_overtime_weeks')) {
            db()->exec("CREATE TABLE IF NOT EXISTS `payroll_overtime_weeks` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL,
                `payroll_employee_id` INT UNSIGNED NOT NULL,
                `week_start` DATE NOT NULL,
                `week_end` DATE NOT NULL,
                `total_hours` DECIMAL(7,2) NOT NULL DEFAULT 0.00,
                `regular_hours` DECIMAL(7,2) NOT NULL DEFAULT 0.00,
                `overtime_hours` DECIMAL(7,2) NOT NULL DEFAULT 0.00,
                `hourly_rate` DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
                `multiplier` DECIMAL(6,3) NOT NULL DEFAULT 1.000,
                `calculated_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `approved_amount` DECIMAL(14,2) DEFAULT NULL,
                `adjust_reason` VARCHAR(255) DEFAULT NULL,
                `daily_json` TEXT DEFAULT NULL,
                `status` ENUM('calculated','approved') NOT NULL DEFAULT 'calculated',
                `approved_by` INT UNSIGNED DEFAULT NULL,
                `approved_at` TIMESTAMP NULL DEFAULT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_ot_week` (`payroll_employee_id`, `week_start`),
                KEY `idx_ot_weeks_company` (`company_id`, `week_start`),
                CONSTRAINT `fk_ot_weeks_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_ot_weeks_employee` FOREIGN KEY (`payroll_employee_id`) REFERENCES `payroll_employees` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        if (!accounting_repair_table_exists('payroll_overtime_entries')) {
            db()->exec("CREATE TABLE IF NOT EXISTS `payroll_overtime_entries` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL,
                `payroll_employee_id` INT UNSIGNED NOT NULL,
                `week_start` DATE NOT NULL,
                `ot_date` DATE NOT NULL,
                `hours` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
                `amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `run_id` INT UNSIGNED DEFAULT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_ot_entry_date` (`payroll_employee_id`, `ot_date`),
                KEY `idx_ot_entries_week` (`payroll_employee_id`, `week_start`),
                KEY `idx_ot_entries_run` (`run_id`),
                CONSTRAINT `fk_ot_entries_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_ot_entries_employee` FOREIGN KEY (`payroll_employee_id`) REFERENCES `payroll_employees` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_ot_entries_run` FOREIGN KEY (`run_id`) REFERENCES `payroll_runs` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }

        accounting_repair_add_column('payroll_settings', 'ot_weekly_threshold', '`ot_weekly_threshold` DECIMAL(5,2) NOT NULL DEFAULT 40.00');
        accounting_repair_add_column('payroll_settings', 'ot_week_start', '`ot_week_start` TINYINT NOT NULL DEFAULT 0');
        accounting_repair_add_column('payroll_settings', 'ot_component_id', '`ot_component_id` INT UNSIGNED DEFAULT NULL');
        accounting_repair_add_column('payroll_settings', 'ot_rate_source', "`ot_rate_source` ENUM('salary_derived','fixed_rate') NOT NULL DEFAULT 'salary_derived'");
        accounting_repair_add_column('payroll_settings', 'ot_monthly_hours', '`ot_monthly_hours` DECIMAL(6,2) NOT NULL DEFAULT 208.00');
        accounting_repair_add_column('payroll_settings', 'ot_multiplier', '`ot_multiplier` DECIMAL(6,3) NOT NULL DEFAULT 1.500');
        accounting_repair_add_column('payroll_settings', 'ot_rounding', "`ot_rounding` ENUM('none','nearest','down') NOT NULL DEFAULT 'nearest'");
        accounting_repair_add_column('payroll_settings', 'ot_require_approval', '`ot_require_approval` TINYINT(1) NOT NULL DEFAULT 1');
        accounting_repair_add_column('payroll_settings', 'sc_component_id', '`sc_component_id` INT UNSIGNED DEFAULT NULL');
        accounting_repair_add_column('payroll_settings', 'sc_employee_pct', '`sc_employee_pct` DECIMAL(5,2) NOT NULL DEFAULT 68.00');
        accounting_repair_add_column('payroll_settings', 'sc_employer_pct', '`sc_employer_pct` DECIMAL(5,2) NOT NULL DEFAULT 32.00');
        accounting_repair_add_constraint('payroll_settings', 'fk_payroll_settings_ot_comp', '`fk_payroll_settings_ot_comp` FOREIGN KEY (`ot_component_id`) REFERENCES `payroll_components` (`id`) ON DELETE SET NULL');
        accounting_repair_add_constraint('payroll_settings', 'fk_payroll_settings_sc_comp', '`fk_payroll_settings_sc_comp` FOREIGN KEY (`sc_component_id`) REFERENCES `payroll_components` (`id`) ON DELETE SET NULL');

        accounting_repair_add_column('payroll_employees', 'ot_hourly_rate', '`ot_hourly_rate` DECIMAL(14,4) DEFAULT NULL AFTER `basic_salary`');
        accounting_repair_add_column('payroll_employees', 'sc_eligible', '`sc_eligible` TINYINT(1) NOT NULL DEFAULT 1 AFTER `ot_hourly_rate`');
    });

    $run('Projected annual payroll tax (migration 062)', static function (): void {
        if (!accounting_repair_table_exists('payroll_components')) {
            return;
        }
        $projectionIsNew = !accounting_repair_column_exists('payroll_components', 'tax_projection_method');
        accounting_repair_add_column('payroll_components', 'tax_projection_method', "`tax_projection_method` ENUM('regular','actual_only','guaranteed','manual','excluded') DEFAULT NULL AFTER `taxable`");
        if ($projectionIsNew) {
            db()->exec("UPDATE payroll_components SET tax_projection_method = CASE
                    WHEN calc_type IN ('overtime_hours','service_charge','manual') THEN 'actual_only' ELSE 'regular' END
                WHERE tax_projection_method IS NULL");
        }
        accounting_repair_add_column('payroll_run_components', 'tax_projection_method', '`tax_projection_method` VARCHAR(20) DEFAULT NULL AFTER `taxable`');
        accounting_repair_add_column('payroll_run_lines', 'assessable_month', '`assessable_month` DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER `gross`');
        accounting_repair_add_column('payroll_run_lines', 'regular_month', '`regular_month` DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER `assessable_month`');
        accounting_repair_add_column('payroll_run_lines', 'irregular_month', '`irregular_month` DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER `regular_month`');
        accounting_repair_add_column('payroll_run_lines', 'tax_override', '`tax_override` DECIMAL(14,2) DEFAULT NULL AFTER `tax_month`');
        accounting_repair_add_column('payroll_run_lines', 'tax_override_reason', '`tax_override_reason` VARCHAR(255) DEFAULT NULL AFTER `tax_override`');
        accounting_repair_add_column('payroll_run_lines', 'tax_override_by', '`tax_override_by` INT UNSIGNED DEFAULT NULL AFTER `tax_override_reason`');
        accounting_repair_add_column('payroll_employees', 'contract_end_date', '`contract_end_date` DATE DEFAULT NULL AFTER `terminated_on`');
        accounting_repair_add_column('payroll_settings', 'excess_tax_treatment', "`excess_tax_treatment` ENUM('offset','refund','carry_forward','manual') NOT NULL DEFAULT 'offset'");

        if (!accounting_repair_table_exists('payroll_employee_tax_profiles')) {
            db()->exec("CREATE TABLE IF NOT EXISTS `payroll_employee_tax_profiles` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL,
                `payroll_employee_id` INT UNSIGNED NOT NULL,
                `fiscal_year_id` INT UNSIGNED NOT NULL,
                `prior_employment_income` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `prior_tax_withheld` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `prior_employer_details` VARCHAR(255) DEFAULT NULL,
                `document_reference` VARCHAR(255) DEFAULT NULL,
                `opening_income_adjustment` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `opening_tax_adjustment` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `remarks` VARCHAR(255) DEFAULT NULL,
                `entered_by` INT UNSIGNED DEFAULT NULL,
                `entered_at` TIMESTAMP NULL DEFAULT NULL,
                `approved_by` INT UNSIGNED DEFAULT NULL,
                `approved_at` TIMESTAMP NULL DEFAULT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_tax_profile` (`payroll_employee_id`, `fiscal_year_id`),
                KEY `idx_tax_profiles_company` (`company_id`),
                CONSTRAINT `fk_tax_profiles_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_tax_profiles_employee` FOREIGN KEY (`payroll_employee_id`) REFERENCES `payroll_employees` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_tax_profiles_fy` FOREIGN KEY (`fiscal_year_id`) REFERENCES `fiscal_years` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        if (!accounting_repair_table_exists('payroll_salary_revisions')) {
            db()->exec("CREATE TABLE IF NOT EXISTS `payroll_salary_revisions` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL,
                `payroll_employee_id` INT UNSIGNED NOT NULL,
                `effective_from` DATE NOT NULL,
                `basic_salary` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `reason` VARCHAR(255) DEFAULT NULL,
                `created_by` INT UNSIGNED DEFAULT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_salary_revision` (`payroll_employee_id`, `effective_from`),
                KEY `idx_salary_revisions_company` (`company_id`),
                CONSTRAINT `fk_salary_revisions_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_salary_revisions_employee` FOREIGN KEY (`payroll_employee_id`) REFERENCES `payroll_employees` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        if (!accounting_repair_table_exists('payroll_manual_projections')) {
            db()->exec("CREATE TABLE IF NOT EXISTS `payroll_manual_projections` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL,
                `payroll_employee_id` INT UNSIGNED NOT NULL,
                `fiscal_year_id` INT UNSIGNED NOT NULL,
                `component_id` INT UNSIGNED DEFAULT NULL,
                `label` VARCHAR(120) NOT NULL,
                `amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `period_from` TINYINT UNSIGNED NOT NULL DEFAULT 1,
                `period_to` TINYINT UNSIGNED NOT NULL DEFAULT 12,
                `reason` VARCHAR(255) DEFAULT NULL,
                `prepared_by` INT UNSIGNED DEFAULT NULL,
                `approved_by` INT UNSIGNED DEFAULT NULL,
                `approved_at` TIMESTAMP NULL DEFAULT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_manual_projections_employee` (`payroll_employee_id`, `fiscal_year_id`),
                CONSTRAINT `fk_manual_projections_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_manual_projections_employee` FOREIGN KEY (`payroll_employee_id`) REFERENCES `payroll_employees` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_manual_projections_fy` FOREIGN KEY (`fiscal_year_id`) REFERENCES `fiscal_years` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_manual_projections_component` FOREIGN KEY (`component_id`) REFERENCES `payroll_components` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        if (!accounting_repair_table_exists('payroll_tax_calculations')) {
            db()->exec("CREATE TABLE IF NOT EXISTS `payroll_tax_calculations` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL,
                `run_id` INT UNSIGNED NOT NULL,
                `payroll_employee_id` INT UNSIGNED NOT NULL,
                `fiscal_year_id` INT UNSIGNED NOT NULL,
                `period_no` TINYINT UNSIGNED NOT NULL DEFAULT 1,
                `start_period` TINYINT UNSIGNED NOT NULL DEFAULT 1,
                `end_period` TINYINT UNSIGNED NOT NULL DEFAULT 12,
                `remaining_periods` TINYINT UNSIGNED NOT NULL DEFAULT 1,
                `employment_start_used` DATE DEFAULT NULL,
                `employment_end_used` DATE DEFAULT NULL,
                `actual_regular_to_date` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `actual_irregular_to_date` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `current_regular` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `current_irregular` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `projected_regular_income` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `manual_projected_income` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `prior_employment_income` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `estimated_annual_taxable_income` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `retirement_deduction` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `taxable_annual` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `estimated_annual_tax` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `tax_withheld_before_period` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `prior_employer_tax` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `remaining_tax` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `system_tax` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `tax_override` DECIMAL(14,2) DEFAULT NULL,
                `current_tax` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `excess_tax` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `calculation_version` TINYINT UNSIGNED NOT NULL DEFAULT 2,
                `snapshot_json` TEXT DEFAULT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_tax_calc_line` (`run_id`, `payroll_employee_id`),
                KEY `idx_tax_calcs_employee` (`payroll_employee_id`, `fiscal_year_id`),
                KEY `idx_tax_calcs_company` (`company_id`),
                CONSTRAINT `fk_tax_calcs_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_tax_calcs_run` FOREIGN KEY (`run_id`) REFERENCES `payroll_runs` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_tax_calcs_employee` FOREIGN KEY (`payroll_employee_id`) REFERENCES `payroll_employees` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
    });

    $run('Hospitality accounting — recipe costing, reference-only (migration 063)', static function (): void {
        if (!accounting_repair_table_exists('client_profiles')) {
            return;
        }
        accounting_repair_add_column('client_profiles', 'hospitality_accounting_enabled', '`hospitality_accounting_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_active`');

        if (!accounting_repair_table_exists('hospitality_settings')) {
            db()->exec("CREATE TABLE IF NOT EXISTS `hospitality_settings` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL,
                `cost_source` ENUM('manual','latest_purchase') NOT NULL DEFAULT 'manual',
                `costing_basis` ENUM('sale_date','calculation_date') NOT NULL DEFAULT 'sale_date',
                `include_invoice_discount` TINYINT(1) NOT NULL DEFAULT 1,
                `net_of_vat` TINYINT(1) NOT NULL DEFAULT 1,
                `include_packaging` TINYINT(1) NOT NULL DEFAULT 0,
                `packaging_pct` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
                `include_kitchen_wastage` TINYINT(1) NOT NULL DEFAULT 0,
                `kitchen_wastage_pct` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
                `include_other_variable` TINYINT(1) NOT NULL DEFAULT 0,
                `other_variable_pct` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
                `cost_precision` TINYINT UNSIGNED NOT NULL DEFAULT 2,
                `display_precision` TINYINT UNSIGNED NOT NULL DEFAULT 2,
                `dashboard_range` VARCHAR(20) NOT NULL DEFAULT 'month',
                `updated_by` INT UNSIGNED DEFAULT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_hosp_settings_company` (`company_id`),
                CONSTRAINT `fk_hosp_settings_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        if (!accounting_repair_table_exists('hospitality_ingredients')) {
            db()->exec("CREATE TABLE IF NOT EXISTS `hospitality_ingredients` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL,
                `code` VARCHAR(40) NOT NULL,
                `name` VARCHAR(160) NOT NULL,
                `name_np` VARCHAR(160) DEFAULT NULL,
                `category` VARCHAR(80) DEFAULT NULL,
                `purchase_unit` VARCHAR(40) NOT NULL DEFAULT 'unit',
                `recipe_unit` VARCHAR(40) NOT NULL DEFAULT 'unit',
                `conversion_factor` DECIMAL(14,4) NOT NULL DEFAULT 1.0000,
                `purchase_cost` DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
                `cost_source` ENUM('manual','latest_purchase') NOT NULL DEFAULT 'manual',
                `effective_date` DATE NOT NULL,
                `wastage_pct` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
                `yield_pct` DECIMAL(6,2) NOT NULL DEFAULT 100.00,
                `active` TINYINT(1) NOT NULL DEFAULT 1,
                `notes` VARCHAR(255) DEFAULT NULL,
                `created_by` INT UNSIGNED DEFAULT NULL,
                `updated_by` INT UNSIGNED DEFAULT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_hosp_ingredient_code` (`company_id`, `code`),
                KEY `idx_hosp_ingredients_active` (`company_id`, `active`),
                CONSTRAINT `fk_hosp_ingredients_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        if (!accounting_repair_table_exists('hospitality_ingredient_costs')) {
            db()->exec("CREATE TABLE IF NOT EXISTS `hospitality_ingredient_costs` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL,
                `ingredient_id` INT UNSIGNED NOT NULL,
                `purchase_cost` DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
                `effective_date` DATE NOT NULL,
                `source` VARCHAR(30) NOT NULL DEFAULT 'manual',
                `created_by` INT UNSIGNED DEFAULT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_hosp_ing_costs` (`ingredient_id`, `effective_date`),
                KEY `idx_hosp_ing_costs_company` (`company_id`),
                CONSTRAINT `fk_hosp_ing_costs_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_hosp_ing_costs_ingredient` FOREIGN KEY (`ingredient_id`) REFERENCES `hospitality_ingredients` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        if (!accounting_repair_table_exists('hospitality_menu_items')) {
            db()->exec("CREATE TABLE IF NOT EXISTS `hospitality_menu_items` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL,
                `code` VARCHAR(40) NOT NULL,
                `name` VARCHAR(160) NOT NULL,
                `name_np` VARCHAR(160) DEFAULT NULL,
                `category` VARCHAR(40) NOT NULL DEFAULT 'Food',
                `standard_price` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `unit_of_sale` VARCHAR(40) NOT NULL DEFAULT 'plate',
                `tax_inclusive` TINYINT(1) NOT NULL DEFAULT 0,
                `active` TINYINT(1) NOT NULL DEFAULT 1,
                `notes` VARCHAR(255) DEFAULT NULL,
                `created_by` INT UNSIGNED DEFAULT NULL,
                `updated_by` INT UNSIGNED DEFAULT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_hosp_menu_code` (`company_id`, `code`),
                KEY `idx_hosp_menu_active` (`company_id`, `active`),
                CONSTRAINT `fk_hosp_menu_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        if (!accounting_repair_table_exists('hospitality_recipes')) {
            db()->exec("CREATE TABLE IF NOT EXISTS `hospitality_recipes` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL,
                `menu_item_id` INT UNSIGNED NOT NULL,
                `code` VARCHAR(60) DEFAULT NULL,
                `name` VARCHAR(160) NOT NULL,
                `version` INT UNSIGNED NOT NULL DEFAULT 1,
                `effective_from` DATE NOT NULL,
                `effective_to` DATE DEFAULT NULL,
                `yield_qty` DECIMAL(14,3) NOT NULL DEFAULT 1.000,
                `portions` DECIMAL(14,3) NOT NULL DEFAULT 1.000,
                `portion_size` VARCHAR(60) DEFAULT NULL,
                `prep_wastage_pct` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
                `packaging_cost` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `other_cost` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `status` ENUM('draft','active','archived') NOT NULL DEFAULT 'draft',
                `notes` VARCHAR(255) DEFAULT NULL,
                `created_by` INT UNSIGNED DEFAULT NULL,
                `updated_by` INT UNSIGNED DEFAULT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_hosp_recipe_version` (`menu_item_id`, `version`),
                KEY `idx_hosp_recipes_company` (`company_id`, `status`),
                KEY `idx_hosp_recipes_effective` (`menu_item_id`, `effective_from`),
                CONSTRAINT `fk_hosp_recipes_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_hosp_recipes_menu_item` FOREIGN KEY (`menu_item_id`) REFERENCES `hospitality_menu_items` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        if (!accounting_repair_table_exists('hospitality_recipe_lines')) {
            db()->exec("CREATE TABLE IF NOT EXISTS `hospitality_recipe_lines` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `recipe_id` INT UNSIGNED NOT NULL,
                `ingredient_id` INT UNSIGNED NOT NULL,
                `quantity` DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
                `unit` VARCHAR(40) DEFAULT NULL,
                `notes` VARCHAR(255) DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_hosp_recipe_lines` (`recipe_id`),
                KEY `idx_hosp_recipe_lines_ing` (`ingredient_id`),
                CONSTRAINT `fk_hosp_recipe_lines_recipe` FOREIGN KEY (`recipe_id`) REFERENCES `hospitality_recipes` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_hosp_recipe_lines_ingredient` FOREIGN KEY (`ingredient_id`) REFERENCES `hospitality_ingredients` (`id`) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        if (!accounting_repair_table_exists('hospitality_sales_mappings')) {
            db()->exec("CREATE TABLE IF NOT EXISTS `hospitality_sales_mappings` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL,
                `match_type` ENUM('item','description') NOT NULL DEFAULT 'description',
                `item_id` INT UNSIGNED DEFAULT NULL,
                `description_norm` VARCHAR(255) DEFAULT NULL,
                `menu_item_id` INT UNSIGNED DEFAULT NULL,
                `status` ENUM('mapped','ignored') NOT NULL DEFAULT 'mapped',
                `ignore_reason` VARCHAR(255) DEFAULT NULL,
                `effective_from` DATE DEFAULT NULL,
                `effective_to` DATE DEFAULT NULL,
                `active` TINYINT(1) NOT NULL DEFAULT 1,
                `notes` VARCHAR(255) DEFAULT NULL,
                `created_by` INT UNSIGNED DEFAULT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_hosp_map_item` (`company_id`, `match_type`, `item_id`),
                KEY `idx_hosp_map_desc` (`company_id`, `description_norm`),
                CONSTRAINT `fk_hosp_map_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_hosp_map_menu_item` FOREIGN KEY (`menu_item_id`) REFERENCES `hospitality_menu_items` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        if (!accounting_repair_table_exists('hospitality_costing_runs')) {
            db()->exec("CREATE TABLE IF NOT EXISTS `hospitality_costing_runs` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL,
                `fiscal_year_id` INT UNSIGNED NOT NULL,
                `costing_date` DATE NOT NULL,
                `status` ENUM('costed','partial','empty') NOT NULL DEFAULT 'costed',
                `calc_version` TINYINT UNSIGNED NOT NULL DEFAULT 1,
                `generated_by` INT UNSIGNED DEFAULT NULL,
                `generated_at` TIMESTAMP NULL DEFAULT NULL,
                `recalculated_at` TIMESTAMP NULL DEFAULT NULL,
                `notes` VARCHAR(255) DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_hosp_run_date` (`company_id`, `costing_date`),
                KEY `idx_hosp_runs_fy` (`company_id`, `fiscal_year_id`, `costing_date`),
                CONSTRAINT `fk_hosp_runs_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_hosp_runs_fy` FOREIGN KEY (`fiscal_year_id`) REFERENCES `fiscal_years` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        if (!accounting_repair_table_exists('hospitality_costing_lines')) {
            db()->exec("CREATE TABLE IF NOT EXISTS `hospitality_costing_lines` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `run_id` INT UNSIGNED NOT NULL,
                `company_id` INT UNSIGNED NOT NULL,
                `fiscal_year_id` INT UNSIGNED NOT NULL,
                `sale_date` DATE NOT NULL,
                `invoice_id` INT UNSIGNED NOT NULL,
                `invoice_no` VARCHAR(100) DEFAULT NULL,
                `line_id` INT UNSIGNED NOT NULL,
                `sales_item_id` INT UNSIGNED DEFAULT NULL,
                `description` VARCHAR(255) DEFAULT NULL,
                `menu_item_id` INT UNSIGNED DEFAULT NULL,
                `menu_item_code` VARCHAR(40) DEFAULT NULL,
                `menu_item_name` VARCHAR(160) DEFAULT NULL,
                `category` VARCHAR(40) DEFAULT NULL,
                `recipe_id` INT UNSIGNED DEFAULT NULL,
                `recipe_version` INT UNSIGNED DEFAULT NULL,
                `qty_sold` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
                `qty_returned` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
                `net_qty` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
                `gross_sales` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `discount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `vat` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `net_sales` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `unit_cost` DECIMAL(14,4) DEFAULT NULL,
                `total_cost` DECIMAL(14,2) DEFAULT NULL,
                `gross_profit` DECIMAL(14,2) DEFAULT NULL,
                `gp_pct` DECIMAL(8,2) DEFAULT NULL,
                `status` VARCHAR(30) NOT NULL DEFAULT 'unmapped',
                `warning` VARCHAR(255) DEFAULT NULL,
                `snapshot_json` TEXT DEFAULT NULL,
                `calc_version` TINYINT UNSIGNED NOT NULL DEFAULT 1,
                `calculated_by` INT UNSIGNED DEFAULT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_hosp_line_source` (`run_id`, `line_id`),
                KEY `idx_hosp_lines_date` (`company_id`, `sale_date`),
                KEY `idx_hosp_lines_menu` (`menu_item_id`, `sale_date`),
                KEY `idx_hosp_lines_fy` (`company_id`, `fiscal_year_id`, `sale_date`),
                KEY `idx_hosp_lines_status` (`company_id`, `status`),
                CONSTRAINT `fk_hosp_lines_run` FOREIGN KEY (`run_id`) REFERENCES `hospitality_costing_runs` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_hosp_lines_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        if (!accounting_repair_table_exists('hospitality_recalc_history')) {
            db()->exec("CREATE TABLE IF NOT EXISTS `hospitality_recalc_history` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL,
                `run_id` INT UNSIGNED NOT NULL,
                `costing_date` DATE NOT NULL,
                `old_totals_json` TEXT DEFAULT NULL,
                `new_totals_json` TEXT DEFAULT NULL,
                `reason` VARCHAR(255) NOT NULL,
                `recalculated_by` INT UNSIGNED DEFAULT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_hosp_recalc_company` (`company_id`, `costing_date`),
                CONSTRAINT `fk_hosp_recalc_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_hosp_recalc_run` FOREIGN KEY (`run_id`) REFERENCES `hospitality_costing_runs` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
    });

    return $errors;
}
