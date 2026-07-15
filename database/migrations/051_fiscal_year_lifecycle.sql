-- Fiscal-year lifecycle: status (upcoming/open/closed/locked) and authorship
-- columns. The cutoff date stays in fiscal_period_locks (one row per
-- company+fiscal year, UNIQUE) — this migration does not move it.
-- Overlap/duplicate protection is enforced in the application inside a
-- transaction (SELECT ... FOR UPDATE on the company's fiscal_years rows);
-- MySQL cannot express a no-overlap constraint declaratively.

ALTER TABLE fiscal_years
  ADD COLUMN status ENUM('upcoming', 'open', 'closed', 'locked') NOT NULL DEFAULT 'open' AFTER is_default,
  ADD COLUMN created_by INT UNSIGNED DEFAULT NULL AFTER status,
  ADD COLUMN updated_by INT UNSIGNED DEFAULT NULL AFTER created_by,
  ADD KEY idx_fiscal_years_status (status);

-- Existing rows keep behaving exactly as before: everything is 'open'.
UPDATE fiscal_years SET status = 'open' WHERE status IS NULL OR status = '';
