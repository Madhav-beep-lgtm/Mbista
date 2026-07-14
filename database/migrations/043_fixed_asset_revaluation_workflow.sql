-- Fixed Asset Revaluation Batch Workflow
-- Migration 043
-- The runtime repair function in app/fixed_asset_revaluation.php applies the
-- same changes safely to existing installations.

ALTER TABLE fixed_assets
    ADD COLUMN IF NOT EXISTS revaluation_loss_balance DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER revaluation_reserve,
    ADD COLUMN IF NOT EXISTS depreciation_base DECIMAL(18,2) DEFAULT NULL AFTER carrying_amount,
    ADD COLUMN IF NOT EXISTS depreciation_base_accumulated DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER depreciation_base,
    ADD COLUMN IF NOT EXISTS revaluation_life_months INT UNSIGNED DEFAULT NULL AFTER depreciation_base_accumulated,
    ADD COLUMN IF NOT EXISTS last_revaluation_date DATE DEFAULT NULL AFTER revaluation_life_months;

CREATE TABLE IF NOT EXISTS asset_revaluation_batches (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id INT UNSIGNED NOT NULL,
    batch_no VARCHAR(80) NOT NULL,
    asset_class VARCHAR(40) NOT NULL,
    revaluation_date DATE NOT NULL,
    valuer_name VARCHAR(190) NOT NULL,
    valuer_reference VARCHAR(190) DEFAULT NULL,
    valuation_method VARCHAR(80) NOT NULL,
    reason TEXT NOT NULL,
    report_path VARCHAR(255) NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'draft',
    submitted_by INT UNSIGNED DEFAULT NULL,
    submitted_at DATETIME DEFAULT NULL,
    approved_by INT UNSIGNED DEFAULT NULL,
    approved_at DATETIME DEFAULT NULL,
    rejected_by INT UNSIGNED DEFAULT NULL,
    rejected_at DATETIME DEFAULT NULL,
    rejection_reason TEXT DEFAULT NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_asset_revaluation_batch_no (company_id, batch_no),
    KEY idx_asset_revaluation_batch_status (company_id, status, revaluation_date),
    KEY idx_asset_revaluation_batch_class (company_id, asset_class, revaluation_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS asset_revaluation_lines (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    batch_id INT UNSIGNED NOT NULL,
    company_id INT UNSIGNED NOT NULL,
    asset_id INT UNSIGNED NOT NULL,
    previous_carrying_value DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    new_fair_value DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    increase_decrease DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    revised_useful_life_months INT UNSIGNED NOT NULL DEFAULT 1,
    revised_residual_value DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    remarks VARCHAR(500) DEFAULT NULL,
    pnl_effect DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    oci_effect DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    voucher_id INT UNSIGNED DEFAULT NULL,
    posted_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_asset_revaluation_batch_asset (batch_id, asset_id),
    KEY idx_asset_revaluation_line_company (company_id, batch_id),
    KEY idx_asset_revaluation_line_asset (asset_id, batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
