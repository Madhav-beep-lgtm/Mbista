-- Production upgrade: invoice workflow module (migrations 010 + 018 + 019 combined, idempotent)
-- Run ONCE in phpMyAdmin against the production database. Safe to re-run: every statement
-- uses IF NOT EXISTS (MariaDB), so already-applied parts are skipped.
-- Fixes the 500 error on admin/invoice.php and admin/receipts.php after deploying from an
-- older schema.sql that lacked these tables/columns.

-- ---- From migration 010: VAT / proforma-tax support on task_invoices ----
ALTER TABLE company_accounting_preferences ADD COLUMN IF NOT EXISTS default_excise_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER business_type;
ALTER TABLE task_invoices ADD COLUMN IF NOT EXISTS invoice_category ENUM('proforma', 'tax') DEFAULT 'proforma' AFTER invoice_type;
ALTER TABLE task_invoices ADD COLUMN IF NOT EXISTS tax_invoice_id INT UNSIGNED DEFAULT NULL AFTER invoice_category;
ALTER TABLE task_invoices ADD COLUMN IF NOT EXISTS vat_rate DECIMAL(5,2) DEFAULT 13.00 AFTER amount;
ALTER TABLE task_invoices ADD COLUMN IF NOT EXISTS excise_rate DECIMAL(5,2) DEFAULT 0.00 AFTER vat_rate;
ALTER TABLE task_invoices ADD COLUMN IF NOT EXISTS excise_amount DECIMAL(12,2) DEFAULT 0.00 AFTER excise_rate;
ALTER TABLE task_invoices ADD COLUMN IF NOT EXISTS vat_amount DECIMAL(12,2) DEFAULT 0.00 AFTER vat_rate;
ALTER TABLE task_invoices ADD COLUMN IF NOT EXISTS taxable_amount DECIMAL(12,2) DEFAULT 0.00 AFTER vat_amount;
ALTER TABLE task_invoices ADD COLUMN IF NOT EXISTS total_amount DECIMAL(12,2) DEFAULT 0.00 AFTER taxable_amount;
ALTER TABLE task_invoices ADD COLUMN IF NOT EXISTS tax_invoice_date DATE DEFAULT NULL AFTER total_amount;
ALTER TABLE task_invoices ADD COLUMN IF NOT EXISTS pan_number VARCHAR(20) DEFAULT NULL AFTER tax_invoice_date;
ALTER TABLE task_invoices ADD COLUMN IF NOT EXISTS vat_reg_number VARCHAR(20) DEFAULT NULL AFTER pan_number;
ALTER TABLE task_invoices ADD COLUMN IF NOT EXISTS converted_to_tax_on TIMESTAMP NULL DEFAULT NULL AFTER vat_reg_number;
ALTER TABLE task_invoices ADD COLUMN IF NOT EXISTS converted_by INT UNSIGNED DEFAULT NULL AFTER converted_to_tax_on;
ALTER TABLE task_invoices ADD KEY IF NOT EXISTS idx_task_invoices_category (invoice_category);
ALTER TABLE task_invoices ADD KEY IF NOT EXISTS idx_task_invoices_tax_invoice (tax_invoice_id);
ALTER TABLE task_invoices ADD FOREIGN KEY IF NOT EXISTS fk_task_invoices_tax_invoice (tax_invoice_id) REFERENCES task_invoices (id) ON DELETE SET NULL;
ALTER TABLE task_invoices ADD FOREIGN KEY IF NOT EXISTS fk_task_invoices_converted_by (converted_by) REFERENCES users (id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS invoice_payment_requests (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  invoice_id INT UNSIGNED NOT NULL,
  company_id INT UNSIGNED DEFAULT NULL,
  requested_by INT UNSIGNED NOT NULL,
  amount_requested DECIMAL(12,2) NOT NULL,
  payment_method VARCHAR(100) DEFAULT NULL,
  status ENUM('pending', 'paid', 'partial', 'cancelled') DEFAULT 'pending',
  payment_received_on DATE DEFAULT NULL,
  payment_amount DECIMAL(12,2) DEFAULT 0.00,
  notes TEXT DEFAULT NULL,
  requested_on TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_invoice_payment_requests_invoice (invoice_id),
  KEY idx_invoice_payment_requests_company (company_id),
  KEY idx_invoice_payment_requests_status (status),
  CONSTRAINT fk_invoice_payment_requests_invoice FOREIGN KEY (invoice_id) REFERENCES task_invoices (id) ON DELETE CASCADE,
  CONSTRAINT fk_invoice_payment_requests_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- From migration 018: discounts, ticket requests, payment receipts ----
ALTER TABLE task_invoices ADD COLUMN IF NOT EXISTS discount_type ENUM('none', 'percent', 'fixed') NOT NULL DEFAULT 'none' AFTER total_amount;
ALTER TABLE task_invoices ADD COLUMN IF NOT EXISTS discount_value DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER discount_type;
ALTER TABLE task_invoices ADD COLUMN IF NOT EXISTS discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER discount_value;
ALTER TABLE task_invoices ADD COLUMN IF NOT EXISTS adjusted_on TIMESTAMP NULL DEFAULT NULL AFTER discount_amount;
ALTER TABLE task_invoices ADD COLUMN IF NOT EXISTS adjusted_by INT UNSIGNED DEFAULT NULL AFTER adjusted_on;
ALTER TABLE task_invoices ADD KEY IF NOT EXISTS idx_task_invoices_discount_type (discount_type);
ALTER TABLE task_invoices ADD FOREIGN KEY IF NOT EXISTS fk_task_invoices_adjusted_by (adjusted_by) REFERENCES users (id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS support_ticket_requests (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ticket_id INT UNSIGNED NOT NULL,
  company_id INT UNSIGNED NOT NULL,
  client_id INT UNSIGNED NOT NULL,
  request_type ENUM('invoice_request', 'discount_request', 'credit_period_request', 'advance_payment_request', 'partial_payment_request') NOT NULL,
  target_task_id INT UNSIGNED DEFAULT NULL,
  target_stage_id INT UNSIGNED DEFAULT NULL,
  target_invoice_id INT UNSIGNED DEFAULT NULL,
  requested_amount DECIMAL(12,2) DEFAULT NULL,
  requested_percent DECIMAL(6,2) DEFAULT NULL,
  requested_due_on DATE DEFAULT NULL,
  decision_status ENUM('pending', 'approved', 'rejected', 'negotiation', 'deferred') NOT NULL DEFAULT 'pending',
  approved_amount DECIMAL(12,2) DEFAULT NULL,
  approved_percent DECIMAL(6,2) DEFAULT NULL,
  approved_due_on DATE DEFAULT NULL,
  applied_invoice_id INT UNSIGNED DEFAULT NULL,
  admin_note TEXT DEFAULT NULL,
  processed_by INT UNSIGNED DEFAULT NULL,
  processed_on TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_support_ticket_request_ticket (ticket_id),
  KEY idx_support_ticket_requests_company (company_id),
  KEY idx_support_ticket_requests_client (client_id),
  KEY idx_support_ticket_requests_type (request_type),
  KEY idx_support_ticket_requests_target_invoice (target_invoice_id),
  CONSTRAINT fk_support_ticket_requests_ticket FOREIGN KEY (ticket_id) REFERENCES support_tickets (id) ON DELETE CASCADE,
  CONSTRAINT fk_support_ticket_requests_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT fk_support_ticket_requests_client FOREIGN KEY (client_id) REFERENCES client_profiles (id) ON DELETE CASCADE,
  CONSTRAINT fk_support_ticket_requests_task FOREIGN KEY (target_task_id) REFERENCES client_tasks (id) ON DELETE SET NULL,
  CONSTRAINT fk_support_ticket_requests_stage FOREIGN KEY (target_stage_id) REFERENCES task_stages (id) ON DELETE SET NULL,
  CONSTRAINT fk_support_ticket_requests_invoice FOREIGN KEY (target_invoice_id) REFERENCES task_invoices (id) ON DELETE SET NULL,
  CONSTRAINT fk_support_ticket_requests_applied_invoice FOREIGN KEY (applied_invoice_id) REFERENCES task_invoices (id) ON DELETE SET NULL,
  CONSTRAINT fk_support_ticket_requests_processed_by FOREIGN KEY (processed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoice_payment_receipts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  payment_request_id INT UNSIGNED NOT NULL,
  invoice_id INT UNSIGNED NOT NULL,
  company_id INT UNSIGNED NOT NULL,
  client_id INT UNSIGNED DEFAULT NULL,
  receipt_no VARCHAR(60) NOT NULL,
  amount_received DECIMAL(12,2) NOT NULL,
  payment_method VARCHAR(100) DEFAULT NULL,
  payment_reference VARCHAR(190) DEFAULT NULL,
  received_on DATE NOT NULL,
  notes TEXT DEFAULT NULL,
  created_by INT UNSIGNED DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_invoice_payment_receipts_no (receipt_no),
  KEY idx_invoice_payment_receipts_request (payment_request_id),
  KEY idx_invoice_payment_receipts_invoice (invoice_id),
  KEY idx_invoice_payment_receipts_company (company_id),
  CONSTRAINT fk_invoice_payment_receipts_request FOREIGN KEY (payment_request_id) REFERENCES invoice_payment_requests (id) ON DELETE CASCADE,
  CONSTRAINT fk_invoice_payment_receipts_invoice FOREIGN KEY (invoice_id) REFERENCES task_invoices (id) ON DELETE CASCADE,
  CONSTRAINT fk_invoice_payment_receipts_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT fk_invoice_payment_receipts_client FOREIGN KEY (client_id) REFERENCES client_profiles (id) ON DELETE SET NULL,
  CONSTRAINT fk_invoice_payment_receipts_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- From migration 019: client payment declarations + task/stage staff assignment ----
ALTER TABLE invoice_payment_requests ADD COLUMN IF NOT EXISTS client_declared_status ENUM('none', 'partial', 'complete') NOT NULL DEFAULT 'none' AFTER notes;
ALTER TABLE invoice_payment_requests ADD COLUMN IF NOT EXISTS client_declared_amount DECIMAL(12,2) DEFAULT NULL AFTER client_declared_status;
ALTER TABLE invoice_payment_requests ADD COLUMN IF NOT EXISTS client_declared_method VARCHAR(100) DEFAULT NULL AFTER client_declared_amount;
ALTER TABLE invoice_payment_requests ADD COLUMN IF NOT EXISTS client_declared_reference VARCHAR(190) DEFAULT NULL AFTER client_declared_method;
ALTER TABLE invoice_payment_requests ADD COLUMN IF NOT EXISTS client_declared_on DATE DEFAULT NULL AFTER client_declared_reference;
ALTER TABLE invoice_payment_requests ADD COLUMN IF NOT EXISTS client_declared_note TEXT DEFAULT NULL AFTER client_declared_on;
ALTER TABLE invoice_payment_requests ADD COLUMN IF NOT EXISTS client_declared_at TIMESTAMP NULL DEFAULT NULL AFTER client_declared_note;
ALTER TABLE invoice_payment_requests ADD KEY IF NOT EXISTS idx_invoice_payment_requests_declared (client_declared_status);

ALTER TABLE client_tasks ADD COLUMN IF NOT EXISTS assigned_staff_user_id INT UNSIGNED DEFAULT NULL AFTER team_id;
ALTER TABLE client_tasks ADD KEY IF NOT EXISTS idx_client_tasks_assigned_staff (assigned_staff_user_id);
ALTER TABLE client_tasks ADD FOREIGN KEY IF NOT EXISTS fk_client_tasks_assigned_staff (assigned_staff_user_id) REFERENCES users (id) ON DELETE SET NULL;

ALTER TABLE task_stages ADD COLUMN IF NOT EXISTS assigned_staff_user_id INT UNSIGNED DEFAULT NULL AFTER stage_fee;
ALTER TABLE task_stages ADD KEY IF NOT EXISTS idx_task_stages_assigned_staff (assigned_staff_user_id);
ALTER TABLE task_stages ADD FOREIGN KEY IF NOT EXISTS fk_task_stages_assigned_staff (assigned_staff_user_id) REFERENCES users (id) ON DELETE SET NULL;
