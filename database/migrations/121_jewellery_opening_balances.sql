-- 121_jewellery_opening_balances.sql
--
-- Perpetual succession for jewellery stock: the closing position of one fiscal
-- year, recorded as the opening position of the next.
--
-- Until now a jewellery opening lived in inventory_items.opening_qty /
-- opening_amount — ONE year-less pair per item — so a company could only ever
-- hold one opening for its whole life. Saving an opening while a later year was
-- selected re-dated the earlier year's opening onto the new year rather than
-- creating a second one, which is the opposite of what a fiscal-year boundary
-- is for.
--
-- This table is the per-year statement of what was brought forward. It is a
-- QUANTITY AND WEIGHT record, not a posting: the value side already carries
-- through the Opening Balances batch, where the stock ledgers and each
-- "Metal with <kaligad>" ledger are ordinary assets. Generating a carry
-- therefore writes nothing to the general ledger, and only a deliberate
-- adjustment against a physical count does.
--
-- One row per item PER HOLDER, because at a year end metal is not all in one
-- place: some sits in the showroom, some is out with a kaligad, some is with a
-- refiner, and some is made but not yet collected. Folded into a single figure
-- the boundary cannot be reconciled against the ledgers that hold those same
-- positions in money.

CREATE TABLE IF NOT EXISTS `jewellery_opening_balances` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `fiscal_year_id` INT UNSIGNED NOT NULL,
  `item_id` INT UNSIGNED NOT NULL,
  `holder_type` ENUM('stock','karigar','refinery','customer') NOT NULL DEFAULT 'stock',
  -- 0, never NULL. MySQL treats NULLs as distinct in a UNIQUE key, so a
  -- nullable holder would let the same item and holder be carried twice.
  `holder_id` INT UNSIGNED NOT NULL DEFAULT 0,
  -- Within the showroom: held against a customer order rather than free to sell.
  `reserved` TINYINT(1) NOT NULL DEFAULT 0,
  -- GRAMS, like jewellery_stock_txns.gross_grams / fine_grams and never like
  -- its gross_weight, which is in each item's own unit. One statement spans
  -- items kept in tola and items kept in grams, so it has to be canonical; the
  -- screen converts back to the item's unit when it draws the line.
  `qty_pieces` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
  `gross_grams` DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  `stone_grams` DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  `fine_grams` DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  `amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  -- What the REPLAY said, frozen when the line was generated and never touched
  -- by an adjustment. An adjustment posts the difference between the counted
  -- figure and this one; measured against the live figure instead, correcting
  -- the same line twice would post the second difference on top of the first
  -- and the books would drift by the amount of the first correction.
  `carried_gross_grams` DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  `carried_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  -- carried  = replayed from the previous year's closing
  -- initial  = first year, seeded from the item master
  -- adjusted = a physical count differed and somebody said why
  `source` ENUM('carried','initial','adjusted') NOT NULL DEFAULT 'carried',
  `adjust_reason` VARCHAR(255) DEFAULT NULL,
  `adjustment_voucher_id` INT UNSIGNED DEFAULT NULL,
  `adjusted_by` INT UNSIGNED DEFAULT NULL,
  `adjusted_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_jw_ob_line` (`fiscal_year_id`, `item_id`, `holder_type`, `holder_id`, `reserved`),
  KEY `idx_jw_ob_company_fy` (`company_id`, `fiscal_year_id`),
  KEY `idx_jw_ob_item` (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
