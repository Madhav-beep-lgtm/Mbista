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
        if (preg_match('/^\s*CONSTRAINT\b/i', $definition)) {
            db()->exec('ALTER TABLE `' . $tableName . '` ADD ' . $definition);
        } else {
            db()->exec('ALTER TABLE `' . $tableName . '` ADD CONSTRAINT ' . $definition);
        }
    }
}

/**
 * Replay a numbered migration file when its tables are missing.
 *
 * The older repair steps inline their DDL, which means the same CREATE TABLE
 * exists twice and the two copies can drift. For large additions that is a
 * real liability, so these steps read the migration file itself — one source
 * of truth. `database/` is rsynced to the server by deploy/tasks.sh, so the
 * file is present in production too.
 *
 * $sentinelTables is the fast path: when they all exist there is nothing to
 * do and the file is never opened, so this costs nothing on a normal page
 * load. The migrations it is used for are pure CREATE TABLE IF NOT EXISTS,
 * so a replay is idempotent even if it does run.
 */
function accounting_repair_run_migration_file(string $migrationFile, array $sentinelTables): void
{
    foreach ($sentinelTables as $table) {
        if (!accounting_repair_table_exists($table)) {
            $missing = true;
            break;
        }
    }
    if (!isset($missing)) {
        return;
    }

    $path = dirname(__DIR__) . '/database/migrations/' . $migrationFile;
    if (!is_file($path)) {
        return;
    }
    $sql = (string) file_get_contents($path);

    // Drop whole-line SQL comments before splitting, so a stray semicolon in
    // prose can never cut a statement in half.
    $lines = [];
    foreach (preg_split('/\R/', $sql) ?: [] as $line) {
        if (preg_match('/^\s*--/', $line)) {
            continue;
        }
        $lines[] = $line;
    }

    foreach (explode(';', implode("\n", $lines)) as $statement) {
        $statement = trim($statement);
        if ($statement === '') {
            continue;
        }
        db()->exec($statement);
    }
}

/**
 * Replay a migration until a named index proves that it completed.
 *
 * This is used for data-link migrations where all tables already exist, so a
 * missing-table sentinel cannot tell whether the migration ran.
 */
