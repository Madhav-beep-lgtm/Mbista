-- Migration 041: NRV allowance lifecycle (IAS 2.34).
--
-- A write-down posts Dr write-down expense / Cr write-down allowance WITHOUT
-- touching the cost layers (the allowance is a contra-asset). That left two
-- holes:
--
--   1. When the written-down stock was later sold, COGS came out of the layers
--      at FULL cost while the allowance just sat there — COGS overstated, and a
--      credit balance stranded on the balance sheet forever. IAS 2.34 requires
--      the carrying amount (i.e. the written-down amount) to be the expense. So
--      the allowance must be RELEASED pro-rata as the written-down units leave,
--      crediting back the same expense the movement debited.
--
--   2. Reversing an NRV movement reversed its voucher but left the assessment
--      row standing, so the cumulative prior write-down stayed overstated and
--      every later write-down on that item was silently blocked.
--
-- inventory_nrv_assessments becomes the single ledger of allowance movements:
--   write_down     (+) allowance raised
--   reversal       (-) allowance released because NRV recovered (IAS 2.33)
--   release_amount (-) allowance consumed because the stock left (IAS 2.34)
-- and the standing allowance is the sum of those over status = 'active' rows.

ALTER TABLE `inventory_nrv_assessments`
  ADD COLUMN IF NOT EXISTS `release_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `reversal`,
  ADD COLUMN IF NOT EXISTS `source_txn_id` INT UNSIGNED DEFAULT NULL AFTER `voucher_id`,
  ADD COLUMN IF NOT EXISTS `status` ENUM('active', 'reversed') NOT NULL DEFAULT 'active' AFTER `source_txn_id`;

ALTER TABLE `inventory_nrv_assessments`
  ADD INDEX IF NOT EXISTS `idx_inv_nrv_source_txn` (`source_txn_id`),
  ADD INDEX IF NOT EXISTS `idx_inv_nrv_item_status` (`company_id`, `item_id`, `status`);
