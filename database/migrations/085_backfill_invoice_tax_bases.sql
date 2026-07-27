-- 085: Fill in the printed tax bases on documents raised before 083.
--
-- 083 added non_taxable_amount / sd_taxable_amount / vatable_amount to the
-- sale and purchase headers and backfilled the LINE weights, but left the
-- header bases at zero on everything already in the books. The invoice prints
-- those three figures straight from the header, so reprinting an older bill
-- produced a totals block reading 0.00 / 0.00 / 0.00 above a correct net
-- total — a document that contradicts itself.
--
-- What a pre-083 bill actually knew:
--     taxable_amount   the one base VAT was charged on
--     vat_amount       the VAT itself
-- and no notion of the Skills Development levy, which did not exist on it. So
-- the reconstruction is exact rather than a guess:
--     vatable_amount     = taxable_amount     (what VAT was charged on)
--     non_taxable_amount = the rest of the document value
--     sd_taxable_amount  = 0                  (the levy was not charged)
--
-- The WHERE clause is what makes this safe to re-run and impossible to apply
-- to a current document. Any bill the present engine computes with a value on
-- it leaves at least one of the three bases non-zero: metal or making puts a
-- figure in the SD base, a stone puts one in the vatable base, and anything
-- left over lands in the non-taxable base. So "all three are zero, yet the
-- document is worth something" identifies a pre-083 row and nothing else.
--
-- Note the value expression adds wastage_amount. On a pre-083 document the
-- wastage was its own revenue leg beside the metal; the present engine folds
-- it into metal_amount instead, which is exactly why this must never touch a
-- current row — it would count the wastage twice.

UPDATE `jewellery_sales`
   SET `vatable_amount` = `taxable_amount`,
       `non_taxable_amount` = GREATEST(0,
            `metal_amount` + `wastage_amount` + `making_amount` + `stone_amount` - `taxable_amount`)
 WHERE `non_taxable_amount` = 0
   AND `sd_taxable_amount` = 0
   AND `vatable_amount` = 0
   AND `total_amount` <> 0;

UPDATE `jewellery_purchases`
   SET `vatable_amount` = `taxable_amount`,
       `non_taxable_amount` = GREATEST(0,
            `metal_amount` + `wastage_amount` + `making_amount` + `stone_amount` - `taxable_amount`)
 WHERE `non_taxable_amount` = 0
   AND `sd_taxable_amount` = 0
   AND `vatable_amount` = 0
   AND `total_amount` <> 0;
