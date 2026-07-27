-- 071: Jewellery Accounting — Phase 2. Item master, dual-unit stock ledger
-- and opening stock.
--
-- THE DUAL LEDGER. jewellery_stock_txns is the one table every metal movement
-- flows through, and it carries BOTH books on every row:
--   * the money book — rate and amount, reconciled to the voucher in voucher_id
--   * the metal book — gross_weight, fine_weight, metal_id, purity_id and the
--     HOLDER (own stock / a karigar / a refinery / a customer)
-- That holder column is what lets the system answer "how many tola of 22K is
-- with karigar Ram right now?" — a question the money ledger alone cannot.
--
-- Movements are recorded, never mutated: a correction is a reversing row, so
-- the weight history stays auditable alongside the voucher trail.

-- ---------------------------------------------------------------------------
-- 1. Item master. An item pins ONE metal and ONE purity; mixed-purity goods
--    are separate items. Stones are carried as a weight and a value alongside
--    the metal so a diamond ring's VAT can be based on the stone alone.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `jewellery_items` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `code` VARCHAR(60) NOT NULL,
  `name` VARCHAR(190) NOT NULL,
  `category` VARCHAR(120) DEFAULT NULL,
  `item_type` ENUM('ornament','bullion','stone','other') NOT NULL DEFAULT 'ornament',
  `metal_id` INT UNSIGNED NOT NULL,
  `purity_id` INT UNSIGNED NOT NULL,
  `unit_id` INT UNSIGNED NOT NULL,
  -- 'weight' items price off their weight; 'piece' items (a fixed-price chain,
  -- a loose stone lot) price per piece and keep weight for information only.
  `track_mode` ENUM('weight','piece') NOT NULL DEFAULT 'weight',
  `gross_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `stone_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `net_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `wastage_pct` DECIMAL(6,3) NOT NULL DEFAULT 0.000,
  -- 'default' defers to jewellery_settings, so a shop-wide change of policy
  -- does not mean editing every item.
  `making_charge_basis` ENUM('default','per_unit_weight','percent_of_metal','flat') NOT NULL DEFAULT 'default',
  `making_charge_rate` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `stone_value` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  -- Per-item VAT: gold ornaments are commonly taxed on the making charge only
  -- while diamond and gem lines are taxed on full value. Both live here.
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 2. The dual-unit stock ledger. metal_id / purity_id are denormalised from
--    the item on purpose: old gold taken in exchange arrives at a purity that
--    is NOT the item's, and a metal-position report must not have to guess.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `jewellery_stock_txns` (
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
  -- Where the metal physically sits after this row. Own stock is the default;
  -- karigar and refinery rows are what make the outstanding-metal reports work.
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 3. Opening stock. Held as a draft the shop can revise, then POSTED once —
--    which both writes the opening stock_txn row and creates the opening
--    voucher (Dr stock, Cr opening equity). One row per item per fiscal year.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `jewellery_opening_stock` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 4. Now that jewellery_items exists, the item-scoped ledger mapping can carry
--    its foreign key.
-- ---------------------------------------------------------------------------
ALTER TABLE `jewellery_ledger_mappings`
  ADD CONSTRAINT `fk_jw_mapping_item` FOREIGN KEY (`item_id`) REFERENCES `jewellery_items` (`id`) ON DELETE CASCADE;
