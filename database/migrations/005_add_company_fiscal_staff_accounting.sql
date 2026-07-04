ALTER TABLE users
    MODIFY COLUMN role ENUM('admin', 'staff', 'customer') NOT NULL DEFAULT 'customer';

CREATE TABLE IF NOT EXISTS companies (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(150) NOT NULL,
    code VARCHAR(30) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_companies_code (code),
    KEY idx_companies_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fiscal_years (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id INT UNSIGNED NOT NULL,
    label VARCHAR(80) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_fiscal_years_company (company_id),
    KEY idx_fiscal_years_active (is_active),
    CONSTRAINT fk_fiscal_years_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ledgers (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id INT UNSIGNED NOT NULL,
    code VARCHAR(40) NOT NULL,
    name VARCHAR(150) NOT NULL,
    type ENUM('asset', 'liability', 'equity', 'revenue', 'expense') NOT NULL,
    is_system TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_ledgers_company_code (company_id, code),
    KEY idx_ledgers_company (company_id),
    CONSTRAINT fk_ledgers_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vouchers (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id INT UNSIGNED NOT NULL,
    fiscal_year_id INT UNSIGNED NOT NULL,
    voucher_no VARCHAR(80) NOT NULL,
    voucher_type ENUM('payment', 'receipt', 'journal') NOT NULL DEFAULT 'journal',
    source_type VARCHAR(80) DEFAULT NULL,
    source_id INT UNSIGNED DEFAULT NULL,
    narration TEXT DEFAULT NULL,
    total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    status ENUM('draft', 'posted', 'cancelled') NOT NULL DEFAULT 'posted',
    posted_by INT UNSIGNED DEFAULT NULL,
    posted_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_vouchers_no_company (company_id, voucher_no),
    UNIQUE KEY uniq_vouchers_source (source_type, source_id),
    KEY idx_vouchers_company_fiscal (company_id, fiscal_year_id),
    CONSTRAINT fk_vouchers_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_vouchers_fiscal_year FOREIGN KEY (fiscal_year_id) REFERENCES fiscal_years(id) ON DELETE CASCADE,
    CONSTRAINT fk_vouchers_posted_by FOREIGN KEY (posted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS voucher_entries (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    voucher_id INT UNSIGNED NOT NULL,
    ledger_id INT UNSIGNED NOT NULL,
    entry_type ENUM('debit', 'credit') NOT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    memo VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_voucher_entries_voucher (voucher_id),
    KEY idx_voucher_entries_ledger (ledger_id),
    CONSTRAINT fk_voucher_entries_voucher FOREIGN KEY (voucher_id) REFERENCES vouchers(id) ON DELETE CASCADE,
    CONSTRAINT fk_voucher_entries_ledger FOREIGN KEY (ledger_id) REFERENCES ledgers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO companies (name, code, is_active)
SELECT 'Altiora Hosting', 'ALTIORA', 1
WHERE NOT EXISTS (SELECT 1 FROM companies WHERE code = 'ALTIORA');

INSERT INTO fiscal_years (company_id, label, start_date, end_date, is_active, is_default)
SELECT c.id, 'FY 2026-2027', '2026-01-01', '2026-12-31', 1, 1
FROM companies c
WHERE c.code = 'ALTIORA'
  AND NOT EXISTS (
      SELECT 1
      FROM fiscal_years fy
      WHERE fy.company_id = c.id
        AND fy.label = 'FY 2026-2027'
  );

INSERT INTO ledgers (company_id, code, name, type, is_system)
SELECT c.id, 'CASH', 'Cash/Bank', 'asset', 1
FROM companies c
WHERE c.code = 'ALTIORA'
  AND NOT EXISTS (
      SELECT 1
      FROM ledgers l
      WHERE l.company_id = c.id
        AND l.code = 'CASH'
  );

INSERT INTO ledgers (company_id, code, name, type, is_system)
SELECT c.id, 'HOSTING_REVENUE', 'Hosting Revenue', 'revenue', 1
FROM companies c
WHERE c.code = 'ALTIORA'
  AND NOT EXISTS (
      SELECT 1
      FROM ledgers l
      WHERE l.company_id = c.id
        AND l.code = 'HOSTING_REVENUE'
  );
