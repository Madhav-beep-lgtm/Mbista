-- Migration 127: old gold worth more than the bill.
--
-- A customer walks in with a chain and leaves with a lighter ring. The metal
-- handed over is worth more than the metal handed back, and the shop owes the
-- difference. The counter could not record that sale at all: the settlement
-- identity refused anything where cash + old gold came to more than the total,
-- so the only ways through were to under-state the gold or to invent a line
-- that was never sold. Both put a wrong figure in the books to get past a
-- guard that was protecting the books.
--
-- The excess is now a leg of its own, and the person at the counter says which
-- of the two things it is -- because they are genuinely different, and only
-- somebody standing there knows:
--
--   advance   the customer leaves the money with the shop, to be used on the
--             next bill. It is a liability, it belongs in that customer's own
--             advance ledger, and it shows up in their open advances so the
--             next bill can apply it.
--   refund    the shop hands the difference back over the counter. It is cash
--             (or bank) going out, now.
--
-- `none` is every sale that ever existed before this and every one where the
-- bill covers the gold, which is nearly all of them.
ALTER TABLE `jewellery_sales`
  ADD COLUMN `excess_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `advance_amount`,
  ADD COLUMN `excess_mode` ENUM('none', 'advance', 'refund') NOT NULL DEFAULT 'none' AFTER `excess_amount`,
  ADD COLUMN `excess_ledger_id` INT UNSIGNED DEFAULT NULL AFTER `excess_mode`;
