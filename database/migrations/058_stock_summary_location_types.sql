-- Department/location-specific item classification: the same item can be
-- Finished Goods for the producing location and Raw Material for the
-- consuming one. The master inventory_items.item_type stays as the fallback.
CREATE TABLE IF NOT EXISTS `inventory_item_location_types` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `item_id` INT UNSIGNED NOT NULL,
  `warehouse_id` INT UNSIGNED NOT NULL,
  `item_type` VARCHAR(30) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_item_location_type` (`item_id`, `warehouse_id`),
  KEY `idx_item_location_types_company` (`company_id`),
  KEY `idx_item_location_types_warehouse` (`warehouse_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