function accounting_repair_run_migration_file_if_index_missing(
    string $migrationFile,
    string $tableName,
    string $indexName
): void {
    if (accounting_repair_index_exists($tableName, $indexName)) {
        return;
    }

    $path = dirname(__DIR__) . '/database/migrations/' . $migrationFile;
    if (!is_file($path)) {
        return;
    }

    $lines = [];
    foreach (preg_split('/\R/', (string) file_get_contents($path)) ?: [] as $line) {
        if (preg_match('/^\s*--/', $line)) {
            continue;
        }
        $lines[] = $line;
    }

    foreach (explode(';', implode("\n", $lines)) as $statement) {
        $statement = trim($statement);
        if ($statement !== '') {
            db()->exec($statement);
        }
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

    $run('Provision per-type voucher fields (migration 100)', static function (): void {
        // The supplier's own bill number and date, the cheque on a payment, and
        // the reason behind a debit or credit note. Each belongs to one type of
        // voucher, and none of them fits in the narration.
        accounting_repair_add_column('vouchers', 'reference_date', '`reference_date` DATE DEFAULT NULL AFTER `reference_no`');
        accounting_repair_add_column('vouchers', 'instrument_type', '`instrument_type` VARCHAR(30) DEFAULT NULL AFTER `reference_date`');
        accounting_repair_add_column('vouchers', 'instrument_no', '`instrument_no` VARCHAR(80) DEFAULT NULL AFTER `instrument_type`');
        accounting_repair_add_column('vouchers', 'instrument_date', '`instrument_date` DATE DEFAULT NULL AFTER `instrument_no`');
        accounting_repair_add_column('vouchers', 'return_reason', '`return_reason` VARCHAR(255) DEFAULT NULL AFTER `instrument_date`');
        accounting_repair_add_index('vouchers', 'idx_vouchers_type_date', 'KEY `idx_vouchers_type_date` (`company_id`, `voucher_type`, `voucher_date`)');
    });

    $run('Provision voucher stock lines (migration 101)', static function (): void {
        // A sales or purchase voucher names the goods it moved, so the stock
        // rises and falls with the entry instead of being reconciled by hand.
        accounting_repair_add_column('voucher_entries', 'item_id', '`item_id` INT UNSIGNED DEFAULT NULL AFTER `ledger_id`');
        accounting_repair_add_column('voucher_entries', 'quantity', '`quantity` DECIMAL(14,3) NOT NULL DEFAULT 0.000 AFTER `item_id`');
        accounting_repair_add_index('voucher_entries', 'idx_voucher_entries_item', 'KEY `idx_voucher_entries_item` (`item_id`)');
        accounting_repair_add_column('vouchers', 'warehouse_id', '`warehouse_id` INT UNSIGNED DEFAULT NULL AFTER `location`');
        // Distinct from voucher_id, which names the voucher carrying the
        // movement's VALUE — for a sale that is the COGS journal, not the sale.
        accounting_repair_add_column('inventory_transactions', 'source_voucher_id', '`source_voucher_id` INT UNSIGNED DEFAULT NULL AFTER `voucher_id`');
        accounting_repair_add_index('inventory_transactions', 'idx_inventory_transactions_source_voucher', 'KEY `idx_inventory_transactions_source_voucher` (`source_voucher_id`)');
        // NOTE the shape of these: the helper already writes "ADD CONSTRAINT",
        // so the definition starts at the NAME. Repeating the keyword here —
        // which is how the migration file spells it — produced
        // "ADD CONSTRAINT CONSTRAINT `fk_...`" and a syntax error on the page.
        // The name still has to be given, or MariaDB invents one and the
        // exists-check below never matches it again.
        //
        // The cascade matters: a deleted voucher must take its stock movements
        // with it, or the shop's on-hand keeps counting goods no entry backs.
        if (accounting_repair_table_exists('vouchers')) {
            accounting_repair_add_constraint(
                'inventory_transactions',
                'fk_inventory_transactions_source_voucher',
                '`fk_inventory_transactions_source_voucher` FOREIGN KEY (`source_voucher_id`) REFERENCES `vouchers` (`id`) ON DELETE CASCADE'
            );
        }
        if (accounting_repair_table_exists('inventory_items')) {
            accounting_repair_add_constraint(
                'voucher_entries',
                'fk_voucher_entries_item',
                '`fk_voucher_entries_item` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`id`) ON DELETE SET NULL'
            );
        }
        if (accounting_repair_table_exists('warehouses')) {
            accounting_repair_add_constraint(
                'vouchers',
                'fk_vouchers_warehouse',
                '`fk_vouchers_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE SET NULL'
            );
        }
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

    $run('Unify client, sales party and ledger identity (migration 107)', static function (): void {
        foreach (['accounting_parties', 'client_profiles', 'client_tasks', 'task_invoices'] as $requiredTable) {
            if (!accounting_repair_table_exists($requiredTable)) {
                return;
            }
        }
        accounting_repair_run_migration_file_if_index_missing(
            '107_unified_party_identity.sql',
            'accounting_parties',
            'uniq_accounting_parties_company_client'
        );
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

    $run('Unify Party Master ledger roles (migration 108)', static function (): void {
        if (!accounting_repair_table_exists('ledger_groups') || !accounting_repair_table_exists('accounting_parties')) {
            return;
        }
        accounting_repair_run_migration_file_if_index_missing(
            '108_party_ledger_roles.sql',
            'ledger_groups',
            'uniq_ledger_groups_company_party_role'
        );
        // Complete a partially-applied deployment as well as a clean one.
        accounting_repair_add_column('accounting_parties', 'advance_ledger_id', '`advance_ledger_id` INT UNSIGNED DEFAULT NULL AFTER `payable_ledger_id`');
        accounting_repair_add_column('accounting_parties', 'supplier_advance_ledger_id', '`supplier_advance_ledger_id` INT UNSIGNED DEFAULT NULL AFTER `advance_ledger_id`');
        accounting_repair_add_index('accounting_parties', 'idx_parties_advance_ledger', 'KEY `idx_parties_advance_ledger` (`advance_ledger_id`)');
        accounting_repair_add_index('accounting_parties', 'idx_parties_supplier_advance_ledger', 'KEY `idx_parties_supplier_advance_ledger` (`supplier_advance_ledger_id`)');
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

    $run('Company bank accounts + bilingual service agreements (migration 064)', static function (): void {
        if (!accounting_repair_table_exists('company_bank_accounts')) {
            db()->exec("CREATE TABLE IF NOT EXISTS `company_bank_accounts` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL,
                `bank_name` VARCHAR(160) NOT NULL,
                `account_name` VARCHAR(190) NOT NULL,
                `account_number` VARCHAR(80) NOT NULL,
                `branch` VARCHAR(160) DEFAULT NULL,
                `swift_code` VARCHAR(40) DEFAULT NULL,
                `is_default` TINYINT(1) NOT NULL DEFAULT 0,
                `show_on_invoice` TINYINT(1) NOT NULL DEFAULT 1,
                `sort_order` INT NOT NULL DEFAULT 0,
                `active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_by` INT UNSIGNED DEFAULT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_bank_accounts_company` (`company_id`, `active`, `show_on_invoice`),
                CONSTRAINT `fk_bank_accounts_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        if (!accounting_repair_table_exists('service_agreements')) {
            db()->exec("CREATE TABLE IF NOT EXISTS `service_agreements` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL,
                `client_id` INT UNSIGNED DEFAULT NULL,
                `agreement_no` VARCHAR(60) DEFAULT NULL,
                `purpose_en` VARCHAR(190) NOT NULL DEFAULT 'Accounting and Advisory Services',
                `purpose_np` VARCHAR(190) NOT NULL DEFAULT 'लेखा तथा परामर्श सेवा',
                `first_party_name_en` VARCHAR(190) NOT NULL,
                `first_party_name_np` VARCHAR(190) DEFAULT NULL,
                `first_party_address` VARCHAR(255) DEFAULT NULL,
                `first_party_reg_no` VARCHAR(80) DEFAULT NULL,
                `first_party_signatory` VARCHAR(160) DEFAULT NULL,
                `first_party_position` VARCHAR(160) DEFAULT NULL,
                `second_party_name_en` VARCHAR(190) NOT NULL,
                `second_party_name_np` VARCHAR(190) DEFAULT NULL,
                `second_party_address` VARCHAR(255) DEFAULT NULL,
                `second_party_reg_no` VARCHAR(80) DEFAULT NULL,
                `second_party_signatory` VARCHAR(160) DEFAULT NULL,
                `second_party_position` VARCHAR(160) DEFAULT NULL,
                `agreement_date_bs` VARCHAR(30) DEFAULT NULL,
                `effective_date` DATE DEFAULT NULL,
                `effective_date_bs` VARCHAR(30) DEFAULT NULL,
                `duration_months` INT UNSIGNED NOT NULL DEFAULT 24,
                `trial_months` INT UNSIGNED NOT NULL DEFAULT 1,
                `fee_trial` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `fee_monthly` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `payment_days` INT UNSIGNED NOT NULL DEFAULT 7,
                `termination_notice_days` INT UNSIGNED NOT NULL DEFAULT 3,
                `cure_days` INT UNSIGNED NOT NULL DEFAULT 7,
                `jurisdiction_en` VARCHAR(120) NOT NULL DEFAULT 'the competent court of Kathmandu District',
                `jurisdiction_np` VARCHAR(120) NOT NULL DEFAULT 'काठमाडौँ जिल्लाको सम्बन्धित अदालत',
                `staffing_np` TEXT DEFAULT NULL,
                `staffing_en` TEXT DEFAULT NULL,
                `services_json` TEXT DEFAULT NULL,
                `fee_rows_json` TEXT DEFAULT NULL,
                `witnesses_json` TEXT DEFAULT NULL,
                `custom_clauses_np` TEXT DEFAULT NULL,
                `custom_clauses_en` TEXT DEFAULT NULL,
                `status` ENUM('draft','final') NOT NULL DEFAULT 'draft',
                `created_by` INT UNSIGNED DEFAULT NULL,
                `updated_by` INT UNSIGNED DEFAULT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_agreements_company` (`company_id`, `status`),
                KEY `idx_agreements_client` (`client_id`),
                CONSTRAINT `fk_agreements_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_agreements_client` FOREIGN KEY (`client_id`) REFERENCES `client_profiles` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
    });

    $run('Service agreements merged into work-portal contracts (migration 065)', static function (): void {
        if (!accounting_repair_table_exists('service_agreements') || !accounting_repair_table_exists('service_contracts')) {
            return;
        }
        accounting_repair_add_column('service_agreements', 'contract_id', '`contract_id` INT UNSIGNED DEFAULT NULL AFTER `client_id`');
        accounting_repair_add_index('service_agreements', 'uq_agreements_contract', 'UNIQUE KEY `uq_agreements_contract` (`contract_id`)');
        accounting_repair_add_constraint('service_agreements', 'fk_agreements_contract', '`fk_agreements_contract` FOREIGN KEY (`contract_id`) REFERENCES `service_contracts` (`id`) ON DELETE SET NULL');
    });

    $run('Structured agreement drafting engine (migration 066)', static function (): void {
        if (!accounting_repair_table_exists('service_agreements')) {
            return;
        }
        $needsBackfill = !accounting_repair_column_exists('service_agreements', 'workflow_status');
        foreach ([
            'structure_mode' => "`structure_mode` ENUM('classic','builder') NOT NULL DEFAULT 'classic' AFTER `status`",
            'workflow_status' => "`workflow_status` VARCHAR(30) NOT NULL DEFAULT 'draft' AFTER `structure_mode`",
            'language_mode' => "`language_mode` ENUM('np','en','both','both_seq') NOT NULL DEFAULT 'both' AFTER `workflow_status`",
            'prevailing_language' => "`prevailing_language` ENUM('np','en') NOT NULL DEFAULT 'np' AFTER `language_mode`",
            'template_id' => '`template_id` INT UNSIGNED DEFAULT NULL AFTER `prevailing_language`',
            'owner_id' => '`owner_id` INT UNSIGNED DEFAULT NULL AFTER `template_id`',
            'reviewer_id' => '`reviewer_id` INT UNSIGNED DEFAULT NULL AFTER `owner_id`',
            'approver_id' => '`approver_id` INT UNSIGNED DEFAULT NULL AFTER `reviewer_id`',
            'current_version' => '`current_version` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `approver_id`',
            'approved_version' => '`approved_version` INT UNSIGNED DEFAULT NULL AFTER `current_version`',
            'client_snapshot_json' => '`client_snapshot_json` TEXT DEFAULT NULL AFTER `approved_version`',
            'submitted_by' => '`submitted_by` INT UNSIGNED DEFAULT NULL AFTER `client_snapshot_json`',
            'submitted_at' => '`submitted_at` DATETIME DEFAULT NULL AFTER `submitted_by`',
            'reviewed_by' => '`reviewed_by` INT UNSIGNED DEFAULT NULL AFTER `submitted_at`',
            'reviewed_at' => '`reviewed_at` DATETIME DEFAULT NULL AFTER `reviewed_by`',
            'approved_by' => '`approved_by` INT UNSIGNED DEFAULT NULL AFTER `reviewed_at`',
            'approved_at' => '`approved_at` DATETIME DEFAULT NULL AFTER `approved_by`',
            'issued_by' => '`issued_by` INT UNSIGNED DEFAULT NULL AFTER `approved_at`',
            'issued_at' => '`issued_at` DATETIME DEFAULT NULL AFTER `issued_by`',
            'accepted_at' => '`accepted_at` DATETIME DEFAULT NULL AFTER `issued_at`',
            'accepted_by_user_id' => '`accepted_by_user_id` INT UNSIGNED DEFAULT NULL AFTER `accepted_at`',
            'acceptance_note' => '`acceptance_note` VARCHAR(255) DEFAULT NULL AFTER `accepted_by_user_id`',
            'acceptance_ip' => '`acceptance_ip` VARCHAR(45) DEFAULT NULL AFTER `acceptance_note`',
            'signed_document_id' => '`signed_document_id` INT UNSIGNED DEFAULT NULL AFTER `acceptance_ip`',
            'activated_at' => '`activated_at` DATETIME DEFAULT NULL AFTER `signed_document_id`',
            'expiry_date' => '`expiry_date` DATE DEFAULT NULL AFTER `activated_at`',
            'terminated_at' => '`terminated_at` DATETIME DEFAULT NULL AFTER `expiry_date`',
            'termination_reason' => '`termination_reason` VARCHAR(255) DEFAULT NULL AFTER `terminated_at`',
            'superseded_by_id' => '`superseded_by_id` INT UNSIGNED DEFAULT NULL AFTER `termination_reason`',
            'archived_at' => '`archived_at` DATETIME DEFAULT NULL AFTER `superseded_by_id`',
        ] as $column => $definition) {
            accounting_repair_add_column('service_agreements', $column, $definition);
        }
        accounting_repair_add_index('service_agreements', 'idx_agreements_workflow', 'KEY `idx_agreements_workflow` (`company_id`, `workflow_status`)');
        if ($needsBackfill) {
            db()->exec("UPDATE `service_agreements` SET `workflow_status` = 'approved' WHERE `status` = 'final' AND `workflow_status` = 'draft'");
        }

        if (!accounting_repair_table_exists('agreement_sections')) {
            db()->exec("CREATE TABLE IF NOT EXISTS `agreement_sections` (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        if (!accounting_repair_table_exists('agreement_versions')) {
            db()->exec("CREATE TABLE IF NOT EXISTS `agreement_versions` (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        if (!accounting_repair_table_exists('agreement_task_links') && accounting_repair_table_exists('client_tasks')) {
            db()->exec("CREATE TABLE IF NOT EXISTS `agreement_task_links` (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        if (!accounting_repair_table_exists('agreement_templates')) {
            db()->exec("CREATE TABLE IF NOT EXISTS `agreement_templates` (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        if (!accounting_repair_table_exists('agreement_comments')) {
            db()->exec("CREATE TABLE IF NOT EXISTS `agreement_comments` (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
    });

    $run('Hospitality upload-driven costing runs (migration 068)', static function (): void {
        if (!accounting_repair_table_exists('hospitality_costing_runs') || !accounting_repair_table_exists('hospitality_costing_lines')) {
            return;
        }
        accounting_repair_add_column('hospitality_costing_runs', 'source', "`source` ENUM('invoice','upload') NOT NULL DEFAULT 'invoice' AFTER `status`");
        accounting_repair_add_column('hospitality_costing_runs', 'upload_id', '`upload_id` INT UNSIGNED DEFAULT NULL AFTER `source`');
        accounting_repair_add_column('hospitality_costing_lines', 'sales_row_id', '`sales_row_id` INT UNSIGNED DEFAULT NULL AFTER `line_id`');
        // Upload-sourced lines have no invoice behind them; relax once.
        $nullable = db()->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'hospitality_costing_lines'
              AND COLUMN_NAME IN ('invoice_id', 'line_id') AND IS_NULLABLE = 'NO'")->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('invoice_id', $nullable, true)) {
            db()->exec('ALTER TABLE `hospitality_costing_lines` MODIFY `invoice_id` INT UNSIGNED DEFAULT NULL');
        }
        if (in_array('line_id', $nullable, true)) {
            db()->exec('ALTER TABLE `hospitality_costing_lines` MODIFY `line_id` INT UNSIGNED DEFAULT NULL');
        }
        // Deleting a run must NOT erase its preserved totals: detach instead
        // of cascading (one-time FK swap).
        if (accounting_repair_table_exists('hospitality_recalc_history')) {
            $cascade = db()->query("SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'hospitality_recalc_history'
                  AND CONSTRAINT_NAME = 'fk_hosp_recalc_run'")->fetchColumn();
            if ($cascade === 'CASCADE') {
                db()->exec('ALTER TABLE `hospitality_recalc_history` DROP FOREIGN KEY `fk_hosp_recalc_run`');
                db()->exec('ALTER TABLE `hospitality_recalc_history` MODIFY `run_id` INT UNSIGNED DEFAULT NULL');
                db()->exec('ALTER TABLE `hospitality_recalc_history` ADD CONSTRAINT `fk_hosp_recalc_run` FOREIGN KEY (`run_id`) REFERENCES `hospitality_costing_runs` (`id`) ON DELETE SET NULL');
            }
        }
        // A short-lived dev iteration created hospitality_sales_rows before the
        // upload feature settled on hospitality_sales_upload_lines. Drop it
        // only when it is provably unused.
        if (accounting_repair_table_exists('hospitality_sales_rows')
            && (int) db()->query('SELECT COUNT(*) FROM `hospitality_sales_rows`')->fetchColumn() === 0) {
            db()->exec('DROP TABLE `hospitality_sales_rows`');
        }
    });

    $run('Hospitality per-category ledger set (migration 069)', static function (): void {
        if (!accounting_repair_table_exists('hospitality_sales_ledger_maps')) {
            return;
        }
        accounting_repair_add_column('hospitality_sales_ledger_maps', 'receivable_ledger_id', '`receivable_ledger_id` INT UNSIGNED DEFAULT NULL AFTER `sales_ledger_id`');
        accounting_repair_add_column('hospitality_sales_ledger_maps', 'discount_ledger_id', '`discount_ledger_id` INT UNSIGNED DEFAULT NULL AFTER `receivable_ledger_id`');
        accounting_repair_add_constraint('hospitality_sales_ledger_maps', 'fk_hosp_ledger_map_recv', '`fk_hosp_ledger_map_recv` FOREIGN KEY (`receivable_ledger_id`) REFERENCES `ledgers` (`id`) ON DELETE SET NULL');
        accounting_repair_add_constraint('hospitality_sales_ledger_maps', 'fk_hosp_ledger_map_disc', '`fk_hosp_ledger_map_disc` FOREIGN KEY (`discount_ledger_id`) REFERENCES `ledgers` (`id`) ON DELETE SET NULL');
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

    $run('Hospitality daily sales upload + ledger posting (migration 067)', static function (): void {
        if (!accounting_repair_table_exists('hospitality_settings')) {
            return;
        }
        accounting_repair_add_column('hospitality_settings', 'post_sales_ledger_id', '`post_sales_ledger_id` INT UNSIGNED DEFAULT NULL AFTER `dashboard_range`');
        accounting_repair_add_column('hospitality_settings', 'post_vat_ledger_id', '`post_vat_ledger_id` INT UNSIGNED DEFAULT NULL AFTER `post_sales_ledger_id`');
        accounting_repair_add_column('hospitality_settings', 'post_discount_ledger_id', '`post_discount_ledger_id` INT UNSIGNED DEFAULT NULL AFTER `post_vat_ledger_id`');
        accounting_repair_add_column('hospitality_settings', 'post_receivable_ledger_id', '`post_receivable_ledger_id` INT UNSIGNED DEFAULT NULL AFTER `post_discount_ledger_id`');
        accounting_repair_add_column('hospitality_settings', 'post_vat_rate', '`post_vat_rate` DECIMAL(6,2) NOT NULL DEFAULT 13.00 AFTER `post_receivable_ledger_id`');
        accounting_repair_add_column('hospitality_settings', 'post_amount_includes_vat', '`post_amount_includes_vat` TINYINT(1) NOT NULL DEFAULT 1 AFTER `post_vat_rate`');

        if (!accounting_repair_table_exists('hospitality_sales_ledger_maps')) {
            db()->exec("CREATE TABLE IF NOT EXISTS `hospitality_sales_ledger_maps` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL,
                `map_type` ENUM('category','item') NOT NULL DEFAULT 'category',
                `match_value` VARCHAR(160) NOT NULL,
                `display_value` VARCHAR(160) NOT NULL,
                `sales_ledger_id` INT UNSIGNED NOT NULL,
                `active` TINYINT(1) NOT NULL DEFAULT 1,
                `notes` VARCHAR(255) DEFAULT NULL,
                `created_by` INT UNSIGNED DEFAULT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_hosp_ledger_map` (`company_id`, `map_type`, `match_value`),
                KEY `idx_hosp_ledger_map_ledger` (`sales_ledger_id`),
                CONSTRAINT `fk_hosp_ledger_map_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_hosp_ledger_map_ledger` FOREIGN KEY (`sales_ledger_id`) REFERENCES `ledgers` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        if (!accounting_repair_table_exists('hospitality_sales_uploads')) {
            db()->exec("CREATE TABLE IF NOT EXISTS `hospitality_sales_uploads` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL,
                `fiscal_year_id` INT UNSIGNED NOT NULL,
                `file_name` VARCHAR(255) DEFAULT NULL,
                `date_from` DATE NOT NULL,
                `date_to` DATE NOT NULL,
                `row_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `voucher_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `gross_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `discount_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `vat_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `taxable_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `receivable_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `status` ENUM('posted','pending_approval') NOT NULL DEFAULT 'posted',
                `posted_by` INT UNSIGNED DEFAULT NULL,
                `posted_at` TIMESTAMP NULL DEFAULT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_hosp_sales_uploads` (`company_id`, `date_from`, `date_to`),
                CONSTRAINT `fk_hosp_sales_uploads_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_hosp_sales_uploads_fy` FOREIGN KEY (`fiscal_year_id`) REFERENCES `fiscal_years` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        if (!accounting_repair_table_exists('hospitality_sales_upload_lines')) {
            db()->exec("CREATE TABLE IF NOT EXISTS `hospitality_sales_upload_lines` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `upload_id` INT UNSIGNED NOT NULL,
                `company_id` INT UNSIGNED NOT NULL,
                `sale_date` DATE NOT NULL,
                `category` VARCHAR(160) NOT NULL,
                `item_name` VARCHAR(255) NOT NULL,
                `qty` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
                `gross_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `discount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `vat_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `taxable_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `sales_ledger_id` INT UNSIGNED DEFAULT NULL,
                `ledger_source` VARCHAR(20) DEFAULT NULL,
                `voucher_id` INT UNSIGNED DEFAULT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_hosp_upload_lines_date` (`company_id`, `sale_date`),
                KEY `idx_hosp_upload_lines_upload` (`upload_id`),
                CONSTRAINT `fk_hosp_upload_lines_upload` FOREIGN KEY (`upload_id`) REFERENCES `hospitality_sales_uploads` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_hosp_upload_lines_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
    });

    // Ordered after the hospitality steps above: that is what guarantees the
    // `hospitality_accounting_enabled` column the new flag is positioned after
    // (with a fallback for databases that never ran the hospitality step).
    $run('Jewellery accounting — masters, daily rates, ledger maps (migration 070)', static function (): void {
        if (accounting_repair_table_exists('client_profiles')) {
            $after = accounting_repair_column_exists('client_profiles', 'hospitality_accounting_enabled')
                ? '`hospitality_accounting_enabled`'
                : '`is_active`';
            accounting_repair_add_column('client_profiles', 'jewellery_accounting_enabled', '`jewellery_accounting_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER ' . $after);
        }

        if (!accounting_repair_table_exists('jewellery_units')) {
            db()->exec("CREATE TABLE IF NOT EXISTS `jewellery_units` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL,
                `code` VARCHAR(20) NOT NULL,
                `name` VARCHAR(60) NOT NULL,
                `grams` DECIMAL(18,6) NOT NULL DEFAULT 1.000000,
                `is_base` TINYINT(1) NOT NULL DEFAULT 0,
                `sort_order` INT NOT NULL DEFAULT 0,
                `active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_jw_unit_code` (`company_id`, `code`),
                KEY `idx_jw_units_active` (`company_id`, `active`),
                CONSTRAINT `fk_jw_units_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }

        if (!accounting_repair_table_exists('jewellery_metals')) {
            db()->exec("CREATE TABLE IF NOT EXISTS `jewellery_metals` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL,
                `code` VARCHAR(20) NOT NULL,
                `name` VARCHAR(80) NOT NULL,
                `metal_kind` ENUM('metal','stone','other') NOT NULL DEFAULT 'metal',
                `track_purity` TINYINT(1) NOT NULL DEFAULT 1,
                `default_unit_id` INT UNSIGNED DEFAULT NULL,
                `sort_order` INT NOT NULL DEFAULT 0,
                `active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_jw_metal_code` (`company_id`, `code`),
                KEY `idx_jw_metals_active` (`company_id`, `active`),
                KEY `idx_jw_metals_unit` (`default_unit_id`),
                CONSTRAINT `fk_jw_metals_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_jw_metals_unit` FOREIGN KEY (`default_unit_id`) REFERENCES `jewellery_units` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }

        if (!accounting_repair_table_exists('jewellery_purities')) {
            db()->exec("CREATE TABLE IF NOT EXISTS `jewellery_purities` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL,
                `metal_id` INT UNSIGNED NOT NULL,
                `code` VARCHAR(20) NOT NULL,
                `name` VARCHAR(80) NOT NULL,
                `fineness` DECIMAL(9,4) NOT NULL DEFAULT 1000.0000,
                `is_default` TINYINT(1) NOT NULL DEFAULT 0,
                `sort_order` INT NOT NULL DEFAULT 0,
                `active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_jw_purity_code` (`company_id`, `metal_id`, `code`),
                KEY `idx_jw_purities_metal` (`metal_id`, `active`),
                CONSTRAINT `fk_jw_purities_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_jw_purities_metal` FOREIGN KEY (`metal_id`) REFERENCES `jewellery_metals` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }

        if (!accounting_repair_table_exists('jewellery_settings')) {
            db()->exec("CREATE TABLE IF NOT EXISTS `jewellery_settings` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL,
                `base_unit_id` INT UNSIGNED DEFAULT NULL,
                `default_metal_id` INT UNSIGNED DEFAULT NULL,
                `weight_precision` TINYINT UNSIGNED NOT NULL DEFAULT 4,
                `rate_precision` TINYINT UNSIGNED NOT NULL DEFAULT 2,
                `amount_precision` TINYINT UNSIGNED NOT NULL DEFAULT 2,
                `vat_rate` DECIMAL(6,2) NOT NULL DEFAULT 13.00,
                `default_vat_base` ENUM('full_value','making_only','stone_only') NOT NULL DEFAULT 'full_value',
                `making_charge_basis` ENUM('per_unit_weight','percent_of_metal','flat') NOT NULL DEFAULT 'per_unit_weight',
                `default_wastage_pct` DECIMAL(6,3) NOT NULL DEFAULT 0.000,
                `rate_source` ENUM('manual','last_known') NOT NULL DEFAULT 'last_known',
                `allow_negative_stock` TINYINT(1) NOT NULL DEFAULT 0,
                `auto_post` TINYINT(1) NOT NULL DEFAULT 1,
                `sale_no_prefix` VARCHAR(20) NOT NULL DEFAULT 'JS',
                `purchase_no_prefix` VARCHAR(20) NOT NULL DEFAULT 'JP',
                `order_no_prefix` VARCHAR(20) NOT NULL DEFAULT 'JO',
                `issue_no_prefix` VARCHAR(20) NOT NULL DEFAULT 'JI',
                `refinery_no_prefix` VARCHAR(20) NOT NULL DEFAULT 'JR',
                `masters_seeded` TINYINT(1) NOT NULL DEFAULT 0,
                `updated_by` INT UNSIGNED DEFAULT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_jw_settings_company` (`company_id`),
                KEY `idx_jw_settings_unit` (`base_unit_id`),
                KEY `idx_jw_settings_metal` (`default_metal_id`),
                CONSTRAINT `fk_jw_settings_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_jw_settings_unit` FOREIGN KEY (`base_unit_id`) REFERENCES `jewellery_units` (`id`) ON DELETE SET NULL,
                CONSTRAINT `fk_jw_settings_metal` FOREIGN KEY (`default_metal_id`) REFERENCES `jewellery_metals` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }

        if (!accounting_repair_table_exists('jewellery_daily_rates')) {
            db()->exec("CREATE TABLE IF NOT EXISTS `jewellery_daily_rates` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL,
                `rate_date` DATE NOT NULL,
                `metal_id` INT UNSIGNED NOT NULL,
                `purity_id` INT UNSIGNED NOT NULL,
                `unit_id` INT UNSIGNED NOT NULL,
                `rate_type` ENUM('market','sale','purchase') NOT NULL DEFAULT 'market',
                `rate` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
                `note` VARCHAR(190) DEFAULT NULL,
                `created_by` INT UNSIGNED DEFAULT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_jw_rate` (`company_id`, `rate_date`, `metal_id`, `purity_id`, `rate_type`),
                KEY `idx_jw_rates_lookup` (`company_id`, `metal_id`, `purity_id`, `rate_type`, `rate_date`),
                KEY `idx_jw_rates_unit` (`unit_id`),
                CONSTRAINT `fk_jw_rates_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_jw_rates_metal` FOREIGN KEY (`metal_id`) REFERENCES `jewellery_metals` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_jw_rates_purity` FOREIGN KEY (`purity_id`) REFERENCES `jewellery_purities` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_jw_rates_unit` FOREIGN KEY (`unit_id`) REFERENCES `jewellery_units` (`id`) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }

        if (!accounting_repair_table_exists('jewellery_ledger_mappings')) {
            db()->exec("CREATE TABLE IF NOT EXISTS `jewellery_ledger_mappings` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL,
                `scope` ENUM('global','category','item') NOT NULL DEFAULT 'global',
                `category` VARCHAR(120) DEFAULT NULL,
                `item_id` INT UNSIGNED DEFAULT NULL,
                `purpose` VARCHAR(60) NOT NULL,
                `ledger_id` INT UNSIGNED NOT NULL,
                `created_by` INT UNSIGNED DEFAULT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_jw_mapping_scope` (`company_id`, `scope`, `category`, `item_id`, `purpose`),
                KEY `idx_jw_mapping_lookup` (`company_id`, `purpose`, `scope`),
                KEY `idx_jw_mapping_ledger` (`ledger_id`),
                CONSTRAINT `fk_jw_mapping_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_jw_mapping_ledger` FOREIGN KEY (`ledger_id`) REFERENCES `ledgers` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
    });

    $run('Jewellery items, dual-unit stock ledger, opening stock (migration 071)', static function (): void {
        if (!accounting_repair_table_exists('jewellery_metals')) {
            return; // migration 070 has not landed yet
        }

        if (!accounting_repair_table_exists('jewellery_items')) {
            db()->exec("CREATE TABLE IF NOT EXISTS `jewellery_items` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL,
                `code` VARCHAR(60) NOT NULL,
                `name` VARCHAR(190) NOT NULL,
                `category` VARCHAR(120) DEFAULT NULL,
                `item_type` ENUM('ornament','bullion','stone','other') NOT NULL DEFAULT 'ornament',
                `metal_id` INT UNSIGNED NOT NULL,
                `purity_id` INT UNSIGNED NOT NULL,
                `unit_id` INT UNSIGNED NOT NULL,
                `track_mode` ENUM('weight','piece') NOT NULL DEFAULT 'weight',
                `gross_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
                `stone_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
                `net_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
                `wastage_pct` DECIMAL(6,3) NOT NULL DEFAULT 0.000,
                `making_charge_basis` ENUM('default','per_unit_weight','percent_of_metal','flat') NOT NULL DEFAULT 'default',
                `making_charge_rate` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
                `stone_value` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
                `vat_applicable` TINYINT(1) NOT NULL DEFAULT 0,
                `vat_base` ENUM('default','full_value','making_only','stone_only') NOT NULL DEFAULT 'default',
                `hs_code` VARCHAR(40) DEFAULT NULL,
                `hallmark` VARCHAR(60) DEFAULT NULL,
                `design_no` VARCHAR(60) DEFAULT NULL,
                `reorder_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
                `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
                `notes` TEXT DEFAULT NULL,
                `created_by` INT UNSIGNED DEFAULT NULL,
                `updated_by` INT UNSIGNED DEFAULT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_jw_item_code` (`company_id`, `code`),
                KEY `idx_jw_items_status` (`company_id`, `status`),
                KEY `idx_jw_items_metal` (`company_id`, `metal_id`, `purity_id`),
                KEY `idx_jw_items_category` (`company_id`, `category`),
                KEY `idx_jw_items_purity` (`purity_id`),
                KEY `idx_jw_items_unit` (`unit_id`),
                CONSTRAINT `fk_jw_items_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_jw_items_metal` FOREIGN KEY (`metal_id`) REFERENCES `jewellery_metals` (`id`) ON DELETE RESTRICT,
                CONSTRAINT `fk_jw_items_purity` FOREIGN KEY (`purity_id`) REFERENCES `jewellery_purities` (`id`) ON DELETE RESTRICT,
                CONSTRAINT `fk_jw_items_unit` FOREIGN KEY (`unit_id`) REFERENCES `jewellery_units` (`id`) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }

        if (!accounting_repair_table_exists('jewellery_stock_txns')) {
            db()->exec("CREATE TABLE IF NOT EXISTS `jewellery_stock_txns` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL,
                `fiscal_year_id` INT UNSIGNED DEFAULT NULL,
                `item_id` INT UNSIGNED NOT NULL,
                `txn_type` ENUM('opening','purchase','purchase_return','sale','sales_return','issue_karigar',
                                'receive_karigar','issue_refinery','receive_refinery','wastage','adjustment','transfer')
                           NOT NULL DEFAULT 'adjustment',
                `direction` ENUM('in','out') NOT NULL,
                `txn_date` DATE NOT NULL,
                `ref_no` VARCHAR(120) DEFAULT NULL,
                `holder_type` ENUM('stock','karigar','refinery','customer') NOT NULL DEFAULT 'stock',
                `holder_id` INT UNSIGNED DEFAULT NULL,
                `metal_id` INT UNSIGNED NOT NULL,
                `purity_id` INT UNSIGNED NOT NULL,
                `unit_id` INT UNSIGNED NOT NULL,
                `qty_pieces` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
                `gross_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
                `fine_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
                `rate` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
                `amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
                `source_type` VARCHAR(40) DEFAULT NULL,
                `source_id` INT UNSIGNED DEFAULT NULL,
                `voucher_id` INT UNSIGNED DEFAULT NULL,
                `party_id` INT UNSIGNED DEFAULT NULL,
                `notes` VARCHAR(255) DEFAULT NULL,
                `created_by` INT UNSIGNED DEFAULT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_jw_stock_item_date` (`company_id`, `item_id`, `txn_date`),
                KEY `idx_jw_stock_date` (`company_id`, `txn_date`),
                KEY `idx_jw_stock_holder` (`company_id`, `holder_type`, `holder_id`),
                KEY `idx_jw_stock_metal` (`company_id`, `metal_id`, `purity_id`, `txn_date`),
                KEY `idx_jw_stock_source` (`company_id`, `source_type`, `source_id`),
                KEY `idx_jw_stock_voucher` (`voucher_id`),
                KEY `idx_jw_stock_party` (`party_id`),
                KEY `idx_jw_stock_purity` (`purity_id`),
                KEY `idx_jw_stock_unit` (`unit_id`),
                CONSTRAINT `fk_jw_stock_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_jw_stock_fy` FOREIGN KEY (`fiscal_year_id`) REFERENCES `fiscal_years` (`id`) ON DELETE SET NULL,
                CONSTRAINT `fk_jw_stock_item` FOREIGN KEY (`item_id`) REFERENCES `jewellery_items` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_jw_stock_metal` FOREIGN KEY (`metal_id`) REFERENCES `jewellery_metals` (`id`) ON DELETE RESTRICT,
                CONSTRAINT `fk_jw_stock_purity` FOREIGN KEY (`purity_id`) REFERENCES `jewellery_purities` (`id`) ON DELETE RESTRICT,
                CONSTRAINT `fk_jw_stock_unit` FOREIGN KEY (`unit_id`) REFERENCES `jewellery_units` (`id`) ON DELETE RESTRICT,
                CONSTRAINT `fk_jw_stock_voucher` FOREIGN KEY (`voucher_id`) REFERENCES `vouchers` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }

        if (!accounting_repair_table_exists('jewellery_opening_stock')) {
            db()->exec("CREATE TABLE IF NOT EXISTS `jewellery_opening_stock` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL,
                `fiscal_year_id` INT UNSIGNED NOT NULL,
                `item_id` INT UNSIGNED NOT NULL,
                `as_on` DATE NOT NULL,
                `qty_pieces` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
                `gross_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
                `fine_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
                `unit_id` INT UNSIGNED NOT NULL,
                `rate` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
                `amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
                `status` ENUM('draft','posted') NOT NULL DEFAULT 'draft',
                `voucher_id` INT UNSIGNED DEFAULT NULL,
                `stock_txn_id` INT UNSIGNED DEFAULT NULL,
                `notes` VARCHAR(255) DEFAULT NULL,
                `posted_by` INT UNSIGNED DEFAULT NULL,
                `posted_at` DATETIME DEFAULT NULL,
                `created_by` INT UNSIGNED DEFAULT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_jw_opening_item_fy` (`company_id`, `fiscal_year_id`, `item_id`),
                KEY `idx_jw_opening_status` (`company_id`, `fiscal_year_id`, `status`),
                KEY `idx_jw_opening_item` (`item_id`),
                KEY `idx_jw_opening_unit` (`unit_id`),
                KEY `idx_jw_opening_voucher` (`voucher_id`),
                CONSTRAINT `fk_jw_opening_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_jw_opening_fy` FOREIGN KEY (`fiscal_year_id`) REFERENCES `fiscal_years` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_jw_opening_item` FOREIGN KEY (`item_id`) REFERENCES `jewellery_items` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_jw_opening_unit` FOREIGN KEY (`unit_id`) REFERENCES `jewellery_units` (`id`) ON DELETE RESTRICT,
                CONSTRAINT `fk_jw_opening_voucher` FOREIGN KEY (`voucher_id`) REFERENCES `vouchers` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }

        accounting_repair_add_constraint('jewellery_ledger_mappings', 'fk_jw_mapping_item',
            '`fk_jw_mapping_item` FOREIGN KEY (`item_id`) REFERENCES `jewellery_items` (`id`) ON DELETE CASCADE');
    });

    $run('Jewellery purchases, sales, exchange and bill-wise accounting (migration 072)', static function (): void {
        if (!accounting_repair_table_exists('jewellery_items') || !accounting_repair_table_exists('accounting_parties')) {
            return; // migration 071 / the parties module has not landed yet
        }
        accounting_repair_run_migration_file('072_jewellery_trading.sql', [
            'jewellery_purchases', 'jewellery_purchase_lines', 'jewellery_sales', 'jewellery_sale_lines',
            'jewellery_sale_exchanges', 'jewellery_bills', 'jewellery_settlements', 'jewellery_settlement_allocations',
        ]);
    });

    $run('Jewellery karigars, orders, assignments and refinery jobs (migration 073)', static function (): void {
        if (!accounting_repair_table_exists('jewellery_sales')) {
            return; // migration 072 has not landed yet (orders reference sales)
        }
        accounting_repair_run_migration_file('073_jewellery_workshop.sql', [
            'jewellery_karigars', 'jewellery_orders', 'jewellery_order_assignments',
            'jewellery_order_receipts', 'jewellery_refinery_jobs',
        ]);
    });

    $run('Jewellery items merged into the shared item master (migration 074)', static function (): void {
        if (!accounting_repair_table_exists('jewellery_metals') || !accounting_repair_table_exists('inventory_items')) {
            return; // migration 070 / the inventory module has not landed yet
        }
        accounting_repair_run_migration_file('074_jewellery_shared_item_master.sql', ['jewellery_item_profiles']);
        if (!accounting_repair_table_exists('jewellery_item_profiles')) {
            return;
        }

        // Every table that pointed at the old jewellery_items master, and the
        // constraint that held it there.
        $children = [
            ['jewellery_stock_txns', 'item_id', 'fk_jw_stock_item', 'RESTRICT'],
            ['jewellery_opening_stock', 'item_id', 'fk_jw_opening_item', 'CASCADE'],
            ['jewellery_purchase_lines', 'item_id', 'fk_jw_pline_item', 'RESTRICT'],
            ['jewellery_sale_lines', 'item_id', 'fk_jw_sline_item', 'RESTRICT'],
            ['jewellery_sale_exchanges', 'item_id', 'fk_jw_exch_item', 'RESTRICT'],
            ['jewellery_settlements', 'item_id', 'fk_jw_settle_item', 'SET NULL'],
            ['jewellery_orders', 'item_id', 'fk_jw_orders_item', 'SET NULL'],
            ['jewellery_order_assignments', 'item_id', 'fk_jw_assign_item', 'RESTRICT'],
            ['jewellery_order_receipts', 'received_item_id', 'fk_jw_receipts_item', 'RESTRICT'],
            ['jewellery_refinery_jobs', 'item_id', 'fk_jw_refjobs_item', 'RESTRICT'],
            ['jewellery_refinery_jobs', 'received_item_id', 'fk_jw_refjobs_ritem', 'SET NULL'],
            ['jewellery_ledger_mappings', 'item_id', 'fk_jw_mapping_item', 'CASCADE'],
        ];

        // Nothing to do once jewellery_items is gone — this step already ran.
        if (!accounting_repair_table_exists('jewellery_items')) {
            return;
        }

        // 1. Move every jewellery item onto the shared master, remembering
        //    where each one landed so the children can be repointed.
        $idMap = [];
        $rows = db()->query('SELECT * FROM jewellery_items ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $companyId = (int) $row['company_id'];
            $unitCode = '';
            if (accounting_repair_table_exists('jewellery_units')) {
                $unitStmt = db()->prepare('SELECT code FROM jewellery_units WHERE id = :id LIMIT 1');
                $unitStmt->execute(['id' => (int) $row['unit_id']]);
                $unitCode = (string) ($unitStmt->fetchColumn() ?: '');
            }
            // A jewellery type maps onto the generic classification the core
            // module reasons about; the precise one is kept on the profile.
            $genericType = match ((string) $row['item_type']) {
                'ornament' => 'finished_good',
                'bullion', 'stone' => 'raw_material',
                default => 'stock',
            };

            // The SKU must stay unique per company. A collision with an
            // existing inventory item means that code is already taken, so
            // suffix rather than fail the whole upgrade.
            $sku = (string) $row['code'];
            $clash = db()->prepare('SELECT COUNT(*) FROM inventory_items WHERE company_id = :cid AND sku = :sku');
            $clash->execute(['cid' => $companyId, 'sku' => $sku]);
            if ((int) $clash->fetchColumn() > 0) {
                $sku = substr($sku, 0, 72) . '-JW' . (int) $row['id'];
            }

            db()->prepare('INSERT INTO inventory_items (company_id, ledger_id, sku, name, category, item_type,
                    unit, hs_code, tax_rate, reorder_level, status, notes)
                VALUES (:cid, :ledger, :sku, :name, :category, :type, :unit, :hs, :tax, :reorder, :status, :notes)')
                ->execute([
                    'cid' => $companyId,
                    'ledger' => null,
                    'sku' => $sku,
                    'name' => (string) $row['name'],
                    'category' => ($row['category'] ?? '') !== '' ? $row['category'] : null,
                    'type' => $genericType,
                    'unit' => $unitCode !== '' ? $unitCode : 'pcs',
                    'hs' => ($row['hs_code'] ?? '') !== '' ? $row['hs_code'] : null,
                    'tax' => 0,
                    'reorder' => 0,
                    'status' => (string) $row['status'] === 'inactive' ? 'inactive' : 'active',
                    'notes' => $row['notes'] ?? null,
                ]);
            $newId = (int) db()->lastInsertId();
            $idMap[(int) $row['id']] = $newId;

            db()->prepare('INSERT INTO jewellery_item_profiles (inventory_item_id, company_id, metal_id, purity_id,
                    unit_id, jewellery_type, track_mode, gross_weight, stone_weight, net_weight, wastage_pct,
                    making_charge_basis, making_charge_rate, stone_value, vat_applicable, vat_base,
                    hallmark, design_no, reorder_weight)
                VALUES (:id, :cid, :metal, :purity, :unit, :jtype, :track, :gross, :stone, :net, :wastage,
                    :basis, :rate, :svalue, :vat, :vbase, :hallmark, :design, :reorder)')
                ->execute([
                    'id' => $newId, 'cid' => $companyId,
                    'metal' => (int) $row['metal_id'], 'purity' => (int) $row['purity_id'], 'unit' => (int) $row['unit_id'],
                    'jtype' => (string) $row['item_type'], 'track' => (string) $row['track_mode'],
                    'gross' => $row['gross_weight'], 'stone' => $row['stone_weight'], 'net' => $row['net_weight'],
                    'wastage' => $row['wastage_pct'], 'basis' => (string) $row['making_charge_basis'],
                    'rate' => $row['making_charge_rate'], 'svalue' => $row['stone_value'],
                    'vat' => (int) $row['vat_applicable'], 'vbase' => (string) $row['vat_base'],
                    'hallmark' => $row['hallmark'] ?? null, 'design' => $row['design_no'] ?? null,
                    'reorder' => $row['reorder_weight'],
                ]);
        }

        // 2. Repoint every child at the shared master. The constraint has to
        //    come off before the ids can be rewritten, and the rewrite has to
        //    happen before the new constraint can be trusted.
        foreach ($children as [$table, $column, $constraint, $onDelete]) {
            if (!accounting_repair_table_exists($table)) {
                continue;
            }
            if (accounting_repair_constraint_exists($table, $constraint)) {
                db()->exec('ALTER TABLE `' . $table . '` DROP FOREIGN KEY `' . $constraint . '`');
            }
            foreach ($idMap as $oldId => $newId) {
                db()->prepare('UPDATE `' . $table . '` SET `' . $column . '` = :new WHERE `' . $column . '` = :old')
                    ->execute(['new' => $newId, 'old' => $oldId]);
            }
            // Any id that survived the remap points at an item that no longer
            // exists; null it rather than let the new constraint reject it.
            db()->exec('UPDATE `' . $table . '` c LEFT JOIN inventory_items i ON i.id = c.`' . $column . '`
                SET c.`' . $column . '` = NULL WHERE c.`' . $column . '` IS NOT NULL AND i.id IS NULL');
            accounting_repair_add_constraint($table, $constraint,
                '`' . $constraint . '` FOREIGN KEY (`' . $column . '`) REFERENCES `inventory_items` (`id`) ON DELETE ' . $onDelete);
        }

        // 3. The old master has no rows left worth keeping.
        db()->exec('DROP TABLE IF EXISTS `jewellery_items`');
    });

    $run('Jewellery ledger mappings merged into the shared table (migration 075)', static function (): void {
        if (!accounting_repair_table_exists('jewellery_ledger_mappings')
            || !accounting_repair_table_exists('inventory_ledger_mappings')) {
            return; // nothing to move, or already moved
        }

        // The jewellery purpose -> canonical purpose translation. Kept here
        // rather than pulled from jewellery_engine.php so the repair layer
        // stays self-contained and runnable before the module is loaded.
        $canonical = [
            'stock_metal' => 'inventory_asset',
            'stock_finished' => 'finished_goods',
            'sales_metal' => 'sales_revenue',
            'vat_output' => 'tax_output',
            'vat_input' => 'tax_input',
            'stock_gain' => 'inventory_gain',
            'stock_loss' => 'inventory_loss',
        ];

        foreach (db()->query('SELECT * FROM jewellery_ledger_mappings')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $purpose = $canonical[(string) $row['purpose']] ?? (string) $row['purpose'];
            // An existing core mapping wins: it is the one the Inventory module
            // has been posting through, so silently overwriting it would move
            // live postings to a different ledger.
            $exists = db()->prepare('SELECT COUNT(*) FROM inventory_ledger_mappings
                WHERE company_id = :cid AND scope = :scope AND purpose = :p
                  AND (category <=> :cat) AND (item_id <=> :iid)');
            $exists->execute([
                'cid' => (int) $row['company_id'], 'scope' => (string) $row['scope'], 'p' => $purpose,
                'cat' => $row['category'], 'iid' => $row['item_id'],
            ]);
            if ((int) $exists->fetchColumn() > 0) {
                continue;
            }
            // The ledger must still exist and still belong to that company.
            $ledgerOk = db()->prepare('SELECT COUNT(*) FROM ledgers WHERE id = :lid AND company_id = :cid');
            $ledgerOk->execute(['lid' => (int) $row['ledger_id'], 'cid' => (int) $row['company_id']]);
            if ((int) $ledgerOk->fetchColumn() === 0) {
                continue;
            }
            db()->prepare('INSERT INTO inventory_ledger_mappings (company_id, scope, category, item_id, purpose, ledger_id, created_by)
                VALUES (:cid, :scope, :cat, :iid, :p, :lid, :by)')
                ->execute([
                    'cid' => (int) $row['company_id'], 'scope' => (string) $row['scope'],
                    'cat' => $row['category'], 'iid' => $row['item_id'], 'p' => $purpose,
                    'lid' => (int) $row['ledger_id'], 'by' => $row['created_by'] ?? null,
                ]);
        }

        db()->exec('DROP TABLE IF EXISTS `jewellery_ledger_mappings`');
    });

    $run('Jewellery opening stock merged into the shared item opening (migration 076)', static function (): void {
        if (!accounting_repair_table_exists('jewellery_opening_stock')
            || !accounting_repair_table_exists('inventory_items')) {
            return; // already merged, or the module never landed
        }

        // While jewellery kept its own opening table, an item edited on the core
        // Inventory form posted an `inventory_opening` voucher and the jewellery
        // screen posted a `jewellery_opening` one — two vouchers for one
        // opening. Move the numbers onto the item, drop the jewellery-side
        // voucher and movement, and let the shared poster own it from here.
        foreach (db()->query('SELECT * FROM jewellery_opening_stock ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $companyId = (int) $row['company_id'];
            $itemId = (int) $row['item_id'];
            $owns = db()->prepare('SELECT COUNT(*) FROM inventory_items WHERE id = :id AND company_id = :cid');
            $owns->execute(['id' => $itemId, 'cid' => $companyId]);
            if ((int) $owns->fetchColumn() === 0) {
                continue;
            }
            // Only carry a posted opening across; a draft was never in the books.
            if ((string) $row['status'] === 'posted') {
                db()->prepare('UPDATE inventory_items SET opening_qty = :qty, opening_amount = :amount
                    WHERE id = :id AND company_id = :cid')
                    ->execute([
                        'qty' => (float) $row['gross_weight'], 'amount' => (float) $row['amount'],
                        'id' => $itemId, 'cid' => $companyId,
                    ]);
            }
            // Retire the jewellery-side voucher; the shared poster re-creates
            // an equivalent one the first time the opening is saved or the item
            // is edited, and leaving both would double-count the metal.
            if ((int) ($row['voucher_id'] ?? 0) > 0) {
                db()->prepare('DELETE FROM vouchers WHERE id = :id AND company_id = :cid')
                    ->execute(['id' => (int) $row['voucher_id'], 'cid' => $companyId]);
            }
            // Re-point the metal movement at the shared source so it is found
            // and replaced by the new path instead of being orphaned.
            db()->prepare("UPDATE jewellery_stock_txns SET source_type = 'inventory_opening', source_id = :iid
                WHERE company_id = :cid AND source_type = 'jewellery_opening' AND source_id = :oid")
                ->execute(['iid' => $itemId, 'cid' => $companyId, 'oid' => (int) $row['id']]);
        }

        db()->exec('DROP TABLE IF EXISTS `jewellery_opening_stock`');
    });

    $run('Per-kaligad metal ledger (migration 077)', static function (): void {
        if (!accounting_repair_table_exists('jewellery_karigars')) {
            return;
        }
        accounting_repair_add_column('jewellery_karigars', 'metal_ledger_id',
            '`metal_ledger_id` INT UNSIGNED DEFAULT NULL AFTER `party_id`');
        accounting_repair_add_index('jewellery_karigars', 'idx_jw_karigars_metal_ledger',
            'KEY `idx_jw_karigars_metal_ledger` (`metal_ledger_id`)');
        accounting_repair_add_constraint('jewellery_karigars', 'fk_jw_karigars_metal_ledger',
            '`fk_jw_karigars_metal_ledger` FOREIGN KEY (`metal_ledger_id`) REFERENCES `ledgers` (`id`) ON DELETE SET NULL');
    });

    $run('Remember the holder ledger an issue debited (migration 078)', static function (): void {
        // Backfilling reads the issue voucher's own debit leg on an asset
        // ledger — that IS the holder ledger that was used. Anything whose
        // issue posted no voucher stays NULL, which correctly says the value
        // never left the item's own stock.
        $backfill = static function (string $table, string $alias): void {
            if (!accounting_repair_table_exists('voucher_entries') || !accounting_repair_table_exists('ledgers')) {
                return;
            }
            db()->exec("UPDATE `$table` $alias
                JOIN `voucher_entries` e ON e.voucher_id = $alias.issue_voucher_id AND e.entry_type = 'debit'
                JOIN `ledgers` l ON l.id = e.ledger_id AND l.type = 'asset'
                SET $alias.metal_ledger_id = e.ledger_id
                WHERE $alias.metal_ledger_id IS NULL AND $alias.issue_voucher_id IS NOT NULL");
        };

        foreach ([
            ['jewellery_order_assignments', 'a', 'idx_jw_assign_metal_ledger', 'fk_jw_assign_metal_ledger'],
            ['jewellery_refinery_jobs', 'j', 'idx_jw_refjobs_metal_ledger', 'fk_jw_refjobs_metal_ledger'],
        ] as [$table, $alias, $index, $constraint]) {
            if (!accounting_repair_table_exists($table)) {
                continue;
            }
            accounting_repair_add_column($table, 'metal_ledger_id',
                '`metal_ledger_id` INT UNSIGNED DEFAULT NULL AFTER `issue_voucher_id`');
            accounting_repair_add_index($table, $index, 'KEY `' . $index . '` (`metal_ledger_id`)');
            accounting_repair_add_constraint($table, $constraint,
                '`' . $constraint . '` FOREIGN KEY (`metal_ledger_id`) REFERENCES `ledgers` (`id`) ON DELETE SET NULL');
            $backfill($table, $alias);
        }
    });

    $run('Tax framework (migration 079)', static function (): void {
        accounting_repair_run_migration_file('079_jewellery_tax_framework.sql',
            ['jewellery_taxes', 'jewellery_item_taxes', 'jewellery_line_taxes']);

        // Seed the two taxes in force today into any company that has the
        // module on and no tax register yet. Existing registers are never
        // touched — a shop that retired one must not have it reinstated.
        if (!accounting_repair_table_exists('jewellery_taxes') || !accounting_repair_table_exists('jewellery_settings')) {
            return;
        }
        // This file is included by pages that have never heard of the jewellery
        // module, so the engine has to be loaded here rather than assumed. It
        // was assumed, and every one of those pages showed a repair warning.
        if (!function_exists('jewellery_seed_taxes')) {
            require_once __DIR__ . '/jewellery_engine.php';
        }
        $companies = db()->query('SELECT company_id FROM jewellery_settings')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($companies as $companyId) {
            jewellery_seed_taxes((int) $companyId);
        }
    });

    $run('Order advances (migration 080)', static function (): void {
        if (accounting_repair_table_exists('jewellery_settlements')) {
            accounting_repair_add_column('jewellery_settlements', 'order_id',
                '`order_id` INT UNSIGNED DEFAULT NULL AFTER `party_id`');
            accounting_repair_add_column('jewellery_settlements', 'is_advance',
                '`is_advance` TINYINT(1) NOT NULL DEFAULT 0 AFTER `order_id`');
            accounting_repair_add_index('jewellery_settlements', 'idx_jw_settle_order',
                'KEY `idx_jw_settle_order` (`company_id`, `order_id`)');
            if (accounting_repair_table_exists('jewellery_orders')) {
                accounting_repair_add_constraint('jewellery_settlements', 'fk_jw_settle_order',
                    '`fk_jw_settle_order` FOREIGN KEY (`order_id`) REFERENCES `jewellery_orders` (`id`) ON DELETE SET NULL');
            }
        }
        if (accounting_repair_table_exists('jewellery_sales')) {
            accounting_repair_add_column('jewellery_sales', 'advance_amount',
                '`advance_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `exchange_amount`');
        }
        if (accounting_repair_table_exists('accounting_parties')) {
            accounting_repair_add_column('accounting_parties', 'advance_ledger_id',
                '`advance_ledger_id` INT UNSIGNED DEFAULT NULL');
            accounting_repair_add_index('accounting_parties', 'idx_parties_advance_ledger',
                'KEY `idx_parties_advance_ledger` (`advance_ledger_id`)');
        }
    });

    $run('Opening stock import staging (migration 081)', static function (): void {
        accounting_repair_run_migration_file('081_opening_stock_import.sql',
            ['inventory_opening_imports', 'inventory_opening_import_rows']);
    });

    $run('Chart of accounts import staging (migration 104)', static function (): void {
        accounting_repair_run_migration_file('104_coa_import.sql',
            ['coa_imports', 'coa_import_rows']);
    });

    $run('Jewellery tag printing settings (migration 105)', static function (): void {
        // Column-only migration, so it cannot go through
        // accounting_repair_run_migration_file() — that runner fires only when a
        // whole TABLE is missing.
        if (!accounting_repair_table_exists('jewellery_settings')) {
            return;
        }
        $tagColumns = [
            'tag_shop_name' => '`tag_shop_name` VARCHAR(60) DEFAULT NULL',
            'tag_width_mm' => '`tag_width_mm` DECIMAL(6,1) NOT NULL DEFAULT 12.0',
            'tag_height_mm' => '`tag_height_mm` DECIMAL(6,1) NOT NULL DEFAULT 75.0',
            'tag_gap_mm' => '`tag_gap_mm` DECIMAL(6,1) NOT NULL DEFAULT 3.0',
            'tag_wing_mm' => '`tag_wing_mm` DECIMAL(6,1) NOT NULL DEFAULT 22.0',
            'tag_dpi' => '`tag_dpi` SMALLINT UNSIGNED NOT NULL DEFAULT 203',
            'tag_darkness' => '`tag_darkness` TINYINT UNSIGNED NOT NULL DEFAULT 15',
            'tag_speed' => '`tag_speed` TINYINT UNSIGNED NOT NULL DEFAULT 3',
            'tag_rotation' => "`tag_rotation` ENUM('0','90','180','270') NOT NULL DEFAULT '0'",
            'tag_offset_x_mm' => '`tag_offset_x_mm` DECIMAL(5,1) NOT NULL DEFAULT 0.0',
            'tag_offset_y_mm' => '`tag_offset_y_mm` DECIMAL(5,1) NOT NULL DEFAULT 0.0',
            'tag_media' => "`tag_media` ENUM('gap','continuous','mark') NOT NULL DEFAULT 'gap'",
            'tag_hide_empty_stone' => '`tag_hide_empty_stone` TINYINT(1) NOT NULL DEFAULT 1',
        ];
        foreach ($tagColumns as $column => $ddl) {
            accounting_repair_add_column('jewellery_settings', $column, $ddl);
        }
    });

    $run('Invoice fields and tax bases (migration 083)', static function (): void {
        // Column-only migration, so it cannot go through
        // accounting_repair_run_migration_file() — that runner deliberately
        // fires only when a whole TABLE is missing.
        $lineColumns = [
            'wastage_weight' => '`wastage_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000 AFTER `wastage_pct`',
            'total_weight' => '`total_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000 AFTER `wastage_weight`',
            'diamond_carat' => '`diamond_carat` DECIMAL(18,4) NOT NULL DEFAULT 0.0000 AFTER `stone_amount`',
            'diamond_amount' => '`diamond_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `diamond_carat`',
            'other_diamond_carat' => '`other_diamond_carat` DECIMAL(18,4) NOT NULL DEFAULT 0.0000 AFTER `diamond_amount`',
            'other_diamond_amount' => '`other_diamond_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `other_diamond_carat`',
            'stone_carat' => '`stone_carat` DECIMAL(18,4) NOT NULL DEFAULT 0.0000 AFTER `other_diamond_amount`',
        ];
        foreach (['jewellery_sale_lines', 'jewellery_purchase_lines'] as $table) {
            if (!accounting_repair_table_exists($table)) {
                continue;
            }
            foreach ($lineColumns as $column => $ddl) {
                accounting_repair_add_column($table, $column, $ddl);
            }
            // A line written before this carries no wastage weight, so the
            // weight it was priced on is simply its net.
            db()->exec("UPDATE `$table` SET `total_weight` = `net_weight`
                WHERE `total_weight` = 0 AND `net_weight` <> 0");
        }

        $headerColumns = [
            'non_taxable_amount' => '`non_taxable_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `taxable_amount`',
            'sd_taxable_amount' => '`sd_taxable_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `non_taxable_amount`',
            'vatable_amount' => '`vatable_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `sd_taxable_amount`',
            'diamond_amount' => '`diamond_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `stone_amount`',
        ];
        foreach (['jewellery_sales', 'jewellery_purchases'] as $table) {
            if (!accounting_repair_table_exists($table)) {
                continue;
            }
            foreach ($headerColumns as $column => $ddl) {
                accounting_repair_add_column($table, $column, $ddl);
            }
        }
        if (accounting_repair_table_exists('jewellery_sales')) {
            accounting_repair_add_column('jewellery_sales', 'sales_person',
                '`sales_person` VARCHAR(120) DEFAULT NULL AFTER `customer_name`');
        }

        // Diamonds and stones are taxed alike, so they need one base to be
        // charged on. Widening an ENUM keeps every existing value.
        if (accounting_repair_table_exists('jewellery_taxes')) {
            $base = db()->query("SHOW COLUMNS FROM `jewellery_taxes` LIKE 'base'")->fetch(PDO::FETCH_ASSOC);
            if ($base && !str_contains((string) ($base['Type'] ?? ''), 'stone_diamond')) {
                db()->exec("ALTER TABLE `jewellery_taxes`
                    MODIFY COLUMN `base` ENUM('metal','making','stone','wastage','metal_making',
                        'metal_wastage_making','stone_diamond','subtotal','subtotal_with_taxes')
                    NOT NULL DEFAULT 'subtotal'");
            }
        }
    });

    $run('Tender breakdown and bill references (migration 084)', static function (): void {
        if (!accounting_repair_table_exists('jewellery_sales')) {
            return;
        }
        foreach ([
            'paid_cash' => '`paid_cash` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `received_amount`',
            'paid_card' => '`paid_card` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `paid_cash`',
            'paid_cheque' => '`paid_cheque` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `paid_card`',
            'paid_qr' => '`paid_qr` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `paid_cheque`',
            'customer_ref' => '`customer_ref` VARCHAR(60) DEFAULT NULL AFTER `sales_person`',
            'tran_date_bs' => '`tran_date_bs` VARCHAR(20) DEFAULT NULL AFTER `sale_date`',
            'remarks' => '`remarks` VARCHAR(255) DEFAULT NULL AFTER `narration`',
        ] as $column => $ddl) {
            accounting_repair_add_column('jewellery_sales', $column, $ddl);
        }
    });

    $run('Multi-item orders with a real quote (migration 087)', static function (): void {
        if (!accounting_repair_table_exists('jewellery_orders')) {
            return;
        }
        db()->exec("CREATE TABLE IF NOT EXISTS `jewellery_order_lines` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `order_id` INT UNSIGNED NOT NULL,
            `company_id` INT UNSIGNED NOT NULL,
            `item_id` INT UNSIGNED NOT NULL,
            `purity_id` INT UNSIGNED NOT NULL,
            `unit_id` INT UNSIGNED NOT NULL,
            `qty_pieces` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
            `gross_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
            `stone_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
            `net_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
            `fine_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
            `rate` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
            `metal_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            `wastage_pct` DECIMAL(9,3) NOT NULL DEFAULT 0.000,
            `wastage_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
            `total_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
            `wastage_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            `making_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            `stone_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            `diamond_carat` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
            `diamond_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            `other_diamond_carat` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
            `other_diamond_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            `stone_carat` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
            `vat_base` ENUM('none','full_value','making_only','stone_only','stone_diamond') NOT NULL DEFAULT 'none',
            `vat_rate` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
            `vat_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            `tax_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            `allocated_adjust` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            `line_total` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            `notes` VARCHAR(255) DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_jw_oline_order` (`order_id`),
            KEY `idx_jw_oline_item` (`company_id`, `item_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        foreach ([
            'metal_amount' => '`metal_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `making_rate`',
            'wastage_amount' => '`wastage_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `metal_amount`',
            'making_amount' => '`making_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `wastage_amount`',
            'stone_amount' => '`stone_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `making_amount`',
            'diamond_amount' => '`diamond_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `stone_amount`',
            'other_charges' => '`other_charges` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `diamond_amount`',
            'discount' => '`discount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `other_charges`',
            'taxable_amount' => '`taxable_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `discount`',
            'non_taxable_amount' => '`non_taxable_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `taxable_amount`',
            'sd_taxable_amount' => '`sd_taxable_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `non_taxable_amount`',
            'vatable_amount' => '`vatable_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `sd_taxable_amount`',
            'vat_amount' => '`vat_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `vatable_amount`',
            'tax_amount' => '`tax_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `vat_amount`',
            'manual_tax_amount' => '`manual_tax_amount` DECIMAL(18,2) DEFAULT NULL AFTER `tax_amount`',
            'total_amount' => '`total_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `manual_tax_amount`',
        ] as $column => $ddl) {
            accounting_repair_add_column('jewellery_orders', $column, $ddl);
        }

        // Carry every order already taken into a line, priced at zero — the old
        // order never held a metal rate, and inventing one would put a figure
        // in front of a customer that nobody quoted.
        db()->exec("INSERT INTO `jewellery_order_lines`
                (`order_id`, `company_id`, `item_id`, `purity_id`, `unit_id`, `qty_pieces`,
                 `gross_weight`, `net_weight`, `fine_weight`, `total_weight`)
            SELECT o.`id`, o.`company_id`, o.`item_id`, o.`purity_id`, o.`unit_id`, 1,
                   o.`expected_gross_weight`, o.`expected_gross_weight`, o.`expected_fine_weight`,
                   o.`expected_gross_weight`
              FROM `jewellery_orders` o
             WHERE o.`item_id` IS NOT NULL
               AND NOT EXISTS (SELECT 1 FROM `jewellery_order_lines` l WHERE l.`order_id` = o.`id`)");
    });

    $run('Per-item kaligad and delivery date on orders (migration 088)', static function (): void {
        if (!accounting_repair_table_exists('jewellery_order_lines')
            || !accounting_repair_table_exists('jewellery_order_assignments')) {
            return;
        }
        foreach ([
            'karigar_id' => '`karigar_id` INT UNSIGNED DEFAULT NULL AFTER `item_id`',
            'delivery_date' => '`delivery_date` DATE DEFAULT NULL AFTER `karigar_id`',
            'assignment_id' => '`assignment_id` INT UNSIGNED DEFAULT NULL AFTER `delivery_date`',
        ] as $column => $ddl) {
            accounting_repair_add_column('jewellery_order_lines', $column, $ddl);
        }
        accounting_repair_add_column('jewellery_order_assignments', 'order_line_id',
            '`order_line_id` INT UNSIGNED DEFAULT NULL AFTER `order_id`');

        // Existing lines inherit the order's single promise, so nothing already
        // taken loses its date the moment the column appears.
        db()->exec("UPDATE `jewellery_order_lines` l
            INNER JOIN `jewellery_orders` o ON o.`id` = l.`order_id`
               SET l.`delivery_date` = o.`delivery_date`
             WHERE l.`delivery_date` IS NULL AND o.`delivery_date` IS NOT NULL");
    });

    $run('Saved line templates (migration 089)', static function (): void {
        db()->exec("CREATE TABLE IF NOT EXISTS `jewellery_line_templates` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `company_id` INT UNSIGNED NOT NULL,
            `doc_type` ENUM('sale','purchase') NOT NULL DEFAULT 'sale',
            `name` VARCHAR(120) NOT NULL,
            `lines_json` MEDIUMTEXT NOT NULL,
            `line_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `created_by` INT UNSIGNED DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_jw_template` (`company_id`, `doc_type`, `name`),
            KEY `idx_jw_template_company` (`company_id`, `doc_type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    });

    $run('Metal the refiner supplied of his own (migration 091)', static function (): void {
        // A furnace cannot make gold, so more fine weight coming back than went
        // out means the refiner added some of his own — usually to settle on a
        // round bar. That used to be refused; it is a purchase from him, and the
        // job now records it the way it already records the refining loss.
        if (!accounting_repair_table_exists('jewellery_refinery_jobs')) {
            return;
        }
        accounting_repair_add_column('jewellery_refinery_jobs', 'surplus_fine_weight',
            '`surplus_fine_weight` DECIMAL(18,4) NOT NULL DEFAULT 0 AFTER `loss_amount`');
        accounting_repair_add_column('jewellery_refinery_jobs', 'surplus_amount',
            '`surplus_amount` DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER `surplus_fine_weight`');
    });

    $run('Recompute order statuses from all their items (migration 090)', static function (): void {
        // The status used to move on the FIRST issue and the FIRST piece back,
        // so a five-piece order with one ring returned read as fully received
        // and turned up on the ready-to-deliver list. The engine now derives it
        // from every item; this brings already-stored orders into line.
        //
        // Only 'assigned'/'received' orders are touched — delivered and
        // cancelled are a person's decision about the whole order — and only
        // those with item rows, since a single-item order gave the same answer
        // under the old rule anyway.
        if (!accounting_repair_table_exists('jewellery_orders')
            || !accounting_repair_table_exists('jewellery_order_lines')
            || !accounting_repair_table_exists('jewellery_order_assignments')) {
            return;
        }
        // 'partially_received' once migration 093 has widened the enum to hold
        // it; the old 'assigned' before that. This step runs BEFORE the 093
        // step on a fresh upgrade, so the first pass writes the old word and
        // 093's own backfill immediately corrects it — every later pass writes
        // the right word directly.
        $status = db()->query("SHOW COLUMNS FROM `jewellery_orders` LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
        $partial = stripos((string) ($status['Type'] ?? ''), "'partially_received'") !== false
            ? 'partially_received' : 'assigned';
        // An item taken off the Ready to Sale shelf (migration 106) counts as
        // already back, exactly as jewellery_sync_order_status() counts it.
        // This step runs on every page load, so a rule it does not know is a
        // rule it silently undoes: without the clause below it knocked every
        // shelf order back to 'confirmed' seconds after it was written, and the
        // customer's ring dropped off the ready-to-deliver board.
        $shelf = accounting_repair_column_exists('jewellery_order_lines', 'source')
            ? "WHEN l.`source` = 'stock' THEN 1 " : '';
        db()->exec("UPDATE `jewellery_orders` o
            INNER JOIN (
                SELECT l.`order_id`,
                       COUNT(*) AS total_items,
                       SUM(CASE $shelf WHEN a.`id` IS NOT NULL AND a.`status` <> 'cancelled' THEN 1 ELSE 0 END) AS out_now,
                       SUM(CASE $shelf WHEN a.`status` = 'received' THEN 1 ELSE 0 END) AS came_back
                  FROM `jewellery_order_lines` l
                  LEFT JOIN `jewellery_order_assignments` a
                         ON a.`id` = l.`assignment_id` AND a.`company_id` = l.`company_id`
                 GROUP BY l.`order_id`
            ) t ON t.`order_id` = o.`id`
               SET o.`status` = CASE
                    WHEN t.came_back >= t.total_items THEN 'received'
                    WHEN t.came_back > 0              THEN '$partial'
                    WHEN t.out_now > 0                THEN 'assigned'
                    ELSE 'confirmed'
               END
             WHERE o.`status` IN ('assigned', 'partially_received', 'received')");
    });

    $run('One payment split across several ways of paying (migration 092)', static function (): void {
        // A customer pays 20,000 cash, 15,000 by Fonepay and the rest in old
        // gold, all at once. That was three settlements with three numbers,
        // because the row held one mode and one amount. It is one payment, and
        // it is now stored as one — with a child row per way it was tendered.
        //
        // Metal is why these are rows and not columns like the sale's
        // paid_cash/paid_card/paid_cheque/paid_qr: a metal tender carries an
        // item, a purity, a unit and a weight.
        if (!accounting_repair_table_exists('jewellery_settlements')) {
            return;
        }
        db()->exec("CREATE TABLE IF NOT EXISTS `jewellery_settlement_tenders` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `company_id` INT UNSIGNED NOT NULL,
            `settlement_id` INT UNSIGNED NOT NULL,
            `line_no` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            `mode` ENUM('cash','bank','card','cheque','qr','wallet','metal','adjustment','other') NOT NULL DEFAULT 'cash',
            `mode_label` VARCHAR(60) DEFAULT NULL,
            `reference` VARCHAR(60) DEFAULT NULL,
            `amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            `ledger_id` INT UNSIGNED DEFAULT NULL,
            `item_id` INT UNSIGNED DEFAULT NULL,
            `purity_id` INT UNSIGNED DEFAULT NULL,
            `unit_id` INT UNSIGNED DEFAULT NULL,
            `gross_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
            `fine_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
            `stock_txn_id` INT UNSIGNED DEFAULT NULL,
            `notes` VARCHAR(255) DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_jw_tender_settlement` (`settlement_id`),
            KEY `idx_jw_tender_company` (`company_id`, `mode`),
            KEY `idx_jw_tender_ledger` (`ledger_id`),
            KEY `idx_jw_tender_item` (`item_id`),
            CONSTRAINT `fk_jw_tender_settlement` FOREIGN KEY (`settlement_id`) REFERENCES `jewellery_settlements` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_jw_tender_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_jw_tender_ledger` FOREIGN KEY (`ledger_id`) REFERENCES `ledgers` (`id`) ON DELETE SET NULL,
            CONSTRAINT `fk_jw_tender_item` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`id`) ON DELETE SET NULL,
            CONSTRAINT `fk_jw_tender_purity` FOREIGN KEY (`purity_id`) REFERENCES `jewellery_purities` (`id`) ON DELETE SET NULL,
            CONSTRAINT `fk_jw_tender_unit` FOREIGN KEY (`unit_id`) REFERENCES `jewellery_units` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Widen the header's own mode. 'mixed' is what it reads when the child
        // rows disagree; the rest are modes a shop could always name but the
        // column could not hold. Re-running this is harmless — MODIFY to the
        // same definition is a no-op, and every value already stored is still
        // in the list.
        $mode = db()->query("SHOW COLUMNS FROM `jewellery_settlements` LIKE 'mode'")->fetch(PDO::FETCH_ASSOC);
        if ($mode && stripos((string) ($mode['Type'] ?? ''), "'mixed'") === false) {
            db()->exec("ALTER TABLE `jewellery_settlements`
                MODIFY COLUMN `mode` ENUM('cash','bank','card','cheque','qr','wallet','metal','adjustment','other','mixed')
                    NOT NULL DEFAULT 'cash'");
        }
    });

    $run('Order status vocabulary and the sale-order link (migration 093)', static function (): void {
        // Four states of affairs had no word: partially_received (two of five
        // pieces back), invoiced (billed, not yet handed over), closed
        // (delivered AND paid), and the sale's own knowledge of which order it
        // delivers — which used to live in a POST field and die with the
        // request, so a sale posted after being drafted never delivered its
        // order at all.
        if (!accounting_repair_table_exists('jewellery_orders')
            || !accounting_repair_table_exists('jewellery_sales')) {
            return;
        }
        $status = db()->query("SHOW COLUMNS FROM `jewellery_orders` LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
        if ($status && stripos((string) ($status['Type'] ?? ''), "'closed'") === false) {
            db()->exec("ALTER TABLE `jewellery_orders`
                MODIFY COLUMN `status` ENUM('draft','confirmed','assigned','partially_received','received',
                    'invoiced','delivered','closed','cancelled') NOT NULL DEFAULT 'draft'");
        }
        $hadLink = accounting_repair_column_exists('jewellery_sales', 'order_id');
        accounting_repair_add_column('jewellery_sales', 'order_id',
            '`order_id` INT UNSIGNED DEFAULT NULL AFTER `party_id`');
        accounting_repair_add_index('jewellery_sales', 'idx_jw_sales_order',
            'KEY `idx_jw_sales_order` (`company_id`, `order_id`)');
        accounting_repair_add_constraint('jewellery_sales', 'fk_jw_sales_order',
            '`fk_jw_sales_order` FOREIGN KEY (`order_id`) REFERENCES `jewellery_orders` (`id`) ON DELETE SET NULL');
        if (!$hadLink) {
            // First arrival of the column: history gets the link back-filled
            // from the delivery record, and the statuses the old vocabulary
            // could not express are recomputed once, exactly as migration 093
            // writes them.
            db()->exec("UPDATE `jewellery_sales` s
                INNER JOIN `jewellery_orders` o ON o.`delivered_sale_id` = s.`id` AND o.`company_id` = s.`company_id`
                  SET s.`order_id` = o.`id`
                WHERE s.`order_id` IS NULL");
            db()->exec("UPDATE `jewellery_orders` o
                INNER JOIN (
                    SELECT l.`order_id`,
                           COUNT(*) AS total_items,
                           SUM(CASE WHEN a.`status` = 'received' THEN 1 ELSE 0 END) AS came_back
                      FROM `jewellery_order_lines` l
                      LEFT JOIN `jewellery_order_assignments` a
                             ON a.`id` = l.`assignment_id` AND a.`company_id` = l.`company_id`
                     GROUP BY l.`order_id`
                ) t ON t.`order_id` = o.`id`
                   SET o.`status` = 'partially_received'
                 WHERE o.`status` = 'assigned'
                   AND t.came_back > 0
                   AND t.came_back < t.total_items");
            db()->exec("UPDATE `jewellery_orders` o
                INNER JOIN `jewellery_sales` s ON s.`id` = o.`delivered_sale_id` AND s.`company_id` = o.`company_id`
                LEFT JOIN `jewellery_bills` b ON b.`source_type` = 'jewellery_sale' AND b.`source_id` = s.`id`
                      AND b.`company_id` = o.`company_id`
                  SET o.`status` = 'closed'
                WHERE o.`status` = 'delivered'
                  AND (b.`id` IS NULL OR b.`status` = 'settled')");
        }
    });

    $run('Which advances paid which bill (migration 094)', static function (): void {
        // The sale's advance_amount was one number; which advance ENTRIES it
        // consumed was never recorded, so it could not be shown on the bill,
        // audited from the entry's side, or drawn from another order's
        // advance. Each row below says: this sale took this much from that
        // settlement entry, and the sale's number becomes the sum of its rows.
        if (!accounting_repair_table_exists('jewellery_sales')
            || !accounting_repair_table_exists('jewellery_settlements')) {
            return;
        }
        db()->exec("CREATE TABLE IF NOT EXISTS `jewellery_advance_allocations` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `company_id` INT UNSIGNED NOT NULL,
            `sale_id` INT UNSIGNED NOT NULL,
            `settlement_id` INT UNSIGNED NOT NULL,
            `amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            `created_by` INT UNSIGNED DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_jw_advalloc_sale` (`sale_id`),
            KEY `idx_jw_advalloc_settlement` (`settlement_id`),
            KEY `idx_jw_advalloc_company` (`company_id`),
            CONSTRAINT `fk_jw_advalloc_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_jw_advalloc_sale` FOREIGN KEY (`sale_id`) REFERENCES `jewellery_sales` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_jw_advalloc_settlement` FOREIGN KEY (`settlement_id`) REFERENCES `jewellery_settlements` (`id`) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        if (!accounting_repair_column_exists('jewellery_settlements', 'order_id')) {
            return;
        }
        // Every sale that applied an advance but has NO rows saying which
        // entries funded it gets its one number spread OLDEST-FIRST across the
        // delivering order's own posted advance entries — the exact pool the
        // old cap drew on, made explicit. FIFO because money held longest is
        // applied first, which is what a shop does and what a customer expects
        // of it. Deterministic, so replaying the reconstruction on another
        // copy of the books gives the same rows.
        //
        // GUARDED PER SALE, not by the table's existence. The table can exist
        // with the backfill still owed — 094.sql applied by hand before the
        // repair runs, or a crash mid-loop (CREATE TABLE is DDL and commits
        // regardless). A table-existence guard then skipped the backfill
        // FOREVER, and every already-applied advance read as still held: the
        // picker offered it again and the shop credited the customer twice.
        // The NOT EXISTS makes re-running always safe and always complete.
        $sales = db()->query("SELECT s.id, s.company_id, s.advance_amount,
                COALESCE(NULLIF(s.order_id, 0), o.id) AS order_id
            FROM jewellery_sales s
            LEFT JOIN jewellery_orders o ON o.delivered_sale_id = s.id AND o.company_id = s.company_id
            WHERE s.advance_amount > 0.005 AND s.status <> 'cancelled'
              AND NOT EXISTS (SELECT 1 FROM jewellery_advance_allocations a WHERE a.sale_id = s.id)
            ORDER BY s.company_id, s.id")->fetchAll(PDO::FETCH_ASSOC);
        if ($sales === []) {
            return;
        }
        $entriesStmt = db()->prepare("SELECT id, amount FROM jewellery_settlements
            WHERE company_id = :cid AND order_id = :oid AND is_advance = 1
              AND direction = 'received' AND status = 'posted'
            ORDER BY settlement_date ASC, id ASC");
        $insert = db()->prepare('INSERT INTO jewellery_advance_allocations (company_id, sale_id, settlement_id, amount)
            VALUES (:cid, :sid, :stid, :amount)');
        // What each entry has already given — seeded from rows ALREADY stored,
        // so a partial earlier run (or live sales saved since the table
        // appeared) cannot be handed out a second time.
        $used = [];
        $usedStmt = db()->query('SELECT settlement_id, SUM(amount) AS total FROM jewellery_advance_allocations GROUP BY settlement_id');
        foreach ($usedStmt->fetchAll(PDO::FETCH_ASSOC) as $usedRow) {
            $used[(int) $usedRow['settlement_id']] = (float) $usedRow['total'];
        }
        $seen = [];
        foreach ($sales as $sale) {
            $saleId = (int) $sale['id'];
            // The delivered_sale_id join can return one sale twice when two
            // orders point at the same bill; processing the duplicate would
            // allocate the sale's number a second time from the next entries.
            if (isset($seen[$saleId])) {
                continue;
            }
            $seen[$saleId] = true;
            $orderId = (int) ($sale['order_id'] ?? 0);
            if ($orderId <= 0) {
                continue; // an advance applied with no order on record — nothing to reconstruct from
            }
            $left = round((float) $sale['advance_amount'], 2);
            $entriesStmt->execute(['cid' => (int) $sale['company_id'], 'oid' => $orderId]);
            foreach ($entriesStmt->fetchAll(PDO::FETCH_ASSOC) as $entry) {
                if ($left <= 0.005) {
                    break;
                }
                $entryId = (int) $entry['id'];
                $room = round((float) $entry['amount'] - ($used[$entryId] ?? 0.0), 2);
                if ($room <= 0.005) {
                    continue;
                }
                $take = min($room, $left);
                $insert->execute(['cid' => (int) $sale['company_id'], 'sid' => $saleId,
                    'stid' => $entryId, 'amount' => $take]);
                $used[$entryId] = ($used[$entryId] ?? 0.0) + $take;
                $left = round($left - $take, 2);
            }
            // $left > 0 here means the old books applied more than the order's
            // entries ever held (refunds crossing, hand edits). The rows record
            // what CAN be traced; the sale's own number is untouched either way.
        }
    });

    $run('Stones weighed apart on kaligad receipts (migration 095)', static function (): void {
        // The fine-gold equivalent of a stone-set piece was computed over the
        // stones too — crediting the kaligad with metal he never returned and
        // understating his wastage. The receipt now stores the stone weight
        // and the net gold weight the fine figure is computed from. Existing
        // rows backfill stone 0 / net = gross, keeping their meaning exactly.
        if (!accounting_repair_table_exists('jewellery_order_receipts')) {
            return;
        }
        accounting_repair_add_column('jewellery_order_receipts', 'stone_weight',
            '`stone_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000 AFTER `received_gross_weight`');
        accounting_repair_add_column('jewellery_order_receipts', 'net_gold_weight',
            '`net_gold_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000 AFTER `stone_weight`');
        db()->exec("UPDATE `jewellery_order_receipts`
               SET `net_gold_weight` = `received_gross_weight`
             WHERE `net_gold_weight` = 0 AND `received_gross_weight` > 0");
    });

    $run('SD base no longer counts the wastage twice (migration 096)', static function (): void {
        // Since 083 the metal amount already carries the wastage, but the tax
        // base 'metal_wastage_making' still added the wastage value on top —
        // so bills printed an SD Taxable Amt inflated by exactly the wastage
        // and a NEGATIVE Non Taxable Amt absorbing the difference. The engine
        // is fixed; this corrects what is stored. The tax AMOUNT charged is
        // history and stays; only the printed/reported bases are re-derived.
        if (!accounting_repair_table_exists('jewellery_line_taxes')
            || !accounting_repair_table_exists('jewellery_taxes')) {
            return;
        }
        foreach ([['sale', 'jewellery_sale_lines', 'jewellery_sales'],
                  ['purchase', 'jewellery_purchase_lines', 'jewellery_purchases']] as [$docType, $lineTable, $headTable]) {
            // Which documents carried the bad base at all — scoped first, so
            // the header rewrite below never touches an unaffected bill.
            $affectedStmt = db()->prepare("SELECT DISTINCT lt.doc_id
                FROM jewellery_line_taxes lt
                INNER JOIN jewellery_taxes t ON t.id = lt.tax_id AND t.base = 'metal_wastage_making'
                WHERE lt.doc_type = :dt");
            $affectedStmt->execute(['dt' => $docType]);
            $affected = array_map('intval', $affectedStmt->fetchAll(PDO::FETCH_COLUMN));
            if ($affected === []) {
                continue;
            }

            // The line bases, re-derived from the lines they were charged on.
            // Absolute assignment: running this twice converges.
            db()->exec("UPDATE jewellery_line_taxes lt
                INNER JOIN jewellery_taxes t ON t.id = lt.tax_id AND t.base = 'metal_wastage_making'
                INNER JOIN `$lineTable` l ON l.id = lt.line_id
                   SET lt.base_amount = ROUND(l.metal_amount + l.making_amount, 2)
                 WHERE lt.doc_type = '$docType'
                   AND lt.base_amount <> ROUND(l.metal_amount + l.making_amount, 2)");

            // The header block the bill prints, re-derived exactly as the
            // engine derives it: SD taxable is the sum of every non-VAT base
            // on the document, and non-taxable is what neither tax reached.
            $sdStmt = db()->prepare("SELECT COALESCE(SUM(base_amount), 0) FROM jewellery_line_taxes
                WHERE doc_type = :dt AND doc_id = :did AND output_purpose <> 'vat_output'");
            $headStmt = db()->prepare("SELECT metal_amount, making_amount, stone_amount, diamond_amount,
                    other_charges, discount, vatable_amount, sd_taxable_amount, non_taxable_amount
                FROM `$headTable` WHERE id = :did");
            $fixStmt = db()->prepare("UPDATE `$headTable`
                SET sd_taxable_amount = :sd, non_taxable_amount = :nt WHERE id = :did");
            foreach ($affected as $docId) {
                $sdStmt->execute(['dt' => $docType, 'did' => $docId]);
                $sdTaxable = round((float) $sdStmt->fetchColumn(), 2);
                $headStmt->execute(['did' => $docId]);
                $head = $headStmt->fetch(PDO::FETCH_ASSOC);
                if (!$head) {
                    continue;
                }
                $nonTaxable = round((float) $head['metal_amount'] + (float) $head['making_amount']
                    + (float) $head['stone_amount'] + (float) $head['diamond_amount']
                    + (float) $head['other_charges'] - (float) $head['discount']
                    - $sdTaxable - (float) $head['vatable_amount'], 2);
                if (abs((float) $head['sd_taxable_amount'] - $sdTaxable) > 0.005
                    || abs((float) $head['non_taxable_amount'] - $nonTaxable) > 0.005) {
                    $fixStmt->execute(['sd' => $sdTaxable, 'nt' => $nonTaxable, 'did' => $docId]);
                }
            }
        }
    });

    $run('The levy is called by its name: SPT, not SD (migration 097)', static function (): void {
        // The 0.5% levy is the Skills PROMOTION Tax. It was seeded coded "SD"
        // (Skills Development), and the bill printed "SD Tax" off that code —
        // wrong name on a statutory document. New books seed SPT; this
        // renames what the old seed wrote, in the register and in the stored
        // per-line tax rows the reports group by. Nothing about the money
        // changes — same tax, same rate, same rows, right name.
        if (!accounting_repair_table_exists('jewellery_taxes')) {
            return;
        }
        // Guarded per company: if a company somehow already holds an SPT code,
        // renaming its SD row would collide with the unique (company, code)
        // key, so that company is left for a person to look at.
        db()->exec("UPDATE jewellery_taxes t
            SET t.code = 'SPT', t.name = 'Skills Promotion Tax'
            WHERE t.code = 'SD' AND t.output_purpose = 'spt_output'
              AND NOT EXISTS (SELECT 1 FROM (SELECT company_id FROM jewellery_taxes WHERE code = 'SPT') s
                              WHERE s.company_id = t.company_id)");
        if (accounting_repair_table_exists('jewellery_line_taxes')) {
            db()->exec("UPDATE jewellery_line_taxes
                SET tax_code = 'SPT', tax_name = 'Skills Promotion Tax'
                WHERE tax_code = 'SD' AND output_purpose = 'spt_output'");
        }
    });

    $run('What the customer asked for, and what size (migration 098)', static function (): void {
        // expected_item: the customer's own words for the order ("bridal
        // set", "ring like my mother's") — searchable, printable, apart from
        // the description everything else was squeezed into. size: per ITEM,
        // because one order carries a ring for her and a chain for him; plain
        // text because sizes are written a dozen ways.
        if (accounting_repair_table_exists('jewellery_orders')) {
            accounting_repair_add_column('jewellery_orders', 'expected_item',
                '`expected_item` VARCHAR(190) DEFAULT NULL AFTER `design_no`');
        }
        if (accounting_repair_table_exists('jewellery_order_lines')) {
            accounting_repair_add_column('jewellery_order_lines', 'size',
                '`size` VARCHAR(60) DEFAULT NULL AFTER `delivery_date`');
        }
    });

    $run('Drafts do not post yesterday\'s wrong tax (migration 099)', static function (): void {
        // 096 corrected the base and left the CHARGED tax alone, because on a
        // posted document that figure is history. On a draft it is not: it is
        // a pending instruction, and posting one saved before the fix would
        // send the inflated levy to the ledger today. Drafts are therefore
        // re-derived from the corrected base; posted rows are not touched by
        // anything here.
        if (!accounting_repair_table_exists('jewellery_line_taxes')) {
            return;
        }
        foreach ([['sale', 'jewellery_sales'], ['purchase', 'jewellery_purchases']] as [$docType, $headTable]) {
            if (!accounting_repair_table_exists($headTable)) {
                continue;
            }
            db()->exec("UPDATE jewellery_line_taxes lt
                INNER JOIN `$headTable` h ON h.id = lt.doc_id AND lt.doc_type = '$docType'
                   SET lt.amount = ROUND(lt.base_amount * lt.rate / 100, 2)
                 WHERE h.status = 'draft'
                   AND lt.amount <> ROUND(lt.base_amount * lt.rate / 100, 2)");
            // The header total is the sum of its lines' non-VAT taxes. A
            // manually punched figure (manual_tax_amount) is the user's own
            // and stays exactly as typed.
            db()->exec("UPDATE `$headTable` h
                SET h.tax_amount = COALESCE((SELECT SUM(lt.amount) FROM jewellery_line_taxes lt
                        WHERE lt.doc_type = '$docType' AND lt.doc_id = h.id
                          AND lt.output_purpose <> 'vat_output'), 0)
                WHERE h.status = 'draft' AND h.manual_tax_amount IS NULL");
        }

        // Orders keep no line-tax rows — their quote is recomputed wholesale
        // on save — so the header is re-derived here from the same rule the
        // engine uses: the levy is charged on metal + making, and the metal
        // figure already carries the wastage.
        if (!accounting_repair_table_exists('jewellery_orders')
            || !accounting_repair_column_exists('jewellery_orders', 'sd_taxable_amount')) {
            return;
        }
        $rateStmt = db()->query("SELECT company_id, rate FROM jewellery_taxes
            WHERE output_purpose = 'spt_output' AND active = 1");
        foreach ($rateStmt->fetchAll(PDO::FETCH_ASSOC) as $rateRow) {
            $companyId = (int) $rateRow['company_id'];
            $rate = (float) $rateRow['rate'];
            db()->prepare("UPDATE jewellery_orders
                    SET sd_taxable_amount = ROUND(metal_amount + making_amount, 2),
                        tax_amount = ROUND((metal_amount + making_amount) * :rate / 100, 2)
                  WHERE company_id = :cid
                    AND status IN ('draft', 'confirmed')
                    AND manual_tax_amount IS NULL
                    AND ABS(sd_taxable_amount - ROUND(metal_amount + making_amount, 2)) > 0.005")
                ->execute(['rate' => $rate, 'cid' => $companyId]);
        }
    });

    $run('Item category master (migration 086)', static function (): void {
        db()->exec("CREATE TABLE IF NOT EXISTS `jewellery_item_categories` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `company_id` INT UNSIGNED NOT NULL,
            `name` VARCHAR(120) NOT NULL,
            `sort_order` INT NOT NULL DEFAULT 0,
            `active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_jw_category` (`company_id`, `name`),
            KEY `idx_jw_category_company` (`company_id`, `active`, `sort_order`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Adopt whatever is already filed, so turning the free-text box into a
        // dropdown cannot orphan an existing item's category.
        if (accounting_repair_table_exists('inventory_items') && accounting_repair_table_exists('jewellery_item_profiles')) {
            db()->exec("INSERT IGNORE INTO `jewellery_item_categories` (`company_id`, `name`, `sort_order`, `active`)
                SELECT i.`company_id`, TRIM(i.`category`), 0, 1
                  FROM `inventory_items` i
                 INNER JOIN `jewellery_item_profiles` j ON j.`inventory_item_id` = i.`id`
                 WHERE i.`category` IS NOT NULL AND TRIM(i.`category`) <> ''
                 GROUP BY i.`company_id`, TRIM(i.`category`)");
        }
    });

    $run('Printed tax bases on pre-083 documents (migration 085)', static function (): void {
        // The invoice reads its totals block straight off the header, so a bill
        // raised before the tax bases existed reprints as 0.00 / 0.00 / 0.00
        // above a correct net total. See the migration for why the guard makes
        // this both re-runnable and impossible to apply to a current document.
        foreach ([
            ['jewellery_sales', ['non_taxable_amount', 'sd_taxable_amount', 'vatable_amount', 'taxable_amount',
                'metal_amount', 'wastage_amount', 'making_amount', 'stone_amount', 'total_amount']],
            ['jewellery_purchases', ['non_taxable_amount', 'sd_taxable_amount', 'vatable_amount', 'taxable_amount',
                'metal_amount', 'wastage_amount', 'making_amount', 'stone_amount', 'total_amount']],
        ] as [$table, $columns]) {
            if (!accounting_repair_table_exists($table)) {
                continue;
            }
            foreach ($columns as $column) {
                if (!accounting_repair_column_exists($table, $column)) {
                    continue 2;
                }
            }
            db()->exec("UPDATE `$table`
                   SET `vatable_amount` = `taxable_amount`,
                       `non_taxable_amount` = GREATEST(0,
                            `metal_amount` + `wastage_amount` + `making_amount` + `stone_amount` - `taxable_amount`)
                 WHERE `non_taxable_amount` = 0 AND `sd_taxable_amount` = 0 AND `vatable_amount` = 0
                   AND `total_amount` <> 0");
        }
    });

    $run('Canonical gram weights on stock movements (migration 082)', static function (): void {
        if (!accounting_repair_table_exists('jewellery_stock_txns')) {
            return;
        }
        accounting_repair_add_column('jewellery_stock_txns', 'gross_grams',
            '`gross_grams` DECIMAL(18,6) NOT NULL DEFAULT 0.000000 AFTER `gross_weight`');
        accounting_repair_add_column('jewellery_stock_txns', 'fine_grams',
            '`fine_grams` DECIMAL(18,6) NOT NULL DEFAULT 0.000000 AFTER `fine_weight`');
        accounting_repair_add_index('jewellery_stock_txns', 'idx_jw_txn_item_grams',
            'KEY `idx_jw_txn_item_grams` (`company_id`, `item_id`, `txn_date`)');

        // Backfill from the unit each row was written in. Rows that already
        // carry a gram figure are left alone, so this is safe to re-run.
        if (accounting_repair_table_exists('jewellery_units')) {
            db()->exec('UPDATE `jewellery_stock_txns` t
                JOIN `jewellery_units` u ON u.id = t.unit_id
                SET t.gross_grams = t.gross_weight * IF(u.grams > 0, u.grams, 1),
                    t.fine_grams  = t.fine_weight  * IF(u.grams > 0, u.grams, 1)
                WHERE t.gross_grams = 0 AND t.fine_grams = 0');
        }
    });

    $run('Jewellery movements visible in core stock reports (migration 109)', static function (): void {
        if (!accounting_repair_table_exists('inventory_transactions')
            || !accounting_repair_table_exists('jewellery_stock_txns')
            || !accounting_repair_table_exists('jewellery_item_profiles')
            || !accounting_repair_column_exists('jewellery_stock_txns', 'gross_grams')) {
            return;
        }

        $needsBackfill = !accounting_repair_index_exists(
            'inventory_transactions',
            'uniq_inventory_jewellery_stock_txn'
        );
        accounting_repair_run_migration_file_if_index_missing(
            '109_jewellery_core_stock_sync.sql',
            'inventory_transactions',
            'uniq_inventory_jewellery_stock_txn'
        );
        accounting_repair_add_constraint(
            'inventory_transactions',
            'fk_inventory_jewellery_stock_txn',
            '`fk_inventory_jewellery_stock_txn` FOREIGN KEY (`jewellery_stock_txn_id`) '
                . 'REFERENCES `jewellery_stock_txns` (`id`) ON DELETE CASCADE'
        );

        // The migration adds historical movements. Cost layers are cached, so
        // replay every affected item once or the quantity reports would be
        // correct while the Inventory valuation card still showed the old
        // value until a later edit happened to rebuild it.
        if ($needsBackfill && accounting_repair_index_exists('inventory_transactions', 'uniq_inventory_jewellery_stock_txn')) {
            $affected = db()->query('SELECT DISTINCT company_id, item_id
                FROM inventory_transactions WHERE jewellery_stock_txn_id IS NOT NULL')
                ->fetchAll(PDO::FETCH_ASSOC);
            foreach ($affected as $item) {
                inv_rebuild_item((int) $item['company_id'], (int) $item['item_id']);
            }
        }
    });

    $run('Kaligad assignment carries the piece it asks for (migration 102)', static function (): void {
        // The ornament's specification, kept apart from the metal handed over:
        // a shop assigns work long before any bar leaves the safe, and assigns
        // work with no customer at all to keep the showroom stocked.
        if (!accounting_repair_table_exists('jewellery_order_assignments')) {
            return;
        }
        accounting_repair_add_column('jewellery_order_assignments', 'assignment_no',
            '`assignment_no` VARCHAR(60) DEFAULT NULL AFTER `issue_no`');
        accounting_repair_add_column('jewellery_order_assignments', 'assign_kind',
            "`assign_kind` ENUM('customer','self') NOT NULL DEFAULT 'customer' AFTER `assignment_no`");
        accounting_repair_add_column('jewellery_order_assignments', 'category',
            "`category` ENUM('gold','diamond','other') NOT NULL DEFAULT 'gold' AFTER `assign_kind`");
        accounting_repair_add_column('jewellery_order_assignments', 'size_design',
            '`size_design` VARCHAR(120) DEFAULT NULL AFTER `category`');
        accounting_repair_add_column('jewellery_order_assignments', 'expected_ornament',
            '`expected_ornament` VARCHAR(190) DEFAULT NULL AFTER `size_design`');
        accounting_repair_add_column('jewellery_order_assignments', 'expected_gross_weight',
            '`expected_gross_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000 AFTER `expected_ornament`');
        accounting_repair_add_column('jewellery_order_assignments', 'expected_stone_weight',
            '`expected_stone_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000 AFTER `expected_gross_weight`');
        accounting_repair_add_column('jewellery_order_assignments', 'expected_net_weight',
            '`expected_net_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000 AFTER `expected_stone_weight`');

        // Every assignment already made keeps a number to be known by — its
        // issue number, which is what the table has always shown.
        db()->exec("UPDATE `jewellery_order_assignments`
               SET `assignment_no` = `issue_no`
             WHERE `assignment_no` IS NULL OR `assignment_no` = ''");
        db()->exec('UPDATE `jewellery_order_assignments`
               SET `expected_gross_weight` = `issued_gross_weight`,
                   `expected_net_weight` = `issued_gross_weight`
             WHERE `expected_gross_weight` = 0 AND `issued_gross_weight` > 0');

        accounting_repair_add_index('jewellery_order_assignments', 'uniq_jw_assignment_no',
            'UNIQUE KEY `uniq_jw_assignment_no` (`company_id`, `assignment_no`)');
        accounting_repair_add_index('jewellery_order_assignments', 'idx_jw_assign_kind',
            'KEY `idx_jw_assign_kind` (`company_id`, `assign_kind`, `issue_date`)');

        if (accounting_repair_table_exists('jewellery_settings')) {
            accounting_repair_add_column('jewellery_settings', 'assign_no_prefix',
                "`assign_no_prefix` VARCHAR(20) NOT NULL DEFAULT 'KA' AFTER `issue_no_prefix`");
        }
    });

    $run('One issue, several things in hand (migration 103)', static function (): void {
        // Gold and the diamonds set into it go out together in one packet.
        // Stones total apart from metal and carry NO fine weight — running a
        // fine calculation over them would credit the kaligad with pure metal
        // he never held and understate his wastage by exactly their weight.
        if (!accounting_repair_table_exists('jewellery_order_assignments')) {
            return;
        }
        accounting_repair_run_migration_file('103_assignment_components.sql', ['jewellery_assignment_components']);
        accounting_repair_add_column('jewellery_order_assignments', 'issued_stone_carat',
            '`issued_stone_carat` DECIMAL(18,4) NOT NULL DEFAULT 0.0000 AFTER `issued_fine_weight`');
        accounting_repair_add_column('jewellery_order_assignments', 'issued_stone_amount',
            '`issued_stone_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `issued_stone_carat`');
    });

    $run('An ordered item can be a piece already on the shelf (migration 106)', static function (): void {
        // A customer who points at a ring in the case is placing an order, not
        // commissioning one: there is a customer, an advance, a promised day
        // and a bill, but nothing for a kaligad to make. The line now says so,
        // and names WHICH piece off the Ready to Sale tray it is holding.
        //
        // Every line already written defaults to 'workshop', which is what it
        // has always meant.
        if (!accounting_repair_table_exists('jewellery_order_lines')
            || !accounting_repair_table_exists('jewellery_order_receipts')) {
            return;
        }
        accounting_repair_add_column('jewellery_order_lines', 'source',
            "`source` ENUM('workshop','stock') NOT NULL DEFAULT 'workshop' AFTER `item_id`");
        accounting_repair_add_column('jewellery_order_lines', 'stock_receipt_id',
            '`stock_receipt_id` INT UNSIGNED DEFAULT NULL AFTER `source`');
        accounting_repair_add_index('jewellery_order_lines', 'idx_jw_oline_stock',
            'KEY `idx_jw_oline_stock` (`company_id`, `stock_receipt_id`)');
        db()->exec('UPDATE `jewellery_order_lines` l'
            . ' LEFT JOIN `jewellery_order_receipts` r ON l.`stock_receipt_id` = r.`id`'
            . ' SET l.`stock_receipt_id` = NULL'
            . ' WHERE l.`stock_receipt_id` IS NOT NULL AND r.`id` IS NULL');
        accounting_repair_add_constraint('jewellery_order_lines', 'fk_jw_oline_stock_receipt',
            'CONSTRAINT `fk_jw_oline_stock_receipt` FOREIGN KEY (`stock_receipt_id`) '
            . 'REFERENCES `jewellery_order_receipts` (`id`) ON DELETE SET NULL');
    });

    $run('Jewellery opening stock can be segregated and create item masters (migration 110)', static function (): void {
        if (!accounting_repair_table_exists('jewellery_item_profiles')
            || !accounting_repair_table_exists('inventory_opening_import_rows')) {
            return;
        }
        accounting_repair_run_migration_file_if_index_missing(
            '110_jewellery_opening_stock_classification.sql',
            'inventory_opening_import_rows',
            'idx_inv_opimprow_stock_kind'
        );
        accounting_repair_add_column('jewellery_stock_txns', 'stone_weight',
            '`stone_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000 AFTER `gross_weight`');
        accounting_repair_add_column('jewellery_stock_txns', 'diamond_weight',
            '`diamond_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000 AFTER `stone_weight`');
        accounting_repair_add_column('jewellery_stock_txns', 'stone_carat',
            '`stone_carat` DECIMAL(18,4) NOT NULL DEFAULT 0.0000 AFTER `diamond_weight`');
        accounting_repair_add_column('jewellery_stock_txns', 'diamond_carat',
            '`diamond_carat` DECIMAL(18,4) NOT NULL DEFAULT 0.0000 AFTER `stone_carat`');
        accounting_repair_add_column('jewellery_stock_txns', 'stone_amount',
            '`stone_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `diamond_weight`');
        accounting_repair_add_column('jewellery_stock_txns', 'diamond_amount',
            '`diamond_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `stone_amount`');
        accounting_repair_add_column('jewellery_stock_txns', 'making_amount',
            '`making_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `diamond_amount`');
        // The amount columns below are positioned AFTER diamond_weight. Older
        // databases created by migration 081 do not have either component
        // weight yet, so create both prerequisites before referencing them.
        // Each call is idempotent and also repairs partially applied schemas.
        accounting_repair_add_column('inventory_opening_import_rows', 'stone_weight',
            '`stone_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000 AFTER `gross_weight`');
        accounting_repair_add_column('inventory_opening_import_rows', 'diamond_weight',
            '`diamond_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000 AFTER `stone_weight`');
        accounting_repair_add_column('inventory_opening_import_rows', 'stone_amount',
            '`stone_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `diamond_weight`');
        accounting_repair_add_column('inventory_opening_import_rows', 'diamond_amount',
            '`diamond_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `stone_amount`');
        accounting_repair_add_column('inventory_opening_import_rows', 'making_amount',
            '`making_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `diamond_amount`');
    });

    $run('Every physical jewellery item has one traceable lifecycle (migration 111)', static function (): void {
        if (!accounting_repair_table_exists('jewellery_stock_units')) {
            accounting_repair_run_migration_file('111_jewellery_item_traceability.sql', [
                'jewellery_stock_units', 'jewellery_stock_unit_events',
            ]);
            return;
        }

        if (!accounting_repair_table_exists('jewellery_stock_unit_events')) {
            db()->exec("CREATE TABLE `jewellery_stock_unit_events` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL,
                `stock_unit_id` INT UNSIGNED NOT NULL,
                `event_type` VARCHAR(40) NOT NULL,
                `event_date` DATE NOT NULL,
                `from_status` VARCHAR(30) DEFAULT NULL,
                `to_status` VARCHAR(30) DEFAULT NULL,
                `from_holder_type` VARCHAR(30) DEFAULT NULL,
                `from_holder_id` INT UNSIGNED DEFAULT NULL,
                `to_holder_type` VARCHAR(30) DEFAULT NULL,
                `to_holder_id` INT UNSIGNED DEFAULT NULL,
                `source_type` VARCHAR(40) DEFAULT NULL,
                `source_id` INT UNSIGNED DEFAULT NULL,
                `source_line_id` INT UNSIGNED DEFAULT NULL,
                `reference_no` VARCHAR(120) DEFAULT NULL,
                `notes` VARCHAR(255) DEFAULT NULL,
                `created_by` INT UNSIGNED DEFAULT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_jw_trace_event_unit` (`company_id`,`stock_unit_id`,`id`),
                KEY `idx_jw_trace_event_source` (`company_id`,`source_type`,`source_id`),
                CONSTRAINT `fk_jw_trace_event_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_jw_trace_event_unit` FOREIGN KEY (`stock_unit_id`) REFERENCES `jewellery_stock_units` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }

        // A deployment interrupted after the two new tables were created must
        // still finish the links safely on the next page load.
        if (accounting_repair_table_exists('jewellery_order_assignments')) {
            accounting_repair_add_column('jewellery_order_assignments', 'stock_order_no',
                '`stock_order_no` VARCHAR(60) DEFAULT NULL AFTER `assignment_no`');
            accounting_repair_add_column('jewellery_order_assignments', 'stock_unit_id',
                '`stock_unit_id` INT UNSIGNED DEFAULT NULL AFTER `stock_order_no`');
            accounting_repair_add_index('jewellery_order_assignments', 'idx_jw_assign_stock_order',
                'KEY `idx_jw_assign_stock_order` (`company_id`,`stock_order_no`)');
            accounting_repair_add_index('jewellery_order_assignments', 'idx_jw_assign_trace',
                'KEY `idx_jw_assign_trace` (`company_id`,`stock_unit_id`)');
        }
        foreach ([
            ['jewellery_order_receipts', 'stock_unit_id', '`stock_unit_id` INT UNSIGNED DEFAULT NULL AFTER `assignment_id`',
                'idx_jw_receipt_trace', 'KEY `idx_jw_receipt_trace` (`company_id`,`stock_unit_id`)'],
            ['jewellery_order_lines', 'stock_unit_id', '`stock_unit_id` INT UNSIGNED DEFAULT NULL AFTER `stock_receipt_id`',
                'idx_jw_oline_trace', 'KEY `idx_jw_oline_trace` (`company_id`,`stock_unit_id`)'],
            ['jewellery_purchase_lines', 'stock_unit_id', '`stock_unit_id` INT UNSIGNED DEFAULT NULL AFTER `item_id`',
                'idx_jw_pline_trace', 'KEY `idx_jw_pline_trace` (`company_id`,`stock_unit_id`)'],
            ['jewellery_sale_lines', 'stock_unit_id', '`stock_unit_id` INT UNSIGNED DEFAULT NULL AFTER `item_id`',
                'idx_jw_sline_trace', 'KEY `idx_jw_sline_trace` (`company_id`,`stock_unit_id`)'],
            ['jewellery_sale_exchanges', 'stock_unit_id', '`stock_unit_id` INT UNSIGNED DEFAULT NULL AFTER `item_id`',
                'idx_jw_sexchange_trace', 'KEY `idx_jw_sexchange_trace` (`company_id`,`stock_unit_id`)'],
            ['jewellery_stock_txns', 'stock_unit_id', '`stock_unit_id` INT UNSIGNED DEFAULT NULL AFTER `item_id`',
                'idx_jw_stock_trace', 'KEY `idx_jw_stock_trace` (`company_id`,`stock_unit_id`,`txn_date`)'],
            ['inventory_opening_import_rows', 'stock_unit_id', '`stock_unit_id` INT UNSIGNED DEFAULT NULL AFTER `item_id`',
                'idx_inv_opimprow_trace', 'KEY `idx_inv_opimprow_trace` (`company_id`,`stock_unit_id`)'],
        ] as [$table, $column, $definition, $index, $indexDefinition]) {
            if (!accounting_repair_table_exists($table)) {
                continue;
            }
            accounting_repair_add_column($table, $column, $definition);
            accounting_repair_add_index($table, $index, $indexDefinition);
        }
    });

    return $errors;
}
