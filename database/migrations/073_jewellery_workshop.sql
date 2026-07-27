-- 073: Jewellery Accounting — Phases 5 & 6. Kaligad (karigar) masters, daily
-- order management, order assignment and receipt with wage and wastage
-- settlement, and refinery jobs.
--
-- WHY WASTAGE IS THE WHOLE GAME. Issue a karigar 10 tola of 22K and you will
-- not get 10 tola back — some metal is genuinely consumed in the making. The
-- shop agrees an ALLOWED wastage percentage up front; anything beyond it is
-- the karigar's loss and comes out of their wages. So an order receipt has to
-- settle three things at once:
--     the metal   (received back, plus the wastage written off the holding)
--     the wages   (making charge earned)
--     the excess  (wastage over the agreed allowance, recovered from wages)
--
-- KARIGARS ARE EITHER CONTRACTORS OR EMPLOYEES, per the engagement_type
-- column. A contractor is an accounting_parties row and their wages become a
-- bill-wise trade payable; an employee is a payroll_employees row and their
-- wages flow through payroll instead. Both are first-class here.

-- ---------------------------------------------------------------------------
-- 1. Karigar master.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `jewellery_karigars` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `code` VARCHAR(40) NOT NULL,
  `name` VARCHAR(190) NOT NULL,
  `phone` VARCHAR(60) DEFAULT NULL,
  `address` VARCHAR(255) DEFAULT NULL,
  `engagement_type` ENUM('contractor','employee') NOT NULL DEFAULT 'contractor',
  `party_id` INT UNSIGNED DEFAULT NULL,
  `payroll_employee_id` INT UNSIGNED DEFAULT NULL,
  `default_making_basis` ENUM('per_unit_weight','percent_of_metal','flat') NOT NULL DEFAULT 'per_unit_weight',
  `default_making_rate` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `wastage_allowed_pct` DECIMAL(6,3) NOT NULL DEFAULT 0.000,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `notes` VARCHAR(255) DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_jw_karigar_code` (`company_id`, `code`),
  KEY `idx_jw_karigars_status` (`company_id`, `status`),
  KEY `idx_jw_karigars_party` (`party_id`),
  CONSTRAINT `fk_jw_karigars_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jw_karigars_party` FOREIGN KEY (`party_id`) REFERENCES `accounting_parties` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 2. Customer orders. The status ladder is what drives the daily boards:
--    confirmed -> assigned -> received -> delivered. "Received but not
--    delivered" is simply status = received, which is its own report.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `jewellery_orders` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `fiscal_year_id` INT UNSIGNED DEFAULT NULL,
  `order_no` VARCHAR(60) NOT NULL,
  `order_date` DATE NOT NULL,
  `delivery_date` DATE DEFAULT NULL,
  `party_id` INT UNSIGNED DEFAULT NULL,
  `customer_name` VARCHAR(190) DEFAULT NULL,
  `customer_phone` VARCHAR(60) DEFAULT NULL,
  `item_id` INT UNSIGNED DEFAULT NULL,
  `metal_id` INT UNSIGNED NOT NULL,
  `purity_id` INT UNSIGNED NOT NULL,
  `unit_id` INT UNSIGNED NOT NULL,
  `expected_gross_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `expected_fine_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `design_no` VARCHAR(60) DEFAULT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `making_basis` ENUM('per_unit_weight','percent_of_metal','flat') NOT NULL DEFAULT 'per_unit_weight',
  `making_rate` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `advance_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('draft','confirmed','assigned','received','delivered','cancelled') NOT NULL DEFAULT 'draft',
  `delivered_sale_id` INT UNSIGNED DEFAULT NULL,
  `delivered_at` DATETIME DEFAULT NULL,
  `notes` VARCHAR(255) DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_jw_order_no` (`company_id`, `order_no`),
  KEY `idx_jw_orders_status` (`company_id`, `status`, `order_date`),
  KEY `idx_jw_orders_date` (`company_id`, `order_date`),
  KEY `idx_jw_orders_delivery` (`company_id`, `delivery_date`),
  KEY `idx_jw_orders_party` (`party_id`),
  KEY `idx_jw_orders_item` (`item_id`),
  KEY `idx_jw_orders_metal` (`metal_id`),
  KEY `idx_jw_orders_purity` (`purity_id`),
  KEY `idx_jw_orders_unit` (`unit_id`),
  KEY `idx_jw_orders_fy` (`fiscal_year_id`),
  KEY `idx_jw_orders_sale` (`delivered_sale_id`),
  CONSTRAINT `fk_jw_orders_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jw_orders_fy` FOREIGN KEY (`fiscal_year_id`) REFERENCES `fiscal_years` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_jw_orders_party` FOREIGN KEY (`party_id`) REFERENCES `accounting_parties` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_jw_orders_item` FOREIGN KEY (`item_id`) REFERENCES `jewellery_items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_jw_orders_metal` FOREIGN KEY (`metal_id`) REFERENCES `jewellery_metals` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_jw_orders_purity` FOREIGN KEY (`purity_id`) REFERENCES `jewellery_purities` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_jw_orders_unit` FOREIGN KEY (`unit_id`) REFERENCES `jewellery_units` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_jw_orders_sale` FOREIGN KEY (`delivered_sale_id`) REFERENCES `jewellery_sales` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 3. Assignment — metal issued to a karigar against an order.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `jewellery_order_assignments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `fiscal_year_id` INT UNSIGNED DEFAULT NULL,
  `order_id` INT UNSIGNED DEFAULT NULL,
  `karigar_id` INT UNSIGNED NOT NULL,
  `issue_no` VARCHAR(60) NOT NULL,
  `issue_date` DATE NOT NULL,
  `expected_return_date` DATE DEFAULT NULL,
  `item_id` INT UNSIGNED NOT NULL,
  `purity_id` INT UNSIGNED NOT NULL,
  `unit_id` INT UNSIGNED NOT NULL,
  `issued_gross_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `issued_fine_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `issued_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `wastage_allowed_pct` DECIMAL(6,3) NOT NULL DEFAULT 0.000,
  `making_basis` ENUM('per_unit_weight','percent_of_metal','flat') NOT NULL DEFAULT 'per_unit_weight',
  `making_rate` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `status` ENUM('issued','received','cancelled') NOT NULL DEFAULT 'issued',
  `issue_stock_txn_out` INT UNSIGNED DEFAULT NULL,
  `issue_stock_txn_in` INT UNSIGNED DEFAULT NULL,
  `issue_voucher_id` INT UNSIGNED DEFAULT NULL,
  `notes` VARCHAR(255) DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_jw_issue_no` (`company_id`, `issue_no`),
  KEY `idx_jw_assign_karigar` (`company_id`, `karigar_id`, `status`),
  KEY `idx_jw_assign_order` (`order_id`),
  KEY `idx_jw_assign_date` (`company_id`, `issue_date`),
  KEY `idx_jw_assign_item` (`item_id`),
  KEY `idx_jw_assign_purity` (`purity_id`),
  KEY `idx_jw_assign_unit` (`unit_id`),
  KEY `idx_jw_assign_fy` (`fiscal_year_id`),
  KEY `idx_jw_assign_voucher` (`issue_voucher_id`),
  CONSTRAINT `fk_jw_assign_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jw_assign_fy` FOREIGN KEY (`fiscal_year_id`) REFERENCES `fiscal_years` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_jw_assign_order` FOREIGN KEY (`order_id`) REFERENCES `jewellery_orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_jw_assign_karigar` FOREIGN KEY (`karigar_id`) REFERENCES `jewellery_karigars` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_jw_assign_item` FOREIGN KEY (`item_id`) REFERENCES `jewellery_items` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_jw_assign_purity` FOREIGN KEY (`purity_id`) REFERENCES `jewellery_purities` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_jw_assign_unit` FOREIGN KEY (`unit_id`) REFERENCES `jewellery_units` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_jw_assign_voucher` FOREIGN KEY (`issue_voucher_id`) REFERENCES `vouchers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 4. Receipt — the finished ornament back from the karigar, with the wage and
--    wastage settlement described at the top of this file.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `jewellery_order_receipts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `fiscal_year_id` INT UNSIGNED DEFAULT NULL,
  `assignment_id` INT UNSIGNED NOT NULL,
  `receipt_no` VARCHAR(60) NOT NULL,
  `receive_date` DATE NOT NULL,
  `received_item_id` INT UNSIGNED NOT NULL,
  `received_purity_id` INT UNSIGNED NOT NULL,
  `unit_id` INT UNSIGNED NOT NULL,
  `qty_pieces` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
  `received_gross_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `received_fine_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `wastage_fine_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `wastage_allowed_fine` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `excess_wastage_fine` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `avg_fine_rate` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `wastage_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `recovery_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `making_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `net_payable` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('draft','posted','cancelled') NOT NULL DEFAULT 'draft',
  `voucher_id` INT UNSIGNED DEFAULT NULL,
  `notes` VARCHAR(255) DEFAULT NULL,
  `posted_by` INT UNSIGNED DEFAULT NULL,
  `posted_at` DATETIME DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_jw_receipt_no` (`company_id`, `receipt_no`),
  UNIQUE KEY `uniq_jw_receipt_assignment` (`assignment_id`),
  KEY `idx_jw_receipts_date` (`company_id`, `receive_date`),
  KEY `idx_jw_receipts_status` (`company_id`, `status`),
  KEY `idx_jw_receipts_item` (`received_item_id`),
  KEY `idx_jw_receipts_purity` (`received_purity_id`),
  KEY `idx_jw_receipts_unit` (`unit_id`),
  KEY `idx_jw_receipts_fy` (`fiscal_year_id`),
  KEY `idx_jw_receipts_voucher` (`voucher_id`),
  CONSTRAINT `fk_jw_receipts_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jw_receipts_fy` FOREIGN KEY (`fiscal_year_id`) REFERENCES `fiscal_years` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_jw_receipts_assignment` FOREIGN KEY (`assignment_id`) REFERENCES `jewellery_order_assignments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jw_receipts_item` FOREIGN KEY (`received_item_id`) REFERENCES `jewellery_items` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_jw_receipts_purity` FOREIGN KEY (`received_purity_id`) REFERENCES `jewellery_purities` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_jw_receipts_unit` FOREIGN KEY (`unit_id`) REFERENCES `jewellery_units` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_jw_receipts_voucher` FOREIGN KEY (`voucher_id`) REFERENCES `vouchers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 5. Refinery jobs. Same shape as a karigar round trip, but the loss is a
