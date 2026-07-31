-- 102: Assigning the work is its own act, and it has its own page.
--
-- Until now the only way to put work in a kaligad's hands was the issue form,
-- which is really a METAL form: it asks what bar to hand over, and treats the
-- ornament as an afterthought. But the shop assigns work long before any metal
-- moves — "Bharat is making the bridal set, 22K, ring size 14, wanted by
-- Tihar" — and it assigns work that has no customer at all, to keep the
-- showroom stocked.
--
-- So an assignment now records the PIECE it is asking for, separately from the
-- metal that may later be handed over against it:
--
--     expected_gross_weight   the finished piece on the scale, stones and all
--     expected_stone_weight   what of that is stone or diamond
--     expected_net_weight     gross − stone: the metal it must contain
--
-- These are the ornament's specification. issued_gross_weight, which already
-- exists, stays exactly what it was — the metal actually handed over, in as
-- many instalments as the shop likes. Conflating the two would have made every
-- work-order look like a metal issue of zero grams.
--
-- assign_kind is the fork the whole page turns on:
--     customer  against an order — the customer, the size and the piece's
--               weights are all read off the order line, and the dates are
--               fenced by the order's own dates
--     self      no customer, no order — the showroom ordering its own stock,
--               so every field is typed and the piece is picked from finished
--               stock items
--
-- Existing rows are all 'customer': every assignment made so far was made
-- against an order or as a work-order for one, and none was self-ordered
-- because there was no such thing.

ALTER TABLE `jewellery_order_assignments`
  ADD COLUMN IF NOT EXISTS `assignment_no` VARCHAR(60) DEFAULT NULL AFTER `issue_no`,
  ADD COLUMN IF NOT EXISTS `assign_kind` ENUM('customer','self') NOT NULL DEFAULT 'customer' AFTER `assignment_no`,
  ADD COLUMN IF NOT EXISTS `category` ENUM('gold','diamond','other') NOT NULL DEFAULT 'gold' AFTER `assign_kind`,
  ADD COLUMN IF NOT EXISTS `size_design` VARCHAR(120) DEFAULT NULL AFTER `category`,
  ADD COLUMN IF NOT EXISTS `expected_ornament` VARCHAR(190) DEFAULT NULL AFTER `size_design`,
  ADD COLUMN IF NOT EXISTS `expected_gross_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000 AFTER `expected_ornament`,
  ADD COLUMN IF NOT EXISTS `expected_stone_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000 AFTER `expected_gross_weight`,
  ADD COLUMN IF NOT EXISTS `expected_net_weight` DECIMAL(18,4) NOT NULL DEFAULT 0.0000 AFTER `expected_stone_weight`;

-- Every assignment already made keeps a number to be known by: its issue
-- number, which is what the assignments table has always shown.
UPDATE `jewellery_order_assignments`
   SET `assignment_no` = `issue_no`
 WHERE `assignment_no` IS NULL OR `assignment_no` = '';

-- The piece an old assignment was for is the metal that went out for it — the
-- best statement available, and it keeps the grid from showing rows of zeros.
UPDATE `jewellery_order_assignments`
   SET `expected_gross_weight` = `issued_gross_weight`,
       `expected_net_weight` = `issued_gross_weight`
 WHERE `expected_gross_weight` = 0 AND `issued_gross_weight` > 0;

ALTER TABLE `jewellery_order_assignments`
  ADD UNIQUE KEY `uniq_jw_assignment_no` (`company_id`, `assignment_no`),
  ADD KEY `idx_jw_assign_kind` (`company_id`, `assign_kind`, `issue_date`);

-- Its own series, because an assignment can exist with no metal issued against
-- it and so cannot borrow the issue numbering.
ALTER TABLE `jewellery_settings`
  ADD COLUMN IF NOT EXISTS `assign_no_prefix` VARCHAR(20) NOT NULL DEFAULT 'KA' AFTER `issue_no_prefix`;
