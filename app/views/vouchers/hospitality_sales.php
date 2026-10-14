<?php
declare(strict_types=1);
require_once __DIR__.'/../../hospitality_sales_posting.php';
require_once __DIR__.'/../../hospitality_sales_workbook.php';
require_hospitality();
require_permission('accounting','create');
require_permission('hospitality','create');
$menuQuery=db()->prepare('SELECT id,name,category,standard_price FROM hospitality_menu_items WHERE company_id=? AND active=1 ORDER BY name');
$menuQuery->execute([$companyId]);
$menu=[];
foreach($menuQuery->fetchAll() as $row) $menu[(int)$row['id']]=$row;
$ledgers=voucher_ledgers_for_role(voucher_ledger_directory($companyId),'settlement');
$input=$_SERVER['REQUEST_METHOD']==='POST' ? $_POST : [];
$error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    try {
        $token=(string)($input['entry_token'] ?? '');
        if (!$token || !isset($_SESSION['hospitality_voucher_tokens'][$companyId][$token])) throw new RuntimeException('This entry has already been submitted or expired. Reload the voucher.');
        if ((int)($input['context_fiscal_year_id'] ?? 0)!==$fiscalYearId) throw new RuntimeException('Fiscal year changed. Reload the voucher.');
        if (count((array)($input['menu_id'] ?? []))>100) throw new RuntimeException('Use Sales Upload for more than 100 menu lines.');
        $date=(string)($input['voucher_date'] ?? '');
        $ledgerId=(int)($input['settlement_ledger_id'] ?? 0);
        $settlement=null;
        foreach($ledgers as $ledger) if ((int)$ledger['id']===$ledgerId) $settlement=$ledger;
        if (!$settlement) throw new RuntimeException('Select a settlement account.');
        $templates=hospitality_workbook_template_sheets();
        $items=[$templates[HOSPITALITY_SHEET_ITEMS][0]];
        $totals=['amount'=>0.0,'discount'=>0.0,'taxable'=>0.0,'vat'=>0.0,'total'=>0.0];
        foreach((array)($input['menu_id'] ?? []) as $index=>$menuId) {
            if (!(int)$menuId) continue;
            $item=$menu[(int)$menuId] ?? null;
            if (!$item) throw new RuntimeException('Select an active menu item of this company.');
            $qty=(float)($input['qty'][$index] ?? 0);
            $amount=round((float)($input['amount'][$index] ?? 0),2);
            $discount=round((float)($input['discount'][$index] ?? 0),2);
            $vat=round((float)($input['vat'][$index] ?? 0),2);
            if ($qty<=0 || $amount<=0 || $discount<0 || $discount>$amount || $vat<0) throw new RuntimeException('Check quantity, sales amount, discount and VAT.');
            $taxable=round($amount-$discount,2); $total=round($taxable+$vat,2);
            $items[]=[$date,$item['category'],$item['name'],$qty,$amount,$discount,$taxable,$vat,$total];
            foreach(array_keys($totals) as $key) $totals[$key]+=${$key};
        }
        if (count($items)===1) throw new RuntimeException('Add at least one menu item.');
        $invoiceNo=trim((string)($input['reference_no'] ?? ''));
        if ($invoiceNo==='') throw new RuntimeException('Enter the bill reference.');
        $invoices=[$templates[HOSPITALITY_SHEET_INVOICES][0],[$date,$invoiceNo,'Voucher',$settlement['code'],$totals['amount'],$totals['discount'],$totals['taxable'],$totals['vat'],$totals['total']]];
        $parsed=hospitality_workbook_parse_rows($items,$invoices,$companyId,$fiscalYearId,hospitality_settings($companyId));
        // The workbook engine performs the same reconciliation and posting as upload.
        $result=hospitality_post_sales_workbook($companyId,$fiscalYearId,$parsed,'Voucher '.$invoiceNo,$userId,true,true);
        if (empty($result['ok'])) throw new RuntimeException((string)$result['error']);
        unset($_SESSION['hospitality_voucher_tokens'][$companyId][$token]);
        flash('success',!empty($result['needs_approval']) ? 'Sales voucher submitted for approval.' : 'Sales voucher posted.');
        redirect('admin/accounting.php');
    } catch(Throwable $exception) { $error=$exception instanceof PDOException ? 'Could not save the sales voucher.' : $exception->getMessage(); }
}
$token=(string)($input['entry_token'] ?? '');
if (!$token || !isset($_SESSION['hospitality_voucher_tokens'][$companyId][$token])) {
    $token=bin2hex(random_bytes(20));
    $_SESSION['hospitality_voucher_tokens'][$companyId][$token]=time();
    $_SESSION['hospitality_voucher_tokens'][$companyId]=array_slice($_SESSION['hospitality_voucher_tokens'][$companyId],-20,null,true);
}
$pageTitle='Sales voucher'; $pageHero=false;
include __DIR__.'/../partials/admin_header.php';
?>
<section class="mbw-card">
 <div class="mbw-card-head"><h2>Sales voucher</h2><div class="actions"><a class="button secondary" href="<?= e(url('admin/hospitality.php?view=menu-items')) ?>">Menu items</a><a class="button secondary" href="<?= e(url('admin/hospitality.php?view=sales-upload')) ?>">Sales upload</a></div></div>
 <?php if($error): ?><div class="notice error"><?= e($error) ?></div><?php endif; ?>
 <form method="post">
 <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="type" value="sales"><input type="hidden" name="entry_token" value="<?= e($token) ?>"><input type="hidden" name="context_fiscal_year_id" value="<?= $fiscalYearId ?>">
 <div class="frm-grid frm-grid-3">
 <label>Date<input type="date" name="voucher_date" required value="<?= e($input['voucher_date'] ?? date('Y-m-d')) ?>"></label>
 <label>Bill reference<input name="reference_no" maxlength="100" required value="<?= e($input['reference_no'] ?? '') ?>"></label>
 <label>Settlement account<select name="settlement_ledger_id" required><option value="">Select account</option><?php foreach($ledgers as $ledger): ?><option value="<?= (int)$ledger['id'] ?>" <?= (int)($input['settlement_ledger_id'] ?? 0)===(int)$ledger['id'] ? 'selected' : '' ?>><?= e($ledger['name'].' · '.$ledger['code']) ?></option><?php endforeach; ?></select></label>
 </div>
 <div class="table-responsive"><table><thead><tr><th>Menu item</th><th>Quantity</th><th>Sales amount (excl. VAT)</th><th>Discount</th><th>VAT</th></tr></thead><tbody id="hospitality-voucher-lines">
 <?php for($i=0;$i<min(100,max(3,count((array)($input['menu_id'] ?? []))));$i++): ?><tr>
 <td><select name="menu_id[]"><option value="">Select menu item</option><?php foreach($menu as $item): ?><option value="<?= (int)$item['id'] ?>" <?= (int)($input['menu_id'][$i] ?? 0)===(int)$item['id'] ? 'selected' : '' ?>><?= e($item['name']) ?></option><?php endforeach; ?></select></td>
 <?php foreach(['qty','amount','discount','vat'] as $field): ?><td><input type="number" name="<?= $field ?>[]" step="<?= $field==='qty' ? '0.001' : '0.01' ?>" min="0" value="<?= e((string)($input[$field][$i] ?? '')) ?>" aria-label="<?= e($field) ?>"></td><?php endforeach; ?>
 </tr><?php endfor; ?></tbody></table></div>
 <div class="actions"><button type="button" class="button secondary" id="hospitality-add-line">Add item</button><button class="button" type="submit">Save sales voucher</button></div>
 </form>
</section>
<script>document.getElementById('hospitality-add-line').addEventListener('click',function(){var body=document.getElementById('hospitality-voucher-lines'),row=body.rows[0].cloneNode(true);row.querySelectorAll('input,select').forEach(function(field){field.value='';});body.appendChild(row);});</script>
<?php include __DIR__.'/../partials/admin_footer.php'; ?>
