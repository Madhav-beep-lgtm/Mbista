-- 080: Order advances — cash or old gold taken before the work is done.
--
-- NOTHING NEW IS BUILT HERE, deliberately. A customer advance is already a
-- jewellery_settlements row: direction 'received', no bill allocation, and for
-- old gold mode 'metal', which already moves the weight into stock and posts
-- the money leg. Numbering, posting, unposting, the settlements list and the
-- Voucher Register's mutation guard all work on it today. Building a second
-- "order advances" table beside it would duplicate every one of those.
--
-- What was genuinely missing is only this:
--
--   order_id    which order the advance was taken against, so the order screen
--               can show it and the sale can apply it at delivery. Without it
--               an advance is just an unattached credit on the customer.
--   is_advance  advances must NOT be netted into the customer's receivable.
--               Money taken before delivery is money owed back until the piece
--               is handed over — a liability, not a reduction of a debt that
--               does not exist yet. Flagged rows post to the customer's own
--               advance ledger instead, which is what makes "advance recorded
--               with separate mapping" true rather than approximately true.
--
-- And on the sale, the fourth settlement leg:
--
--   advance_amount   the identity becomes
--                        received + exchange + advance + balance == total
--                    so applying an advance is not a special case; it is one
--                    more way a sale can be paid for, sitting alongside cash
--                    and old gold. Excess advance leaves a credit the customer
--                    is refunded — in cash or in gold — through the settlement
--                    machinery that already handles both.

ALTER TABLE `jewellery_settlements`
  ADD COLUMN IF NOT EXISTS `order_id` INT UNSIGNED DEFAULT NULL AFTER `party_id`,
  ADD COLUMN IF NOT EXISTS `is_advance` TINYINT(1) NOT NULL DEFAULT 0 AFTER `order_id`,
  ADD KEY IF NOT EXISTS `idx_jw_settle_order` (`company_id`, `order_id`),
  ADD CONSTRAINT `fk_jw_settle_order` FOREIGN KEY (`order_id`) REFERENCES `jewellery_orders` (`id`) ON DELETE SET NULL;

ALTER TABLE `jewellery_sales`
  ADD COLUMN IF NOT EXISTS `advance_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `exchange_amount`;

-- The per-customer advance ledger, so an advance can be found by name in the
-- chart of accounts the same way a receivable can.
ALTER TABLE `accounting_parties`
  ADD COLUMN IF NOT EXISTS `advance_ledger_id` INT UNSIGNED DEFAULT NULL,
  ADD KEY IF NOT EXISTS `idx_parties_advance_ledger` (`advance_ledger_id`);
