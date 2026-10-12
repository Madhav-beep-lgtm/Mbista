-- An opening balance belongs to the ACCOUNT, not to a journal.
--
-- It used to be posted as a two-sided voucher dated on the fiscal year's first
-- day: debit the ledger, credit "Opening Balance Adjustments". That made a
-- non-event look like an event -- nothing moved on day one, the account simply
-- started where it started -- and it put the figure in the voucher register,
-- the day book, and an editable journal form that offered to re-point it.
--
-- The counterpart of an opening balance is not an account. It is the rest of
-- the opening balances. So the figure is held here, on one side only, and the
-- difference between all opening debits and all opening credits is computed
-- and reported as "Difference in Opening Balances".
--
-- NULL side means no opening has been set, which is not the same as an opening
-- of zero deliberately entered to clear a previous one.
ALTER TABLE `ledgers`
  ADD COLUMN `opening_balance` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `status`,
  ADD COLUMN `opening_side` ENUM('debit', 'credit') DEFAULT NULL AFTER `opening_balance`;
