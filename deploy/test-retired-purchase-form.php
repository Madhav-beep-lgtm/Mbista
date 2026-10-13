<?php
declare(strict_types=1);
$source = file_get_contents(__DIR__ . '/../public_html/admin/accounting-inventory.php');
foreach (['Record Purchase / Opening Stock', 'Record all bills', 'purchaseBillForm', 'purchaseAddBill', 'replace_bill_id', 'inv_purchase_batch_post(', 'edit_bill='] as $retired) {
    if (str_contains($source, $retired)) throw new RuntimeException('Retired purchase form remains: ' . $retired);
}
foreach (['inv_purchase_bill_list(', "\$action === 'upload_opening'", "\$action === 'record_purchase_batch'", 'This purchase entry form has been retired.', "\$type === 'purchase'", 'admin/voucher-form.php?type=purchase'] as $required) {
    if (!str_contains($source, $required)) throw new RuntimeException('Missing history, opening stock or retirement guard: ' . $required);
}
echo "Retired purchase form removed; history, opening stock and posting guards retained.\n";
