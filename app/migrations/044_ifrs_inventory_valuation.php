<?php
/**
 * Migration: IAS 2 Inventory Valuation Tables
 *
 * Creates infrastructure for IAS 2 compliant inventory valuation
 * Supports FIFO, Weighted Average Cost, and Specific Identification methods
 */

declare(strict_types=1);

return [
    'up' => function () {
        $sql = <<<'SQL'
-- Main inventory valuation record
CREATE TABLE IF NOT EXISTS inventory_valuations (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    company_id INT UNSIGNED NOT NULL,
    valuation_date DATE NOT NULL,
    method ENUM('fifo', 'weighted_average', 'specific') NOT NULL,
    fiscal_year_id INT UNSIGNED,
    status ENUM('draft', 'posting', 'posted', 'archived') DEFAULT 'draft',
    item_count INT DEFAULT 0,
    total_value DECIMAL(18,2) DEFAULT 0.00,
    obsolescence_provision DECIMAL(18,2) DEFAULT 0.00,
    adjustment_posting_id INT UNSIGNED COMMENT 'Voucher ID for valuation adjustments',
    notes TEXT,
    created_by INT UNSIGNED,
    posted_by INT UNSIGNED,
    posted_at DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY fk_val_company (company_id)
        REFERENCES companies(id) ON DELETE RESTRICT,
    INDEX ix_val_company_date (company_id, valuation_date),
    INDEX ix_val_status (company_id, status),
    UNIQUE KEY uk_val_fiscal_year (company_id, fiscal_year_id, method)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Individual item valuations within each valuation record
CREATE TABLE IF NOT EXISTS inventory_valuation_items (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    valuation_id INT UNSIGNED NOT NULL,
    item_id INT UNSIGNED NOT NULL,
    opening_qty DECIMAL(14,3),
    opening_value DECIMAL(18,2),
    purchases_qty DECIMAL(14,3),
    purchases_value DECIMAL(18,2),
    sales_qty DECIMAL(14,3),
    sales_value DECIMAL(18,2),
    closing_qty DECIMAL(14,3),
    closing_value DECIMAL(18,2),
    obsolete_qty DECIMAL(14,3) COMMENT 'Quantity deemed obsolete',
    obsolete_value DECIMAL(18,2) COMMENT 'Value of obsolete stock',
    valuation_method_used VARCHAR(40) COMMENT 'Which method for this item',
    unit_cost DECIMAL(14,4) COMMENT 'Cost per unit after valuation',
    net_realizable_value DECIMAL(14,4) COMMENT 'Expected selling price per unit',

    FOREIGN KEY fk_val_items_valuation (valuation_id)
        REFERENCES inventory_valuations(id) ON DELETE CASCADE,
    FOREIGN KEY fk_val_items_item (item_id)
        REFERENCES inventory_items(id) ON DELETE RESTRICT,
    INDEX ix_val_items_valuation (valuation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FIFO layer tracking (for FIFO method)
CREATE TABLE IF NOT EXISTS inventory_fifo_layers (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    item_id INT UNSIGNED NOT NULL,
    company_id INT UNSIGNED NOT NULL,
    purchase_date DATE,
    receipt_id INT UNSIGNED,
    quantity_received DECIMAL(14,3),
    quantity_remaining DECIMAL(14,3),
    unit_cost DECIMAL(14,4),
    total_cost DECIMAL(18,2),
    status ENUM('active', 'exhausted') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY fk_fifo_item (item_id)
        REFERENCES inventory_items(id) ON DELETE RESTRICT,
    FOREIGN KEY fk_fifo_company (company_id)
        REFERENCES companies(id) ON DELETE RESTRICT,
    INDEX ix_fifo_item_status (item_id, status),
    INDEX ix_fifo_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Weighted average cost tracking
CREATE TABLE IF NOT EXISTS inventory_weighted_avg_costs (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    item_id INT UNSIGNED NOT NULL,
    company_id INT UNSIGNED NOT NULL,
    fiscal_year_id INT UNSIGNED,
    total_quantity DECIMAL(14,3),
    total_cost DECIMAL(18,2),
    weighted_avg_cost DECIMAL(14,4),
    last_updated DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY fk_wavg_item (item_id)
        REFERENCES inventory_items(id) ON DELETE RESTRICT,
    FOREIGN KEY fk_wavg_company (company_id)
        REFERENCES companies(id) ON DELETE RESTRICT,
    UNIQUE KEY uk_wavg_item_fy (item_id, company_id, fiscal_year_id),
    INDEX ix_wavg_company (company_id, last_updated)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Specific identification tracking (for high-value items like jewelry)
CREATE TABLE IF NOT EXISTS inventory_specific_identification (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    item_id INT UNSIGNED NOT NULL,
    company_id INT UNSIGNED NOT NULL,
    reference_no VARCHAR(120) COMMENT 'Serial number, certificate ID, etc',
    purchase_date DATE,
    receipt_id INT UNSIGNED,
    unit_cost DECIMAL(14,4),
    status ENUM('in_stock', 'sold', 'scrapped') DEFAULT 'in_stock',
    sold_date DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY fk_spec_item (item_id)
        REFERENCES inventory_items(id) ON DELETE RESTRICT,
    FOREIGN KEY fk_spec_company (company_id)
        REFERENCES companies(id) ON DELETE RESTRICT,
    UNIQUE KEY uk_spec_reference (company_id, item_id, reference_no),
    INDEX ix_spec_status (company_id, status),
    INDEX ix_spec_item (item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Valuation adjustment postings
CREATE TABLE IF NOT EXISTS inventory_adjustment_postings (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    company_id INT UNSIGNED NOT NULL,
    valuation_id INT UNSIGNED NOT NULL,
    voucher_id INT UNSIGNED,
    posting_type ENUM('obsolescence', 'nrv_adjustment', 'cost_recovery') NOT NULL,
    adjustment_amount DECIMAL(18,2),
    posted_at DATETIME,

    FOREIGN KEY fk_adj_company (company_id)
        REFERENCES companies(id) ON DELETE RESTRICT,
    FOREIGN KEY fk_adj_valuation (valuation_id)
        REFERENCES inventory_valuations(id) ON DELETE CASCADE,
    FOREIGN KEY fk_adj_voucher (voucher_id)
        REFERENCES accounting_vouchers(id) ON DELETE RESTRICT,
    INDEX ix_adj_valuation (valuation_id)
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
        return db()->exec("
            DROP TABLE IF EXISTS inventory_adjustment_postings;
            DROP TABLE IF EXISTS inventory_specific_identification;
            DROP TABLE IF EXISTS inventory_weighted_avg_costs;
            DROP TABLE IF EXISTS inventory_fifo_layers;
            DROP TABLE IF EXISTS inventory_valuation_items;
            DROP TABLE IF EXISTS inventory_valuations;
        ");
    }
];
