-- 082: Store every stock movement's weight in GRAMS as well as its own unit.
--
-- THE BUG THIS FIXES. jewellery_stock_txns records a weight in whatever unit
-- the document was written in, and every balance in the module then did
--     SUM(CASE WHEN direction = 'in' THEN fine_weight ELSE -fine_weight END)
-- straight in SQL. That adds tola to gram.
--
-- Buy 1 tola of gold, sell 1 gram of it, and the module reported ZERO stock
-- left. The truth is 0.9143 tola — 10.66 g — still on the shelf. It is not a
-- rounding wobble; the metal simply vanishes, and everything downstream
-- inherits it: stock valuation, the weighted-average cost that COGS is charged
-- at, the negative-stock guard, the inventory report, the kaligad ledger.
--
-- The unit is per LINE, not per item — the sale and purchase grids both carry
-- a unit dropdown — so a shop that buys bullion in tola and sells scrap in
-- grams hits this on day one.
--
-- WHY A STORED COLUMN rather than converting in PHP. Converting at read time
-- means every balance stops being a single aggregate and becomes "fetch every
-- movement, convert each, add up" — on a hot path called once per line, per
-- item, per report row. Grams is already the module's pivot: every unit
-- declares its `grams` factor and jw_to_grams() has always been the conversion
-- route. Writing the gram figure once, at the single choke point that records
-- a movement, keeps every balance a plain SUM and makes it a correct one.
--
-- The entered unit and weight are untouched, so a document still shows exactly
-- what was typed on it.

ALTER TABLE `jewellery_stock_txns`
  ADD COLUMN IF NOT EXISTS `gross_grams` DECIMAL(18,6) NOT NULL DEFAULT 0.000000 AFTER `gross_weight`,
  ADD COLUMN IF NOT EXISTS `fine_grams` DECIMAL(18,6) NOT NULL DEFAULT 0.000000 AFTER `fine_weight`;

-- Backfill from the unit each row was written in. A unit with a missing or
-- nonsensical factor falls back to 1 g so the row keeps its magnitude rather
-- than silently becoming zero.
UPDATE `jewellery_stock_txns` t
  JOIN `jewellery_units` u ON u.id = t.unit_id
  SET t.gross_grams = t.gross_weight * IF(u.grams > 0, u.grams, 1),
      t.fine_grams  = t.fine_weight  * IF(u.grams > 0, u.grams, 1)
  WHERE t.gross_grams = 0 AND t.fine_grams = 0;

CREATE INDEX IF NOT EXISTS `idx_jw_txn_item_grams` ON `jewellery_stock_txns` (`company_id`, `item_id`, `txn_date`);
