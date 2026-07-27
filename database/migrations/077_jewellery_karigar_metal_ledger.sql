-- 077: Every kaligad gets its OWN metal ledger.
--
-- Metal issued for making is still the shop's asset — it has simply moved into
-- someone else's hands. Posting every kaligad's metal to one shared "Metal with
-- Karigar" account answers "how much metal is out?" but not "how much is with
-- RAM?", which is the question that actually settles an argument at the counter.
-- So each kaligad now carries a dedicated asset ledger and the trial balance
-- shows the holding per kaligad, exactly like a party ledger does for money.
--
-- It also removes a crash. The issue voucher was
--     Dr <shared metal-with-karigar>   Cr <item stock>
-- and when a company mapped both purposes to the SAME ledger the two legs
-- netted to zero, every entry was dropped, and create_voucher_with_entries()
-- refused the empty voucher. A per-kaligad ledger can never collide with the
-- item's stock ledger, and the posting code now skips a genuinely null transfer
-- instead of failing.

ALTER TABLE `jewellery_karigars`
  ADD COLUMN IF NOT EXISTS `metal_ledger_id` INT UNSIGNED DEFAULT NULL AFTER `party_id`,
  ADD KEY IF NOT EXISTS `idx_jw_karigars_metal_ledger` (`metal_ledger_id`),
  ADD CONSTRAINT `fk_jw_karigars_metal_ledger` FOREIGN KEY (`metal_ledger_id`) REFERENCES `ledgers` (`id`) ON DELETE SET NULL;
