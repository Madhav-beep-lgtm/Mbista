-- 068: Costing runs driven by the uploaded daily sales sheet (067).
--
-- The sales upload (migration 067) posts revenue vouchers; this migration
-- lets the SAME uploaded rows drive the reference-only recipe costing:
-- "Run costing" on an upload creates one daily costing run per sale date,
-- matching each row's item name against hospitality_menu_items and pricing
-- it from the recipe as of the sale date. Re-costing a date snapshots the
-- previous totals into hospitality_recalc_history first (cost history).

ALTER TABLE `hospitality_costing_runs`
  ADD COLUMN `source` ENUM('invoice','upload') NOT NULL DEFAULT 'invoice' AFTER `status`,
  ADD COLUMN `upload_id` INT UNSIGNED DEFAULT NULL AFTER `source`;

-- Upload-sourced lines have no invoice behind them; sales_row_id links back
-- to the hospitality_sales_upload_lines row the costing came from.
ALTER TABLE `hospitality_costing_lines`
  MODIFY `invoice_id` INT UNSIGNED DEFAULT NULL,
  MODIFY `line_id` INT UNSIGNED DEFAULT NULL,
  ADD COLUMN `sales_row_id` INT UNSIGNED DEFAULT NULL AFTER `line_id`;

-- Deleting a run must NOT erase its preserved totals: the history keeps the
-- date, totals and reason with run_id detached instead of cascading away.
ALTER TABLE `hospitality_recalc_history`
  DROP FOREIGN KEY `fk_hosp_recalc_run`;
ALTER TABLE `hospitality_recalc_history`
  MODIFY `run_id` INT UNSIGNED DEFAULT NULL;
ALTER TABLE `hospitality_recalc_history`
  ADD CONSTRAINT `fk_hosp_recalc_run` FOREIGN KEY (`run_id`) REFERENCES `hospitality_costing_runs` (`id`) ON DELETE SET NULL;
