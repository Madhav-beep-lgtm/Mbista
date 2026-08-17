<?php
/**
 * Migration: Create payment_gateway_config table
 *
 * Stores configuration for eSewa, Khalti, Fonepay, Stripe
 * Each gateway can be enabled/disabled and configured per company
 */

declare(strict_types=1);

return [
    'up' => function () {
        $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS payment_gateway_config (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    company_id INT UNSIGNED NOT NULL,
    gateway_id VARCHAR(40) NOT NULL COMMENT 'esewa, khalti, fonepay, stripe',
    config_json JSON NOT NULL COMMENT 'API keys, merchant codes, URLs stored as JSON',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_company_gateway (company_id, gateway_id),
    FOREIGN KEY fk_payment_config_company (company_id)
        REFERENCES companies(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table to track payment processing
CREATE TABLE IF NOT EXISTS invoice_payments (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    company_id INT UNSIGNED NOT NULL,
    invoice_id INT UNSIGNED NOT NULL,
    payment_gateway VARCHAR(40) NOT NULL COMMENT 'Which gateway processed this',
    gateway_ref_id VARCHAR(120) NOT NULL COMMENT 'Gateway transaction ID',
    amount DECIMAL(14,2) NOT NULL,
    currency VARCHAR(3) DEFAULT 'NPR',
    status ENUM('pending', 'completed', 'failed', 'refunded') DEFAULT 'pending',
    payment_method VARCHAR(40) COMMENT 'wallet, qr, bank_transfer, card, etc',
    raw_response JSON COMMENT 'Full gateway response for debugging',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_gateway_ref (company_id, gateway_ref_id),
    FOREIGN KEY fk_invoice_payment_company (company_id)
        REFERENCES companies(id) ON DELETE RESTRICT,
    FOREIGN KEY fk_invoice_payment_invoice (invoice_id)
        REFERENCES accounting_vouchers(id) ON DELETE CASCADE,
    INDEX ix_invoice_payments_status (company_id, status),
    INDEX ix_invoice_payments_created (company_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Webhook event log for debugging
CREATE TABLE IF NOT EXISTS payment_webhook_events (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    company_id INT UNSIGNED NOT NULL,
    gateway_id VARCHAR(40) NOT NULL,
    event_type VARCHAR(60) NOT NULL,
    payload JSON NOT NULL,
    processed BOOLEAN DEFAULT FALSE,
    error_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY fk_webhook_company (company_id)
        REFERENCES companies(id) ON DELETE RESTRICT,
    INDEX ix_webhooks_gateway (company_id, gateway_id, created_at),
    INDEX ix_webhooks_processed (company_id, processed)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

        return db()->exec($sql);
    },

    'down' => function () {
        return db()->exec("
            DROP TABLE IF EXISTS payment_webhook_events;
            DROP TABLE IF EXISTS invoice_payments;
            DROP TABLE IF EXISTS payment_gateway_config;
        ");
    }
];
