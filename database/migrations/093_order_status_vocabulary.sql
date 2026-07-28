-- 093: An order's status can now say the four things it could not.
--
-- The lifecycle ran draft → confirmed → assigned → received → delivered, and
-- between those words four real states of affairs had no name:
--
--   partially_received  two of five pieces are back from the kaligads. The
--                       status said 'assigned', which is also what it said
--                       when nothing was back, so the counter could not tell
--                       a customer "three still to come" without opening the
--                       order.
--   invoiced            the bill is posted but the goods have not been handed
--                       over — a piece billed by phone and collected Tuesday.
--   closed              delivered AND paid in full. Finished with. Until now
--                       'delivered' covered both the order that is done and
--                       the order still owed 200,000, and every list that
--                       wanted "actually finished" had to join the bill.
--   (and the sale)      the sale knew which order it delivered only through
--                       jewellery_orders.delivered_sale_id, written at
--                       delivery. Before delivery the link lived in a POST
--                       field and died with the request — which is why a sale
--                       posted after being saved as a draft never delivered
--                       its order at all: the page tried at save time, the
--                       engine rightly refused a draft, and nothing ever
--                       tried again.
--
-- jewellery_sales.order_id is that link made durable: written when the sale
-- is SAVED against an order, so posting can finish the job the save started.
--
-- Status stays DERIVED (jewellery_sync_order_status) for the workshop states,
-- and the boundary is unchanged: invoiced, delivered, closed and cancelled
-- are decisions of the billing machinery or a person, and the workshop's
-- arithmetic may never overwrite them.

ALTER TABLE `jewellery_orders`
  MODIFY COLUMN `status` ENUM('draft','confirmed','assigned','partially_received','received',
      'invoiced','delivered','closed','cancelled') NOT NULL DEFAULT 'draft';

ALTER TABLE `jewellery_sales`
  ADD COLUMN IF NOT EXISTS `order_id` INT UNSIGNED DEFAULT NULL AFTER `party_id`,
  ADD KEY IF NOT EXISTS `idx_jw_sales_order` (`company_id`, `order_id`),
  ADD CONSTRAINT `fk_jw_sales_order` FOREIGN KEY (`order_id`) REFERENCES `jewellery_orders` (`id`) ON DELETE SET NULL;

-- Every sale that has already delivered an order gets the link back-filled
-- from the delivery record, so history answers the same question new rows do.
UPDATE `jewellery_sales` s
  INNER JOIN `jewellery_orders` o ON o.`delivered_sale_id` = s.`id` AND o.`company_id` = s.`company_id`
    SET s.`order_id` = o.`id`
  WHERE s.`order_id` IS NULL;

-- Orders sitting in 'assigned' with some pieces back are the ones the old
-- vocabulary could not describe. Same shape as migration 090, same safety:
-- only workshop-owned statuses are touched, only orders with item rows.
UPDATE `jewellery_orders` o
INNER JOIN (
    SELECT l.`order_id`,
           COUNT(*) AS total_items,
           SUM(CASE WHEN a.`status` = 'received' THEN 1 ELSE 0 END) AS came_back
      FROM `jewellery_order_lines` l
      LEFT JOIN `jewellery_order_assignments` a
             ON a.`id` = l.`assignment_id` AND a.`company_id` = l.`company_id`
     GROUP BY l.`order_id`
) t ON t.`order_id` = o.`id`
   SET o.`status` = 'partially_received'
 WHERE o.`status` = 'assigned'
   AND t.came_back > 0
   AND t.came_back < t.total_items;

-- A delivered order whose bill has been fully settled — or that never left a
-- balance — is finished, and now there is a word for it.
UPDATE `jewellery_orders` o
  INNER JOIN `jewellery_sales` s ON s.`id` = o.`delivered_sale_id` AND s.`company_id` = o.`company_id`
  LEFT JOIN `jewellery_bills` b ON b.`source_type` = 'jewellery_sale' AND b.`source_id` = s.`id`
        AND b.`company_id` = o.`company_id`
    SET o.`status` = 'closed'
  WHERE o.`status` = 'delivered'
    AND (b.`id` IS NULL OR b.`status` = 'settled');
