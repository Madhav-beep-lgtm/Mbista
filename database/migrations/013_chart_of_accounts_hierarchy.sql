-- Adds a hierarchical chart of accounts: fixed Masters (IAS 1 aligned, stored as an ENUM),
-- admin-created Groups nested inside a Master, and Ledgers nested inside a Group.
-- Additive only: ledgers.type is untouched so existing report/export queries keep working.

CREATE TABLE IF NOT EXISTS `ledger_groups` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `master_key` ENUM(
      'equity', 'non_current_liability', 'current_liability',
      'non_current_asset', 'current_asset',
      'direct_income', 'indirect_income', 'direct_expense', 'indirect_expense'
  ) NOT NULL,
  `code` VARCHAR(40) NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `is_cash_or_bank` TINYINT(1) NOT NULL DEFAULT 0,
  `is_system` TINYINT(1) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_ledger_groups_company_code` (`company_id`, `code`),
  KEY `idx_ledger_groups_company_master` (`company_id`, `master_key`),
  KEY `idx_ledger_groups_cash_bank` (`company_id`, `is_cash_or_bank`),
  CONSTRAINT `fk_ledger_groups_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `ledgers`
  ADD COLUMN `group_id` INT UNSIGNED DEFAULT NULL AFTER `company_id`,
  ADD COLUMN `status` ENUM('active','suspended') NOT NULL DEFAULT 'active' AFTER `is_system`,
  ADD KEY `idx_ledgers_group` (`group_id`),
  ADD CONSTRAINT `fk_ledgers_group` FOREIGN KEY (`group_id`) REFERENCES `ledger_groups` (`id`) ON DELETE RESTRICT;

ALTER TABLE `vouchers`
  MODIFY COLUMN `voucher_type` ENUM(
      'payment','receipt','journal','sales','purchase','contra','debit_note','credit_note'
  ) NOT NULL DEFAULT 'journal';

-- Seed a minimal, deliberate set of default groups per active company.
INSERT INTO `ledger_groups` (`company_id`, `code`, `name`, `master_key`, `is_cash_or_bank`, `is_system`)
SELECT c.id, seed.code, seed.name, seed.master_key, seed.is_cash_or_bank, 1
FROM `companies` c
JOIN (
    SELECT 'BANK' AS code, 'Bank' AS name, 'current_asset' AS master_key, 1 AS is_cash_or_bank
    UNION ALL SELECT 'CASH_GRP', 'Cash in Hand', 'current_asset', 1
    UNION ALL SELECT 'RECEIVABLE', 'Trade Receivables', 'current_asset', 0
    UNION ALL SELECT 'PREPAID_GRP', 'Prepaid Expenses', 'current_asset', 0
    UNION ALL SELECT 'INVEST_GRP', 'Investments', 'non_current_asset', 0
    UNION ALL SELECT 'PAYABLE', 'Trade Payables', 'current_liability', 0
    UNION ALL SELECT 'DUTIES_TAXES', 'Duties and Taxes', 'current_liability', 0
    UNION ALL SELECT 'SHARE_CAPITAL_GRP', 'Share Capital', 'equity', 0
    UNION ALL SELECT 'RESERVE_SURPLUS', 'Reserve & Surplus', 'equity', 0
    UNION ALL SELECT 'DIRECT_INCOME_GRP', 'Professional Fee Income', 'direct_income', 0
    UNION ALL SELECT 'INDIRECT_INCOME_GRP', 'Other Income', 'indirect_income', 0
    UNION ALL SELECT 'ADMIN_EXP', 'Administrative Expenses', 'indirect_expense', 0
    UNION ALL SELECT 'EMP_BENEFIT_EXP', 'Operating/Employee Benefit Expenses', 'indirect_expense', 0
    UNION ALL SELECT 'SALES_MKT_EXP', 'Sales and Marketing Expenses', 'indirect_expense', 0
    UNION ALL SELECT 'OTHER_NONOP_EXP', 'Other Non-operating Expenses', 'indirect_expense', 0
    UNION ALL SELECT 'DIRECT_EXP_GRP', 'Direct Expenses', 'direct_expense', 0
    UNION ALL SELECT 'TAX_EXP_GRP', 'Tax Expenses', 'indirect_expense', 0
) seed
WHERE c.is_active = 1
  AND NOT EXISTS (
      SELECT 1 FROM `ledger_groups` existing
      WHERE existing.company_id = c.id AND existing.code = seed.code
  );

-- Backfill the 14 known system ledger codes into their matching group.
UPDATE `ledgers` l
INNER JOIN `ledger_groups` g
    ON g.company_id = l.company_id
   AND g.code = CASE l.code
        WHEN 'CASH' THEN 'BANK'
        WHEN 'AR' THEN 'RECEIVABLE'
        WHEN 'PREPAID' THEN 'PREPAID_GRP'
        WHEN 'INVESTMENTS' THEN 'INVEST_GRP'
        WHEN 'AP' THEN 'PAYABLE'
        WHEN 'TAX_PAYABLE' THEN 'DUTIES_TAXES'
        WHEN 'SHARE_CAPITAL' THEN 'SHARE_CAPITAL_GRP'
        WHEN 'RETAINED_EARNINGS' THEN 'RESERVE_SURPLUS'
        WHEN 'SERVICE_REVENUE' THEN 'DIRECT_INCOME_GRP'
        WHEN 'OTHER_INCOME' THEN 'INDIRECT_INCOME_GRP'
        WHEN 'SALARY_EXPENSE' THEN 'EMP_BENEFIT_EXP'
        WHEN 'RENT_EXPENSE' THEN 'ADMIN_EXP'
        WHEN 'PROFESSIONAL_FEES' THEN 'DIRECT_EXP_GRP'
        WHEN 'TAX_EXPENSE' THEN 'TAX_EXP_GRP'
        ELSE NULL
    END
SET l.group_id = g.id
WHERE l.group_id IS NULL
  AND l.code IN ('CASH','AR','PREPAID','INVESTMENTS','AP','TAX_PAYABLE','SHARE_CAPITAL',
                 'RETAINED_EARNINGS','SERVICE_REVENUE','OTHER_INCOME','SALARY_EXPENSE',
                 'RENT_EXPENSE','PROFESSIONAL_FEES','TAX_EXPENSE');
