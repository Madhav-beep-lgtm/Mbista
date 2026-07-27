-- 079: Taxes become data, not code. Plus stone weight and wastage on the line.
--
-- VAT was hard-wired: one rate in settings, one flag on the item, one formula
-- in jw_compute_document(). That answered exactly one question — "is this item
-- VATable?" — and nothing else. Nepal's jewellery trade needs more than that
-- today: Skills Promotion Tax at 0.5% sits on the metal-plus-wastage-plus-
-- making value, VAT applies only to diamond this year, and both of those are
-- ordinary government decisions that will change again.
--
-- So a tax is now a ROW. It carries its own rate, its own base, whether it
-- applies to every item or only tagged ones, and where in the sequence it
-- lands. VAT is simply the tax with the highest sequence and a base that
-- includes the taxes before it — which is what "VAT is the final tax" means
-- arithmetically. Levying a new tax next year is an INSERT, not a release.
--
-- Two supporting changes on the line, both of which the tax bases need:
--
--   stone_weight  Gross weight includes the stones. Charging the gold rate on
--                 a diamond's weight overstates the metal, and the Skills
--                 Promotion Tax base is explicitly the weight LESS anything
--                 that is not gold or silver. Net weight is now carried, and
--                 the metal value and fine content are computed from it.
--   wastage_pct   A shop quotes wastage as a percentage and expects to see the
--                 money. It is metal, so it is valued at the metal rate, and it
--                 forms part of the Skills Promotion Tax base.
--
-- Existing rows are unaffected: stone_weight and wastage_pct default to zero,
-- net weight then equals gross, and every figure computes exactly as before.

