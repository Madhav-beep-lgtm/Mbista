-- 078: Remember WHICH holder ledger an issue debited.
--
-- Both the kaligad and the refinery receipt used to re-derive the holder's
-- metal ledger at receive time, from the holder's CURRENT name and the CURRENT
-- mapping. That is not the same thing as the ledger the issue actually debited,
-- and three ordinary events pulled them apart:
--
--   * the issue posted no money leg at all (the "Metal with karigar" purpose
--     was still unmapped), so the value never left the item's stock ledger —
--     yet the receipt credited a freshly created holder ledger for the full
--     issued amount, leaving an asset ledger with a permanent CREDIT balance
--     and the stock ledger overstated by the whole issue;
--   * somebody renamed the party between issue and receive, so the name lookup
--     missed and a SECOND ledger was created — one left holding a stranded
--     debit, the other an equal credit, for a job that completed cleanly;
--   * the refiner's party row was deleted (the FK is ON DELETE SET NULL), so
--     the receipt resolved the generic shared ledger instead of the named one.
--
-- Each of those keeps every individual voucher internally balanced, which is
-- exactly why a trial balance never flags it. The fix is to stop deriving and
-- start remembering: the issue records the ledger it debited, and the receipt
-- credits that one. NULL means the issue genuinely posted no money leg, and the
-- receipt then relieves the item's own stock ledger, which is where the value
-- really still is.

ALTER TABLE `jewellery_order_assignments`
  ADD COLUMN IF NOT EXISTS `metal_ledger_id` INT UNSIGNED DEFAULT NULL AFTER `issue_voucher_id`,
  ADD KEY IF NOT EXISTS `idx_jw_assign_metal_ledger` (`metal_ledger_id`),
  ADD CONSTRAINT `fk_jw_assign_metal_ledger` FOREIGN KEY (`metal_ledger_id`) REFERENCES `ledgers` (`id`) ON DELETE SET NULL;

ALTER TABLE `jewellery_refinery_jobs`
  ADD COLUMN IF NOT EXISTS `metal_ledger_id` INT UNSIGNED DEFAULT NULL AFTER `issue_voucher_id`,
  ADD KEY IF NOT EXISTS `idx_jw_refjobs_metal_ledger` (`metal_ledger_id`),
  ADD CONSTRAINT `fk_jw_refjobs_metal_ledger` FOREIGN KEY (`metal_ledger_id`) REFERENCES `ledgers` (`id`) ON DELETE SET NULL;

-- Backfill from the issue voucher itself: its DEBIT leg on an asset ledger IS
-- the holder ledger that was used. Assignments and jobs whose issue posted no
-- voucher correctly stay NULL.
UPDATE `jewellery_order_assignments` a
  JOIN `voucher_entries` e ON e.voucher_id = a.issue_voucher_id AND e.entry_type = 'debit'
  JOIN `ledgers` l ON l.id = e.ledger_id AND l.type = 'asset'
  SET a.metal_ledger_id = e.ledger_id
  WHERE a.metal_ledger_id IS NULL AND a.issue_voucher_id IS NOT NULL;

UPDATE `jewellery_refinery_jobs` j
  JOIN `voucher_entries` e ON e.voucher_id = j.issue_voucher_id AND e.entry_type = 'debit'
  JOIN `ledgers` l ON l.id = e.ledger_id AND l.type = 'asset'
  SET j.metal_ledger_id = e.ledger_id
  WHERE j.metal_ledger_id IS NULL AND j.issue_voucher_id IS NOT NULL;
