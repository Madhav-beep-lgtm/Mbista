-- 106: An ordered item can be a piece the shop ALREADY HAS.
--
-- Until now every item on a customer order was a job: it waited for a kaligad,
-- for metal to go out, for the piece to come back. But the commonest counter
-- conversation is not that at all. A customer sees a ring in the case, likes
-- it, and asks the shop to hold it while they fetch the money. That is an
-- order — it has a customer, an advance, a promised day and a bill — but there
-- is nothing to make. The piece is on the tray.
--
-- The shop already has a name for those pieces: Ready to Sale. They are the
-- self-ordered assignments that came back from the kaligad for the SHOWROOM
-- rather than for a customer, and they are sitting in stock.
--
-- So an order line now says where its piece comes from:
--
--     workshop   a kaligad has to make it — the flow that already existed,
--                and the default, so every line already written stays what
--                it was
--     stock      it is on the shelf; stock_receipt_id names WHICH physical
--                piece, and no kaligad is ever assigned to it
--
-- stock_receipt_id points at the kaligad RECEIPT that put the piece into
-- stock, because that receipt is the piece: one receipt per assignment, its
-- own weights, its own purity, its own item. Naming the item alone would not
-- do — two 22K rings of the same item code are two different objects, and the
-- shop has to know which one it promised away.
--
-- The link is what reserves it. A piece already named by a live order line is
-- not offered to the next customer, and the Ready to Sale board says who it is
-- being held for. A cancelled order releases its pieces, because a cancelled
-- order is not holding anything — the line keeps the id as the record of what
-- it once reserved, and the reservation test simply ignores it.
--
-- ON DELETE SET NULL rather than CASCADE: deleting the receipt would be
-- unwinding the piece's arrival into stock, and an order line that named it
-- should be left saying "the piece is gone" instead of vanishing with it.

ALTER TABLE `jewellery_order_lines`
  ADD COLUMN IF NOT EXISTS `source` ENUM('workshop','stock') NOT NULL DEFAULT 'workshop' AFTER `item_id`,
  ADD COLUMN IF NOT EXISTS `stock_receipt_id` INT UNSIGNED DEFAULT NULL AFTER `source`;

ALTER TABLE `jewellery_order_lines`
  ADD KEY `idx_jw_oline_stock` (`company_id`, `stock_receipt_id`),
  ADD CONSTRAINT `fk_jw_oline_stock_receipt` FOREIGN KEY (`stock_receipt_id`)
      REFERENCES `jewellery_order_receipts` (`id`) ON DELETE SET NULL;
