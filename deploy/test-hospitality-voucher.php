<?php
declare(strict_types=1);
require __DIR__.'/../app/hospitality_sales_posting.php';
require __DIR__.'/../app/hospitality_sales_workbook.php';
function ck(bool $ok,string $why):void { if(!$ok)throw new RuntimeException($why); }
$sheets=hospitality_workbook_template_sheets();
$map=hospitality_item_sheet_headers($sheets[HOSPITALITY_SHEET_ITEMS][0]);
$money=hospitality_workbook_money(['2026-10-14','Food','Momo',2,1000,100,900,117,1017],$map,13);
ck($money['errors']===[] && $money['total']===1017.0 && $money['taxable']===900.0,'Manual row matches upload columns');
$bad=hospitality_workbook_money(['2026-10-14','Food','Momo',2,1000,100,950,117,1017],$map,13);
ck($bad['errors']!==[],'Inconsistent totals rejected');
$page=file_get_contents(__DIR__.'/../public_html/admin/hospitality.php');
foreach(['<strong>Reference only.</strong>','<strong>Ingredients come from','Menu items are created from the daily sales upload'] as $text) ck(!str_contains($page,$text),'Retired banner: '.$text);
ck(str_contains($page,'New menu item'),'Direct menu creation');
$view=file_get_contents(__DIR__.'/../app/views/vouchers/hospitality_sales.php');
foreach(['verify_csrf();','context_fiscal_year_id','hospitality_workbook_parse_rows','hospitality_post_sales_workbook','entry_token','settlement_ledger_id'] as $hook) ck(str_contains($view,$hook),'Manual voucher control: '.$hook);
$posting=file_get_contents(__DIR__.'/../app/hospitality_sales_workbook.php');
ck(str_contains($posting,'SELECT id FROM companies WHERE id=? FOR UPDATE'),'Posting serialized per company');
ck(str_contains($posting,'This bill reference has already been recorded'),'Duplicate manual bill blocked');
echo "Hospitality voucher: sheet-compatible amounts, reconciliation, direct menu creation, retired banners and posting guards passed.\n";
