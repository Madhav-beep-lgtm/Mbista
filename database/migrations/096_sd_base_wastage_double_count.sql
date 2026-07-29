-- 096: The SD base counted the wastage twice, and the bill said so in print.
--
-- Since 083 the metal amount on a line IS the total weight priced as one
-- number — net metal AND wastage together, the way the bill's own arithmetic
-- reads. The tax base 'metal_wastage_making' predates that: it still added
-- the wastage value ON TOP of a metal figure that already contained it.
--
-- Every bill with wastage whose SD tax used that base printed an
-- "SD Taxable Amt" inflated by exactly the wastage value — and a NEGATIVE
-- "Non Taxable Amt", because that row is the remainder once the other two
-- bases are taken out of the document value. A statutory totals block that
-- contradicts itself on its face.
--
-- The engine now computes that base as metal + making (the metal already
-- carrying the wastage), which is what the base always MEANT: the whole
-- metal value, wastage and all, plus the making charge.
--
-- Below, the stored line-tax bases are re-derived from the lines they were
-- charged on — an ABSOLUTE assignment, so re-running converges. Migration
-- 085 guarantees no pre-083 document carries an SD row (their sd_taxable is
-- 0 by that backfill), so every row this touches was priced by the current
-- engine and is inflated by exactly its line's wastage.
--
-- THE TAX CHARGED IS NOT TOUCHED. The amount reached the ledger and, on a
-- posted bill, the customer; it is history. What is corrected is the BASE
-- the registers report and the bill prints. Where the two now disagree
-- (amount != base x rate on an old row), that disagreement is the honest
-- record of what happened.
--
-- The header figures (sd_taxable_amount, non_taxable_amount) are re-derived
-- in the repair step, which mirrors the engine's own formula in PHP.

UPDATE `jewellery_line_taxes` lt
INNER JOIN `jewellery_taxes` t ON t.`id` = lt.`tax_id` AND t.`base` = 'metal_wastage_making'
INNER JOIN `jewellery_sale_lines` l ON l.`id` = lt.`line_id`
   SET lt.`base_amount` = ROUND(l.`metal_amount` + l.`making_amount`, 2)
 WHERE lt.`doc_type` = 'sale'
   AND lt.`base_amount` <> ROUND(l.`metal_amount` + l.`making_amount`, 2);

UPDATE `jewellery_line_taxes` lt
INNER JOIN `jewellery_taxes` t ON t.`id` = lt.`tax_id` AND t.`base` = 'metal_wastage_making'
INNER JOIN `jewellery_purchase_lines` l ON l.`id` = lt.`line_id`
   SET lt.`base_amount` = ROUND(l.`metal_amount` + l.`making_amount`, 2)
 WHERE lt.`doc_type` = 'purchase'
   AND lt.`base_amount` <> ROUND(l.`metal_amount` + l.`making_amount`, 2);
