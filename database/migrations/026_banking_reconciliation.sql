-- 026: Banking & bank reconciliation support
-- Bank account metadata on ledgers (shown masked on Banking page and the
-- dashboard Bank Accounts widget) and reconciliation state on voucher entries.
-- The accounting module self-repair (app/accounting_module_repair.php) applies
-- the same changes automatically on first page load after deployment.

ALTER TABLE `ledgers`
  ADD COLUMN `bank_name` VARCHAR(120) DEFAULT NULL AFTER `name`,
  ADD COLUMN `bank_account_no` VARCHAR(40) DEFAULT NULL AFTER `bank_name`;

ALTER TABLE `voucher_entries`
  ADD COLUMN `reconciled_at` DATETIME DEFAULT NULL AFTER `memo`,
  ADD COLUMN `reconciled_by` INT UNSIGNED DEFAULT NULL AFTER `reconciled_at`,
  ADD COLUMN `statement_date` DATE DEFAULT NULL AFTER `reconciled_by`,
  ADD KEY `idx_voucher_entries_reconciled` (`ledger_id`, `reconciled_at`);
