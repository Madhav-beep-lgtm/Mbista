-- 090: Recompute order statuses that the old first-event-wins rule got wrong.
--
-- Until now the status of an order was nudged by whichever workshop event
-- happened FIRST of its kind:
--
--     the first issue      made the order 'assigned'
--     the first piece back made the order 'received'
--
-- On a one-item order that is right. On an order for five pieces going to
-- different karigars it is not: one ring coming back marked the whole order
-- 'received', and jewellery_pending_delivery() lists exactly that status as
-- ready to hand over. So an order with four bangles still at the bench was
-- being shown to the counter as collectable. Somebody would have gone to
-- fetch it and it would not have been there.
--
-- The engine now derives the status from ALL the items
-- (jewellery_sync_order_status), so new orders are correct. This corrects the
-- ones already stored, using exactly the same rule:
--
--     received   every item is back
--     assigned   at least one item has been sent out, but not all are back
--     confirmed  nothing is out at the moment
--
-- Two things make this safe.
--
-- Only orders sitting in 'assigned' or 'received' are considered. 'delivered'
-- and 'cancelled' are decisions a person made about the whole order, and
-- 'draft'/'confirmed' orders have not reached the workshop — none of them are
-- the engine's to revise.
--
-- And only orders that HAVE item rows are joined. An older single-item order
-- carries no rows in jewellery_order_lines, and for those the old rule and the
-- new one give the same answer, so there is nothing to correct.
--
-- Re-running this is harmless: it recomputes the same answer from the same
-- rows. Expect it to move some orders BACKWARDS out of 'received' — that is
-- the point of it, and those orders were never actually ready.

UPDATE `jewellery_orders` o
INNER JOIN (
    SELECT l.`order_id`,
           COUNT(*) AS total_items,
           SUM(CASE WHEN a.`id` IS NOT NULL AND a.`status` <> 'cancelled' THEN 1 ELSE 0 END) AS out_now,
           SUM(CASE WHEN a.`status` = 'received' THEN 1 ELSE 0 END) AS came_back
      FROM `jewellery_order_lines` l
      LEFT JOIN `jewellery_order_assignments` a
             ON a.`id` = l.`assignment_id` AND a.`company_id` = l.`company_id`
     GROUP BY l.`order_id`
) t ON t.`order_id` = o.`id`
   SET o.`status` = CASE
        WHEN t.came_back >= t.total_items THEN 'received'
        WHEN t.out_now > 0                THEN 'assigned'
        ELSE 'confirmed'
   END
 WHERE o.`status` IN ('assigned', 'received');
