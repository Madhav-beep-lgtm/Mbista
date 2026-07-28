-- 095: Stones are not gold — the receipt now weighs them apart.
--
-- A kaligad returns a stone-set ring: 10.2 tola on the scale, of which 1.2
-- tola is stone. The receipt knew only the gross, so its fine-gold equivalent
-- was computed over the STONES TOO — crediting the kaligad with pure metal he
-- never returned, understating his wastage by exactly the stones' weight in
-- fine, and putting a fine figure into stock that the melt would never yield.
--
--     stone_weight      what the set stones weigh, typed at the receipt
--     net_gold_weight   gross − stone: the metal actually returned, the
--                       weight the fine equivalent is now computed from
--
-- Both actual weight and fine equivalent live on the row, which is what lets
-- every screen show them TOGETHER — the ornament as the customer knows it,
-- the metal as the melt would prove it.
--
-- Existing receipts backfill stone 0 / net = gross: no shop could record a
-- stone before this column existed, so as far as the books know every piece
-- received so far was all metal, and their stored fine weights keep exactly
-- the meaning they had.

ALTER TABLE `jewellery_order_receipts`
  ADD COLUMN IF NOT EXISTS `stone_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000 AFTER `received_gross_weight`,
  ADD COLUMN IF NOT EXISTS `net_gold_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000 AFTER `stone_weight`;

UPDATE `jewellery_order_receipts`
   SET `net_gold_weight` = `received_gross_weight`
 WHERE `net_gold_weight` = 0 AND `received_gross_weight` > 0;
