-- 084: How the customer actually paid, split the way the bill prints it.
--
-- The paper carries one row across the foot:
--     Cash | Card | Advance | Cheque | Credit | QR/Transfer | Purchase
-- and the module could only fill three of those. It knew the TOTAL received
-- and which ledger it landed in, but not whether that was cash, a card, a
-- cheque or a QR transfer — so a bill printed from it could never be the same
-- document the shop hands over.
--
-- Three of the seven were already answerable and are NOT duplicated here:
--     Advance   jewellery_sales.advance_amount
--     Credit    jewellery_sales.balance_amount
--     Purchase  jewellery_sales.exchange_amount   (old gold bought in)
--
-- The four added below are the tender split of received_amount. They are a
-- BREAKDOWN, not a second source of truth: whatever they carry must add up to
-- received_amount, and the save refuses them if they do not. Leaving them all
-- at zero keeps the existing behaviour exactly — the whole receipt is simply
-- shown against the settle mode the sale already records.

ALTER TABLE `jewellery_sales`
  ADD COLUMN IF NOT EXISTS `paid_cash` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `received_amount`,
  ADD COLUMN IF NOT EXISTS `paid_card` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `paid_cash`,
  ADD COLUMN IF NOT EXISTS `paid_cheque` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `paid_card`,
  ADD COLUMN IF NOT EXISTS `paid_qr` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `paid_cheque`;

-- The bill's own reference fields, so a reprint carries the same identifiers
-- the customer's copy does.
ALTER TABLE `jewellery_sales`
  ADD COLUMN IF NOT EXISTS `customer_ref` VARCHAR(60) DEFAULT NULL AFTER `sales_person`,
  ADD COLUMN IF NOT EXISTS `tran_date_bs` VARCHAR(20) DEFAULT NULL AFTER `sale_date`,
  ADD COLUMN IF NOT EXISTS `remarks` VARCHAR(255) DEFAULT NULL AFTER `narration`;
