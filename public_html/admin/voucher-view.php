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
if (isset($_GET['document']) && in_array((string) $voucher['voucher_type'], ['sales', 'purchase', 'debit_note', 'credit_note'], true)) {
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
