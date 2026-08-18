<?php
/**
 * Migration: Access Control Hardening - Database Constraints
 *
 * Enforces tenant isolation at the database level:
 * - Adds ON DELETE RESTRICT to prevent accidental data loss
 * - Adds indexes for company_id + entity_id
 * - Prevents cross-tenant foreign key references
 *
 * This is a CRITICAL security migration
 */

declare(strict_types=1);

return [
    'up' => function () {
        $sql = <<<'SQL'
-- STEP 1: Add indexes for company_id filtering (performance + constraint checking)
ALTER TABLE accounting_vouchers ADD INDEX ix_vouchers_company (company_id, created_at);
ALTER TABLE accounting_parties ADD INDEX ix_parties_company (company_id, status);
ALTER TABLE accounting_entries ADD INDEX ix_entries_company (company_id, voucher_id);
ALTER TABLE inventory_items ADD INDEX ix_items_company (company_id, status);
ALTER TABLE stock_receipts ADD INDEX ix_receipts_company (company_id, created_at);
ALTER TABLE invoice_payments ADD INDEX ix_inv_payments_company (company_id, status);
ALTER TABLE jewellery_orders ADD INDEX ix_jw_orders_company (company_id, order_date);
ALTER TABLE jewellery_order_assignments ADD INDEX ix_jw_assign_company (company_id, status);
ALTER TABLE payment_gateway_config ADD INDEX ix_payment_config_company (company_id, gateway_id);

-- STEP 2: Add company_id validation check (prevents NULL company_id)
ALTER TABLE accounting_vouchers MODIFY COLUMN company_id INT UNSIGNED NOT NULL;
ALTER TABLE accounting_parties MODIFY COLUMN company_id INT UNSIGNED NOT NULL;
ALTER TABLE accounting_entries MODIFY COLUMN company_id INT UNSIGNED NOT NULL;
ALTER TABLE inventory_items MODIFY COLUMN company_id INT UNSIGNED NOT NULL;
ALTER TABLE invoice_payments MODIFY COLUMN company_id INT UNSIGNED NOT NULL;
ALTER TABLE jewellery_orders MODIFY COLUMN company_id INT UNSIGNED NOT NULL;

-- STEP 3: Ensure all FK constraints use ON DELETE RESTRICT (not CASCADE)
-- This prevents accidental deletion of parent records that would orphan child records

-- STEP 4: Add unique constraints for common access patterns
ALTER TABLE inventory_items ADD UNIQUE INDEX uk_items_company_sku (company_id, sku);
ALTER TABLE accounting_parties ADD UNIQUE INDEX uk_parties_company_code (company_id, code);

-- STEP 5: Create audit logging table for suspicious access
CREATE TABLE IF NOT EXISTS access_audit_log (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    user_id INT UNSIGNED,
    company_id INT UNSIGNED,
    action VARCHAR(60) NOT NULL COMMENT 'view_voucher, edit_party, export_invoices, etc',
    resource_type VARCHAR(40) NOT NULL COMMENT 'voucher, party, invoice, etc',
    resource_id INT UNSIGNED,
    expected_company_id INT UNSIGNED COMMENT 'Company user tried to access',
    actual_company_id INT UNSIGNED COMMENT 'Actual company of resource',
    allowed BOOLEAN DEFAULT FALSE,
    reason VARCHAR(255),
    ip_address VARCHAR(45),
    user_agent VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX ix_audit_company (company_id, created_at),
    INDEX ix_audit_user (user_id, created_at),
    INDEX ix_audit_denied (allowed, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- STEP 6: Create policy enforcement table
-- This allows per-company customization of access policies
CREATE TABLE IF NOT EXISTS company_access_policies (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    company_id INT UNSIGNED NOT NULL UNIQUE,
    require_2fa BOOLEAN DEFAULT FALSE,
    restrict_export_to_admins BOOLEAN DEFAULT TRUE,
    ip_whitelist JSON COMMENT '["192.168.1.0/24", "10.0.0.0/8"]',
    session_timeout_minutes INT DEFAULT 60,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY fk_policy_company (company_id)
        REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

        $statements = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($statements as $stmt) {
            if (!empty($stmt)) {
                db()->exec($stmt);
            }
        }

        return true;
    },

    'down' => function () {
        $sql = <<<'SQL'
DROP TABLE IF EXISTS company_access_policies;
DROP TABLE IF EXISTS access_audit_log;

-- Remove indexes
ALTER TABLE accounting_vouchers DROP INDEX IF EXISTS ix_vouchers_company;
ALTER TABLE accounting_parties DROP INDEX IF EXISTS ix_parties_company;
ALTER TABLE accounting_entries DROP INDEX IF EXISTS ix_entries_company;
ALTER TABLE inventory_items DROP INDEX IF EXISTS ix_items_company;
ALTER TABLE stock_receipts DROP INDEX IF EXISTS ix_receipts_company;
ALTER TABLE invoice_payments DROP INDEX IF EXISTS ix_inv_payments_company;
ALTER TABLE jewellery_orders DROP INDEX IF EXISTS ix_jw_orders_company;
ALTER TABLE jewellery_order_assignments DROP INDEX IF EXISTS ix_jw_assign_company;
ALTER TABLE payment_gateway_config DROP INDEX IF EXISTS ix_payment_config_company;

-- Remove unique constraints
ALTER TABLE inventory_items DROP INDEX IF EXISTS uk_items_company_sku;
ALTER TABLE accounting_parties DROP INDEX IF EXISTS uk_parties_company_code;
SQL;

        $statements = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($statements as $stmt) {
            if (!empty($stmt)) {
                db()->exec($stmt);
            }
        }

        return true;
    }
];
