-- 072: Jewellery Accounting — Phases 3 & 4. Purchases, sales, old-gold
-- exchange, per-item VAT, and bill-wise party accounting.
--
-- SETTLEMENT IS THE INTERESTING PART. A jewellery sale is rarely "customer
-- pays cash". It is usually some mix of:
--     cash  +  old gold handed back across the counter  +  credit
-- so every document carries three settlement figures that must add up to its
-- total: received_amount (cash/bank), exchange_amount (metal taken in) and
-- balance_amount (what the party still owes). That single rule is what makes
-- metal-to-metal and metal-to-cash fall out of one model instead of needing
-- special cases:
--     pure metal-to-metal  -> exchange_amount == total, cash and credit zero
--     metal-to-cash        -> an old-gold PURCHASE settled from the cash ledger
--
-- Bills give the party ledger a bill-by-bill life of its own: each posted
-- purchase, sale and karigar wage run opens a bill, and settlements allocate
-- against specific bills so "which invoices are still open" is answerable.

-- ---------------------------------------------------------------------------
-- 1. Purchases. `source` separates trade purchases from over-the-counter old
--    gold bought from a walk-in customer — same table, very different report.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `jewellery_purchases` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `fiscal_year_id` INT UNSIGNED DEFAULT NULL,
  `purchase_no` VARCHAR(60) NOT NULL,
  `purchase_date` DATE NOT NULL,
  `party_id` INT UNSIGNED DEFAULT NULL,
  `source` ENUM('supplier','customer_old_gold') NOT NULL DEFAULT 'supplier',
  `ref_no` VARCHAR(120) DEFAULT NULL,
  `narration` VARCHAR(255) DEFAULT NULL,
  `metal_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `making_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `stone_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `other_charges` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `discount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `taxable_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `vat_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `total_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `paid_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `balance_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `settle_mode` ENUM('credit','cash','bank') NOT NULL DEFAULT 'credit',
  `settle_ledger_id` INT UNSIGNED DEFAULT NULL,
  `status` ENUM('draft','posted','cancelled') NOT NULL DEFAULT 'draft',
  `voucher_id` INT UNSIGNED DEFAULT NULL,
  `posted_by` INT UNSIGNED DEFAULT NULL,
  `posted_at` DATETIME DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_jw_purchase_no` (`company_id`, `purchase_no`),
  KEY `idx_jw_purchases_date` (`company_id`, `purchase_date`),
  KEY `idx_jw_purchases_party` (`company_id`, `party_id`),
  KEY `idx_jw_purchases_status` (`company_id`, `status`),
  KEY `idx_jw_purchases_voucher` (`voucher_id`),
  KEY `idx_jw_purchases_fy` (`fiscal_year_id`),
  KEY `idx_jw_purchases_settle_ledger` (`settle_ledger_id`),
  CONSTRAINT `fk_jw_purchases_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jw_purchases_fy` FOREIGN KEY (`fiscal_year_id`) REFERENCES `fiscal_years` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_jw_purchases_party` FOREIGN KEY (`party_id`) REFERENCES `accounting_parties` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_jw_purchases_ledger` FOREIGN KEY (`settle_ledger_id`) REFERENCES `ledgers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_jw_purchases_voucher` FOREIGN KEY (`voucher_id`) REFERENCES `vouchers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `jewellery_purchase_lines` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `purchase_id` INT UNSIGNED NOT NULL,
  `company_id` INT UNSIGNED NOT NULL,
  `item_id` INT UNSIGNED NOT NULL,
  `purity_id` INT UNSIGNED NOT NULL,
  `unit_id` INT UNSIGNED NOT NULL,
  `qty_pieces` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
  `gross_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `fine_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `rate` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `metal_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `making_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `stone_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `vat_base` ENUM('none','full_value','making_only','stone_only') NOT NULL DEFAULT 'none',
  `vat_rate` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
  `vat_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  -- Header discount and other charges land back on the line pro rata, so the
  -- amount capitalised into stock is a true landed cost.
  `allocated_adjust` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `line_total` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `stock_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `stock_txn_id` INT UNSIGNED DEFAULT NULL,
  `notes` VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_jw_pline_purchase` (`purchase_id`),
  KEY `idx_jw_pline_item` (`company_id`, `item_id`),
  KEY `idx_jw_pline_purity` (`purity_id`),
  KEY `idx_jw_pline_unit` (`unit_id`),
  CONSTRAINT `fk_jw_pline_purchase` FOREIGN KEY (`purchase_id`) REFERENCES `jewellery_purchases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jw_pline_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jw_pline_item` FOREIGN KEY (`item_id`) REFERENCES `jewellery_items` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_jw_pline_purity` FOREIGN KEY (`purity_id`) REFERENCES `jewellery_purities` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_jw_pline_unit` FOREIGN KEY (`unit_id`) REFERENCES `jewellery_units` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 2. Sales. cogs_amount is stamped at POSTING time from the weighted-average
--    cost then in force, so a later purchase can never restate a closed sale.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `jewellery_sales` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `fiscal_year_id` INT UNSIGNED DEFAULT NULL,
  `sale_no` VARCHAR(60) NOT NULL,
  `sale_date` DATE NOT NULL,
  `party_id` INT UNSIGNED DEFAULT NULL,
  `customer_name` VARCHAR(190) DEFAULT NULL,
  `ref_no` VARCHAR(120) DEFAULT NULL,
  `narration` VARCHAR(255) DEFAULT NULL,
  `metal_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `making_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `stone_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `other_charges` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `discount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `taxable_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `vat_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `total_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  -- The three settlement legs. received + exchange + balance == total.
  `received_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `exchange_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `balance_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `cogs_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `settle_mode` ENUM('credit','cash','bank') NOT NULL DEFAULT 'credit',
  `settle_ledger_id` INT UNSIGNED DEFAULT NULL,
  `status` ENUM('draft','posted','cancelled') NOT NULL DEFAULT 'draft',
  `voucher_id` INT UNSIGNED DEFAULT NULL,
  `posted_by` INT UNSIGNED DEFAULT NULL,
  `posted_at` DATETIME DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_jw_sale_no` (`company_id`, `sale_no`),
  KEY `idx_jw_sales_date` (`company_id`, `sale_date`),
  KEY `idx_jw_sales_party` (`company_id`, `party_id`),
  KEY `idx_jw_sales_status` (`company_id`, `status`),
  KEY `idx_jw_sales_voucher` (`voucher_id`),
  KEY `idx_jw_sales_fy` (`fiscal_year_id`),
  KEY `idx_jw_sales_settle_ledger` (`settle_ledger_id`),
  CONSTRAINT `fk_jw_sales_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jw_sales_fy` FOREIGN KEY (`fiscal_year_id`) REFERENCES `fiscal_years` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_jw_sales_party` FOREIGN KEY (`party_id`) REFERENCES `accounting_parties` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_jw_sales_ledger` FOREIGN KEY (`settle_ledger_id`) REFERENCES `ledgers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_jw_sales_voucher` FOREIGN KEY (`voucher_id`) REFERENCES `vouchers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `jewellery_sale_lines` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sale_id` INT UNSIGNED NOT NULL,
  `company_id` INT UNSIGNED NOT NULL,
  `item_id` INT UNSIGNED NOT NULL,
  `purity_id` INT UNSIGNED NOT NULL,
  `unit_id` INT UNSIGNED NOT NULL,
  `qty_pieces` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
  `gross_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `fine_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `rate` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `metal_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `making_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `stone_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `vat_base` ENUM('none','full_value','making_only','stone_only') NOT NULL DEFAULT 'none',
  `vat_rate` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
  `vat_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `allocated_adjust` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `line_total` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `cogs_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `stock_txn_id` INT UNSIGNED DEFAULT NULL,
  `notes` VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_jw_sline_sale` (`sale_id`),
  KEY `idx_jw_sline_item` (`company_id`, `item_id`),
  KEY `idx_jw_sline_purity` (`purity_id`),
  KEY `idx_jw_sline_unit` (`unit_id`),
  CONSTRAINT `fk_jw_sline_sale` FOREIGN KEY (`sale_id`) REFERENCES `jewellery_sales` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jw_sline_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jw_sline_item` FOREIGN KEY (`item_id`) REFERENCES `jewellery_items` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_jw_sline_purity` FOREIGN KEY (`purity_id`) REFERENCES `jewellery_purities` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_jw_sline_unit` FOREIGN KEY (`unit_id`) REFERENCES `jewellery_units` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 3. Old gold taken in against a sale — the metal-to-metal leg. It lands in
