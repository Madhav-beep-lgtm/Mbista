-- 063: Hospitality Accounting — recipe costing and ESTIMATED gross profit.
--
-- REFERENCE-ONLY module for hospitality clients (restaurants, cafés, hotels,
-- bakeries, bars, catering, cloud kitchens). It reads posted sales and keeps
-- its own recipe/costing tables. It never creates vouchers, never posts COGS,
-- never touches inventory, stock, ledgers, or statutory reports.
--
-- Activation: per CLIENT flag on client_profiles, Super Admin only. All data
-- tables are scoped to the client's books company (companies.id) like every
-- other tenant-owned accounting table.

-- 1. Client-level feature flag (default OFF for everyone).
ALTER TABLE `client_profiles`
  ADD COLUMN `hospitality_accounting_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_active`;

-- 2. Tenant-specific hospitality settings.
CREATE TABLE IF NOT EXISTS `hospitality_settings` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Ingredient master (reference costing only — NOT inventory).
CREATE TABLE IF NOT EXISTS `hospitality_ingredients` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Ingredient reference-cost history.
CREATE TABLE IF NOT EXISTS `hospitality_ingredient_costs` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Menu item master.
CREATE TABLE IF NOT EXISTS `hospitality_menu_items` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Recipe versions per menu item.
CREATE TABLE IF NOT EXISTS `hospitality_recipes` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Recipe ingredient lines. RESTRICT on the ingredient: an ingredient used
--    by any recipe cannot be hard-deleted (deactivate instead).
CREATE TABLE IF NOT EXISTS `hospitality_recipe_lines` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Sales-to-menu-item mapping (never touches the sales records themselves).
CREATE TABLE IF NOT EXISTS `hospitality_sales_mappings` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Daily costing runs + immutable line snapshots.
CREATE TABLE IF NOT EXISTS `hospitality_costing_runs` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `hospitality_costing_lines` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Recalculation audit (before/after + mandatory reason).
CREATE TABLE IF NOT EXISTS `hospitality_recalc_history` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
