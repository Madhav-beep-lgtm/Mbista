-- Migration 046: link an accounting party to a client portal profile.
--
-- Invoices raised outside the task workflow (inventory sales, manufacturing,
-- "other" invoices from admin/invoice.php) carry only a party_id, so the
-- client portal could never show them — its My Invoices walked strictly
-- task -> invoice. Linking the party to the client profile lets the portal
-- list every invoice issued to that organization, task-based or party-based.
--
-- The link is optional and set on the party form (Sales & Invoices → party).

ALTER TABLE `accounting_parties`
  ADD COLUMN `client_profile_id` INT UNSIGNED DEFAULT NULL AFTER `payable_ledger_id`,
  ADD KEY `idx_accounting_parties_client_profile` (`client_profile_id`),
  ADD CONSTRAINT `fk_accounting_parties_client_profile` FOREIGN KEY (`client_profile_id`) REFERENCES `client_profiles` (`id`) ON DELETE SET NULL;
