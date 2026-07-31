-- 103: One issue, several things in the kaligad's hand.
--
-- A diamond ring is gold AND diamonds, and they go out together in one packet.
-- The assignment could hold one item at one purity, so a shop making stone-set
-- work had to raise a second issue for the stones — which put them on a
-- separate document, under a separate number, with their own wastage
-- arithmetic over a weight that has no wastage.
--
-- A component is one thing handed over on one issue:
--
--     gold        weighed in the shop's metal unit, at a purity, and its FINE
--                 equivalent is what the kaligad is answerable for
--     diamond     weighed in carats, at the standard 1000 purity the masters
--     / stone     already carry for stones, and carrying NO fine at all
--
-- That last point is the whole reason this table exists rather than a second
-- weight column. Migration 095 learned it on the receive side: run a fine
-- calculation over stones and you credit the kaligad with pure metal he never
-- held, and understate his wastage by exactly the stones' weight in fine. The
-- same is true going out. So the header's issued_fine_weight keeps meaning
-- exactly what it has always meant — METAL issued — and every wastage
-- calculation downstream is untouched. Stones total separately.

CREATE TABLE IF NOT EXISTS `jewellery_assignment_components` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `assignment_id` INT UNSIGNED NOT NULL,
  `item_id` INT UNSIGNED NOT NULL,
  -- Derived from the item's metal, not typed: the masters already know a
  -- diamond from a bar of gold, and asking twice invites the two to disagree.
  `component_kind` ENUM('metal','stone') NOT NULL DEFAULT 'metal',
  `purity_id` INT UNSIGNED NOT NULL,
  `unit_id` INT UNSIGNED NOT NULL,
  `gross_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `fine_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `qty_carat` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `rate` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `issue_date` DATE NOT NULL,
  `stock_txn_out` INT UNSIGNED DEFAULT NULL,
  `stock_txn_in` INT UNSIGNED DEFAULT NULL,
  `voucher_id` INT UNSIGNED DEFAULT NULL,
  `notes` VARCHAR(255) DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_jw_acomp_assignment` (`assignment_id`),
  KEY `idx_jw_acomp_company_kind` (`company_id`, `component_kind`),
  KEY `idx_jw_acomp_item` (`item_id`),
  KEY `idx_jw_acomp_voucher` (`voucher_id`),
  CONSTRAINT `fk_jw_acomp_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jw_acomp_assignment` FOREIGN KEY (`assignment_id`) REFERENCES `jewellery_order_assignments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jw_acomp_item` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_jw_acomp_purity` FOREIGN KEY (`purity_id`) REFERENCES `jewellery_purities` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_jw_acomp_unit` FOREIGN KEY (`unit_id`) REFERENCES `jewellery_units` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_jw_acomp_voucher` FOREIGN KEY (`voucher_id`) REFERENCES `vouchers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The stones' side of the header, beside the metal's. Kept apart for the
-- reason above: they must never be added into a fine weight.
ALTER TABLE `jewellery_order_assignments`
  ADD COLUMN IF NOT EXISTS `issued_stone_carat` DECIMAL(18,4) NOT NULL DEFAULT 0.0000 AFTER `issued_fine_weight`,
  ADD COLUMN IF NOT EXISTS `issued_stone_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `issued_stone_carat`;
