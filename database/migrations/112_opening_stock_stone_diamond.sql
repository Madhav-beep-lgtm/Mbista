-- Keep stone and diamond weights supplied with opening stock all the way from
-- the staged spreadsheet preview to the posted opening movement.
ALTER TABLE `inventory_opening_import_rows`
  ADD COLUMN IF NOT EXISTS `stone_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000 AFTER `gross_weight`,
  ADD COLUMN IF NOT EXISTS `diamond_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000 AFTER `stone_weight`;

ALTER TABLE `jewellery_stock_txns`
  ADD COLUMN IF NOT EXISTS `stone_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000 AFTER `gross_weight`,
  ADD COLUMN IF NOT EXISTS `diamond_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000 AFTER `stone_weight`;