-- ---------------------------------------------------------------------------
-- 1. The tax register
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `jewellery_taxes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `code` VARCHAR(20) NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `rate` DECIMAL(9,4) NOT NULL DEFAULT 0.0000,
  -- What the rate is charged on. metal_wastage_making is the Skills Promotion
  -- Tax base; subtotal_with_taxes is what makes a tax apply LAST, on top of
  -- every tax with a lower sequence.
  `base` ENUM('metal','making','stone','wastage','metal_making','metal_wastage_making','subtotal','subtotal_with_taxes')
      NOT NULL DEFAULT 'subtotal',
  -- 'all' charges every line; 'tagged' charges only items linked below, which
  -- is how "VAT on diamond only, this year" is expressed.
  `applies_to` ENUM('all','tagged') NOT NULL DEFAULT 'all',
  `doc_types` SET('sale','purchase') NOT NULL DEFAULT 'sale,purchase',
  -- Low numbers are charged first. A tax based on subtotal_with_taxes sees
  -- every tax with a strictly lower sequence.
  `sequence` INT NOT NULL DEFAULT 100,
  -- The amount is punched by hand on the document instead of computed. The
  -- computed figure is still shown, so the entered one can be checked.
  `manual_entry` TINYINT(1) NOT NULL DEFAULT 0,
  -- Which posting purpose the collected tax is credited to.
  `output_purpose` VARCHAR(40) NOT NULL DEFAULT 'vat_output',
  `input_purpose` VARCHAR(40) NOT NULL DEFAULT 'vat_input',
  -- A tax that ends is not deleted — last year's invoices must still reprice.
  `effective_from` DATE DEFAULT NULL,
  `effective_to` DATE DEFAULT NULL,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `notes` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_jw_tax_code` (`company_id`, `code`),
  KEY `idx_jw_tax_active` (`company_id`, `active`, `sequence`),
  CONSTRAINT `fk_jw_tax_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which items a 'tagged' tax reaches.
CREATE TABLE IF NOT EXISTS `jewellery_item_taxes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `item_id` INT UNSIGNED NOT NULL,
  `tax_id` INT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_jw_item_tax` (`item_id`, `tax_id`),
  KEY `idx_jw_item_tax_company` (`company_id`, `tax_id`),
  CONSTRAINT `fk_jw_item_tax_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jw_item_tax_item` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jw_item_tax_tax` FOREIGN KEY (`tax_id`) REFERENCES `jewellery_taxes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The per-line breakdown, so a tax register can be produced for any tax
-- without re-deriving it from a formula that may since have changed.
CREATE TABLE IF NOT EXISTS `jewellery_line_taxes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `doc_type` ENUM('sale','purchase') NOT NULL,
  `doc_id` INT UNSIGNED NOT NULL,
  `line_id` INT UNSIGNED NOT NULL,
  `tax_id` INT UNSIGNED DEFAULT NULL,
  `tax_code` VARCHAR(20) NOT NULL,
  `tax_name` VARCHAR(120) NOT NULL,
  `base_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `rate` DECIMAL(9,4) NOT NULL DEFAULT 0.0000,
  `amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `sequence` INT NOT NULL DEFAULT 100,
  `output_purpose` VARCHAR(40) NOT NULL DEFAULT 'vat_output',
  `input_purpose` VARCHAR(40) NOT NULL DEFAULT 'vat_input',
  PRIMARY KEY (`id`),
  KEY `idx_jw_ltax_doc` (`company_id`, `doc_type`, `doc_id`),
  KEY `idx_jw_ltax_line` (`doc_type`, `line_id`),
  KEY `idx_jw_ltax_tax` (`company_id`, `tax_id`),
  CONSTRAINT `fk_jw_ltax_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jw_ltax_tax` FOREIGN KEY (`tax_id`) REFERENCES `jewellery_taxes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 2. Stone weight, wastage and the tax total on every line
-- ---------------------------------------------------------------------------
ALTER TABLE `jewellery_sale_lines`
  ADD COLUMN IF NOT EXISTS `stone_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000 AFTER `gross_weight`,
  ADD COLUMN IF NOT EXISTS `net_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000 AFTER `stone_weight`,
  ADD COLUMN IF NOT EXISTS `wastage_pct` DECIMAL(9,3) NOT NULL DEFAULT 0.000 AFTER `metal_amount`,
  ADD COLUMN IF NOT EXISTS `wastage_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `wastage_pct`,
  ADD COLUMN IF NOT EXISTS `tax_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `vat_amount`;

ALTER TABLE `jewellery_purchase_lines`
  ADD COLUMN IF NOT EXISTS `stone_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000 AFTER `gross_weight`,
  ADD COLUMN IF NOT EXISTS `net_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000 AFTER `stone_weight`,
  ADD COLUMN IF NOT EXISTS `wastage_pct` DECIMAL(9,3) NOT NULL DEFAULT 0.000 AFTER `metal_amount`,
  ADD COLUMN IF NOT EXISTS `wastage_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `wastage_pct`,
  ADD COLUMN IF NOT EXISTS `tax_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `vat_amount`;

-- Existing lines: net weight is the gross, because no stone weight was ever
-- recorded against them and inventing one would restate posted documents.
UPDATE `jewellery_sale_lines` SET `net_weight` = `gross_weight` WHERE `net_weight` = 0 AND `gross_weight` <> 0;
UPDATE `jewellery_purchase_lines` SET `net_weight` = `gross_weight` WHERE `net_weight` = 0 AND `gross_weight` <> 0;

ALTER TABLE `jewellery_sales`
  ADD COLUMN IF NOT EXISTS `wastage_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `metal_amount`,
  ADD COLUMN IF NOT EXISTS `tax_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `vat_amount`,
  -- A manually punched tax total overrides the computed one; NULL means "use
  -- what was computed", which is not the same as a punched zero.
  ADD COLUMN IF NOT EXISTS `manual_tax_amount` DECIMAL(18,2) DEFAULT NULL AFTER `tax_amount`;

ALTER TABLE `jewellery_purchases`
  ADD COLUMN IF NOT EXISTS `wastage_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `metal_amount`,
  ADD COLUMN IF NOT EXISTS `tax_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `vat_amount`,
  ADD COLUMN IF NOT EXISTS `manual_tax_amount` DECIMAL(18,2) DEFAULT NULL AFTER `tax_amount`;
