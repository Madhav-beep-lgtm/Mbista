-- 052: Ledger deletion must never destroy posted accounting entries.
--
-- voucher_entries.ledger_id was ON DELETE CASCADE, so deleting a ledger
-- silently removed its legs from posted vouchers and unbalanced the books
-- (three one-legged vouchers were found and repaired on the sample data).
-- chart-ledgers.php already assumed the database would refuse ("cannot be
-- deleted while transactions still reference it") — RESTRICT makes that true.

ALTER TABLE voucher_entries
    DROP FOREIGN KEY fk_voucher_entries_ledger;

ALTER TABLE voucher_entries
    ADD CONSTRAINT fk_voucher_entries_ledger
        FOREIGN KEY (ledger_id) REFERENCES ledgers (id) ON DELETE RESTRICT;
