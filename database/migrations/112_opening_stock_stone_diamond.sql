ALTER TABLE `jewellery_stock_txns`
  ADD COLUMN IF NOT EXISTS `stone_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000 AFTER `gross_weight`,
  ADD COLUMN IF NOT EXISTS `diamond_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000 AFTER `stone_weight`;

ALTER TABLE `jewellery_stock_txns`
  ADD COLUMN IF NOT EXISTS `stone_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `diamond_weight`,
  ADD COLUMN IF NOT EXISTS `diamond_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `stone_amount`,
  ADD COLUMN IF NOT EXISTS `making_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `diamond_amount`;

ALTER TABLE `jewellery_stock_txns`
  ADD COLUMN IF NOT EXISTS `stone_carat` DECIMAL(18,4) NOT NULL DEFAULT 0.0000 AFTER `diamond_weight`,
  ADD COLUMN IF NOT EXISTS `diamond_carat` DECIMAL(18,4) NOT NULL DEFAULT 0.0000 AFTER `stone_carat`;

ALTER TABLE `inventory_opening_import_rows`
  ADD COLUMN IF NOT EXISTS `stone_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000 AFTER `gross_weight`,
  ADD COLUMN IF NOT EXISTS `diamond_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000 AFTER `stone_weight`;

ALTER TABLE `inventory_opening_import_rows`
  ADD COLUMN IF NOT EXISTS `stone_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `diamond_weight`,
  ADD COLUMN IF NOT EXISTS `diamond_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `stone_amount`,
  ADD COLUMN IF NOT EXISTS `making_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `diamond_amount`;
