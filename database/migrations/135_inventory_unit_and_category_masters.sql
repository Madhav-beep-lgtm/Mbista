-- A unit and a purchase category are chosen from a list, not typed.
--
-- Both were free text on the item: `unit VARCHAR(40)` and
-- `category VARCHAR(120)`. Free text on a field that groups a report is an
-- invitation to a second spelling -- this database already carries a category
-- called ",jhgfd" sitting beside "Gold Ornaments" -- and every misspelling is
-- a report line that does not add up with the one above it.
--
-- Units form a chain: box > packet > piece. Rather than walk a parent link at
-- every read, each unit names the SMALLEST unit of its chain and how many of
-- that smallest unit it is worth. A base unit names no other and is worth one:
--
--   piece   base_unit_id NULL   factor 1
--   packet  base_unit_id piece  factor 10
--   box     base_unit_id piece  factor 100
--
-- factor is DECIMAL(18,6) because a chain is not always whole numbers -- a
-- carton of 2.5 kg bags is a real thing -- while quantities themselves stay at
-- three decimals as they are everywhere else in this application.
CREATE TABLE IF NOT EXISTS `inventory_units` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(40) NOT NULL,
  `base_unit_id` INT UNSIGNED DEFAULT NULL,
  `factor` DECIMAL(18,6) NOT NULL DEFAULT 1.000000,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_inventory_units_company_name` (`company_id`, `name`),
  KEY `idx_inventory_units_base` (`base_unit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `inventory_categories` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_inventory_categories_company_name` (`company_id`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The item points at the masters. The old text columns are left in place and
-- still written: they are read by reports, exports and the jewellery module,
-- and emptying them would be a far larger change than this one.
ALTER TABLE `inventory_items`
  ADD COLUMN `unit_id` INT UNSIGNED DEFAULT NULL AFTER `unit`,
  ADD COLUMN `category_id` INT UNSIGNED DEFAULT NULL AFTER `category`;
