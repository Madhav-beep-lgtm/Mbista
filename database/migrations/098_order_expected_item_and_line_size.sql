-- 098: What the customer ASKED FOR, in their own words — and what size.
--
-- Two gaps the counter kept working around:
--
-- expected_item — the order records WHICH stock items will be made, but not
-- what the customer actually said: "a bridal set", "a ring like my mother's",
-- "diamond pendant, small". That phrase is the order's own description of
-- itself, the thing the shop repeats back when the customer rings up. It was
-- being squeezed into the description field alongside everything else, where
-- search could not tell it apart.
--
-- size — a ring has a ring size, a chain a length, a bangle a diameter. Per
-- ITEM, because one order carries a ring for her and a chain for him. It was
-- going into line notes as free text, unlabelled, and the kaligad had to
-- guess which number in the note was the size.
--
-- Both are plain text on purpose: sizes are written a dozen ways (US 7,
-- 17 mm, 22 inches, "bangle 2-6") and a unit-typed column would refuse most
-- of them. The lines' notes column already exists and stays what it was —
-- engraving, finishing, the customer's wishes for THAT piece.

ALTER TABLE `jewellery_orders`
  ADD COLUMN IF NOT EXISTS `expected_item` VARCHAR(190) DEFAULT NULL AFTER `design_no`;

ALTER TABLE `jewellery_order_lines`
  ADD COLUMN IF NOT EXISTS `size` VARCHAR(60) DEFAULT NULL AFTER `delivery_date`;
