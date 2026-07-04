-- Completes hierarchical ledger groups and configurable automated-posting ledgers.
-- Additive only: ledgers.type remains the compatibility nature used by reports.

SET @schema_name = DATABASE();

SET @parent_column_exists = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = @schema_name
      AND table_name = 'ledger_groups'
      AND column_name = 'parent_group_id'
);
SET @sql = IF(
    @parent_column_exists = 0,
    'ALTER TABLE ledger_groups ADD COLUMN parent_group_id INT UNSIGNED DEFAULT NULL AFTER master_key',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @parent_index_exists = (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = @schema_name
      AND table_name = 'ledger_groups'
      AND index_name = 'idx_ledger_groups_parent'
);
SET @sql = IF(
    @parent_index_exists = 0,
    'ALTER TABLE ledger_groups ADD KEY idx_ledger_groups_parent (parent_group_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @parent_fk_exists = (
    SELECT COUNT(*)
    FROM information_schema.table_constraints
    WHERE constraint_schema = @schema_name
      AND table_name = 'ledger_groups'
      AND constraint_name = 'fk_ledger_groups_parent'
      AND constraint_type = 'FOREIGN KEY'
);
SET @sql = IF(
    @parent_fk_exists = 0,
    'ALTER TABLE ledger_groups ADD CONSTRAINT fk_ledger_groups_parent FOREIGN KEY (parent_group_id) REFERENCES ledger_groups(id) ON DELETE RESTRICT',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `company_ledger_mappings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `map_key` VARCHAR(80) NOT NULL,
  `ledger_id` INT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_company_ledger_mapping` (`company_id`, `map_key`),
  KEY `idx_company_ledger_mappings_ledger` (`ledger_id`),
  CONSTRAINT `fk_company_ledger_mappings_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_company_ledger_mappings_ledger` FOREIGN KEY (`ledger_id`) REFERENCES `ledgers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `company_ledger_mappings` (`company_id`, `map_key`, `ledger_id`)
SELECT c.id, seed.map_key, l.id
FROM `companies` c
INNER JOIN (
    SELECT 'default_cash_bank' AS map_key, 'CASH' AS ledger_code
    UNION ALL SELECT 'default_accounts_receivable', 'AR'
    UNION ALL SELECT 'default_accounts_payable', 'AP'
    UNION ALL SELECT 'default_tax_payable', 'TAX_PAYABLE'
    UNION ALL SELECT 'default_service_revenue', 'SERVICE_REVENUE'
) seed
INNER JOIN `ledgers` l
    ON l.company_id = c.id
   AND l.code = seed.ledger_code
WHERE NOT EXISTS (
    SELECT 1
    FROM `company_ledger_mappings` existing
    WHERE existing.company_id = c.id
      AND existing.map_key = seed.map_key
);

-- Prefer a dedicated hosting account, then fall back to service revenue.
INSERT INTO `company_ledger_mappings` (`company_id`, `map_key`, `ledger_id`)
SELECT c.id, 'default_hosting_revenue', l.id
FROM `companies` c
INNER JOIN `ledgers` l
    ON l.company_id = c.id
   AND l.code = 'HOSTING_REVENUE'
WHERE NOT EXISTS (
    SELECT 1
    FROM `company_ledger_mappings` existing
    WHERE existing.company_id = c.id
      AND existing.map_key = 'default_hosting_revenue'
);

INSERT INTO `company_ledger_mappings` (`company_id`, `map_key`, `ledger_id`)
SELECT c.id, 'default_hosting_revenue', l.id
FROM `companies` c
INNER JOIN `ledgers` l
    ON l.company_id = c.id
   AND l.code = 'SERVICE_REVENUE'
WHERE NOT EXISTS (
    SELECT 1
    FROM `company_ledger_mappings` existing
    WHERE existing.company_id = c.id
      AND existing.map_key = 'default_hosting_revenue'
);
