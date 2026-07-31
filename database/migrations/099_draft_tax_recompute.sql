-- 099: A draft saved under the old arithmetic must not POST the old figure.
--
-- 096 corrected the wastage double-count in the SPT base, and re-derived the
-- printed bases on documents already stored. It deliberately left the tax
-- AMOUNT alone, because on a posted document that figure reached the ledger
-- and the customer — it is history, and rewriting it would put the voucher
-- and the books at odds.
--
-- But a DRAFT has posted nothing. Its stored tax is not history, it is a
-- pending instruction: post that draft and the inflated levy goes to the
-- ledger for the first time, today, under arithmetic that was corrected
-- weeks ago. The sale, purchase or order simply has to be re-saved to fix
-- itself — and nobody knows that, so nobody does it.
--
-- So on DRAFTS only, the tax is re-derived from the base 096 corrected:
--
--     amount = ROUND(base_amount x rate / 100, 2)
--
-- and the header total re-rolled from its lines. Posted documents are not
-- touched by any statement here; the WHERE clause on status is what
-- separates a pending instruction from a historical fact.
--
-- Orders have no line-tax rows of their own — their quote is recomputed
-- wholesale whenever the order is saved — so the repair step recomputes the
-- order headers in PHP, where the same rounding as the engine is available.

UPDATE `jewellery_line_taxes` lt
INNER JOIN `jewellery_sales` s ON s.`id` = lt.`doc_id` AND lt.`doc_type` = 'sale'
   SET lt.`amount` = ROUND(lt.`base_amount` * lt.`rate` / 100, 2)
 WHERE s.`status` = 'draft'
   AND lt.`amount` <> ROUND(lt.`base_amount` * lt.`rate` / 100, 2);

UPDATE `jewellery_line_taxes` lt
INNER JOIN `jewellery_purchases` p ON p.`id` = lt.`doc_id` AND lt.`doc_type` = 'purchase'
   SET lt.`amount` = ROUND(lt.`base_amount` * lt.`rate` / 100, 2)
 WHERE p.`status` = 'draft'
   AND lt.`amount` <> ROUND(lt.`base_amount` * lt.`rate` / 100, 2);
