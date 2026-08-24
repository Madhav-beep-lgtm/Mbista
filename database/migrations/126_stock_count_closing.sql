-- Migration 126: the closing stock somebody actually counted.
--
-- The Stock Summary Report derives closing stock by replaying every recorded
-- movement. For a shop that records what it BUYS but not what it CONSUMES --
-- a kitchen, a cafe, a counter that rings up "1 coffee" and not the milk
-- inside it -- that replay says the milk is all still there. Closing stock is
-- overstated by exactly the consumption, and the cost of that consumption
-- never reaches the books: no COGS.
--
-- The fix is the count. Somebody walks the shelf, counts what is on it, and
-- punches that quantity in against the date. The difference between what the
-- replay says and what the shelf holds IS the consumption, and posting it at
-- inventory cost is what finally charges COGS.
--
-- Two things are needed and both are additive.

-- 1. A movement type for it. `adjustment` already exists but charges shortage
--    to inventory LOSS -- correct for breakage, wrong for a coffee that was
--    sold. A counted shortfall in a kitchen is cost of sales, so it gets its
--    own type and its own posting plan (see inv_movement_posting_plan):
--      out  Dr Cost of Goods Sold  /  Cr Inventory
--      in   Dr Inventory           /  Cr Cost of Goods Sold
--    Existing values are a strict subset of the new list, so the widen is a
--    safe in-place change.
ALTER TABLE `inventory_transactions`
  MODIFY COLUMN `transaction_type` ENUM(
    'opening', 'purchase', 'sale', 'sales_return', 'purchase_return', 'adjustment',
    'consume', 'produce', 'write_off', 'damage', 'expiry',
    'warehouse_transfer', 'departmental_transfer', 'nrv_write_down', 'nrv_reversal',
    'stock_count'
  ) NOT NULL DEFAULT 'adjustment';

-- 2. The count itself, kept as a record rather than as a number typed into a
--    posting and forgotten: who counted, on what date, at which location, what
--    the system said at the time, and which movement carried the difference.
--
--    `warehouse_id` is 0, never NULL, for a whole-company count -- a NULL
--    never collides in a UNIQUE key, so two company-wide counts of the same
--    item on the same date could both be stored and both be posted.
--
--    POSTED IS `posted_at`, AND ONLY `posted_at`. The link columns say which
--    movement and voucher carried it; there is no second status column free to
--    disagree with them. A count that agreed with the system to the decimal is
--    posted with no movement at all -- `txn_id` NULL, variance zero -- because
--    "counted, and it matched" is a result worth keeping.
CREATE TABLE IF NOT EXISTS `inventory_stock_counts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `item_id` INT UNSIGNED NOT NULL,
  `warehouse_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `count_date` DATE NOT NULL,
  `counted_qty` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `system_qty` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `variance_qty` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `variance_value` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `charge_to` ENUM('cogs', 'inventory_loss') NOT NULL DEFAULT 'cogs',
  `notes` VARCHAR(255) DEFAULT NULL,
  `txn_id` INT UNSIGNED DEFAULT NULL,
  `voucher_id` INT UNSIGNED DEFAULT NULL,
  `counted_by` INT UNSIGNED DEFAULT NULL,
  `posted_by` INT UNSIGNED DEFAULT NULL,
  `posted_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_inv_stock_count` (`company_id`, `item_id`, `warehouse_id`, `count_date`),
  KEY `idx_inv_stock_count_date` (`company_id`, `count_date`),
  KEY `idx_inv_stock_count_open` (`company_id`, `count_date`, `posted_at`),
  KEY `idx_inv_stock_count_txn` (`txn_id`),
  CONSTRAINT `fk_inv_stock_count_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_inv_stock_count_item` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
