<?php
declare(strict_types=1);
accounting_repair_add_column('invoice_payment_requests','source_voucher_id','source_voucher_id INT UNSIGNED NULL');
accounting_repair_add_index('invoice_payment_requests','uniq_payment_source_voucher','UNIQUE KEY uniq_payment_source_voucher (source_voucher_id)');
db()->exec("CREATE TABLE IF NOT EXISTS voucher_billing (
 voucher_id INT UNSIGNED NOT NULL PRIMARY KEY,
 company_id INT UNSIGNED NOT NULL,
 kind VARCHAR(16) NOT NULL,
 task_id INT UNSIGNED NULL,
 stage_id INT UNSIGNED NULL,
 invoice_id INT UNSIGNED NULL,
 document_id INT UNSIGNED NULL,
 payload LONGTEXT NOT NULL,
 KEY idx_voucher_billing_company (company_id),
 CONSTRAINT fk_voucher_billing_voucher FOREIGN KEY (voucher_id) REFERENCES vouchers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
accounting_repair_add_index('voucher_billing','idx_voucher_billing_document','KEY idx_voucher_billing_document (kind,document_id)');
