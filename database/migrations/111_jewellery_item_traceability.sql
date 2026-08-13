-- 111: One traceable identity for every physical jewellery piece or stock lot.
--
-- Item masters describe a product. They do not identify the physical bangle
-- in the showcase. A trace unit does. It starts at opening/import, purchase or
-- a workshop assignment, records every custody/status change as an event, and
-- ends at the exact order and sale that disposed of it.

CREATE TABLE IF NOT EXISTS `jewellery_stock_units` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `fiscal_year_id` INT UNSIGNED DEFAULT NULL,
  `trace_code` VARCHAR(80) NOT NULL,
  `item_id` INT UNSIGNED NOT NULL,
  `purity_id` INT UNSIGNED NOT NULL,
  `unit_id` INT UNSIGNED NOT NULL,
  `stock_kind` ENUM('showroom','customer_ordered') NOT NULL DEFAULT 'showroom',
  `status` ENUM('planned','in_production','in_stock','reserved','sold','delivered','returned','cancelled') NOT NULL DEFAULT 'in_stock',
  `current_holder_type` ENUM('stock','karigar','refinery','customer') NOT NULL DEFAULT 'stock',
  `current_holder_id` INT UNSIGNED DEFAULT NULL,
  `qty_pieces` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
  `gross_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `stone_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `net_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `fine_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `cost_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `origin_type` VARCHAR(40) NOT NULL,
  `origin_id` INT UNSIGNED DEFAULT NULL,
  `origin_line_id` INT UNSIGNED DEFAULT NULL,
  `assignment_id` INT UNSIGNED DEFAULT NULL,
  `receipt_id` INT UNSIGNED DEFAULT NULL,
  `stock_order_no` VARCHAR(60) DEFAULT NULL,
  `customer_party_id` INT UNSIGNED DEFAULT NULL,
  `customer_name` VARCHAR(190) DEFAULT NULL,
  `customer_order_no` VARCHAR(120) DEFAULT NULL,
  `reserved_order_id` INT UNSIGNED DEFAULT NULL,
  `reserved_order_line_id` INT UNSIGNED DEFAULT NULL,
  `reserved_sale_id` INT UNSIGNED DEFAULT NULL,
  `reserved_sale_line_id` INT UNSIGNED DEFAULT NULL,
  `sold_sale_id` INT UNSIGNED DEFAULT NULL,
  `sold_sale_line_id` INT UNSIGNED DEFAULT NULL,
  `tag_no` VARCHAR(80) DEFAULT NULL,
  `notes` VARCHAR(255) DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_jw_trace_code` (`company_id`,`trace_code`),
  KEY `idx_jw_trace_item_status` (`company_id`,`item_id`,`status`),
  KEY `idx_jw_trace_ready` (`company_id`,`stock_kind`,`status`),
  KEY `idx_jw_trace_origin` (`company_id`,`origin_type`,`origin_id`,`origin_line_id`),
  KEY `idx_jw_trace_assignment` (`company_id`,`assignment_id`),
  KEY `idx_jw_trace_receipt` (`company_id`,`receipt_id`),
  KEY `idx_jw_trace_order` (`company_id`,`reserved_order_id`,`reserved_order_line_id`),
  KEY `idx_jw_trace_sale` (`company_id`,`sold_sale_id`,`sold_sale_line_id`),
  CONSTRAINT `fk_jw_trace_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jw_trace_item` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_jw_trace_purity` FOREIGN KEY (`purity_id`) REFERENCES `jewellery_purities` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_jw_trace_unit` FOREIGN KEY (`unit_id`) REFERENCES `jewellery_units` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `jewellery_stock_unit_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `stock_unit_id` INT UNSIGNED NOT NULL,
  `event_type` VARCHAR(40) NOT NULL,
  `event_date` DATE NOT NULL,
  `from_status` VARCHAR(30) DEFAULT NULL,
  `to_status` VARCHAR(30) DEFAULT NULL,
  `from_holder_type` VARCHAR(30) DEFAULT NULL,
  `from_holder_id` INT UNSIGNED DEFAULT NULL,
  `to_holder_type` VARCHAR(30) DEFAULT NULL,
  `to_holder_id` INT UNSIGNED DEFAULT NULL,
  `source_type` VARCHAR(40) DEFAULT NULL,
  `source_id` INT UNSIGNED DEFAULT NULL,
  `source_line_id` INT UNSIGNED DEFAULT NULL,
  `reference_no` VARCHAR(120) DEFAULT NULL,
  `notes` VARCHAR(255) DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_jw_trace_event_unit` (`company_id`,`stock_unit_id`,`id`),
  KEY `idx_jw_trace_event_source` (`company_id`,`source_type`,`source_id`),
  CONSTRAINT `fk_jw_trace_event_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jw_trace_event_unit` FOREIGN KEY (`stock_unit_id`) REFERENCES `jewellery_stock_units` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `jewellery_order_assignments`
  ADD COLUMN IF NOT EXISTS `stock_order_no` VARCHAR(60) DEFAULT NULL AFTER `assignment_no`,
  ADD COLUMN IF NOT EXISTS `stock_unit_id` INT UNSIGNED DEFAULT NULL AFTER `stock_order_no`;

