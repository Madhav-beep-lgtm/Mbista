-- 069: Each sales category carries its COMPLETE ledger set.
--
-- The category mapping row now holds sales + receivable + discount ledgers,
-- so each category's totals post to its own ledgers:
--     Dr  <category receivable ledger>  (category gross - discount)
--     Dr  <category discount ledger>    (category discount)
--     Cr  <category sales ledger>       (category taxable)
--     Cr  VAT payable                   (common)
-- The global ledgers in hospitality_settings remain only as FALLBACK for
-- categories without their own mapping.
ALTER TABLE `hospitality_sales_ledger_maps`
  ADD COLUMN `receivable_ledger_id` INT UNSIGNED DEFAULT NULL AFTER `sales_ledger_id`,
  ADD COLUMN `discount_ledger_id` INT UNSIGNED DEFAULT NULL AFTER `receivable_ledger_id`,
  ADD CONSTRAINT `fk_hosp_ledger_map_recv` FOREIGN KEY (`receivable_ledger_id`) REFERENCES `ledgers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_hosp_ledger_map_disc` FOREIGN KEY (`discount_ledger_id`) REFERENCES `ledgers` (`id`) ON DELETE SET NULL;
