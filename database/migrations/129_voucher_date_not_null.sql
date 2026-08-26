-- Migration 129: let a date filter use the index that is already there.
--
-- Every date-ranged query in the accounting system -- the reports engine, the
-- dashboard, the day book, the ledgers, banking, reconciliation, the party
-- statements -- filtered on
--
--     COALESCE(v.voucher_date, DATE(v.created_at)) BETWEEN :from AND :to
--
-- A column wrapped in a function cannot be looked up in an index. The indexes
-- were never the problem: idx_vouchers_date already leads on
-- (company_id, fiscal_year_id, voucher_date). The COALESCE simply made them
-- unusable for the range, so every one of those queries examined every posted
-- voucher the company had and applied the date test row by row afterwards.
-- Measured on 400,000 vouchers, one such count took 26.9ms wrapped and 2.2ms
-- unwrapped -- twelve times the work, on the query shape that underlies every
-- financial statement in the app.
--
-- The COALESCE was defending against rows written before voucher_date existed
-- as a column. It was never defending against the application: the only INSERT
-- INTO vouchers in the codebase defaults the date to today, and so does the
-- edit form. So the defence is moved from the query to the DATA, where it costs
-- nothing to read: the legacy rows are given the date they were created on, and
-- the column is closed so none can appear again.
--
-- Backfill first, then close the column -- the other order fails on any
-- database that still has one.
UPDATE `vouchers`
   SET `voucher_date` = DATE(`created_at`)
 WHERE `voucher_date` IS NULL;

ALTER TABLE `vouchers`
  MODIFY COLUMN `voucher_date` DATE NOT NULL;

-- The two existing indexes both carry a column between company and date
-- (fiscal_year_id, voucher_type), so a query filtering company + date alone
-- can only use the first part of them. This one is exactly the shape the
-- reports ask for, and takes the same count from 2.2ms to 1.4ms.
ALTER TABLE `vouchers`
  ADD INDEX `idx_vouchers_company_date` (`company_id`, `voucher_date`);
