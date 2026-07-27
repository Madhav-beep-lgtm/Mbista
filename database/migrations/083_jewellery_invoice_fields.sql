-- 083: The fields a Nepali jewellery invoice actually prints, and the tax
--      bases it actually uses.
--
-- Modelled directly on a real merchant copy (Akshara Jewellery Pvt Ltd, bill
-- S8384/51). Every figure below reconciles to the paisa against that bill, and
-- the arithmetic it revealed differs from what this module assumed in three
-- material ways.
--
-- 1. WASTAGE IS A WEIGHT, NOT A PERCENTAGE, and it is added to the net weight
--    BEFORE pricing:
--        Net Wt   = Gross Wt  −  Less          2.550 − 0.040 = 2.510
--        Total Wt = Net Wt    +  Wastage       2.510 + 0.466 = 2.976
--        Amount   = Total Wt  ×  Rate/gram     2.976 × 22,645.062 = 67,391.70
--    The customer is charged on 2.976 g but only 2.510 g of metal leaves the
--    shop — wastage is compensation for melting loss and labour, not gold
--    handed over. So the stock ledger still relieves the NET weight, while the
--    bill prices the total. Conflating the two would either give the customer
--    free gold or drain stock that never moved.
--
-- 2. THE TWO TAXES SIT ON DISJOINT BASES. They do not compound:
--        SD Taxable  = metal + making          67,391.70 + 1,700 = 69,091.70
--        SD Tax 0.5%                                          =    345.46
--        Vatable     = stone + diamond only                   =    232.60
--        VAT 13%                                              =     30.24
--        Net Total   = 69,324.30 + 345.46 + 30.24 = 69,700.00
--    VAT is charged on the STONE side alone — never on gold, never on making,
--    and never on top of the Skills Development tax. The module previously
--    seeded VAT as "the whole line including earlier taxes", which is right in
--    the abstract and wrong for this trade.
--
-- 3. STONES ARE THREE SEPARATE COLUMNS on the bill — Diamond, Other Diamond
--    and Stone — each with its own carat and amount, because they are taxed
--    alike but priced and reported apart.
--
-- Existing rows are unaffected: the new columns default to zero, total_weight
-- backfills to the net weight already stored, and a line with no wastage
-- weight still prices exactly as before.

ALTER TABLE `jewellery_sale_lines`
  ADD COLUMN IF NOT EXISTS `wastage_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000 AFTER `wastage_pct`,
  ADD COLUMN IF NOT EXISTS `total_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000 AFTER `wastage_weight`,
  ADD COLUMN IF NOT EXISTS `diamond_carat` DECIMAL(18,4) NOT NULL DEFAULT 0.0000 AFTER `stone_amount`,
  ADD COLUMN IF NOT EXISTS `diamond_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `diamond_carat`,
  ADD COLUMN IF NOT EXISTS `other_diamond_carat` DECIMAL(18,4) NOT NULL DEFAULT 0.0000 AFTER `diamond_amount`,
  ADD COLUMN IF NOT EXISTS `other_diamond_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `other_diamond_carat`,
  ADD COLUMN IF NOT EXISTS `stone_carat` DECIMAL(18,4) NOT NULL DEFAULT 0.0000 AFTER `other_diamond_amount`;

ALTER TABLE `jewellery_purchase_lines`
  ADD COLUMN IF NOT EXISTS `wastage_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000 AFTER `wastage_pct`,
  ADD COLUMN IF NOT EXISTS `total_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000 AFTER `wastage_weight`,
  ADD COLUMN IF NOT EXISTS `diamond_carat` DECIMAL(18,4) NOT NULL DEFAULT 0.0000 AFTER `stone_amount`,
  ADD COLUMN IF NOT EXISTS `diamond_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `diamond_carat`,
  ADD COLUMN IF NOT EXISTS `other_diamond_carat` DECIMAL(18,4) NOT NULL DEFAULT 0.0000 AFTER `diamond_amount`,
  ADD COLUMN IF NOT EXISTS `other_diamond_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `other_diamond_carat`,
  ADD COLUMN IF NOT EXISTS `stone_carat` DECIMAL(18,4) NOT NULL DEFAULT 0.0000 AFTER `other_diamond_amount`;

-- A line that predates this carries no wastage weight, so its total weight is
-- simply the metal it was already priced on.
UPDATE `jewellery_sale_lines` SET `total_weight` = `net_weight`
  WHERE `total_weight` = 0 AND `net_weight` <> 0;
UPDATE `jewellery_purchase_lines` SET `total_weight` = `net_weight`
  WHERE `total_weight` = 0 AND `net_weight` <> 0;

-- The three printed tax bases, so the invoice prints what was actually
-- charged rather than re-deriving it from a formula that may since have moved.
ALTER TABLE `jewellery_sales`
  ADD COLUMN IF NOT EXISTS `non_taxable_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `taxable_amount`,
  ADD COLUMN IF NOT EXISTS `sd_taxable_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `non_taxable_amount`,
  ADD COLUMN IF NOT EXISTS `vatable_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `sd_taxable_amount`,
  ADD COLUMN IF NOT EXISTS `diamond_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `stone_amount`,
  ADD COLUMN IF NOT EXISTS `sales_person` VARCHAR(120) DEFAULT NULL AFTER `customer_name`;

ALTER TABLE `jewellery_purchases`
  ADD COLUMN IF NOT EXISTS `non_taxable_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `taxable_amount`,
  ADD COLUMN IF NOT EXISTS `sd_taxable_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `non_taxable_amount`,
  ADD COLUMN IF NOT EXISTS `vatable_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `sd_taxable_amount`,
  ADD COLUMN IF NOT EXISTS `diamond_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `stone_amount`;

-- Diamonds and stones are taxed alike, so they need one base to be charged on.
ALTER TABLE `jewellery_taxes`
  MODIFY COLUMN `base` ENUM('metal','making','stone','wastage','metal_making','metal_wastage_making',
                            'stone_diamond','subtotal','subtotal_with_taxes')
    NOT NULL DEFAULT 'subtotal';
