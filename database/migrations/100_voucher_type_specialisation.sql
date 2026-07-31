-- Each voucher type keeps facts the others have no use for.
--
-- A purchase carries the supplier's own bill number and its date, which is what
-- the tax office asks for and is not the same as our voucher number or our
-- entry date. A payment carries the cheque number and the date on the cheque. A
-- debit or credit note carries the reason it was raised. Holding these in the
-- narration made them unsearchable and unprintable; they get columns.

ALTER TABLE `vouchers`
  ADD COLUMN `reference_date` DATE DEFAULT NULL AFTER `reference_no`,
  ADD COLUMN `instrument_type` VARCHAR(30) DEFAULT NULL AFTER `reference_date`,
  ADD COLUMN `instrument_no` VARCHAR(80) DEFAULT NULL AFTER `instrument_type`,
  ADD COLUMN `instrument_date` DATE DEFAULT NULL AFTER `instrument_no`,
  ADD COLUMN `return_reason` VARCHAR(255) DEFAULT NULL AFTER `instrument_date`;

-- The register and the party workspace both filter by type and date; the
-- existing index leads on fiscal year, which a "all payments this month across
-- years" view cannot use.
ALTER TABLE `vouchers`
  ADD KEY `idx_vouchers_type_date` (`company_id`, `voucher_type`, `voucher_date`);
