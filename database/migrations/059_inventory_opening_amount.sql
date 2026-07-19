-- Inventory opening = QUANTITY + AMOUNT (like the accounting opening
-- balances), never quantity x the CURRENT purchase rate — a later rate change
-- must not silently re-value history. Existing openings are frozen at their
-- present valuation once.
ALTER TABLE `inventory_items`
  ADD COLUMN `opening_amount` DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER `opening_qty`;

UPDATE `inventory_items`
   SET `opening_amount` = ROUND(`opening_qty` * `purchase_rate`, 2)
 WHERE `opening_amount` = 0 AND `opening_qty` > 0;

-- Per-fiscal-year inventory opening rows: generated (carried) from the
-- previous year's replayed closing, adjustable, and governed by the SAME
-- opening-balance batch lifecycle (finalize / lock / unlock) as accounting.
CREATE TABLE IF NOT EXISTS `inventory_opening_balances` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `fiscal_year_id` INT UNSIGNED NOT NULL,
  `item_id` INT UNSIGNED NOT NULL,
  `qty` DECIMAL(14,3) NOT NULL DEFAULT 0,
  `amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `source` ENUM('carried','initial','adjusted') NOT NULL DEFAULT 'carried',
  `adjust_reason` VARCHAR(255) DEFAULT NULL,
  `adjusted_by` INT UNSIGNED DEFAULT NULL,
  `adjusted_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_inv_ob_fy_item` (`fiscal_year_id`, `item_id`),
  KEY `idx_inv_ob_company` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
