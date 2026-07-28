-- 094: WHICH advances paid this bill — entry by entry, chosen by a person.
--
-- A sale has carried advance_amount since migration 080: one number, applied
-- against the order being delivered. Which advance ENTRIES it consumed was
-- never recorded, and that one number is why three things could not be done:
--
--   shown      the bill said "advance 280,000" and could not say "the 100,000
--              cash of 1 Shrawan and the 180,000 of old gold of 2 Shrawan";
--   audited    an advance entry could not answer "where did I go?", so the
--              Advance Adjustment Register the shop wants cannot be written;
--   crossed    a customer's advance on a PREVIOUS order, their opening
--              advance, the excess left over from an earlier bill — all owed
--              to the same person, all invisible to a bill that could only
--              net against the one order it delivers.
--
-- The rows below are the record. Each says: this sale took this much from
-- that settlement entry. The sale's advance_amount becomes the SUM of its
-- rows — a total, not a second source of truth — so every bill already
-- stored keeps its number and nothing changes meaning.
--
-- CHOSEN BY A PERSON. The billing screen lists every open advance the
-- customer holds and the user ticks the entries and types the amounts.
-- Nothing is applied silently. (Callers that still send only the old single
-- number get it spread oldest-first across the delivering order's own
-- entries — the exact pool the old cap drew on, made explicit — so the
-- invariant holds everywhere; the SCREEN never takes that path.)
--
-- What an entry still holds is derived, never stored:
--
--     remaining = settlement.amount − SUM(allocations on sales not cancelled)
--
-- A draft sale's rows count — a draft is a bill being written, and the same
-- advance must not be promised to two bills at once. Deleting a draft frees
-- its rows (ON DELETE CASCADE); cancelling a sale releases them by status.
--
-- The backfill for sales already stored runs in the repair step
-- (accounting_module_repair_database), not here: spreading each old sale's
-- one number oldest-first across its order's entries needs a loop.

CREATE TABLE IF NOT EXISTS `jewellery_advance_allocations` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `sale_id` INT UNSIGNED NOT NULL,
  `settlement_id` INT UNSIGNED NOT NULL,
  `amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_jw_advalloc_sale` (`sale_id`),
  KEY `idx_jw_advalloc_settlement` (`settlement_id`),
  KEY `idx_jw_advalloc_company` (`company_id`),
  CONSTRAINT `fk_jw_advalloc_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jw_advalloc_sale` FOREIGN KEY (`sale_id`) REFERENCES `jewellery_sales` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jw_advalloc_settlement` FOREIGN KEY (`settlement_id`) REFERENCES `jewellery_settlements` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
