-- 029: Per-party ledgers under Trade Receivables / Trade Payables.
--
-- Accounts Receivable / Accounts Payable are the RECEIVABLE and PAYABLE
-- ledger GROUPS (seeded by the base schema). Each customer gets a ledger
-- under Trade Receivables (stored in accounting_parties.ledger_id) and
-- each supplier gets one under Trade Payables (stored in the new
-- payable_ledger_id column). Invoices, receipts, purchase bills, and
-- supplier payments now post to the party's own ledger; the generic
-- AR / AP ledgers remain only as a fallback for postings with no party.
--
-- Ledger creation itself happens in the application (ensure_party_ledger)
-- and via the accounting module self-repair, so this migration only adds
-- the storage column.

ALTER TABLE `accounting_parties`
    ADD COLUMN `payable_ledger_id` INT UNSIGNED DEFAULT NULL AFTER `ledger_id`,
    ADD KEY `idx_accounting_parties_payable_ledger` (`payable_ledger_id`);
