-- A sales or purchase voucher can name the goods it moved.
--
-- Until now a voucher line held an amount and a description, and stock moved
-- only through the invoice module. So a purchase entered as a voucher raised
-- the payable and never raised the stock, and somebody reconciled the two by
-- hand at the year end. The line now carries the item and the quantity, which
-- is what a purchase or sale is actually made of.

ALTER TABLE `voucher_entries`
  ADD COLUMN `item_id` INT UNSIGNED DEFAULT NULL AFTER `ledger_id`,
  ADD COLUMN `quantity` DECIMAL(14,3) NOT NULL DEFAULT 0.000 AFTER `item_id`,
  ADD KEY `idx_voucher_entries_item` (`item_id`),
  ADD CONSTRAINT `fk_voucher_entries_item` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`id`) ON DELETE SET NULL;

-- Where the goods landed or left from. Blank means the item's own default.
ALTER TABLE `vouchers`
  ADD COLUMN `warehouse_id` INT UNSIGNED DEFAULT NULL AFTER `location`,
  ADD CONSTRAINT `fk_vouchers_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE SET NULL;

-- inventory_transactions.voucher_id already names the voucher that carries the
-- movement's VALUE — for a sale that is the COGS journal, not the sales
-- voucher. This names the voucher that CAUSED the movement, so a voucher can
-- find, replace and release its own stock without guessing from a reference
-- number that another module might also be using.
ALTER TABLE `inventory_transactions`
  ADD COLUMN `source_voucher_id` INT UNSIGNED DEFAULT NULL AFTER `voucher_id`,
  ADD KEY `idx_inventory_transactions_source_voucher` (`source_voucher_id`),
  ADD CONSTRAINT `fk_inventory_transactions_source_voucher` FOREIGN KEY (`source_voucher_id`) REFERENCES `vouchers` (`id`) ON DELETE CASCADE;
