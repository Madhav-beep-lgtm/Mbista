-- Migration 047: each lease remembers its lessor.
--
-- Lease payments used to credit the single mapped Acquisition Clearing
-- ledger, which collapses every lessor into one balance. With the lessor
-- stored per lease, period postings credit that party's own payable ledger
-- (created automatically under Trade Payables), so multiple leases with
-- different lessors each show what is owed to whom.

ALTER TABLE `lease_liabilities`
  ADD COLUMN `lessor_party_id` INT UNSIGNED DEFAULT NULL AFTER `asset_id`,
  ADD KEY `idx_lease_liabilities_lessor` (`lessor_party_id`),
  ADD CONSTRAINT `fk_lease_liabilities_lessor` FOREIGN KEY (`lessor_party_id`) REFERENCES `accounting_parties` (`id`) ON DELETE SET NULL;
