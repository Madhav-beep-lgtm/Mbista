SET @has_parent_column := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'companies'
      AND column_name = 'parent_company_id'
);
SET @add_parent_column_sql := IF(
    @has_parent_column = 0,
    'ALTER TABLE companies ADD COLUMN parent_company_id INT UNSIGNED NULL AFTER code',
    'SELECT 1'
);
PREPARE stmt_parent_column FROM @add_parent_column_sql;
EXECUTE stmt_parent_column;
DEALLOCATE PREPARE stmt_parent_column;

SET @has_parent_idx := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'companies'
      AND index_name = 'idx_companies_parent'
);
SET @create_parent_idx_sql := IF(
    @has_parent_idx = 0,
    'ALTER TABLE companies ADD KEY idx_companies_parent (parent_company_id)',
    'SELECT 1'
);
PREPARE stmt_parent_idx FROM @create_parent_idx_sql;
EXECUTE stmt_parent_idx;
DEALLOCATE PREPARE stmt_parent_idx;

SET @has_parent_fk := (
    SELECT COUNT(*)
    FROM information_schema.table_constraints
    WHERE constraint_schema = DATABASE()
      AND table_name = 'companies'
      AND constraint_name = 'fk_companies_parent'
      AND constraint_type = 'FOREIGN KEY'
);
SET @create_parent_fk_sql := IF(
    @has_parent_fk = 0,
    'ALTER TABLE companies ADD CONSTRAINT fk_companies_parent FOREIGN KEY (parent_company_id) REFERENCES companies(id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt_parent_fk FROM @create_parent_fk_sql;
EXECUTE stmt_parent_fk;
DEALLOCATE PREPARE stmt_parent_fk;

UPDATE companies
SET name = 'Altiora Global Holdings Private Limited',
    code = 'AGHPL',
    parent_company_id = NULL,
    is_active = 1
WHERE code = 'ALTIORA';

INSERT INTO companies (name, code, parent_company_id, is_active)
SELECT 'Altiora Global Holdings Private Limited', 'AGHPL', NULL, 1
WHERE NOT EXISTS (SELECT 1 FROM companies WHERE code = 'AGHPL');

INSERT INTO companies (name, code, parent_company_id, is_active)
VALUES
('M.B. Training and Advisory Services Private Limited', 'MBTAS', NULL, 1),
('Excel Business Consulting Private Limited', 'EBCPL', NULL, 1),
('Vyra Edupath Private limited', 'VEPL', NULL, 1),
('Passion Eduhub Private Limited', 'PEPL', NULL, 1),
('M.Bista and Associates, Chartered Accountants', 'MBAACA', NULL, 1)
ON DUPLICATE KEY UPDATE
name = VALUES(name),
is_active = VALUES(is_active);

UPDATE companies child
INNER JOIN companies parent ON parent.code = 'AGHPL'
SET child.parent_company_id = parent.id,
    child.is_active = 1
WHERE child.code IN ('MBTAS', 'EBCPL', 'VEPL', 'PEPL');

UPDATE companies
SET parent_company_id = NULL,
    is_active = 1
WHERE code IN ('AGHPL', 'MBAACA');

UPDATE companies
SET parent_company_id = NULL,
    is_active = 0
WHERE code NOT IN ('AGHPL', 'MBTAS', 'EBCPL', 'VEPL', 'PEPL', 'MBAACA');

INSERT INTO fiscal_years (company_id, label, start_date, end_date, is_active, is_default)
SELECT c.id, 'FY 2026-2027', '2026-01-01', '2026-12-31', 1, 1
FROM companies c
WHERE c.code IN ('AGHPL', 'MBTAS', 'EBCPL', 'VEPL', 'PEPL', 'MBAACA')
  AND NOT EXISTS (
      SELECT 1
      FROM fiscal_years fy
      WHERE fy.company_id = c.id
        AND fy.label = 'FY 2026-2027'
  );
