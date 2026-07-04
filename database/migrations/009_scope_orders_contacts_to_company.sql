SET @orders_company_col_exists := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'orders'
      AND column_name = 'company_id'
);
SET @orders_add_company_sql := IF(
    @orders_company_col_exists = 0,
    'ALTER TABLE orders ADD COLUMN company_id INT UNSIGNED NULL AFTER id',
    'SELECT 1'
);
PREPARE stmt_orders_company_col FROM @orders_add_company_sql;
EXECUTE stmt_orders_company_col;
DEALLOCATE PREPARE stmt_orders_company_col;

SET @orders_company_idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'orders'
      AND index_name = 'idx_orders_company_id'
);
SET @orders_add_company_idx_sql := IF(
    @orders_company_idx_exists = 0,
    'ALTER TABLE orders ADD KEY idx_orders_company_id (company_id)',
    'SELECT 1'
);
PREPARE stmt_orders_company_idx FROM @orders_add_company_idx_sql;
EXECUTE stmt_orders_company_idx;
DEALLOCATE PREPARE stmt_orders_company_idx;

UPDATE orders o
LEFT JOIN users u ON u.id = o.user_id
SET o.company_id = u.company_id
WHERE o.company_id IS NULL;

SET @contacts_company_col_exists := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'contacts'
      AND column_name = 'company_id'
);
SET @contacts_add_company_sql := IF(
    @contacts_company_col_exists = 0,
    'ALTER TABLE contacts ADD COLUMN company_id INT UNSIGNED NULL AFTER id',
    'SELECT 1'
);
PREPARE stmt_contacts_company_col FROM @contacts_add_company_sql;
EXECUTE stmt_contacts_company_col;
DEALLOCATE PREPARE stmt_contacts_company_col;

SET @contacts_company_idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'contacts'
      AND index_name = 'idx_contacts_company_id'
);
SET @contacts_add_company_idx_sql := IF(
    @contacts_company_idx_exists = 0,
    'ALTER TABLE contacts ADD KEY idx_contacts_company_id (company_id)',
    'SELECT 1'
);
PREPARE stmt_contacts_company_idx FROM @contacts_add_company_idx_sql;
EXECUTE stmt_contacts_company_idx;
DEALLOCATE PREPARE stmt_contacts_company_idx;

UPDATE contacts c
LEFT JOIN users u ON u.id = c.assigned_admin_id
SET c.company_id = u.company_id
WHERE c.company_id IS NULL;
