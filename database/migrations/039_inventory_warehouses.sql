-- Migration 039: inventory warehouse/location dimension.
--
-- Additive only. Adds a per-company warehouses master table and links it from
-- inventory_items (default warehouse), inventory_transactions (movement
-- warehouse + transfer destination), and inventory_cost_layers (already had
-- an unused warehouse_id column from migration 036 — now wired up). Existing
-- data keeps working unassigned (NULL = "Unassigned/Main location"); on-hand
-- and GL posting are unaffected. Quantity can be reported by warehouse via
-- SUM(qty_in - qty_out) GROUP BY warehouse_id; cost layers remain valued at
-- company+item level (not split per warehouse) — a deliberate scope limit to
-- avoid destabilising the tested FIFO/weighted-average/specific engine.

CREATE TABLE IF NOT EXISTS `warehouses` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `code` VARCHAR(40) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_warehouse_company_name` (`company_id`, `name`),
  KEY `idx_warehouse_company_active` (`company_id`, `is_active`),
  CONSTRAINT `fk_warehouse_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `inventory_items`
  ADD COLUMN IF NOT EXISTS `default_warehouse_id` INT UNSIGNED DEFAULT NULL AFTER `allow_negative_stock`;

ALTER TABLE `inventory_transactions`
  ADD COLUMN IF NOT EXISTS `warehouse_id` INT UNSIGNED DEFAULT NULL AFTER `item_id`,
  ADD COLUMN IF NOT EXISTS `to_warehouse_id` INT UNSIGNED DEFAULT NULL AFTER `warehouse_id`;
