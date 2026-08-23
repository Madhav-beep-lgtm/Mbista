-- A sale line records the precise customer-order line it delivers. Item codes
-- can repeat on one order, while an order line is one physical promise.
ALTER TABLE `jewellery_sale_lines`
  ADD COLUMN IF NOT EXISTS `order_line_id` INT UNSIGNED DEFAULT NULL AFTER `company_id`,
  ADD KEY IF NOT EXISTS `idx_jw_sline_order_line` (`company_id`, `order_line_id`);
