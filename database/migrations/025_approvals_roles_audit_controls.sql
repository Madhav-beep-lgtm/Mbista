-- Batch 2 of the MB World blueprint: approvals, role access levels, audit
-- trail field-history, document approval, ticket SLA, and fiscal controls.

-- Per-user access level (role matrix). role stays admin/staff/customer for auth;
-- access_level refines capabilities inside the admin portal.
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS access_level ENUM('super_admin', 'parent_admin', 'subsidiary_admin', 'accountant', 'approver', 'viewer', 'support') NOT NULL DEFAULT 'accountant' AFTER role;

-- Vouchers gain an approval lifecycle. Existing rows keep 'posted' status; the
-- new approval_state defaults to 'approved' so historical data is unaffected.
ALTER TABLE vouchers
  ADD COLUMN IF NOT EXISTS approval_state ENUM('draft', 'pending_approval', 'approved', 'rejected') NOT NULL DEFAULT 'approved' AFTER status,
  ADD COLUMN IF NOT EXISTS submitted_by INT UNSIGNED DEFAULT NULL AFTER approval_state,
  ADD COLUMN IF NOT EXISTS approved_by INT UNSIGNED DEFAULT NULL AFTER submitted_by,
  ADD COLUMN IF NOT EXISTS approved_at DATETIME DEFAULT NULL AFTER approved_by,
  ADD COLUMN IF NOT EXISTS rejection_reason VARCHAR(255) DEFAULT NULL AFTER approved_at,
  ADD KEY IF NOT EXISTS idx_vouchers_approval (company_id, approval_state);

-- Field-level change history for the Audit Trail page.
CREATE TABLE IF NOT EXISTS audit_change_history (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT UNSIGNED DEFAULT NULL,
  entity_type VARCHAR(60) NOT NULL,
  entity_id INT UNSIGNED NOT NULL,
  field_name VARCHAR(80) NOT NULL,
  old_value TEXT DEFAULT NULL,
  new_value TEXT DEFAULT NULL,
  actor_id INT UNSIGNED DEFAULT NULL,
  ip_address VARCHAR(60) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_change_entity (entity_type, entity_id, created_at),
  KEY idx_audit_change_company (company_id, created_at),
  CONSTRAINT fk_audit_change_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add IP address to the activity log for traceability (page 19 spec).
ALTER TABLE activity_logs
  ADD COLUMN IF NOT EXISTS ip_address VARCHAR(60) DEFAULT NULL AFTER actor_id;

-- Document approval workflow (page 12 spec).
ALTER TABLE documents
  ADD COLUMN IF NOT EXISTS approval_status ENUM('draft', 'under_review', 'approved', 'rejected') NOT NULL DEFAULT 'approved' AFTER visibility,
  ADD COLUMN IF NOT EXISTS approved_by INT UNSIGNED DEFAULT NULL AFTER approval_status,
  ADD COLUMN IF NOT EXISTS approved_at DATETIME DEFAULT NULL AFTER approved_by,
  ADD KEY IF NOT EXISTS idx_documents_approval (company_id, approval_status);

-- Ticket SLA tracking (page 16 spec).
ALTER TABLE support_tickets
  ADD COLUMN IF NOT EXISTS sla_due_at DATETIME DEFAULT NULL AFTER status,
  ADD COLUMN IF NOT EXISTS resolved_at DATETIME DEFAULT NULL AFTER sla_due_at,
  ADD KEY IF NOT EXISTS idx_support_tickets_sla (company_id, sla_due_at);

-- Fiscal period locks per company + fiscal year (page 18 spec).
CREATE TABLE IF NOT EXISTS fiscal_period_locks (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT UNSIGNED NOT NULL,
  fiscal_year_id INT UNSIGNED NOT NULL,
  locked_through DATE NOT NULL,
  locked_by INT UNSIGNED DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_fiscal_period_lock (company_id, fiscal_year_id),
  CONSTRAINT fk_fiscal_period_lock_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_fiscal_period_lock_fy FOREIGN KEY (fiscal_year_id) REFERENCES fiscal_years(id) ON DELETE CASCADE,
  CONSTRAINT fk_fiscal_period_lock_user FOREIGN KEY (locked_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (setting_key, setting_value) VALUES
  ('approvals_enabled', '0'),
  ('default_vat_rate', '13')
ON DUPLICATE KEY UPDATE setting_value = setting_value;

-- Backfill access levels for existing users (column default was 'accountant').
UPDATE users SET access_level = 'staff' WHERE 1=0; -- no-op guard for re-runs
UPDATE users SET access_level = CASE
    WHEN role = 'admin' THEN 'parent_admin'
    WHEN role = 'staff' THEN 'accountant'
    ELSE 'viewer'
END;
-- Primary super admin keeps full access.
UPDATE users SET access_level = 'super_admin' WHERE email = 'admin@mbista.local';
