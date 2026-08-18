-- Fixed assets: the income-tax pool, and what a disposal actually fetched.
--
-- tax_pool
--   The Income Tax Act pools an entity's depreciable assets into five classes
--   (A to E) and depreciates each POOL, not each asset. Accounting depreciates
--   the asset itself over its useful life, so the two never agree - which is
--   the point: the difference is what the deferred tax is computed on. Nothing
--   can be reported by pool until each asset says which pool it belongs to.
--   Nullable, because assets registered before this column existed have not
--   been assigned one and must not be silently dropped into Pool A.
--
-- disposal_proceeds / disposal_gain_loss
--   Both were worked out when an asset was disposed and then thrown away: only
--   the voucher kept them, spread across legs whose ledgers differ per company,
--   so the register could not state the gain on a sale without re-deriving it
--   from entries it cannot reliably identify. Stored here they are read once,
--   which is what keeps the register to a fixed number of queries however many
--   assets a company holds.
--
-- Re-runnable: every clause is IF NOT EXISTS, so applying this twice is safe.

ALTER TABLE fixed_assets
    ADD COLUMN IF NOT EXISTS tax_pool ENUM('a','b','c','d','e') NULL DEFAULT NULL AFTER asset_class,
    ADD COLUMN IF NOT EXISTS disposal_proceeds DECIMAL(18,2) NULL DEFAULT NULL AFTER disposed_on,
    ADD COLUMN IF NOT EXISTS disposal_gain_loss DECIMAL(18,2) NULL DEFAULT NULL AFTER disposal_proceeds;

-- The register groups and filters by pool, and by class within a company.
ALTER TABLE fixed_assets
    ADD INDEX IF NOT EXISTS idx_fixed_assets_company_pool (company_id, tax_pool);
