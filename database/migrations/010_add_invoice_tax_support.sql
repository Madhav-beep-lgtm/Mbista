-- Add invoice type and VAT tracking fields to support proforma to tax invoice conversion
-- Nepal VAT (Value Added Tax) 2052 & Rules 2053 compliance

ALTER TABLE task_invoices
ADD COLUMN invoice_category ENUM('proforma', 'tax') DEFAULT 'proforma' AFTER invoice_type,
ADD COLUMN tax_invoice_id INT UNSIGNED DEFAULT NULL AFTER invoice_category,
ADD COLUMN vat_rate DECIMAL(5,2) DEFAULT 13.00 AFTER amount,
ADD COLUMN vat_amount DECIMAL(12,2) DEFAULT 0.00 AFTER vat_rate,
ADD COLUMN taxable_amount DECIMAL(12,2) DEFAULT 0.00 AFTER vat_amount,
ADD COLUMN total_amount DECIMAL(12,2) DEFAULT 0.00 AFTER taxable_amount,
ADD COLUMN tax_invoice_date DATE DEFAULT NULL AFTER total_amount,
ADD COLUMN pan_number VARCHAR(20) DEFAULT NULL AFTER tax_invoice_date,
ADD COLUMN vat_reg_number VARCHAR(20) DEFAULT NULL AFTER pan_number,
ADD COLUMN converted_to_tax_on TIMESTAMP DEFAULT NULL AFTER vat_reg_number,
ADD COLUMN converted_by INT UNSIGNED DEFAULT NULL AFTER converted_to_tax_on,
ADD KEY idx_task_invoices_category (invoice_category),
ADD KEY idx_task_invoices_tax_invoice (tax_invoice_id),
ADD CONSTRAINT fk_task_invoices_tax_invoice FOREIGN KEY (tax_invoice_id) REFERENCES task_invoices (id) ON DELETE SET NULL,
ADD CONSTRAINT fk_task_invoices_converted_by FOREIGN KEY (converted_by) REFERENCES users (id) ON DELETE SET NULL;

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
  CONSTRAINT fk_invoice_payment_requests_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE SET NULL,
  CONSTRAINT fk_invoice_payment_requests_requested_by FOREIGN KEY (requested_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
