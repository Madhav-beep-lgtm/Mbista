-- 092: One payment, several ways of paying it.
--
-- A customer paying 50,000 hands over 20,000 in cash, taps 15,000 on Fonepay
-- and puts down an old chain for the rest. That is ONE payment. It happens at
-- one moment, across one counter, and the customer thinks of it as one thing.
--
-- A settlement could not say so. It carried a single `mode` and a single
-- `amount`, so that payment had to be keyed as three settlements with three
-- numbers and three receipts — and then nothing in the books tied them back
-- together. The bill already knew better: jewellery_sales has carried
-- paid_cash / paid_card / paid_cheque / paid_qr since migration 084, so a
-- counter sale can be settled six ways at once. It was only money taken
-- OUTSIDE a bill — the advance on an order, the part payment after it, the
-- refund — that still had to pretend a customer pays one way at a time.
--
-- The sale's four columns cannot simply be copied here. A settlement can be
-- taken in METAL, and metal needs an item, a purity, a unit and a weight; that
-- will not fit in a `paid_metal DECIMAL` beside the others. So the split
-- becomes child rows, one per way the customer paid.
--
-- A BREAKDOWN, NOT A SECOND SOURCE OF TRUTH. Exactly the rule migration 084
-- set for the sale: whatever the rows carry must add up to the settlement's
-- own `amount`, and the save refuses them if it does not. No rows at all means
-- the settlement is paid one way, which is every settlement recorded until
-- now — so nothing already stored changes, and a shop that never splits a
-- payment sees no difference.

CREATE TABLE IF NOT EXISTS `jewellery_settlement_tenders` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `settlement_id` INT UNSIGNED NOT NULL,
  `line_no` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  -- `other` is the shop's own way of being paid — a house credit note, a
  -- staff account, whatever it calls the thing. It carries its own name in
  -- `mode_label` and posts to the ledger the user picks, so a mode nobody
  -- anticipated needs no code change to record.
  `mode` ENUM('cash','bank','card','cheque','qr','wallet','metal','adjustment','other') NOT NULL DEFAULT 'cash',
  `mode_label` VARCHAR(60) DEFAULT NULL,
  -- The cheque number, the Fonepay transaction id, the card's last four. What
  -- the customer will quote when they ring up about this payment.
  `reference` VARCHAR(60) DEFAULT NULL,
  `amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `ledger_id` INT UNSIGNED DEFAULT NULL,
  -- Populated only when mode = metal, mirroring the parent's own metal columns.
  `item_id` INT UNSIGNED DEFAULT NULL,
  `purity_id` INT UNSIGNED DEFAULT NULL,
  `unit_id` INT UNSIGNED DEFAULT NULL,
  `gross_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `fine_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `stock_txn_id` INT UNSIGNED DEFAULT NULL,
  `notes` VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_jw_tender_settlement` (`settlement_id`),
  KEY `idx_jw_tender_company` (`company_id`, `mode`),
  KEY `idx_jw_tender_ledger` (`ledger_id`),
  KEY `idx_jw_tender_item` (`item_id`),
  CONSTRAINT `fk_jw_tender_settlement` FOREIGN KEY (`settlement_id`) REFERENCES `jewellery_settlements` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jw_tender_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jw_tender_ledger` FOREIGN KEY (`ledger_id`) REFERENCES `ledgers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_jw_tender_item` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_jw_tender_purity` FOREIGN KEY (`purity_id`) REFERENCES `jewellery_purities` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_jw_tender_unit` FOREIGN KEY (`unit_id`) REFERENCES `jewellery_units` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The header keeps a mode so every screen and report that reads one still
-- works. `mixed` is what it says when the rows disagree — one word that tells
-- a list view to go and look at the breakdown. The rest are modes a shop could
-- always name but the column could not hold, so a single-tender payment by
-- cheque no longer has to be filed as 'cash' and explained in the notes.
ALTER TABLE `jewellery_settlements`
  MODIFY COLUMN `mode` ENUM('cash','bank','card','cheque','qr','wallet','metal','adjustment','other','mixed')
    NOT NULL DEFAULT 'cash';

-- Where each way of being paid lands in the books. `tender_cash`, `tender_card`,
-- `tender_cheque` and `tender_qr` already exist for the sale side (migration
-- 084) and are reused as they are — the same rupee taken the same way belongs
-- in the same ledger whether a bill was raised for it or not.