ALTER TABLE `jewellery_order_assignments`
  ADD KEY IF NOT EXISTS `idx_jw_assign_stock_order` (`company_id`,`stock_order_no`),
  ADD KEY IF NOT EXISTS `idx_jw_assign_trace` (`company_id`,`stock_unit_id`);

ALTER TABLE `jewellery_order_receipts`
  ADD COLUMN IF NOT EXISTS `stock_unit_id` INT UNSIGNED DEFAULT NULL AFTER `assignment_id`,
  ADD KEY IF NOT EXISTS `idx_jw_receipt_trace` (`company_id`,`stock_unit_id`);

ALTER TABLE `jewellery_order_lines`
  ADD COLUMN IF NOT EXISTS `stock_unit_id` INT UNSIGNED DEFAULT NULL AFTER `stock_receipt_id`,
  ADD KEY IF NOT EXISTS `idx_jw_oline_trace` (`company_id`,`stock_unit_id`);

ALTER TABLE `jewellery_purchase_lines`
  ADD COLUMN IF NOT EXISTS `stock_unit_id` INT UNSIGNED DEFAULT NULL AFTER `item_id`,
  ADD KEY IF NOT EXISTS `idx_jw_pline_trace` (`company_id`,`stock_unit_id`);

ALTER TABLE `jewellery_sale_lines`
  ADD COLUMN IF NOT EXISTS `stock_unit_id` INT UNSIGNED DEFAULT NULL AFTER `item_id`,
  ADD KEY IF NOT EXISTS `idx_jw_sline_trace` (`company_id`,`stock_unit_id`);

ALTER TABLE `jewellery_sale_exchanges`
  ADD COLUMN IF NOT EXISTS `stock_unit_id` INT UNSIGNED DEFAULT NULL AFTER `item_id`,
  ADD KEY IF NOT EXISTS `idx_jw_sexchange_trace` (`company_id`,`stock_unit_id`);

ALTER TABLE `jewellery_stock_txns`
  ADD COLUMN IF NOT EXISTS `stock_unit_id` INT UNSIGNED DEFAULT NULL AFTER `item_id`,
  ADD KEY IF NOT EXISTS `idx_jw_stock_trace` (`company_id`,`stock_unit_id`,`txn_date`);

ALTER TABLE `inventory_opening_import_rows`
  ADD COLUMN IF NOT EXISTS `stock_unit_id` INT UNSIGNED DEFAULT NULL AFTER `item_id`,
  ADD KEY IF NOT EXISTS `idx_inv_opimprow_trace` (`company_id`,`stock_unit_id`);
