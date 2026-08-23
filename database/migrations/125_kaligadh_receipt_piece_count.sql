-- A kaligad receipt says how many finished pieces came back, and the receive
-- screen never asked. Every receipt posted before this therefore recorded
-- none: the ornament entered stock carrying its weight but with no piece
-- against it, and the counter sale of that very ornament was then refused —
-- "stock holds 0.000 pieces but the movement takes out 1.000" — for taking out
-- a piece the register said had never arrived.
--
-- A receipt is the hand-over of at least one piece, so zero is never a figure
-- somebody meant. Both statements are idempotent by their own WHERE: a receipt
-- that already carries a count is left exactly as it is.
UPDATE `jewellery_order_receipts`
   SET `qty_pieces` = 1
 WHERE `qty_pieces` <= 0
   AND `received_gross_weight` > 0;

-- The register follows the receipt rather than assuming one, so a receipt that
-- DID carry a count — typed through the API, where the field always existed —
-- has its movement corrected to that same count instead of to 1.
UPDATE `jewellery_stock_txns` t
 INNER JOIN `jewellery_order_receipts` r
    ON r.`id` = t.`source_id` AND r.`company_id` = t.`company_id`
   SET t.`qty_pieces` = r.`qty_pieces`
 WHERE t.`source_type` = 'jewellery_order_receipt'
   AND t.`txn_type` = 'receive_karigar'
   AND t.`direction` = 'in'
   AND t.`holder_type` = 'stock'
   AND t.`qty_pieces` <= 0
   AND r.`qty_pieces` > 0;