--    refining loss rather than a making wastage, and the refiner charges a fee.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `jewellery_refinery_jobs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `fiscal_year_id` INT UNSIGNED DEFAULT NULL,
  `job_no` VARCHAR(60) NOT NULL,
  `party_id` INT UNSIGNED DEFAULT NULL,
  `issue_date` DATE NOT NULL,
  `item_id` INT UNSIGNED NOT NULL,
  `purity_id` INT UNSIGNED NOT NULL,
  `unit_id` INT UNSIGNED NOT NULL,
  `issued_gross_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `issued_fine_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `issued_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `receive_date` DATE DEFAULT NULL,
  `received_item_id` INT UNSIGNED DEFAULT NULL,
  `received_purity_id` INT UNSIGNED DEFAULT NULL,
  `received_gross_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `received_fine_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `loss_fine_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `loss_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `charges_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `charges_settle_mode` ENUM('credit','cash','bank') NOT NULL DEFAULT 'credit',
  `charges_ledger_id` INT UNSIGNED DEFAULT NULL,
  `status` ENUM('issued','received','cancelled') NOT NULL DEFAULT 'issued',
  `issue_stock_txn_out` INT UNSIGNED DEFAULT NULL,
  `issue_stock_txn_in` INT UNSIGNED DEFAULT NULL,
  `issue_voucher_id` INT UNSIGNED DEFAULT NULL,
  `receive_voucher_id` INT UNSIGNED DEFAULT NULL,
  `notes` VARCHAR(255) DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_jw_refjob_no` (`company_id`, `job_no`),
  KEY `idx_jw_refjobs_status` (`company_id`, `status`),
  KEY `idx_jw_refjobs_date` (`company_id`, `issue_date`),
  KEY `idx_jw_refjobs_party` (`party_id`),
  KEY `idx_jw_refjobs_item` (`item_id`),
  KEY `idx_jw_refjobs_purity` (`purity_id`),
  KEY `idx_jw_refjobs_unit` (`unit_id`),
  KEY `idx_jw_refjobs_ritem` (`received_item_id`),
  KEY `idx_jw_refjobs_rpurity` (`received_purity_id`),
  KEY `idx_jw_refjobs_fy` (`fiscal_year_id`),
  KEY `idx_jw_refjobs_cledger` (`charges_ledger_id`),
  CONSTRAINT `fk_jw_refjobs_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jw_refjobs_fy` FOREIGN KEY (`fiscal_year_id`) REFERENCES `fiscal_years` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_jw_refjobs_party` FOREIGN KEY (`party_id`) REFERENCES `accounting_parties` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_jw_refjobs_item` FOREIGN KEY (`item_id`) REFERENCES `jewellery_items` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_jw_refjobs_purity` FOREIGN KEY (`purity_id`) REFERENCES `jewellery_purities` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_jw_refjobs_unit` FOREIGN KEY (`unit_id`) REFERENCES `jewellery_units` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_jw_refjobs_ritem` FOREIGN KEY (`received_item_id`) REFERENCES `jewellery_items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_jw_refjobs_rpurity` FOREIGN KEY (`received_purity_id`) REFERENCES `jewellery_purities` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_jw_refjobs_cledger` FOREIGN KEY (`charges_ledger_id`) REFERENCES `ledgers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
