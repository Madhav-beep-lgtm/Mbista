<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_staff_admin_or_client_books();
require_company_context();
require_permission('accounting','view');
$company = current_company();
$companyId = (int)$company['id'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    deny_access('Create invoices from Sales vouchers and record collections from Receipt vouchers.');
}
$invoiceId = (int)($_GET['id'] ?? 0);
if (($_GET['action'] ?? '') === 'create') redirect('admin/voucher-form.php?type=sales');
if ($invoiceId > 0) {
    $invoice = get_invoice($invoiceId);
    if (!$invoice || (int)$invoice['company_id'] !== $companyId) deny_access('Invoice not found.');
    $documentTitle = $invoice['invoice_category'] === 'proforma' ? 'Proforma Invoice' : 'Tax Invoice';
    $sourceId=0;
    if (table_exists('voucher_billing')) {
        $q=db()->prepare("SELECT voucher_id FROM voucher_billing WHERE company_id=? AND kind='sales' AND document_id=?");
        $q->execute([$companyId,$invoiceId]); $sourceId=(int)$q->fetchColumn();
    }
    $voucher=['id'=>$sourceId,'voucher_no'=>$invoice['invoice_no'],'voucher_date'=>$invoice['issued_on'],'due_date'=>$invoice['due_on'],'status'=>$invoice['status'],'reference_no'=>'','narration'=>$invoice['notes'] ?? ''];
    // Preserve the original tax/discount/excise layout for historical invoices.
    if ($sourceId === 0) {
        header('Cache-Control: no-store');
        echo export_invoice_to_pdf_html($invoiceId);
        exit;
    }
    $documentParty=['name'=>$invoice['client_name'] ?? '', 'address'=>'','pan'=>$invoice['client_pan'] ?? ''];
    if (!empty($invoice['party_id'])) {
        $q=db()->prepare('SELECT name,billing_address AS address,pan_no AS pan FROM accounting_parties WHERE id=? AND company_id=?');
        $q->execute([$invoice['party_id'],$companyId]); $documentParty=$q->fetch() ?: $documentParty;
    }
    $lines=$invoice['line_items'];
    if (!$lines) $lines=[['description'=>$invoice['description'] ?: $invoice['task_title'],'quantity'=>1,'rate'=>$invoice['taxable_amount'] ?: $invoice['amount'],'taxable_amount'=>$invoice['taxable_amount'] ?: $invoice['amount'],'vat_rate'=>$invoice['vat_rate'],'vat_amount'=>$invoice['vat_amount'],'total_amount'=>$invoice['total_amount'] ?: $invoice['amount']]];
    $documentLines=['values'=>[],'tax_mode'=>'exclusive','tax_rate'=>0];
    foreach ($lines as $line) $documentLines['values'][]=['description'=>$line['description'],'qty'=>$line['quantity'],'rate'=>$line['rate'],'amount'=>$line['taxable_amount'],'tax_mode'=>(float)$line['vat_amount'] > 0 ? 'exclusive' : 'none','tax_rate'=>$line['vat_rate']];
    $invoiceYear=fiscal_year_for_date($companyId,$invoice['issued_on']);
    $financialYear = (string)($invoiceYear['label'] ?? '');
    $names=['submitted_by'=>$invoice['issued_by_name'] ?? '', 'approved_by'=>''];
    $company['address']=setting('office_address','',false,$companyId);
    header('Cache-Control: no-store');
    require __DIR__ . '/../../app/views/vouchers/trade_document.php';
    exit;
}
$pageTitle='Invoices'; $pageHero=false;
$page=max(1,(int)($_GET['page'] ?? 1));
$q=db()->prepare('SELECT i.*,t.title AS task_title,p.name AS party_name FROM task_invoices i LEFT JOIN client_tasks t ON t.id=i.task_id AND t.company_id=i.company_id LEFT JOIN accounting_parties p ON p.id=i.party_id AND p.company_id=i.company_id WHERE i.company_id=? ORDER BY i.id DESC LIMIT 51 OFFSET '.(($page-1)*50));
$q->execute([$companyId]); $invoices=$q->fetchAll(); $hasNext=count($invoices)>50; $invoices=array_slice($invoices,0,50);
include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
<section class="mbw-card">
 <div class="actions"><a class="button" href="<?= e(url('admin/voucher-form.php?type=sales')) ?>">Sales voucher</a><a class="button secondary" href="<?= e(url('admin/voucher-form.php?type=receipt')) ?>">Receipt voucher</a></div>
 <div class="table-responsive"><table><thead><tr><th>Invoice</th><th>Client / task</th><th>Issued</th><th>Amount</th><th>Status</th><th>Action</th></tr></thead><tbody>
 <?php foreach ($invoices as $invoice): ?><tr><td><?= e($invoice['invoice_no']) ?></td><td><?= e($invoice['party_name'] ?? '') ?><br><?= e($invoice['task_title'] ?? $invoice['description'] ?? '') ?></td><td><?= e(app_date($invoice['issued_on'])) ?></td><td><?= e(number_format((float)($invoice['total_amount'] ?: $invoice['amount']),2)) ?></td><td><?= e(ucfirst($invoice['status'])) ?></td><td><a class="button secondary" target="_blank" rel="noopener" href="?action=view&amp;id=<?= (int)$invoice['id'] ?>">Print preview</a></td></tr><?php endforeach; ?>
 <?php if (!$invoices): ?><tr><td colspan="6">No invoices yet.</td></tr><?php endif; ?>
 </tbody></table></div>
 <div class="actions"><?php if ($page>1): ?><a href="?page=<?= $page-1 ?>">Previous</a><?php endif; ?><?php if ($hasNext): ?><a href="?page=<?= $page+1 ?>">Next</a><?php endif; ?></div>
</section>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
