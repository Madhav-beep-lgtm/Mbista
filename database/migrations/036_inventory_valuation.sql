-- Migration 036: IAS 2 inventory valuation foundation.
--
-- Additive only. Extends inventory_items with valuation method + master fields,
-- and adds the perpetual cost-layer store, NRV assessments, and scoped ledger
-- mappings (global -> category -> item precedence). Existing inventory data and
-- postings are untouched; every item keeps working with the default method.

-- ---------------------------------------------------------------------------
-- 1. Item master: valuation method + classification + stock-control fields
-- ---------------------------------------------------------------------------
ALTER TABLE `inventory_items`
  MODIFY COLUMN `item_type` ENUM(
    'stock', 'service', 'raw_material', 'work_in_progress', 'finished_good',
    'trading_good', 'consumable', 'packing_material', 'spare_part',
    'by_product', 'scrap'
  ) NOT NULL DEFAULT 'stock';

ALTER TABLE `inventory_items`
  ADD COLUMN IF NOT EXISTS `valuation_method` ENUM('fifo', 'weighted_average', 'specific') NOT NULL DEFAULT 'weighted_average' AFTER `item_type`,
  ADD COLUMN IF NOT EXISTS `category` VARCHAR(120) DEFAULT NULL AFTER `name`,
  ADD COLUMN IF NOT EXISTS `sub_category` VARCHAR(120) DEFAULT NULL AFTER `category`,
  ADD COLUMN IF NOT EXISTS `short_name` VARCHAR(120) DEFAULT NULL AFTER `name`,
  ADD COLUMN IF NOT EXISTS `barcode` VARCHAR(120) DEFAULT NULL AFTER `hs_code`,
  ADD COLUMN IF NOT EXISTS `country_of_origin` VARCHAR(80) DEFAULT NULL AFTER `barcode`,
  ADD COLUMN IF NOT EXISTS `min_stock` DECIMAL(14,3) NOT NULL DEFAULT 0.000 AFTER `reorder_level`,
  ADD COLUMN IF NOT EXISTS `max_stock` DECIMAL(14,3) NOT NULL DEFAULT 0.000 AFTER `min_stock`,
  ADD COLUMN IF NOT EXISTS `safety_stock` DECIMAL(14,3) NOT NULL DEFAULT 0.000 AFTER `max_stock`,
  ADD COLUMN IF NOT EXISTS `allow_negative_stock` TINYINT(1) NOT NULL DEFAULT 0 AFTER `safety_stock`;

-- ---------------------------------------------------------------------------
-- 2. Perpetual cost layers (FIFO + specific identification)
--    Weighted-average items use a single rolling layer (qty/value) but the
--    same store, so the stock ledger reconciles uniformly.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `inventory_cost_layers` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `item_id` INT UNSIGNED NOT NULL,
  `warehouse_id` INT UNSIGNED DEFAULT NULL,
  `batch_no` VARCHAR(80) DEFAULT NULL,
  `identity` VARCHAR(120) DEFAULT NULL COMMENT 'specific-identification unit/serial key',
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
  CONSTRAINT `fk_inv_layers_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_inv_layers_item` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 3. NRV assessments (lower of cost and net realisable value, IAS 2 para 28-33)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `inventory_nrv_assessments` (
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
  CONSTRAINT `fk_inv_nrv_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_inv_nrv_item` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_inv_nrv_voucher` FOREIGN KEY (`voucher_id`) REFERENCES `vouchers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 4. Scoped ledger mappings: global -> category -> item precedence.
--    purpose = inventory_asset, raw_material, wip, finished_goods, trading_goods,
--    cogs, purchase_clearing, sales_revenue, sales_return, purchase_return,
--    inventory_gain, inventory_loss, write_down_expense, write_down_allowance,
--    write_down_reversal, scrap_inventory, scrap_income, overhead_control,
--    overhead_absorbed, material_variance, labour_variance, overhead_variance,
--    tax_input, tax_output, discount, rounding, opening_equity  (etc.)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `inventory_ledger_mappings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `scope` ENUM('global', 'category', 'item') NOT NULL DEFAULT 'global',
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
  CONSTRAINT `fk_inv_mapping_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_inv_mapping_item` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_inv_mapping_ledger` FOREIGN KEY (`ledger_id`) REFERENCES `ledgers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
