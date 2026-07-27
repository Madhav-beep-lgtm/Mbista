-- 070: Jewellery Accounting — Phase 1 foundation.
--
-- A full jewellery vertical: dual-unit stock (weight AND value), daily metal
-- rates, karigar/kaligad order flow, refinery jobs, bill-wise party accounting
-- and automated double-entry posting. This first migration lays the masters
-- every later phase builds on.
--
-- Activation: per-CLIENT flag on client_profiles, Super Admin only — exactly
-- the Hospitality precedent. All data tables are scoped to the client's books
-- company (companies.id) like every other tenant-owned accounting table.
--
-- Weight model: every metal quantity is stored as a GROSS weight in a unit of
-- the company's own unit table, alongside the FINE weight (gross x fineness /
-- 1000). Fine weight is the common denominator that lets a 22K and a 24K
-- balance reconcile into one metal position.

-- ---------------------------------------------------------------------------
-- 1. Client-level feature flag (default OFF for everyone).
-- ---------------------------------------------------------------------------
ALTER TABLE `client_profiles`
  ADD COLUMN IF NOT EXISTS `jewellery_accounting_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `hospitality_accounting_enabled`;

-- ---------------------------------------------------------------------------
-- 2. Weight units. `grams` is the single conversion pivot: every unit declares
--    how many grams it is worth, so any unit converts to any other unit
--    without a hard-coded table of pairs. Seeded per company with gram, tola,
--    laal, ratti and carat; a company may add its own.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `jewellery_units` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 3. Metals and stones. `metal_kind` separates weight-and-purity metals (gold,
--    silver) from stones priced by carat (diamond) and everything else.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `jewellery_metals` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 4. Purities. `fineness` is parts per 1000 (999.9 = 24K, 916 = 22K, 750 =
--    18K). Every metal carries at least one purity row — stones get a single
--    1000-fineness "standard" row — so downstream tables can always join on a
--    NOT NULL purity_id and unique keys stay sound.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `jewellery_purities` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 5. Tenant settings.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `jewellery_settings` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 6. Daily metal rate master (feature 5). One row per date/metal/purity/type;
--    `rate` is money per ONE `unit_id` of metal. Sales and purchases quote
--    their own rate types so a shop can run a buy/sell spread off one date.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `jewellery_daily_rates` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 7. Ledger mappings — the same item -> category -> global resolution ladder
--    the inventory module uses, so automated posting never guesses a ledger.
--    `item_id` gains its foreign key in Phase 2 with jewellery_items.
--
--    Purposes: stock_metal, stock_finished, stock_stone, stock_karigar,
--    stock_refinery, sales_metal, sales_making, sales_stone, sales_return,
--    purchase_clearing, purchase_return, cogs, vat_output, vat_input,
--    karigar_payable, making_expense, wastage_loss, refinery_loss,
--    refinery_charges, metal_exchange, stock_gain, stock_loss, rounding,
--    opening_equity.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `jewellery_ledger_mappings` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
