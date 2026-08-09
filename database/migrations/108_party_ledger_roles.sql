-- 108: One Party Master identity across the Chart of Accounts and every module.
--
-- A party can have four different balance-sheet relationships. They must stay
-- separate for correct presentation, but they all belong to the same party:
--
--   customer_receivable  Current asset      Trade Receivables
--   supplier_payable     Current liability  Trade Payables
--   customer_advance     Current liability  Advances from Customers
--   supplier_advance     Current asset      Advances to Suppliers
--
-- party_role makes these groups authoritative. Names and generated codes can
-- change without splitting Party Master from the Chart of Accounts.

ALTER TABLE `ledger_groups`
  ADD COLUMN IF NOT EXISTS `party_role` VARCHAR(40) DEFAULT NULL AFTER `parent_group_id`,
  ADD UNIQUE KEY IF NOT EXISTS `uniq_ledger_groups_company_party_role` (`company_id`, `party_role`);

ALTER TABLE `accounting_parties`
  ADD COLUMN IF NOT EXISTS `advance_ledger_id` INT UNSIGNED DEFAULT NULL AFTER `payable_ledger_id`,
  ADD COLUMN IF NOT EXISTS `supplier_advance_ledger_id` INT UNSIGNED DEFAULT NULL AFTER `advance_ledger_id`,
  ADD KEY IF NOT EXISTS `idx_parties_advance_ledger` (`advance_ledger_id`),
  ADD KEY IF NOT EXISTS `idx_parties_supplier_advance_ledger` (`supplier_advance_ledger_id`);

-- Adopt one existing canonical group of each kind before creating anything.
UPDATE `ledger_groups` g
INNER JOIN (
    SELECT company_id, MIN(id) AS keep_id
    FROM `ledger_groups`
    WHERE master_key = 'current_asset'
      AND (code = 'RECEIVABLE' OR LOWER(TRIM(name)) = 'trade receivables')
    GROUP BY company_id
) pick ON pick.keep_id = g.id
SET g.party_role = 'customer_receivable'
WHERE g.party_role IS NULL;

UPDATE `ledger_groups` g
INNER JOIN (
    SELECT company_id, MIN(id) AS keep_id
    FROM `ledger_groups`
    WHERE master_key = 'current_liability'
      AND (code = 'PAYABLE' OR LOWER(TRIM(name)) = 'trade payables')
    GROUP BY company_id
) pick ON pick.keep_id = g.id
SET g.party_role = 'supplier_payable'
WHERE g.party_role IS NULL;

UPDATE `ledger_groups` g
INNER JOIN (
    SELECT company_id, MIN(id) AS keep_id
    FROM `ledger_groups`
    WHERE master_key = 'current_liability'
      AND LOWER(TRIM(name)) IN ('customer advances', 'advances from customers')
    GROUP BY company_id
) pick ON pick.keep_id = g.id
SET g.party_role = 'customer_advance'
WHERE g.party_role IS NULL;

UPDATE `ledger_groups` g
INNER JOIN (
    SELECT company_id, MIN(id) AS keep_id
    FROM `ledger_groups`
    WHERE master_key = 'current_asset'
      AND LOWER(TRIM(name)) IN ('supplier advances', 'advances to suppliers')
    GROUP BY company_id
) pick ON pick.keep_id = g.id
SET g.party_role = 'supplier_advance'
WHERE g.party_role IS NULL;

INSERT INTO `ledger_groups`
    (`company_id`, `master_key`, `party_role`, `code`, `name`, `is_cash_or_bank`, `is_system`, `is_active`)
SELECT c.id, role_plan.master_key, role_plan.party_role, role_plan.code, role_plan.name, 0, 1, 1
FROM `companies` c
JOIN (
    SELECT 'current_asset' AS master_key, 'customer_receivable' AS party_role,
           'RECEIVABLE' AS code, 'Trade Receivables' AS name
    UNION ALL
    SELECT 'current_liability', 'supplier_payable', 'PAYABLE', 'Trade Payables'
    UNION ALL
    SELECT 'current_liability', 'customer_advance', 'CUSTOMER_ADVANCES', 'Advances from Customers'
    UNION ALL
    SELECT 'current_asset', 'supplier_advance', 'SUPPLIER_ADVANCES', 'Advances to Suppliers'
) role_plan
WHERE NOT EXISTS (
    SELECT 1 FROM `ledger_groups` existing
    WHERE existing.company_id = c.id
      AND existing.party_role = role_plan.party_role
);
