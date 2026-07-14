-- Fixed Asset Class Measurement Models
-- Migration 044
-- Applies one cost/revaluation model to each complete asset class.

CREATE TABLE IF NOT EXISTS asset_class_measurement_models (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id INT UNSIGNED NOT NULL,
    asset_class VARCHAR(40) NOT NULL,
    measurement_model VARCHAR(24) NOT NULL DEFAULT 'cost',
    active_market_confirmed TINYINT(1) NOT NULL DEFAULT 0,
    effective_date DATE NOT NULL,
    policy_note TEXT DEFAULT NULL,
    approved_by INT UNSIGNED DEFAULT NULL,
    approved_at DATETIME DEFAULT NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_asset_class_measurement_model (company_id, asset_class),
    KEY idx_asset_class_measurement_model (company_id, measurement_model, asset_class)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO asset_class_measurement_models
    (company_id, asset_class, measurement_model, active_market_confirmed,
     effective_date, policy_note, approved_by, approved_at, created_by)
SELECT c.id, classes.asset_class, 'cost', 0, CURDATE(),
       'Default cost model created during system upgrade.', NULL, NULL, c.id
FROM companies c
JOIN (
    SELECT 'ppe' AS asset_class
    UNION ALL SELECT 'intangible'
    UNION ALL SELECT 'rou'
    UNION ALL SELECT 'investment_property'
    UNION ALL SELECT 'cwip'
) classes;

UPDATE asset_class_measurement_models m
SET m.measurement_model = 'revaluation',
    m.active_market_confirmed = CASE WHEN m.asset_class = 'intangible' THEN 1 ELSE m.active_market_confirmed END,
    m.policy_note = 'Revaluation model retained from existing approved revaluation history.'
WHERE m.asset_class IN ('ppe', 'intangible', 'investment_property')
  AND (
        EXISTS (
            SELECT 1 FROM asset_revaluation_batches b
            WHERE b.company_id = m.company_id
              AND b.asset_class = m.asset_class
              AND b.status = 'approved'
        )
        OR EXISTS (
            SELECT 1 FROM fixed_assets fa
            WHERE fa.company_id = m.company_id
              AND fa.asset_class = m.asset_class
              AND (fa.last_revaluation_date IS NOT NULL
                   OR ABS(COALESCE(fa.revaluation_reserve, 0)) >= 0.005
                   OR ABS(COALESCE(fa.revaluation_loss_balance, 0)) >= 0.005)
        )
  );
