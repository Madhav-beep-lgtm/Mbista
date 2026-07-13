-- Migration 040: widen inventory_transactions.transaction_type.
--
-- The IAS 2 posting matrix (inv_movement_posting_plan / inv_transaction_purposes
-- in app/inventory_valuation.php) already speaks write_off/damage/expiry,
-- warehouse_transfer/departmental_transfer, and nrv_write_down/nrv_reversal,
-- but the column's ENUM only allowed 8 legacy values, so no UI could ever
-- persist those movement types. Purely additive — existing rows/values are a
-- strict subset of the new list, so this is a safe in-place widen.

ALTER TABLE `inventory_transactions`
  MODIFY COLUMN `transaction_type` ENUM(
    'opening', 'purchase', 'sale', 'sales_return', 'purchase_return', 'adjustment',
    'consume', 'produce', 'write_off', 'damage', 'expiry',
    'warehouse_transfer', 'departmental_transfer', 'nrv_write_down', 'nrv_reversal'
  ) NOT NULL DEFAULT 'adjustment';
