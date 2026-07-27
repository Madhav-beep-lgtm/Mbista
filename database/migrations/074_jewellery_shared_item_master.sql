-- 074: De-duplicate the item master — jewellery items BECOME inventory items.
--
-- THE PROBLEM THIS FIXES. jewellery_items re-implemented inventory_items:
-- sku/code, name, category, item_type, unit, hs_code, reorder level, status,
-- notes and a stock ledger all existed twice. A company running the Jewellery
-- module therefore had two item masters, two stock lists and two opening-stock
-- screens that never agreed, and the core Inventory page could not see a
-- single gold chain.
--
-- THE SHAPE. There is now ONE item master, inventory_items, and a
-- table-per-subtype extension holding only what is genuinely jewellery:
-- metal, purity, the unit as a real FK, the weight breakdown, making-charge
-- policy and the per-item VAT base. Everything that was generic moves onto
-- inventory_items where it belonged.
--
-- The consequences are the point: a jewellery item now appears in the core
-- Inventory list, in stock valuation, and — because ob_inventory_opening()
-- reads inventory_items — in the existing Opening Balances reconciliation
-- alongside fixed assets and ledger openings, instead of in a jewellery-only
-- screen of its own.
--
-- The row migration and the foreign-key repointing cannot be expressed in
-- portable SQL (they need an old-id -> new-id map), so they live in the
-- matching accounting_module_repair_database() step, which is also the upgrade
-- path that actually runs in production.

CREATE TABLE IF NOT EXISTS `jewellery_item_profiles` (
  `inventory_item_id` INT UNSIGNED NOT NULL,
  `company_id` INT UNSIGNED NOT NULL,
  `metal_id` INT UNSIGNED NOT NULL,
  `purity_id` INT UNSIGNED NOT NULL,
  `unit_id` INT UNSIGNED NOT NULL,
  -- The jewellery-side classification. inventory_items.item_type carries the
  -- generic equivalent (finished_good / raw_material) so the core module can
  -- reason about it, but the stock-ledger purpose ladder needs this one.
  `jewellery_type` ENUM('ornament','bullion','stone','other') NOT NULL DEFAULT 'ornament',
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
  `hallmark` VARCHAR(60) DEFAULT NULL,
  `design_no` VARCHAR(60) DEFAULT NULL,
  `reorder_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`inventory_item_id`),
  KEY `idx_jw_profile_company` (`company_id`),
  KEY `idx_jw_profile_metal` (`company_id`, `metal_id`, `purity_id`),
  KEY `idx_jw_profile_purity` (`purity_id`),
  KEY `idx_jw_profile_unit` (`unit_id`),
  CONSTRAINT `fk_jw_profile_item` FOREIGN KEY (`inventory_item_id`) REFERENCES `inventory_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jw_profile_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jw_profile_metal` FOREIGN KEY (`metal_id`) REFERENCES `jewellery_metals` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_jw_profile_purity` FOREIGN KEY (`purity_id`) REFERENCES `jewellery_purities` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_jw_profile_unit` FOREIGN KEY (`unit_id`) REFERENCES `jewellery_units` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
