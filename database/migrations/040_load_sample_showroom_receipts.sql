-- Load sample showroom receipts for jewellery orders
-- Run this migration to populate jewellery_order_receipts with sample showroom stock

SET FOREIGN_KEY_CHECKS=0;

-- Create assignment if not exists
INSERT INTO jewellery_order_assignments (company_id, karigar_id, assignment_no, assign_kind, status, assign_date, created_by)
SELECT 464, 1, 'SHOWROOM-SAMPLE-001', 'self', 'received', CURDATE(), 1
WHERE NOT EXISTS (SELECT 1 FROM jewellery_order_assignments WHERE assignment_no = 'SHOWROOM-SAMPLE-001');

-- Create receipt with showroom item
INSERT INTO jewellery_order_receipts (company_id, assignment_id, receipt_no, received_item_id, received_purity_id,
    unit_id, qty_pieces, received_gross_weight, stone_weight, size_design, design_no, trace_code,
    making_amount, remarks, status, receive_date)
SELECT
    464,
    (SELECT id FROM jewellery_order_assignments WHERE assignment_no = 'SHOWROOM-SAMPLE-001' LIMIT 1),
    'SHOWROOM-RCP-001',
    (SELECT id FROM inventory_items WHERE company_id = 464 AND name LIKE '%Showroom%' LIMIT 1),
    1,
    1,
    1,
    12.1856,
    0.0000,
    '',
    '',
    'SAMPLE-001',
    500.00,
    'Self order - ready to sale, showroom stock replenishment',
    'received',
    CURDATE()
WHERE NOT EXISTS (SELECT 1 FROM jewellery_order_receipts WHERE receipt_no = 'SHOWROOM-RCP-001')
AND (SELECT id FROM inventory_items WHERE company_id = 464 AND name LIKE '%Showroom%' LIMIT 1) IS NOT NULL;

SET FOREIGN_KEY_CHECKS=1;
