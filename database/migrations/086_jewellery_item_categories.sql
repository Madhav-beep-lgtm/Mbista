-- 086: Item categories become a master you set up once, not free text.
--
-- The item form offered a plain text box with a datalist of whatever had been
-- typed before. That is how a shop ends up with "Ring", "RING", "Rings" and
-- "ring " as four categories, and every report that groups by category then
-- shows four rows for one thing. The category is a heading the books are read
-- by — it belongs in Settings, decided once, not retyped per item.
--
-- inventory_items.category stays a VARCHAR holding the category NAME. That is
-- deliberate: every report already groups by that string, and the shared
-- inventory module writes it too. This table constrains what can be chosen
-- rather than replacing where it is stored, so nothing downstream changes.

CREATE TABLE IF NOT EXISTS `jewellery_item_categories` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_jw_category` (`company_id`, `name`),
  KEY `idx_jw_category_company` (`company_id`, `active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Adopt whatever is already in use, so no existing item becomes unselectable
-- the moment the box turns into a dropdown.
INSERT IGNORE INTO `jewellery_item_categories` (`company_id`, `name`, `sort_order`, `active`)
SELECT i.`company_id`, TRIM(i.`category`), 0, 1
  FROM `inventory_items` i
 INNER JOIN `jewellery_item_profiles` j ON j.`inventory_item_id` = i.`id`
 WHERE i.`category` IS NOT NULL AND TRIM(i.`category`) <> ''
 GROUP BY i.`company_id`, TRIM(i.`category`);
