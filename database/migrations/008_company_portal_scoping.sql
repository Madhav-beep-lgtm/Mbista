SET @company_id := (
    SELECT id FROM companies WHERE code = 'AGHPL' LIMIT 1
);

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS company_id INT UNSIGNED NULL AFTER status,
  ADD KEY IF NOT EXISTS idx_users_company (company_id),
  ADD CONSTRAINT fk_users_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE SET NULL;

ALTER TABLE companies
  ADD COLUMN IF NOT EXISTS admin_pin_hash VARCHAR(255) DEFAULT NULL AFTER parent_company_id,
  ADD COLUMN IF NOT EXISTS admin_pin_reset_token_hash CHAR(64) DEFAULT NULL AFTER admin_pin_hash,
  ADD COLUMN IF NOT EXISTS admin_pin_reset_expires_at DATETIME DEFAULT NULL AFTER admin_pin_reset_token_hash,
  ADD COLUMN IF NOT EXISTS admin_pin_reset_used_at DATETIME DEFAULT NULL AFTER admin_pin_reset_expires_at;

ALTER TABLE teams
  ADD COLUMN IF NOT EXISTS company_id INT UNSIGNED NULL AFTER id,
  ADD KEY IF NOT EXISTS idx_teams_company (company_id),
  ADD CONSTRAINT fk_teams_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE SET NULL;

ALTER TABLE client_profiles
  ADD COLUMN IF NOT EXISTS company_id INT UNSIGNED NULL AFTER user_id,
  ADD KEY IF NOT EXISTS idx_client_profiles_company (company_id),
  ADD CONSTRAINT fk_client_profiles_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE SET NULL;

ALTER TABLE service_contracts
  ADD COLUMN IF NOT EXISTS company_id INT UNSIGNED NULL AFTER id,
  ADD KEY IF NOT EXISTS idx_service_contract_company (company_id),
  ADD CONSTRAINT fk_service_contract_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE SET NULL;

ALTER TABLE client_tasks
  ADD COLUMN IF NOT EXISTS company_id INT UNSIGNED NULL AFTER id,
  ADD KEY IF NOT EXISTS idx_client_tasks_company (company_id),
  ADD CONSTRAINT fk_client_tasks_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE SET NULL;

ALTER TABLE task_stages
  ADD COLUMN IF NOT EXISTS company_id INT UNSIGNED NULL AFTER id,
  ADD KEY IF NOT EXISTS idx_task_stages_company (company_id),
  ADD CONSTRAINT fk_task_stages_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE SET NULL;

ALTER TABLE task_invoices
  ADD COLUMN IF NOT EXISTS company_id INT UNSIGNED NULL AFTER id,
  ADD KEY IF NOT EXISTS idx_task_invoices_company (company_id),
  ADD CONSTRAINT fk_task_invoices_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE SET NULL;

UPDATE users SET company_id = @company_id WHERE company_id IS NULL AND role <> 'admin' AND @company_id IS NOT NULL;
UPDATE client_profiles SET company_id = @company_id WHERE company_id IS NULL AND @company_id IS NOT NULL;
UPDATE teams SET company_id = @company_id WHERE company_id IS NULL AND @company_id IS NOT NULL;
UPDATE service_contracts SET company_id = @company_id WHERE company_id IS NULL AND @company_id IS NOT NULL;
UPDATE client_tasks SET company_id = @company_id WHERE company_id IS NULL AND @company_id IS NOT NULL;
UPDATE task_stages ts
INNER JOIN client_tasks t ON t.id = ts.task_id
SET ts.company_id = t.company_id
WHERE ts.company_id IS NULL;
UPDATE task_invoices ti
INNER JOIN client_tasks t ON t.id = ti.task_id
SET ti.company_id = t.company_id
WHERE ti.company_id IS NULL;