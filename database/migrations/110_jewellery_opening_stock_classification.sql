-- 110: Jewellery opening-stock classification and editable item creation.
--
-- The import remains staged: upload posts nothing. These fields let a user
-- classify and correct each row before commit, and let the normal item master
-- retain whether the piece belongs to showroom stock or a customer order.

ALTER TABLE `jewellery_item_profiles`
  ADD COLUMN IF NOT EXISTS `stock_kind` ENUM('showroom','customer_ordered') NOT NULL DEFAULT 'showroom'
    AFTER `track_mode`;

ALTER TABLE `inventory_opening_import_rows`
  ADD COLUMN IF NOT EXISTS `stock_kind` ENUM('showroom','customer_ordered') DEFAULT NULL AFTER `raw_purity`,
  ADD COLUMN IF NOT EXISTS `raw_group` VARCHAR(190) NOT NULL DEFAULT '' AFTER `stock_kind`,
  ADD COLUMN IF NOT EXISTS `proposed_code` VARCHAR(120) NOT NULL DEFAULT '' AFTER `raw_group`,
  ADD COLUMN IF NOT EXISTS `proposed_name` VARCHAR(255) NOT NULL DEFAULT '' AFTER `proposed_code`,
  ADD COLUMN IF NOT EXISTS `metal_id` INT UNSIGNED DEFAULT NULL AFTER `proposed_name`,
  ADD COLUMN IF NOT EXISTS `create_item` TINYINT(1) NOT NULL DEFAULT 0 AFTER `metal_id`,
  ADD COLUMN IF NOT EXISTS `customer_name` VARCHAR(190) NOT NULL DEFAULT '' AFTER `create_item`,
  ADD COLUMN IF NOT EXISTS `order_number` VARCHAR(120) NOT NULL DEFAULT '' AFTER `customer_name`;

ALTER TABLE `inventory_opening_import_rows`
  ADD KEY IF NOT EXISTS `idx_inv_opimprow_stock_kind` (`company_id`,`stock_kind`);
