-- Migration 038: manufacturing costing (IAS 2 cost accumulation).
-- Adds bills of materials, conversion-cost fields on production orders, and a
-- production variance register. Additive only.

CREATE TABLE IF NOT EXISTS `bom_headers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `bom_no` VARCHAR(80) NOT NULL,
  `version` INT UNSIGNED NOT NULL DEFAULT 1,
  `finished_item_id` INT UNSIGNED NOT NULL,
  `output_qty` DECIMAL(14,3) NOT NULL DEFAULT 1.000,
  `std_labour_cost` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `std_overhead_cost` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('draft', 'active', 'retired') NOT NULL DEFAULT 'active',
  `notes` TEXT DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_bom_company_no_version` (`company_id`, `bom_no`, `version`),
  KEY `idx_bom_company_item` (`company_id`, `finished_item_id`),
  CONSTRAINT `fk_bom_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bom_finished_item` FOREIGN KEY (`finished_item_id`) REFERENCES `inventory_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bom_lines` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bom_id` INT UNSIGNED NOT NULL,
  `item_id` INT UNSIGNED NOT NULL,
  `std_qty` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `waste_pct` DECIMAL(6,3) NOT NULL DEFAULT 0.000,
  `std_rate` DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  PRIMARY KEY (`id`),
  KEY `idx_bom_lines_bom` (`bom_id`),
  KEY `idx_bom_lines_item` (`item_id`),
  CONSTRAINT `fk_bom_lines_bom` FOREIGN KEY (`bom_id`) REFERENCES `bom_headers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bom_lines_item` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Conversion costs + BOM link on production orders (all default 0/NULL).
ALTER TABLE `manufacturing_orders`
  ADD COLUMN IF NOT EXISTS `bom_id` INT UNSIGNED DEFAULT NULL AFTER `finished_item_id`,
  ADD COLUMN IF NOT EXISTS `labour_cost` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `quantity`,
  ADD COLUMN IF NOT EXISTS `overhead_absorbed` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `labour_cost`,
  ADD COLUMN IF NOT EXISTS `byproduct_value` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `overhead_absorbed`,
  ADD COLUMN IF NOT EXISTS `abnormal_waste_cost` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `byproduct_value`;

CREATE TABLE IF NOT EXISTS `production_variances` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `manufacturing_order_id` INT UNSIGNED NOT NULL,
  `variance_type` VARCHAR(40) NOT NULL COMMENT 'material_price, material_usage, labour, overhead, yield',
  `standard_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `actual_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `variance` DECIMAL(18,2) NOT NULL DEFAULT 0.00 COMMENT 'positive = unfavourable (actual above standard)',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_prod_var_order` (`manufacturing_order_id`),
  KEY `idx_prod_var_company_type` (`company_id`, `variance_type`),
  CONSTRAINT `fk_prod_var_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_prod_var_order` FOREIGN KEY (`manufacturing_order_id`) REFERENCES `manufacturing_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
