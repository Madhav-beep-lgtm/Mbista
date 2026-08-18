-- Test Showroom Inventory Setup
-- This script creates sample showroom stock pieces for testing the jewelry order form
-- Created: 2026-08-17

-- Create test showroom (self-order) assignments
-- These represent jewelry pieces made by the kaligadh for display/showroom
INSERT INTO jewellery_order_assignments
(company_id, karigar_id, assign_kind, category, size_design, expected_ornament,
 expected_gross_weight, expected_stone_weight, expected_net_weight,
 item_id, purity_id, unit_id, issue_date, status, assignment_no, issue_no)
VALUES
(1, 32, 'self', 'gold', '2.5 inches', 'Gold Bracelet 22K', 45.5000, 0, 45.5000,
 653, 16834, 8469, CURDATE(), 'received', 'TEST-SHOW-001', 'ISS-20250817-001'),
(1, 32, 'self', 'gold', 'size 7', 'Gold Ring 22K', 8.5000, 0, 8.5000,
 653, 16834, 8469, CURDATE(), 'received', 'TEST-SHOW-002', 'ISS-20250817-002'),
(1, 32, 'self', 'gold', '18 inches', 'Gold Necklace 22K', 35.0000, 0, 35.0000,
 653, 16834, 8469, CURDATE(), 'received', 'TEST-SHOW-003', 'ISS-20250817-003');

-- Create corresponding receipts (these make the pieces available for sale)
INSERT INTO jewellery_order_receipts
(company_id, assignment_id, receipt_no, receive_date, received_item_id, received_purity_id, unit_id,
 qty_pieces, received_gross_weight, stone_weight, net_gold_weight, received_fine_weight, making_amount,
 net_payable, status)
VALUES
(1, (SELECT id FROM jewellery_order_assignments WHERE assignment_no = 'TEST-SHOW-001'), 'RCP-20250817-001', CURDATE(), 653, 16834, 8469, 1, 45.5000, 0, 45.5000, 40.95, 2500.00, 2500.00, 'posted'),
(1, (SELECT id FROM jewellery_order_assignments WHERE assignment_no = 'TEST-SHOW-002'), 'RCP-20250817-002', CURDATE(), 653, 16834, 8469, 1, 8.5000, 0, 8.5000, 7.65, 850.00, 850.00, 'posted'),
(1, (SELECT id FROM jewellery_order_assignments WHERE assignment_no = 'TEST-SHOW-003'), 'RCP-20250817-003', CURDATE(), 653, 16834, 8469, 1, 35.0000, 0, 35.0000, 31.5, 2100.00, 2100.00, 'posted');

-- Verify creation
SELECT 'Test showroom stock created successfully!' as status;
SELECT a.assignment_no, r.receipt_no, r.received_gross_weight, r.making_amount
FROM jewellery_order_assignments a
INNER JOIN jewellery_order_receipts r ON r.assignment_id = a.id
WHERE a.assignment_no LIKE 'TEST-SHOW%'
ORDER BY a.id DESC;
