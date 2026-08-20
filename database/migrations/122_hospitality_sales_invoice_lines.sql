-- The settlement side of a day's sales: how each invoice was paid.
--
-- The item-wise sheet says WHAT was sold and carries the credit side (sales by
-- category, plus VAT). This carries the debit side -- cash, card, FonePay, or a
-- customer's own ledger for a credit sale -- one row per invoice, so a day
-- settled several ways posts several debits against the one set of credits.
CREATE TABLE IF NOT EXISTS `hospitality_sales_invoice_lines` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `upload_id` INT UNSIGNED NOT NULL,
    `company_id` INT UNSIGNED NOT NULL,
    `sale_date` DATE NOT NULL,
    `invoice_no` VARCHAR(60) NOT NULL DEFAULT '',
    `payment_type` VARCHAR(60) NOT NULL DEFAULT '',
    -- What the sheet said, kept beside what it resolved to: the code is the
    -- audit record of the upload, the id is what the voucher used.
    `ledger_code` VARCHAR(60) NOT NULL DEFAULT '',
    `ledger_id` INT UNSIGNED DEFAULT NULL,
    `gross_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `discount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `taxable_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `vat_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `total_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `voucher_id` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_hosp_invoice_lines_date` (`company_id`, `sale_date`),
    KEY `idx_hosp_invoice_lines_upload` (`upload_id`),
    -- Party-wise sales reads by ledger; without this it is a full scan of
    -- every invoice the tenant has ever uploaded.
    KEY `idx_hosp_invoice_lines_ledger` (`company_id`, `ledger_id`),
    CONSTRAINT `fk_hosp_invoice_lines_upload` FOREIGN KEY (`upload_id`) REFERENCES `hospitality_sales_uploads` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_hosp_invoice_lines_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `hospitality_sales_uploads`
    ADD COLUMN `invoice_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `row_count`;
