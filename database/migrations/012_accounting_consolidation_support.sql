CREATE TABLE IF NOT EXISTS company_shareholdings (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    investor_company_id INT UNSIGNED NOT NULL,
    investee_company_id INT UNSIGNED NOT NULL,
    ownership_percent DECIMAL(5,2) NOT NULL DEFAULT 100.00,
    relationship_type ENUM('subsidiary', 'associate', 'joint_venture', 'investment') NOT NULL DEFAULT 'subsidiary',
    consolidation_method ENUM('full', 'equity', 'proportionate', 'cost') NOT NULL DEFAULT 'full',
    effective_from DATE DEFAULT NULL,
    effective_to DATE DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_company_shareholding_pair (investor_company_id, investee_company_id),
    KEY idx_company_shareholdings_investor (investor_company_id),
    KEY idx_company_shareholdings_investee (investee_company_id),
    CONSTRAINT fk_company_shareholdings_investor FOREIGN KEY (investor_company_id) REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_company_shareholdings_investee FOREIGN KEY (investee_company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO company_shareholdings (
    investor_company_id,
    investee_company_id,
    ownership_percent,
    relationship_type,
    consolidation_method,
    effective_from,
    notes
)
SELECT parent.id,
       child.id,
       100.00,
       'subsidiary',
       'full',
       CURRENT_DATE,
       'Default full consolidation for direct Altiora subsidiary.'
FROM companies parent
INNER JOIN companies child ON child.parent_company_id = parent.id
WHERE parent.code = 'AGHPL'
  AND NOT EXISTS (
      SELECT 1
      FROM company_shareholdings existing
      WHERE existing.investor_company_id = parent.id
        AND existing.investee_company_id = child.id
  );

UPDATE company_shareholdings
SET relationship_type = CASE
        WHEN ownership_percent > 50 THEN 'subsidiary'
        WHEN ownership_percent = 50 THEN 'joint_venture'
        WHEN ownership_percent >= 20 THEN 'associate'
        ELSE 'investment'
    END,
    consolidation_method = CASE
        WHEN ownership_percent > 50 THEN 'full'
        WHEN ownership_percent = 50 THEN 'proportionate'
        WHEN ownership_percent >= 20 THEN 'equity'
        ELSE 'cost'
    END;

INSERT INTO ledgers (company_id, code, name, type, is_system)
SELECT c.id, ledger_seed.code, ledger_seed.name, ledger_seed.type, 1
FROM companies c
JOIN (
    SELECT 'CASH' AS code, 'Cash and bank' AS name, 'asset' AS type
    UNION ALL SELECT 'AR', 'Accounts receivable', 'asset'
    UNION ALL SELECT 'PREPAID', 'Prepaid expenses', 'asset'
    UNION ALL SELECT 'INVESTMENTS', 'Investments', 'asset'
    UNION ALL SELECT 'AP', 'Accounts payable', 'liability'
    UNION ALL SELECT 'TAX_PAYABLE', 'Tax payable', 'liability'
    UNION ALL SELECT 'SHARE_CAPITAL', 'Share capital', 'equity'
    UNION ALL SELECT 'RETAINED_EARNINGS', 'Retained earnings', 'equity'
    UNION ALL SELECT 'SERVICE_REVENUE', 'Service revenue', 'revenue'
    UNION ALL SELECT 'OTHER_INCOME', 'Other income', 'revenue'
    UNION ALL SELECT 'SALARY_EXPENSE', 'Salary expense', 'expense'
    UNION ALL SELECT 'RENT_EXPENSE', 'Rent expense', 'expense'
    UNION ALL SELECT 'PROFESSIONAL_FEES', 'Professional fees', 'expense'
    UNION ALL SELECT 'TAX_EXPENSE', 'Tax expense', 'expense'
) ledger_seed
WHERE c.is_active = 1
  AND NOT EXISTS (
      SELECT 1
      FROM ledgers existing
      WHERE existing.company_id = c.id
        AND existing.code = ledger_seed.code
  );
