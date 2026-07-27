-- 087: An order can hold more than one item, and it quotes a real total.
--
-- A customer walks in and orders a ring AND a chain. The order carried one
-- item_id, so that was two orders, two order numbers, two advances and two
-- deliveries for one conversation. And it quoted nothing: the order held an
-- expected weight and a making rate, but no metal value, no wastage, no
-- stones, no VAT and no Skills Development levy — so the one question the
-- customer actually asks, "what will it come to?", could not be answered from
-- the screen that took the order.
--
-- The lines below are the SAME shape as jewellery_sale_lines on purpose. An
-- order is a sale that has not happened yet, and it is priced by the same
-- engine under the same rules, so when it is delivered the bill is the quote
-- with a date on it rather than a fresh piece of arithmetic.
--
-- The single-item columns on the header STAY, mirroring the first line. The
-- karigar issue, the receipt and the refinery all read the order's purity and
-- unit to value the metal they move; repointing them at a line would be a much
-- larger change for no gain, and the first line is the specification in every
-- real case. They are kept in step by jewellery_save_order().

CREATE TABLE IF NOT EXISTS `jewellery_order_lines` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` INT UNSIGNED NOT NULL,
  `company_id` INT UNSIGNED NOT NULL,
  `item_id` INT UNSIGNED NOT NULL,
  `purity_id` INT UNSIGNED NOT NULL,
  `unit_id` INT UNSIGNED NOT NULL,
  `qty_pieces` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
  `gross_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `stone_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `net_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `fine_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `rate` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `metal_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `wastage_pct` DECIMAL(9,3) NOT NULL DEFAULT 0.000,
  `wastage_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `total_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `wastage_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `making_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `stone_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `diamond_carat` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `diamond_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `other_diamond_carat` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `other_diamond_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `stone_carat` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `vat_base` ENUM('none','full_value','making_only','stone_only','stone_diamond') NOT NULL DEFAULT 'none',
  `vat_rate` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
  `vat_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `tax_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `allocated_adjust` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `line_total` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `notes` VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_jw_oline_order` (`order_id`),
  KEY `idx_jw_oline_item` (`company_id`, `item_id`),
  CONSTRAINT `fk_jw_oline_order` FOREIGN KEY (`order_id`) REFERENCES `jewellery_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jw_oline_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jw_oline_item` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`id`),
  CONSTRAINT `fk_jw_oline_purity` FOREIGN KEY (`purity_id`) REFERENCES `jewellery_purities` (`id`),
  CONSTRAINT `fk_jw_oline_unit` FOREIGN KEY (`unit_id`) REFERENCES `jewellery_units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The quote. Same names as the sale header, so the delivered bill and the
-- order it came from can be read side by side without translation.
ALTER TABLE `jewellery_orders`
  ADD COLUMN IF NOT EXISTS `metal_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `making_rate`,
  ADD COLUMN IF NOT EXISTS `wastage_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `metal_amount`,
  ADD COLUMN IF NOT EXISTS `making_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `wastage_amount`,
  ADD COLUMN IF NOT EXISTS `stone_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `making_amount`,
  ADD COLUMN IF NOT EXISTS `diamond_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `stone_amount`,
  ADD COLUMN IF NOT EXISTS `other_charges` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `diamond_amount`,
  ADD COLUMN IF NOT EXISTS `discount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `other_charges`,
  ADD COLUMN IF NOT EXISTS `taxable_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `discount`,
  ADD COLUMN IF NOT EXISTS `non_taxable_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `taxable_amount`,
  ADD COLUMN IF NOT EXISTS `sd_taxable_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `non_taxable_amount`,
  ADD COLUMN IF NOT EXISTS `vatable_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `sd_taxable_amount`,
  ADD COLUMN IF NOT EXISTS `vat_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `vatable_amount`,
  ADD COLUMN IF NOT EXISTS `tax_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `vat_amount`,
  ADD COLUMN IF NOT EXISTS `manual_tax_amount` DECIMAL(18,2) DEFAULT NULL AFTER `tax_amount`,
  ADD COLUMN IF NOT EXISTS `total_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `manual_tax_amount`;

-- Carry every order already taken into a line, so nothing that exists loses
-- its item. Priced at zero: the old order never held a metal rate, and
-- inventing one now would put a figure in front of a customer that nobody
-- quoted. Re-saving the order prices it properly.
INSERT INTO `jewellery_order_lines`
    (`order_id`, `company_id`, `item_id`, `purity_id`, `unit_id`, `qty_pieces`,
     `gross_weight`, `net_weight`, `fine_weight`, `total_weight`)
SELECT o.`id`, o.`company_id`, o.`item_id`, o.`purity_id`, o.`unit_id`, 1,
       o.`expected_gross_weight`, o.`expected_gross_weight`, o.`expected_fine_weight`,
       o.`expected_gross_weight`
  FROM `jewellery_orders` o
 WHERE o.`item_id` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `jewellery_order_lines` l WHERE l.`order_id` = o.`id`);