--    stock exactly like a purchase would, but settles the sale instead of
--    creating a payable.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `jewellery_sale_exchanges` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sale_id` INT UNSIGNED NOT NULL,
  `company_id` INT UNSIGNED NOT NULL,
  `item_id` INT UNSIGNED NOT NULL,
  `purity_id` INT UNSIGNED NOT NULL,
  `unit_id` INT UNSIGNED NOT NULL,
  `qty_pieces` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
  `gross_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `fine_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `rate` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `stock_txn_id` INT UNSIGNED DEFAULT NULL,
  `notes` VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_jw_exch_sale` (`sale_id`),
  KEY `idx_jw_exch_item` (`company_id`, `item_id`),
  KEY `idx_jw_exch_purity` (`purity_id`),
  KEY `idx_jw_exch_unit` (`unit_id`),
  CONSTRAINT `fk_jw_exch_sale` FOREIGN KEY (`sale_id`) REFERENCES `jewellery_sales` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jw_exch_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jw_exch_item` FOREIGN KEY (`item_id`) REFERENCES `jewellery_items` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_jw_exch_purity` FOREIGN KEY (`purity_id`) REFERENCES `jewellery_purities` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_jw_exch_unit` FOREIGN KEY (`unit_id`) REFERENCES `jewellery_units` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 4. Bills — bill-wise party accounting. One row per posted purchase, sale or
--    karigar wage run; settlements allocate against these, so a party ledger
--    can always be broken down into which documents are still open.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `jewellery_bills` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `fiscal_year_id` INT UNSIGNED DEFAULT NULL,
  `party_id` INT UNSIGNED NOT NULL,
  `bill_type` ENUM('purchase','sale','karigar') NOT NULL,
  `source_type` VARCHAR(40) NOT NULL,
  `source_id` INT UNSIGNED NOT NULL,
  `bill_no` VARCHAR(60) NOT NULL,
  `bill_date` DATE NOT NULL,
  `due_date` DATE DEFAULT NULL,
  `bill_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `settled_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('open','part_settled','settled','cancelled') NOT NULL DEFAULT 'open',
  `voucher_id` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_jw_bill_source` (`company_id`, `source_type`, `source_id`),
  KEY `idx_jw_bills_party` (`company_id`, `party_id`, `status`),
  KEY `idx_jw_bills_date` (`company_id`, `bill_date`),
  KEY `idx_jw_bills_type` (`company_id`, `bill_type`, `status`),
  KEY `idx_jw_bills_fy` (`fiscal_year_id`),
  KEY `idx_jw_bills_voucher` (`voucher_id`),
  CONSTRAINT `fk_jw_bills_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jw_bills_fy` FOREIGN KEY (`fiscal_year_id`) REFERENCES `fiscal_years` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_jw_bills_party` FOREIGN KEY (`party_id`) REFERENCES `accounting_parties` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jw_bills_voucher` FOREIGN KEY (`voucher_id`) REFERENCES `vouchers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 5. Settlements — money (or metal) paid to / received from a party, and the
--    allocation of that money across specific bills.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `jewellery_settlements` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `fiscal_year_id` INT UNSIGNED DEFAULT NULL,
  `settlement_no` VARCHAR(60) NOT NULL,
  `settlement_date` DATE NOT NULL,
  `party_id` INT UNSIGNED NOT NULL,
  `direction` ENUM('paid','received') NOT NULL,
  `mode` ENUM('cash','bank','metal','adjustment') NOT NULL DEFAULT 'cash',
  `amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `ledger_id` INT UNSIGNED DEFAULT NULL,
  -- Populated only when mode = metal (a supplier settled in gold, not cash).
  `item_id` INT UNSIGNED DEFAULT NULL,
  `purity_id` INT UNSIGNED DEFAULT NULL,
  `unit_id` INT UNSIGNED DEFAULT NULL,
  `gross_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `fine_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `stock_txn_id` INT UNSIGNED DEFAULT NULL,
  `status` ENUM('draft','posted','cancelled') NOT NULL DEFAULT 'draft',
  `voucher_id` INT UNSIGNED DEFAULT NULL,
  `notes` VARCHAR(255) DEFAULT NULL,
  `posted_by` INT UNSIGNED DEFAULT NULL,
  `posted_at` DATETIME DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_jw_settlement_no` (`company_id`, `settlement_no`),
  KEY `idx_jw_settle_party` (`company_id`, `party_id`, `settlement_date`),
  KEY `idx_jw_settle_status` (`company_id`, `status`),
  KEY `idx_jw_settle_fy` (`fiscal_year_id`),
  KEY `idx_jw_settle_ledger` (`ledger_id`),
  KEY `idx_jw_settle_item` (`item_id`),
  KEY `idx_jw_settle_purity` (`purity_id`),
  KEY `idx_jw_settle_unit` (`unit_id`),
  KEY `idx_jw_settle_voucher` (`voucher_id`),
  CONSTRAINT `fk_jw_settle_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jw_settle_fy` FOREIGN KEY (`fiscal_year_id`) REFERENCES `fiscal_years` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_jw_settle_party` FOREIGN KEY (`party_id`) REFERENCES `accounting_parties` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jw_settle_ledger` FOREIGN KEY (`ledger_id`) REFERENCES `ledgers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_jw_settle_item` FOREIGN KEY (`item_id`) REFERENCES `jewellery_items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_jw_settle_purity` FOREIGN KEY (`purity_id`) REFERENCES `jewellery_purities` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_jw_settle_unit` FOREIGN KEY (`unit_id`) REFERENCES `jewellery_units` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_jw_settle_voucher` FOREIGN KEY (`voucher_id`) REFERENCES `vouchers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `jewellery_settlement_allocations` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `settlement_id` INT UNSIGNED NOT NULL,
  `bill_id` INT UNSIGNED NOT NULL,
  `amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_jw_alloc` (`settlement_id`, `bill_id`),
  KEY `idx_jw_alloc_bill` (`company_id`, `bill_id`),
  CONSTRAINT `fk_jw_alloc_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jw_alloc_settlement` FOREIGN KEY (`settlement_id`) REFERENCES `jewellery_settlements` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jw_alloc_bill` FOREIGN KEY (`bill_id`) REFERENCES `jewellery_bills` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
