-- Migration 131: old gold taken in is a purchase, and is recorded as one.
--
-- A jewellery shop buys most of its metal across the counter, from customers
-- bringing in old pieces. That happens through three doors:
--
--   a purchase bill raised against the customer   -> recorded as 'purchase'
--   metal handed over against a sale (exchange)   -> recorded as 'purchase'
--   metal taken in settlement, or left in advance -> recorded as 'adjustment'
--
-- The third one was wrong. An adjustment is what you record when stock moved
-- and you cannot say why -- a recount, a correction. Here we know exactly what
-- happened: the shop bought gold from a customer at an agreed rate. Typing it
-- as an adjustment kept that metal out of the Purchased column on Stock
-- Summary and out of every report that asks what was bought, while the
-- identical transaction booked through either of the other two doors appeared
-- normally. The same event was reported differently depending on which screen
-- the counter happened to use.
--
-- Only metal coming IN is retyped. Metal going OUT to settle a payable is not
-- the mirror image and is deliberately left alone: the shop handing gold to a
-- supplier has not sold anything -- no bill, no customer, no VAT -- and typing
-- it 'sale' would put it in the sales register. It stays an adjustment until
-- there is a movement type that describes it honestly.
--
-- This restates history, on purpose. The metal, the money and the ledger
-- postings are untouched; what changes is which column of which report the
-- movement is counted in, and the old column was the wrong one.
UPDATE `jewellery_stock_txns`
   SET `txn_type` = 'purchase'
 WHERE `source_type` = 'jewellery_settlement'
   AND `direction` = 'in'
   AND `txn_type` = 'adjustment';

-- And the mirror in the shared inventory ledger, which Stock Summary and the
-- generic stock reports read. Leaving these behind would give two answers to
-- the same question depending on which report was open.
UPDATE `inventory_transactions` `it`
  INNER JOIN `jewellery_stock_txns` `t` ON `t`.`id` = `it`.`jewellery_stock_txn_id`
   SET `it`.`transaction_type` = 'purchase'
 WHERE `t`.`source_type` = 'jewellery_settlement'
   AND `t`.`direction` = 'in'
   AND `t`.`txn_type` = 'purchase'
   AND `it`.`transaction_type` = 'adjustment';
