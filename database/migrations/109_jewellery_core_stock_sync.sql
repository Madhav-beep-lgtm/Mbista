-- 109: Make the shared item master use one quantity history in every report.
--
-- Jewellery already writes the authoritative metal/purity/holder movement to
-- jewellery_stock_txns. Core Stock Summary and the general inventory reports
-- read inventory_transactions, however, so received ornaments and every other
-- Jewellery movement of OWN stock were absent there. The permanent source id
-- below makes the bridge traceable and idempotent.
--
-- Opening rows are deliberately excluded: Jewellery opening stock already
-- lives on inventory_items.opening_qty. Mirroring it would count it twice.
-- Holder rows for a kaligad/refinery/customer are also excluded because core
-- inventory is the company's own shelf, not the metal-location subledger.

ALTER TABLE `inventory_transactions`
  ADD COLUMN IF NOT EXISTS `jewellery_stock_txn_id` INT UNSIGNED DEFAULT NULL AFTER `source_voucher_id`;

INSERT INTO `inventory_transactions`
    (`company_id`, `fiscal_year_id`, `item_id`, `warehouse_id`, `voucher_id`,
     `jewellery_stock_txn_id`, `transaction_type`, `ref_no`, `transaction_date`,
     `qty_in`, `qty_out`, `rate`, `amount`, `notes`, `created_at`)
SELECT t.`company_id`, t.`fiscal_year_id`, t.`item_id`, i.`default_warehouse_id`, t.`voucher_id`,
       t.`id`,
       CASE t.`txn_type`
           WHEN 'purchase' THEN 'purchase'
           WHEN 'purchase_return' THEN 'purchase_return'
           WHEN 'sale' THEN 'sale'
           WHEN 'sales_return' THEN 'sales_return'
           WHEN 'issue_karigar' THEN 'consume'
           WHEN 'issue_refinery' THEN 'consume'
           WHEN 'receive_karigar' THEN 'produce'
           WHEN 'receive_refinery' THEN 'produce'
           WHEN 'wastage' THEN 'write_off'
           ELSE 'adjustment'
       END,
       t.`ref_no`, t.`txn_date`,
       CASE WHEN t.`direction` = 'in' THEN
           ROUND(CASE
               WHEN t.`gross_grams` > 0 OR t.`gross_weight` > 0 THEN
                   (CASE WHEN t.`gross_grams` > 0 THEN t.`gross_grams`
                         ELSE t.`gross_weight` * IF(tu.`grams` > 0, tu.`grams`, 1) END)
                   / IF(iu.`grams` > 0, iu.`grams`, 1)
               ELSE t.`qty_pieces`
           END, 3)
           ELSE 0 END,
       CASE WHEN t.`direction` = 'out' THEN
           ROUND(CASE
               WHEN t.`gross_grams` > 0 OR t.`gross_weight` > 0 THEN
                   (CASE WHEN t.`gross_grams` > 0 THEN t.`gross_grams`
                         ELSE t.`gross_weight` * IF(tu.`grams` > 0, tu.`grams`, 1) END)
                   / IF(iu.`grams` > 0, iu.`grams`, 1)
               ELSE t.`qty_pieces`
           END, 3)
           ELSE 0 END,
       ROUND(t.`amount` / NULLIF(
           ROUND(CASE
               WHEN t.`gross_grams` > 0 OR t.`gross_weight` > 0 THEN
                   (CASE WHEN t.`gross_grams` > 0 THEN t.`gross_grams`
                         ELSE t.`gross_weight` * IF(tu.`grams` > 0, tu.`grams`, 1) END)
                   / IF(iu.`grams` > 0, iu.`grams`, 1)
               ELSE t.`qty_pieces`
           END, 3), 0), 2),
       t.`amount`,
       CONCAT('Jewellery — ', REPLACE(t.`txn_type`, '_', ' '),
              IF(t.`notes` IS NULL OR t.`notes` = '', '', CONCAT(': ', t.`notes`))),
       t.`created_at`
  FROM `jewellery_stock_txns` t
 INNER JOIN `inventory_items` i
         ON i.`id` = t.`item_id` AND i.`company_id` = t.`company_id`
 INNER JOIN `jewellery_item_profiles` jp
         ON jp.`inventory_item_id` = i.`id` AND jp.`company_id` = i.`company_id`
 INNER JOIN `jewellery_units` iu
         ON iu.`id` = jp.`unit_id` AND iu.`company_id` = t.`company_id`
 INNER JOIN `jewellery_units` tu
         ON tu.`id` = t.`unit_id` AND tu.`company_id` = t.`company_id`
 WHERE t.`holder_type` = 'stock'
   AND t.`txn_type` <> 'opening'
   AND NOT EXISTS (
       SELECT 1 FROM `inventory_transactions` it
        WHERE it.`jewellery_stock_txn_id` = t.`id`
   )
   AND ROUND(CASE
       WHEN t.`gross_grams` > 0 OR t.`gross_weight` > 0 THEN
           (CASE WHEN t.`gross_grams` > 0 THEN t.`gross_grams`
                 ELSE t.`gross_weight` * IF(tu.`grams` > 0, tu.`grams`, 1) END)
           / IF(iu.`grams` > 0, iu.`grams`, 1)
       ELSE t.`qty_pieces`
   END, 3) > 0;

CREATE UNIQUE INDEX IF NOT EXISTS `uniq_inventory_jewellery_stock_txn`
  ON `inventory_transactions` (`jewellery_stock_txn_id`);
