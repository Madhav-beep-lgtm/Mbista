-- 088: Each item on an order goes to its own kaligad, on its own date.
--
-- Kaligads specialise. The one who makes chains does not set stones, and the
-- one who sets stones does not draw wire. So an order for a chain and a
-- diamond ring is two craftsmen, two pieces of metal issued, two dates back —
-- and the module could express none of that: an assignment pointed at the
-- ORDER, and the order carried a single promised delivery date for everything
-- on it.
--
-- The result was that a shop taking a mixed order had to raise one order per
-- item to keep the workshop straight, which is exactly the thing order lines
-- were added to stop.
--
-- Three columns and one link:
--     order_lines.karigar_id     who is to make THIS item
--     order_lines.delivery_date  when THIS item is promised
--     order_lines.assignment_id  which issue actually covers it, once issued
--     assignments.order_line_id  the other side of that link
--
-- The order header keeps its own delivery_date as the whole order's promise —
-- the date the customer is told to come in — which jewellery_save_order() sets
-- to the LAST of the line dates. A customer collecting one order makes one
-- journey.

ALTER TABLE `jewellery_order_lines`
  ADD COLUMN IF NOT EXISTS `karigar_id` INT UNSIGNED DEFAULT NULL AFTER `item_id`,
  ADD COLUMN IF NOT EXISTS `delivery_date` DATE DEFAULT NULL AFTER `karigar_id`,
  ADD COLUMN IF NOT EXISTS `assignment_id` INT UNSIGNED DEFAULT NULL AFTER `delivery_date`;

ALTER TABLE `jewellery_order_assignments`
  ADD COLUMN IF NOT EXISTS `order_line_id` INT UNSIGNED DEFAULT NULL AFTER `order_id`;

-- Existing lines inherit the order's single promise, so nothing already taken
-- loses its date the moment the column appears.
UPDATE `jewellery_order_lines` l
  INNER JOIN `jewellery_orders` o ON o.`id` = l.`order_id`
    SET l.`delivery_date` = o.`delivery_date`
  WHERE l.`delivery_date` IS NULL AND o.`delivery_date` IS NOT NULL;
