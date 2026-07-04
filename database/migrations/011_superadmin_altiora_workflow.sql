INSERT INTO companies (name, code, parent_company_id, is_active)
SELECT 'M.Bista and Associates, Chartered Accountants', 'MBAACA', NULL, 1
WHERE NOT EXISTS (SELECT 1 FROM companies WHERE code = 'MBAACA');

INSERT INTO companies (name, code, parent_company_id, is_active)
SELECT 'Altiora Global Holdings Private Limited', 'AGHPL', NULL, 1
WHERE NOT EXISTS (SELECT 1 FROM companies WHERE code = 'AGHPL');

UPDATE companies
SET name = 'M.Bista and Associates, Chartered Accountants',
    parent_company_id = NULL,
    is_active = 1
WHERE code = 'MBAACA';

UPDATE companies
SET name = 'Altiora Global Holdings Private Limited',
    parent_company_id = NULL,
    is_active = 1
WHERE code = 'AGHPL';

INSERT INTO companies (name, code, parent_company_id, is_active)
SELECT child_name, child_code, parent.id, 1
FROM (
    SELECT 'M.B. Training and Advisory Services Private Limited' AS child_name, 'MBTAS' AS child_code
    UNION ALL SELECT 'Excel Business Consulting Private Limited', 'EBCPL'
    UNION ALL SELECT 'Vyra Edupath Private limited', 'VEPL'
    UNION ALL SELECT 'Passion Eduhub Private Limited', 'PEPL'
) seed
INNER JOIN companies parent ON parent.code = 'AGHPL'
WHERE NOT EXISTS (SELECT 1 FROM companies existing WHERE existing.code = seed.child_code);

UPDATE companies child
INNER JOIN companies parent ON parent.code = 'AGHPL'
SET child.parent_company_id = parent.id,
    child.is_active = 1
WHERE child.code IN ('MBTAS', 'EBCPL', 'VEPL', 'PEPL');

INSERT INTO fiscal_years (company_id, label, start_date, end_date, is_active, is_default)
SELECT c.id, 'FY 2026-2027', '2026-01-01', '2026-12-31', 1, 1
FROM companies c
WHERE c.code IN ('MBAACA', 'AGHPL', 'MBTAS', 'EBCPL', 'VEPL', 'PEPL')
  AND NOT EXISTS (
      SELECT 1
      FROM fiscal_years fy
      WHERE fy.company_id = c.id
        AND fy.label = 'FY 2026-2027'
  );
