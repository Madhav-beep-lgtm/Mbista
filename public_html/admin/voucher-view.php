<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/voucher_types.php';
require_staff_admin_or_client_books();
require_company_context();
require_permission('accounting', 'view');
header('Cache-Control: private, no-store');
header('X-Frame-Options: SAMEORIGIN');
$company = current_company();
$query = db()->prepare('SELECT * FROM vouchers WHERE id = :id AND company_id = :company_id');
$query->execute(['id' => (int) ($_GET['id'] ?? 0), 'company_id' => (int) $company['id']]);
$voucher = $query->fetch(PDO::FETCH_ASSOC);
if (!$voucher) {
    http_response_code(404);
    exit('Voucher not found.');
}
$query = db()->prepare('SELECT e.*, l.name AS ledger_name, l.code AS ledger_code FROM voucher_entries e JOIN ledgers l ON l.id = e.ledger_id AND l.company_id = :company_id WHERE e.voucher_id = :voucher_id ORDER BY e.id');
$query->execute(['company_id' => (int) $company['id'], 'voucher_id' => (int) $voucher['id']]);
$entries = $query->fetchAll(PDO::FETCH_ASSOC);
$query = db()->prepare('SELECT label FROM fiscal_years WHERE id = ? AND company_id = ?');
$query->execute([(int) $voucher['fiscal_year_id'], (int) $company['id']]);
$financialYear = (string) ($query->fetchColumn() ?: 'Not recorded');
$names = [];
foreach (['submitted_by', 'approved_by'] as $role) {
    $names[$role] = '';
    if (!empty($voucher[$role])) {
        $query = db()->prepare('SELECT name FROM users WHERE id = ?');
        $query->execute([(int) $voucher[$role]]);
        $names[$role] = (string) ($query->fetchColumn() ?: '');
    }
}
$voucherLabel = (string) voucher_type_spec((string) $voucher['voucher_type'])['label'];
$totalDebit = 0;
$totalCredit = 0;
$receiptAllocation = [];
if (column_exists('invoice_payment_requests','source_voucher_id')) {
    $q=db()->prepare("SELECT p.id,p.payment_amount,p.notes,r.receipt_no,i.invoice_no,COALESCE(NULLIF(i.total_amount,0),i.amount) AS invoice_total,
        COALESCE(a.name,cp.organization_name,'') AS client_name,
        COALESCE(i.description,t.title,'') AS task_description,
        COALESCE((SELECT SUM(pr.payment_amount) FROM invoice_payment_requests pr WHERE pr.invoice_id=i.id AND pr.id<p.id AND pr.status IN ('paid','partial')),0) AS previous
        FROM invoice_payment_requests p JOIN task_invoices i ON i.id=p.invoice_id AND i.company_id=p.company_id
        JOIN invoice_payment_receipts r ON r.payment_request_id=p.id AND r.company_id=p.company_id
        LEFT JOIN accounting_parties a ON a.id=i.party_id AND a.company_id=i.company_id
        LEFT JOIN client_tasks t ON t.id=i.task_id AND t.company_id=i.company_id
        LEFT JOIN client_profiles cp ON cp.id=t.client_id
        WHERE p.source_voucher_id=? AND p.company_id=?");
    $q->execute([$voucher['id'],$company['id']]);
    if ($receipt=$q->fetch()) {
        $receiptAllocation=$receipt;
        $receiptAllocation['received']=$receipt['payment_amount'];
        $receiptAllocation['remaining']=max(0,round((float)$receipt['invoice_total']-(float)$receipt['previous']-(float)$receipt['payment_amount'],2));
        if ($voucher['voucher_type']==='receipt') $voucherLabel='Detailed receipt';
    }
}
if (isset($_GET['document']) && ($voucher['source_type'] ?? '') !== 'business_return' && in_array((string) $voucher['voucher_type'], ['sales', 'purchase', 'debit_note', 'credit_note'], true)) {
    $billing = voucher_billing_link((int) $voucher['id']);
    if ($voucher['voucher_type'] === 'sales' && !empty($billing['document_id'])) {
        redirect('admin/invoice.php?id=' . (int) $billing['document_id']);
    }
    $documentTitle = match ((string) $voucher['voucher_type']) {
        'sales' => 'Sales Invoice', 'purchase' => 'Purchase Record',
        'debit_note' => 'Debit Note', 'credit_note' => 'Credit Note',
    };
    $documentLines = voucher_decompose((string) $voucher['voucher_type'], $voucher, $entries, voucher_ledger_directory((int) $company['id']));
    if (empty($documentLines['ok'])) {
        http_response_code(422);
        exit('This legacy voucher requires its original source document. Open the journal view for its accounting entries.');
    }
    $query = db()->prepare('SELECT name, billing_address AS address, pan_no AS pan FROM accounting_parties WHERE id = ? AND company_id = ?');
    $query->execute([(int) ($voucher['party_id'] ?? 0), (int) $company['id']]);
    $documentParty = $query->fetch(PDO::FETCH_ASSOC) ?: [];
    require __DIR__ . '/../../app/views/vouchers/trade_document.php';
    exit;
}
require __DIR__ . '/../../app/views/vouchers/print.php';
