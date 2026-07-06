<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/accounting_module_repair.php';

require_staff_or_admin();
require_company_context();

$repairErrors = accounting_module_repair_database();
$pageTitle = 'Sales, Purchases & Party Management';
$pageSubtitle = 'Manage invoices, bills, parties, collections, payments and outstanding balances';
$company = current_company();
$fiscalYear = current_fiscal_year();
$companyId = (int) ($company['id'] ?? 0);
$currentUser = current_user();
$userId = (int) ($currentUser['id'] ?? 0);

$partyTypes = ['customer', 'supplier', 'both', 'other'];
$statuses = ['active', 'inactive'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save_party') {
        $partyId = (int) ($_POST['party_id'] ?? 0);
        $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
        $name = trim((string) ($_POST['name'] ?? ''));
        $partyType = (string) ($_POST['party_type'] ?? 'both');
        $status = (string) ($_POST['status'] ?? 'active');
        $ledgerId = (int) ($_POST['ledger_id'] ?? 0);
        $openingBalance = round((float) ($_POST['opening_balance'] ?? 0), 2);
        $openingBalanceType = (string) ($_POST['opening_balance_type'] ?? 'debit');

        if ($code === '' || $name === '' || !in_array($partyType, $partyTypes, true) || !in_array($status, $statuses, true)) {
            flash('error', 'Party code, name, type, and status are required.');
            redirect('admin/accounting-parties.php');
        }
        if (!in_array($openingBalanceType, ['debit', 'credit'], true)) {
            $openingBalanceType = 'debit';
        }

        $params = [
            'company_id' => $companyId,
            'ledger_id' => $ledgerId > 0 ? $ledgerId : null,
            'code' => $code,
            'name' => $name,
            'party_type' => $partyType,
            'pan_no' => trim((string) ($_POST['pan_no'] ?? '')) ?: null,
            'email' => trim((string) ($_POST['email'] ?? '')) ?: null,
            'phone' => trim((string) ($_POST['phone'] ?? '')) ?: null,
            'billing_address' => trim((string) ($_POST['billing_address'] ?? '')) ?: null,
            'opening_balance' => $openingBalance,
            'opening_balance_type' => $openingBalanceType,
            'credit_limit' => round((float) ($_POST['credit_limit'] ?? 0), 2),
            'status' => $status,
            'notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
        ];

        try {
            if ($partyId > 0) {
                $params['id'] = $partyId;
                $stmt = db()->prepare('
                    UPDATE accounting_parties
                    SET ledger_id = :ledger_id, code = :code, name = :name, party_type = :party_type,
                        pan_no = :pan_no, email = :email, phone = :phone, billing_address = :billing_address,
                        opening_balance = :opening_balance, opening_balance_type = :opening_balance_type,
                        credit_limit = :credit_limit, status = :status, notes = :notes
                    WHERE id = :id AND company_id = :company_id
                ');
                $stmt->execute($params);
                log_activity('accounting_party', $partyId, 'updated', 'Accounting party updated.', $userId);
                flash('success', 'Party updated.');
            } else {
                $stmt = db()->prepare('
                    INSERT INTO accounting_parties (
                        company_id, ledger_id, code, name, party_type, pan_no, email, phone, billing_address,
                        opening_balance, opening_balance_type, credit_limit, status, notes
                    ) VALUES (
                        :company_id, :ledger_id, :code, :name, :party_type, :pan_no, :email, :phone, :billing_address,
                        :opening_balance, :opening_balance_type, :credit_limit, :status, :notes
                    )
                ');
                $stmt->execute($params);
                log_activity('accounting_party', (int) db()->lastInsertId(), 'created', 'Accounting party created.', $userId);
                flash('success', 'Party created.');
            }
        } catch (Throwable $exception) {
            flash('error', 'Could not save party. The code may already exist.');
        }

        redirect('admin/accounting-parties.php');
    }

    if ($action === 'toggle_party') {
        $partyId = (int) ($_POST['party_id'] ?? 0);
        if ($partyId > 0) {
            db()->prepare("UPDATE accounting_parties SET status = IF(status = 'active', 'inactive', 'active') WHERE id = :id AND company_id = :company_id")
                ->execute(['id' => $partyId, 'company_id' => $companyId]);
            log_activity('accounting_party', $partyId, 'status_toggled', 'Accounting party status toggled.', $userId);
            flash('success', 'Party status updated.');
        }
        redirect('admin/accounting-parties.php');
    }

    if ($action === 'save_note') {
        $partyId = (int) ($_POST['party_id'] ?? 0);
        $note = trim((string) ($_POST['notes'] ?? ''));
        if ($partyId > 0) {
            db()->prepare('UPDATE accounting_parties SET notes = :notes WHERE id = :id AND company_id = :company_id')
                ->execute(['notes' => $note !== '' ? $note : null, 'id' => $partyId, 'company_id' => $companyId]);
            log_activity('accounting_party', $partyId, 'note_saved', 'Party note updated.', $userId);
            flash('success', 'Note saved.');
        }
        redirect('admin/accounting-parties.php?party_id=' . $partyId . '&ptab=notes');
    }

    if ($action === 'record_payment') {
        $invoiceId = (int) ($_POST['invoice_id'] ?? 0);
        $amount = round((float) ($_POST['amount'] ?? 0), 2);
        $receivedOn = trim((string) ($_POST['received_on'] ?? date('Y-m-d')));
        $method = trim((string) ($_POST['payment_method'] ?? 'Cash'));
        $note = trim((string) ($_POST['notes'] ?? ''));

        $invoiceStmt = db()->prepare('
            SELECT ti.id, ti.invoice_no, ti.total_amount,
                   COALESCE((SELECT SUM(COALESCE(pr.payment_amount, 0)) FROM invoice_payment_requests pr
                             WHERE pr.invoice_id = ti.id AND pr.status IN ("paid", "partial")), 0) AS paid_amount
            FROM task_invoices ti
            WHERE ti.id = :id AND ti.company_id = :company_id AND ti.status <> "cancelled"
            LIMIT 1
        ');
        $invoiceStmt->execute(['id' => $invoiceId, 'company_id' => $companyId]);
        $invoice = $invoiceStmt->fetch();
        $outstanding = $invoice ? max(0.0, round((float) $invoice['total_amount'] - (float) $invoice['paid_amount'], 2)) : 0.0;

        if (!$invoice || $amount <= 0 || $outstanding <= 0) {
            flash('error', 'Choose an open invoice and enter a payment amount.');
            redirect('admin/accounting-parties.php?panel=payment');
        }

        $amount = min($amount, $outstanding);
        $paymentStatus = $amount >= $outstanding ? 'paid' : 'partial';

        db()->prepare('
            INSERT INTO invoice_payment_requests
                (invoice_id, company_id, requested_by, amount_requested, payment_method, status, payment_received_on, payment_amount, notes)
            VALUES (:invoice_id, :company_id, :requested_by, :amount_requested, :payment_method, :status, :received_on, :payment_amount, :notes)
        ')->execute([
            'invoice_id' => $invoiceId,
            'company_id' => $companyId,
            'requested_by' => $userId,
            'amount_requested' => $amount,
            'payment_method' => $method !== '' ? $method : 'Cash',
            'status' => $paymentStatus,
            'received_on' => $receivedOn !== '' ? $receivedOn : date('Y-m-d'),
            'payment_amount' => $amount,
            'notes' => $note !== '' ? $note : null,
        ]);
        $paymentRequestId = (int) db()->lastInsertId();

        try {
            auto_post_invoice_payment_voucher($paymentRequestId, $userId);
        } catch (Throwable $exception) {
            // Payment stays recorded even if the accounting auto-post cannot run.
        }

        log_activity('invoice_payment', $paymentRequestId, 'recorded', 'Payment recorded against invoice ' . $invoice['invoice_no'] . '.', $userId);
        flash('success', 'Payment of ' . site_currency_symbol() . number_format($amount, 2) . ' recorded against ' . $invoice['invoice_no'] . '.');
        redirect('admin/accounting-parties.php?tab=collections');
    }

    if ($action === 'record_purchase') {
        $partyId = (int) ($_POST['party_id'] ?? 0);
        $expenseLedgerId = (int) ($_POST['expense_ledger_id'] ?? 0);
        $amount = round((float) ($_POST['amount'] ?? 0), 2);
        $vatAmount = round((float) ($_POST['vat_amount'] ?? 0), 2);
        $billNo = trim((string) ($_POST['bill_no'] ?? ''));
        $billDate = trim((string) ($_POST['bill_date'] ?? date('Y-m-d')));
        $paidVia = (string) ($_POST['paid_via'] ?? 'credit');
        $note = trim((string) ($_POST['notes'] ?? ''));
        $total = round($amount + max(0, $vatAmount), 2);

        $party = null;
        if ($partyId > 0) {
            $partyStmt = db()->prepare('SELECT * FROM accounting_parties WHERE id = :id AND company_id = :company_id LIMIT 1');
            $partyStmt->execute(['id' => $partyId, 'company_id' => $companyId]);
            $party = $partyStmt->fetch() ?: null;
        }

        $expenseLedger = null;
        if ($expenseLedgerId > 0) {
            $ledgerCheck = db()->prepare("SELECT * FROM ledgers WHERE id = :id AND company_id = :company_id AND status = 'active' LIMIT 1");
            $ledgerCheck->execute(['id' => $expenseLedgerId, 'company_id' => $companyId]);
            $expenseLedger = $ledgerCheck->fetch() ?: null;
        }

        $creditLedger = $paidVia === 'cash' ? ledger_by_code($companyId, 'CASH') : ledger_by_code($companyId, 'AP');

        if (!$party || !$expenseLedger || !$creditLedger || $total <= 0 || current_fiscal_year_id() <= 0) {
            flash('error', 'Supplier, expense ledger, and a positive amount are required to record a purchase.');
            redirect('admin/accounting-parties.php?panel=purchase');
        }

        $voucherNo = 'PB-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        try {
            $voucherId = create_voucher_with_entries([
                'company_id' => $companyId,
                'fiscal_year_id' => current_fiscal_year_id(),
                'voucher_no' => $voucherNo,
                'voucher_type' => 'purchase',
                'source_type' => 'purchase_bill',
                'source_id' => null,
                'party_id' => $partyId,
                'reference_no' => $billNo !== '' ? $billNo : null,
                'voucher_date' => $billDate !== '' ? $billDate : date('Y-m-d'),
                'narration' => $note !== '' ? $note : ('Purchase bill from ' . $party['name']),
                'total_amount' => $total,
                'status' => 'posted',
                'posted_by' => $userId,
                'posted_at' => date('Y-m-d H:i:s'),
            ], [
                ['ledger_id' => (int) $expenseLedger['id'], 'entry_type' => 'debit', 'amount' => $total, 'memo' => 'Purchase from ' . $party['name'] . ($vatAmount > 0 ? ' (incl. VAT ' . number_format($vatAmount, 2) . ')' : '')],
                ['ledger_id' => (int) $creditLedger['id'], 'entry_type' => 'credit', 'amount' => $total, 'memo' => $paidVia === 'cash' ? 'Paid in cash' : 'Payable to supplier'],
            ]);
            log_activity('purchase_bill', $voucherId, 'recorded', 'Purchase bill ' . $voucherNo . ' recorded for ' . $party['name'] . '.', $userId);
            flash('success', 'Purchase bill ' . $voucherNo . ' recorded for ' . $party['name'] . '.');
        } catch (Throwable $exception) {
            flash('error', 'Could not record the purchase bill.');
        }

        redirect('admin/accounting-parties.php?tab=purchases');
    }

    if ($action === 'record_supplier_payment') {
        $partyId = (int) ($_POST['party_id'] ?? 0);
        $amount = round((float) ($_POST['amount'] ?? 0), 2);
        $paidOn = trim((string) ($_POST['paid_on'] ?? date('Y-m-d')));
        $reference = trim((string) ($_POST['reference_no'] ?? ''));
        $note = trim((string) ($_POST['notes'] ?? ''));

        $party = null;
        if ($partyId > 0) {
            $partyStmt = db()->prepare('SELECT * FROM accounting_parties WHERE id = :id AND company_id = :company_id LIMIT 1');
            $partyStmt->execute(['id' => $partyId, 'company_id' => $companyId]);
            $party = $partyStmt->fetch() ?: null;
        }

        $cashLedger = ledger_by_code($companyId, 'CASH');
        $apLedger = ledger_by_code($companyId, 'AP');

        if (!$party || !$cashLedger || !$apLedger || $amount <= 0 || current_fiscal_year_id() <= 0) {
            flash('error', 'Supplier and a positive amount are required to record a payment.');
            redirect('admin/accounting-parties.php?panel=supplier-payment');
        }

        $voucherNo = 'PV-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        try {
            $voucherId = create_voucher_with_entries([
                'company_id' => $companyId,
                'fiscal_year_id' => current_fiscal_year_id(),
                'voucher_no' => $voucherNo,
                'voucher_type' => 'payment',
                'source_type' => 'supplier_payment',
                'source_id' => null,
                'party_id' => $partyId,
                'reference_no' => $reference !== '' ? $reference : null,
                'voucher_date' => $paidOn !== '' ? $paidOn : date('Y-m-d'),
                'narration' => $note !== '' ? $note : ('Payment to ' . $party['name']),
                'total_amount' => $amount,
                'status' => 'posted',
                'posted_by' => $userId,
                'posted_at' => date('Y-m-d H:i:s'),
            ], [
                ['ledger_id' => (int) $apLedger['id'], 'entry_type' => 'debit', 'amount' => $amount, 'memo' => 'Supplier balance settled'],
                ['ledger_id' => (int) $cashLedger['id'], 'entry_type' => 'credit', 'amount' => $amount, 'memo' => 'Paid to ' . $party['name']],
            ]);
            log_activity('supplier_payment', $voucherId, 'recorded', 'Payment ' . $voucherNo . ' made to ' . $party['name'] . '.', $userId);
            flash('success', 'Payment ' . $voucherNo . ' of ' . site_currency_symbol() . number_format($amount, 2) . ' recorded for ' . $party['name'] . '.');
        } catch (Throwable $exception) {
            flash('error', 'Could not record the supplier payment.');
        }

        redirect('admin/accounting-parties.php?tab=payments');
    }
}

// ---------------------------------------------------------------------------
// Filters and view state (every control on this page reads from these).
// ---------------------------------------------------------------------------
$tab = (string) ($_GET['tab'] ?? '');
$typeFilter = (string) ($_GET['type'] ?? '');
if ($typeFilter === 'customer') {
    $tab = 'customers';
} elseif ($typeFilter === 'supplier') {
    $tab = 'suppliers';
}
$validTabs = ['overview', 'sales', 'purchases', 'customers', 'suppliers', 'collections', 'payments', 'aging'];
if (!in_array($tab, $validTabs, true)) {
    $tab = 'overview';
}

$panel = (string) ($_GET['panel'] ?? '');
$ptab = (string) ($_GET['ptab'] ?? 'profile');
if (!in_array($ptab, ['profile', 'ledger', 'documents', 'notes'], true)) {
    $ptab = 'profile';
}
$searchQuery = trim((string) ($_GET['q'] ?? ''));
$statusFilter = (string) ($_GET['status'] ?? '');
$fromDate = trim((string) ($_GET['from'] ?? ''));
$toDate = trim((string) ($_GET['to'] ?? ''));
$dateFormatOk = static fn (string $value): bool => $value !== '' && DateTimeImmutable::createFromFormat('Y-m-d', $value) !== false;
if (!$dateFormatOk($fromDate)) {
    $fromDate = date('Y') . '-01-01';
}
if (!$dateFormatOk($toDate)) {
    $toDate = date('Y') . '-12-31';
}
$perPage = (int) ($_GET['per_page'] ?? 10);
if (!in_array($perPage, [10, 25, 50], true)) {
    $perPage = 10;
}
$page = max(1, (int) ($_GET['page'] ?? 1));

function parties_page_url(array $overrides = []): string
{
    $params = array_merge($_GET, $overrides);
    foreach ($params as $key => $value) {
        if ($value === null || $value === '') {
            unset($params[$key]);
        }
    }
    $query = http_build_query($params);
    return url('admin/accounting-parties.php' . ($query !== '' ? '?' . $query : ''));
}

// ---------------------------------------------------------------------------
// Data: parties, ledgers.
// ---------------------------------------------------------------------------
$editParty = null;
$editId = (int) ($_GET['edit_id'] ?? 0);
if ($editId > 0) {
    $stmt = db()->prepare('SELECT * FROM accounting_parties WHERE id = :id AND company_id = :company_id LIMIT 1');
    $stmt->execute(['id' => $editId, 'company_id' => $companyId]);
    $editParty = $stmt->fetch() ?: null;
}

$ledgerStmt = db()->prepare("SELECT id, code, name, type FROM ledgers WHERE company_id = :company_id AND status = 'active' ORDER BY code ASC");
$ledgerStmt->execute(['company_id' => $companyId]);
$ledgers = $ledgerStmt->fetchAll();
$expenseLedgers = array_values(array_filter($ledgers, static fn (array $ledger): bool => in_array((string) ($ledger['type'] ?? ''), ['expense', 'asset'], true)));

$partyStmt = db()->prepare('
    SELECT p.*, l.code AS ledger_code, l.name AS ledger_name
    FROM accounting_parties p
    LEFT JOIN ledgers l ON l.id = p.ledger_id
    WHERE p.company_id = :company_id
    ORDER BY p.status ASC, p.name ASC
');
$partyStmt->execute(['company_id' => $companyId]);
$parties = $partyStmt->fetchAll();
$supplierParties = array_values(array_filter($parties, static fn (array $party): bool => in_array((string) $party['party_type'], ['supplier', 'both'], true)));

// ---------------------------------------------------------------------------
// Data: sales invoices (with search + date range applied in SQL).
// ---------------------------------------------------------------------------
$documents = [];
if (table_exists('task_invoices')) {
    $documentSql = '
        SELECT ti.id, ti.invoice_no AS voucher_no, ti.invoice_type, ti.invoice_category, ti.status, ti.issued_on, ti.due_on,
               ti.total_amount, ti.amount, ti.vat_amount, ti.notes, ti.created_at AS posted_at,
               ti.party_id AS accounting_party_id, ti.invoice_source_type, ti.description,
               ct.client_id, ct.title AS task_title,
               cp.id AS client_profile_id, cp.client_code, cp.organization_name, cp.address, cp.pan_no, cp.created_at AS client_since,
               u.email, u.phone,
               ap.code AS accounting_party_code, ap.name AS accounting_party_name, ap.party_type AS accounting_party_type,
               ap.email AS accounting_party_email, ap.phone AS accounting_party_phone, ap.billing_address AS accounting_party_address,
               ap.pan_no AS accounting_party_pan, ap.created_at AS accounting_party_since,
               COALESCE(payments.paid_amount, 0) AS paid_amount
        FROM task_invoices ti
        LEFT JOIN client_tasks ct ON ct.id = ti.task_id
        LEFT JOIN client_profiles cp ON cp.id = ct.client_id
        LEFT JOIN users u ON u.id = cp.user_id
        LEFT JOIN accounting_parties ap ON ap.id = ti.party_id
        LEFT JOIN (
            SELECT invoice_id, SUM(COALESCE(payment_amount, 0)) AS paid_amount
            FROM invoice_payment_requests
            WHERE status IN ("paid", "partial")
            GROUP BY invoice_id
        ) payments ON payments.invoice_id = ti.id
        WHERE ti.company_id = :company_id AND ti.status <> "cancelled"
          AND COALESCE(ti.issued_on, DATE(ti.created_at)) BETWEEN :from_date AND :to_date
    ';
    $documentParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
    if ($searchQuery !== '') {
        $documentSql .= ' AND (ti.invoice_no LIKE :search OR ap.name LIKE :search2 OR cp.organization_name LIKE :search3 OR ti.description LIKE :search4)';
        $like = '%' . $searchQuery . '%';
        $documentParams += ['search' => $like, 'search2' => $like, 'search3' => $like, 'search4' => $like];
    }
    $documentSql .= ' ORDER BY ti.issued_on DESC, ti.id DESC LIMIT 500';
    $documentStmt = db()->prepare($documentSql);
    $documentStmt->execute($documentParams);
    $documents = $documentStmt->fetchAll();
}

$summary = [
    'receivables' => 0.0,
    'payables' => 0.0,
    'sales' => 0.0,
    'purchases' => 0.0,
    'overdue' => 0.0,
    'overdue_count' => 0,
    'paid' => 0.0,
    'month_sales' => 0.0,
    'month_purchases' => 0.0,
];
$receivableAging = ['0 - 30 Days' => 0.0, '31 - 60 Days' => 0.0, '61 - 90 Days' => 0.0, '90+ Days' => 0.0];
$payableAging = $receivableAging;
$today = new DateTimeImmutable('today');
$monthStart = $today->modify('first day of this month');

foreach ($documents as $documentIndex => &$document) {
    $dateText = (string) ($document['issued_on'] ?: date('Y-m-d', strtotime((string) $document['posted_at'])));
    $invoiceDate = new DateTimeImmutable($dateText);
    $dueDate = !empty($document['due_on']) ? new DateTimeImmutable((string) $document['due_on']) : $invoiceDate->modify('+15 days');
    $amount = (float) $document['total_amount'];
    $paid = min($amount, round((float) ($document['paid_amount'] ?? 0), 2));
    $outstanding = max(0, $amount - $paid);
    $daysOverdue = max(0, (int) $dueDate->diff($today)->format('%r%a'));
    $bucket = $daysOverdue <= 30 ? '0 - 30 Days' : ($daysOverdue <= 60 ? '31 - 60 Days' : ($daysOverdue <= 90 ? '61 - 90 Days' : '90+ Days'));
    $status = $outstanding <= 0 ? 'Paid' : ($dueDate < $today ? 'Overdue' : 'Open');

    $sourceLabel = match ((string) ($document['invoice_source_type'] ?? 'task')) {
        'inventory' => 'Inventory Invoice',
        'manufacturing' => 'Manufacturing Invoice',
        'other' => 'Manual Invoice',
        default => 'Sales Invoice',
    };
    $document['display_type'] = ($document['invoice_category'] ?? 'proforma') === 'tax' ? 'Tax Invoice' : $sourceLabel;
    $document['invoice_date'] = $invoiceDate->format('d M Y');
    $document['due_date'] = $dueDate->format('d M Y');
    $document['issued_raw'] = $invoiceDate->format('Y-m-d');
    $document['paid_amount'] = $paid;
    $document['outstanding_amount'] = $outstanding;
    $document['display_status'] = $status;
    $document['party_id'] = (int) ($document['accounting_party_id'] ?: ($document['client_profile_id'] ?? 0));
    $document['party_name'] = $document['accounting_party_name'] ?: ($document['organization_name'] ?: 'Client invoice');
    $document['party_type'] = $document['accounting_party_type'] ?: 'customer';
    $document['party_code'] = $document['accounting_party_code'] ?: ($document['client_code'] ?? '');
    $document['email'] = $document['accounting_party_email'] ?: ($document['email'] ?? null);
    $document['phone'] = $document['accounting_party_phone'] ?: ($document['phone'] ?? null);
    $document['billing_address'] = $document['accounting_party_address'] ?: ($document['address'] ?? '');
    $document['pan_no'] = $document['accounting_party_pan'] ?: ($document['pan_no'] ?? null);
    $document['client_since'] = $document['accounting_party_since'] ?: ($document['client_since'] ?? null);
    $document['credit_limit'] = 0;
    $document['opening_balance'] = $outstanding;

    $summary['sales'] += $amount;
    $summary['receivables'] += $outstanding;
    $receivableAging[$bucket] += $outstanding;
    if ($invoiceDate >= $monthStart) {
        $summary['month_sales'] += $amount;
    }
    $summary['paid'] += $paid;
    if ($status === 'Overdue') {
        $summary['overdue'] += $outstanding;
        $summary['overdue_count']++;
    }
}
unset($document);

// ---------------------------------------------------------------------------
// Data: purchase bills and supplier payments (vouchers), collections.
// ---------------------------------------------------------------------------
$purchaseBills = [];
$supplierPayments = [];
if (table_exists('vouchers')) {
    $voucherSql = '
        SELECT v.*, ap.name AS party_name, ap.code AS party_code
        FROM vouchers v
        LEFT JOIN accounting_parties ap ON ap.id = v.party_id
        WHERE v.company_id = :company_id AND v.status = "posted" AND v.voucher_type = :voucher_type
          AND COALESCE(v.voucher_date, DATE(v.created_at)) BETWEEN :from_date AND :to_date
    ';
    $voucherParamsBase = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
    foreach (['purchase' => 'purchaseBills', 'payment' => 'supplierPayments'] as $voucherType => $target) {
        $sql = $voucherSql;
        $voucherParams = $voucherParamsBase + ['voucher_type' => $voucherType];
        if ($searchQuery !== '') {
            $sql .= ' AND (v.voucher_no LIKE :search OR ap.name LIKE :search2 OR v.reference_no LIKE :search3)';
            $like = '%' . $searchQuery . '%';
            $voucherParams += ['search' => $like, 'search2' => $like, 'search3' => $like];
        }
        $sql .= ' ORDER BY COALESCE(v.voucher_date, DATE(v.created_at)) DESC, v.id DESC LIMIT 300';
        $stmt = db()->prepare($sql);
        $stmt->execute($voucherParams);
        ${$target} = $stmt->fetchAll();
    }
}

// Outstanding payables: purchase bills minus supplier payments, allocated per party (FIFO by bill date).
$partyPurchaseTotals = [];
$partyPaymentTotals = [];
foreach ($purchaseBills as $bill) {
    $partyPurchaseTotals[(int) ($bill['party_id'] ?? 0)][] = $bill;
    $summary['purchases'] += (float) $bill['total_amount'];
    $billDate = new DateTimeImmutable((string) ($bill['voucher_date'] ?: date('Y-m-d', strtotime((string) $bill['created_at']))));
    if ($billDate >= $monthStart) {
        $summary['month_purchases'] += (float) $bill['total_amount'];
    }
}
foreach ($supplierPayments as $payment) {
    $partyPaymentTotals[(int) ($payment['party_id'] ?? 0)] = ($partyPaymentTotals[(int) ($payment['party_id'] ?? 0)] ?? 0.0) + (float) $payment['total_amount'];
}
$billOutstanding = [];
foreach ($partyPurchaseTotals as $billPartyId => $bills) {
    usort($bills, static fn (array $a, array $b): int => strcmp((string) ($a['voucher_date'] ?? ''), (string) ($b['voucher_date'] ?? '')));
    $paymentPool = $partyPaymentTotals[$billPartyId] ?? 0.0;
    foreach ($bills as $bill) {
        $billTotal = (float) $bill['total_amount'];
        $applied = min($billTotal, max(0.0, $paymentPool));
        $paymentPool -= $applied;
        $remaining = round($billTotal - $applied, 2);
        $billOutstanding[(int) $bill['id']] = $remaining;
        if ($remaining > 0) {
            $summary['payables'] += $remaining;
            $billDate = new DateTimeImmutable((string) ($bill['voucher_date'] ?: date('Y-m-d', strtotime((string) $bill['created_at']))));
            $daysOld = max(0, (int) $billDate->diff($today)->format('%r%a'));
            $payableBucket = $daysOld <= 30 ? '0 - 30 Days' : ($daysOld <= 60 ? '31 - 60 Days' : ($daysOld <= 90 ? '61 - 90 Days' : '90+ Days'));
            $payableAging[$payableBucket] += $remaining;
        }
    }
}

$collections = [];
if (table_exists('invoice_payment_requests') && table_exists('task_invoices')) {
    $collectionSql = '
        SELECT pr.id, pr.payment_amount, pr.payment_method, pr.payment_received_on, pr.status, pr.notes, pr.requested_on,
               ti.invoice_no, ti.total_amount AS invoice_total,
               COALESCE(ap.name, cp.organization_name, "Client") AS party_name
        FROM invoice_payment_requests pr
        INNER JOIN task_invoices ti ON ti.id = pr.invoice_id
        LEFT JOIN accounting_parties ap ON ap.id = ti.party_id
        LEFT JOIN client_tasks ct ON ct.id = ti.task_id
        LEFT JOIN client_profiles cp ON cp.id = ct.client_id
        WHERE ti.company_id = :company_id AND pr.status IN ("paid", "partial")
          AND COALESCE(pr.payment_received_on, DATE(pr.requested_on)) BETWEEN :from_date AND :to_date
    ';
    $collectionParams = ['company_id' => $companyId, 'from_date' => $fromDate, 'to_date' => $toDate];
    if ($searchQuery !== '') {
        $collectionSql .= ' AND (ti.invoice_no LIKE :search OR ap.name LIKE :search2 OR cp.organization_name LIKE :search3)';
        $like = '%' . $searchQuery . '%';
        $collectionParams += ['search' => $like, 'search2' => $like, 'search3' => $like];
    }
    $collectionSql .= ' ORDER BY COALESCE(pr.payment_received_on, DATE(pr.requested_on)) DESC, pr.id DESC LIMIT 300';
    $stmt = db()->prepare($collectionSql);
    $stmt->execute($collectionParams);
    $collections = $stmt->fetchAll();
}

// ---------------------------------------------------------------------------
// Selected party, its transactions, running ledger.
// ---------------------------------------------------------------------------
$selectedPartyId = (int) ($_GET['party_id'] ?? ($editParty['id'] ?? ($parties[0]['id'] ?? 0)));
$selectedParty = null;
foreach ($parties as $partyRow) {
    if ((int) $partyRow['id'] === $selectedPartyId) {
        $selectedParty = $partyRow;
        break;
    }
}
if (!$selectedParty && $documents !== []) {
    $selectedPartyId = $selectedPartyId > 0 ? $selectedPartyId : (int) ($documents[0]['party_id'] ?? 0);
    foreach ($documents as $documentRow) {
        if ((int) ($documentRow['party_id'] ?? 0) === $selectedPartyId) {
            $selectedParty = [
                'id' => $selectedPartyId,
                'name' => $documentRow['party_name'] ?? 'Client',
                'code' => $documentRow['party_code'] ?? '',
                'party_type' => 'customer',
                'email' => $documentRow['email'] ?? null,
                'phone' => $documentRow['phone'] ?? null,
                'billing_address' => $documentRow['billing_address'] ?? null,
                'pan_no' => $documentRow['pan_no'] ?? null,
                'opening_balance' => $documentRow['outstanding_amount'] ?? 0,
                'opening_balance_type' => 'debit',
                'credit_limit' => 0,
                'notes' => null,
                'created_at' => $documentRow['client_since'] ?? null,
            ];
            break;
        }
    }
}

$partyTransactions = array_values(array_filter($documents, static fn (array $document): bool => (int) ($document['party_id'] ?? 0) === (int) ($selectedParty['id'] ?? 0)));

// Running-balance ledger for the selected party: invoices debit, collections credit,
// purchase bills credit, supplier payments debit.
$partyLedgerRows = [];
if ($selectedParty) {
    foreach ($partyTransactions as $row) {
        $partyLedgerRows[] = ['date' => $row['issued_raw'], 'ref' => $row['voucher_no'], 'label' => $row['display_type'], 'debit' => (float) $row['total_amount'], 'credit' => 0.0];
        if ((float) $row['paid_amount'] > 0) {
            $partyLedgerRows[] = ['date' => $row['issued_raw'], 'ref' => $row['voucher_no'], 'label' => 'Payment received', 'debit' => 0.0, 'credit' => (float) $row['paid_amount']];
        }
    }
    foreach ($purchaseBills as $bill) {
        if ((int) ($bill['party_id'] ?? 0) === (int) $selectedParty['id']) {
            $partyLedgerRows[] = ['date' => (string) ($bill['voucher_date'] ?: date('Y-m-d')), 'ref' => $bill['voucher_no'], 'label' => 'Purchase bill', 'debit' => 0.0, 'credit' => (float) $bill['total_amount']];
        }
    }
    foreach ($supplierPayments as $payment) {
        if ((int) ($payment['party_id'] ?? 0) === (int) $selectedParty['id']) {
            $partyLedgerRows[] = ['date' => (string) ($payment['voucher_date'] ?: date('Y-m-d')), 'ref' => $payment['voucher_no'], 'label' => 'Payment made', 'debit' => (float) $payment['total_amount'], 'credit' => 0.0];
        }
    }
    usort($partyLedgerRows, static fn (array $a, array $b): int => strcmp($a['date'], $b['date']));
    $running = 0.0;
    foreach ($partyLedgerRows as &$ledgerRow) {
        $running += $ledgerRow['debit'] - $ledgerRow['credit'];
        $ledgerRow['balance'] = $running;
    }
    unset($ledgerRow);
}

$collectionEfficiency = ($summary['paid'] + $summary['receivables']) > 0 ? round(($summary['paid'] / ($summary['paid'] + $summary['receivables'])) * 100, 1) : 0.0;

// ---------------------------------------------------------------------------
// Printable party statement (statement=1).
// ---------------------------------------------------------------------------
if (isset($_GET['statement']) && $selectedParty) {
    $statementBalance = end($partyLedgerRows)['balance'] ?? 0.0;
    ?><!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <title>Statement - <?= e($selectedParty['name']) ?></title>
        <style>
            body { font-family: Georgia, 'Times New Roman', serif; margin: 40px; color: #1c2434; }
            h1 { margin: 0 0 4px; font-size: 24px; }
            .muted { color: #64748b; font-size: 13px; }
            table { width: 100%; border-collapse: collapse; margin-top: 24px; font-size: 14px; }
            th, td { border-bottom: 1px solid #e2e8f0; padding: 8px 10px; text-align: left; }
            th { background: #f8fafc; text-transform: uppercase; font-size: 11px; letter-spacing: 0.05em; }
            td.num, th.num { text-align: right; }
            .statement-head { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid #1c2434; padding-bottom: 16px; }
            .total-row td { font-weight: bold; border-top: 2px solid #1c2434; }
            .print-button { margin-top: 24px; padding: 10px 18px; background: #1c2434; color: #fff; border: 0; border-radius: 6px; cursor: pointer; }
            @media print { .print-button { display: none; } body { margin: 12mm; } }
        </style>
    </head>
    <body>
        <div class="statement-head">
            <div>
                <h1><?= e($company['name'] ?? app_name()) ?></h1>
                <div class="muted">Party statement · Generated <?= e(date('d M Y')) ?> · Period <?= e(date('d M Y', strtotime($fromDate))) ?> to <?= e(date('d M Y', strtotime($toDate))) ?></div>
            </div>
            <div>
                <strong><?= e($selectedParty['name']) ?></strong><br>
                <span class="muted"><?= e($selectedParty['code'] ?? '') ?><?= !empty($selectedParty['pan_no']) ? ' · PAN ' . e($selectedParty['pan_no']) : '' ?></span><br>
                <span class="muted"><?= e($selectedParty['billing_address'] ?? '') ?></span>
            </div>
        </div>
        <table>
            <thead>
                <tr><th>Date</th><th>Reference</th><th>Particulars</th><th class="num">Debit</th><th class="num">Credit</th><th class="num">Balance</th></tr>
            </thead>
            <tbody>
                <?php if ($partyLedgerRows === []): ?>
                    <tr><td colspan="6">No transactions in this period.</td></tr>
                <?php endif; ?>
                <?php foreach ($partyLedgerRows as $ledgerRow): ?>
                    <tr>
                        <td><?= e(date('d M Y', strtotime($ledgerRow['date']))) ?></td>
                        <td><?= e($ledgerRow['ref']) ?></td>
                        <td><?= e($ledgerRow['label']) ?></td>
                        <td class="num"><?= $ledgerRow['debit'] > 0 ? e(number_format($ledgerRow['debit'], 2)) : '-' ?></td>
                        <td class="num"><?= $ledgerRow['credit'] > 0 ? e(number_format($ledgerRow['credit'], 2)) : '-' ?></td>
                        <td class="num"><?= e(number_format($ledgerRow['balance'], 2)) ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td colspan="5">Closing balance (<?= $statementBalance >= 0 ? 'receivable from' : 'payable to' ?> party)</td>
                    <td class="num"><?= e(site_currency_symbol()) ?><?= e(number_format(abs($statementBalance), 2)) ?></td>
                </tr>
            </tbody>
        </table>
        <button class="print-button" onclick="window.print()">Print / Save as PDF</button>
    </body>
    </html><?php
    exit;
}

$bodyClass = 'admin-layout accounting-module-page accounting-reference-layout';
include __DIR__ . '/../../app/views/partials/admin_header.php';

$tabLinks = [
    'overview' => ['Overview', parties_page_url(['tab' => null, 'type' => null, 'page' => null])],
    'sales' => ['Sales Invoices', parties_page_url(['tab' => 'sales', 'type' => null, 'page' => null])],
    'purchases' => ['Purchase Bills', parties_page_url(['tab' => 'purchases', 'type' => null, 'page' => null])],
    'customers' => ['Customers', parties_page_url(['tab' => null, 'type' => 'customer', 'page' => null])],
    'suppliers' => ['Suppliers', parties_page_url(['tab' => null, 'type' => 'supplier', 'page' => null])],
    'collections' => ['Collections', parties_page_url(['tab' => 'collections', 'type' => null, 'page' => null])],
    'payments' => ['Payments', parties_page_url(['tab' => 'payments', 'type' => null, 'page' => null])],
    'aging' => ['Aging', parties_page_url(['tab' => 'aging', 'type' => null, 'page' => null])],
];

// Rows for the active tab's table + pagination.
$statusFilteredDocuments = $documents;
if ($statusFilter !== '' && in_array($statusFilter, ['open', 'paid', 'overdue'], true)) {
    $statusFilteredDocuments = array_values(array_filter($documents, static fn (array $document): bool => strtolower((string) $document['display_status']) === $statusFilter));
}
$activeRows = match ($tab) {
    'sales' => $statusFilteredDocuments,
    'purchases' => $purchaseBills,
    'collections' => $collections,
    'payments' => $supplierPayments,
    'customers' => array_values(array_filter($parties, static fn (array $party): bool => in_array((string) $party['party_type'], ['customer', 'both'], true))),
    'suppliers' => $supplierParties,
    default => $statusFilteredDocuments,
};
$totalRows = count($activeRows);
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$pagedRows = array_slice($activeRows, ($page - 1) * $perPage, $perPage);
$showingFrom = $totalRows === 0 ? 0 : (($page - 1) * $perPage) + 1;
$showingTo = min($totalRows, $page * $perPage);
?>

<?php if ($repairErrors !== []): ?>
    <div class="notice error">Accounting module repair warnings: <?= e(implode(' | ', $repairErrors)) ?></div>
<?php endif; ?>

<nav class="reference-tabs" aria-label="Sales and purchase sections">
    <?php foreach ($tabLinks as $tabKey => [$tabLabel, $tabUrl]): ?>
        <a class="<?= $tab === $tabKey ? 'is-active' : '' ?>" href="<?= e($tabUrl) ?>"><?= e($tabLabel) ?></a>
    <?php endforeach; ?>
</nav>


<div class="reference-toolbar">
    <div class="reference-toolbar-actions">
        <a class="button" href="<?= e(url('admin/invoice.php')) ?>"><?= icon('invoices') ?>Create Invoice</a>
        <a class="button secondary" href="<?= e(parties_page_url(['panel' => 'payment'])) ?>"><?= icon('documents') ?>Record Payment</a>
        <a class="button secondary" href="<?= e(parties_page_url(['panel' => 'purchase'])) ?>"><?= icon('documents') ?>Record Purchase</a>
        <a class="button secondary" href="<?= e(parties_page_url(['panel' => 'supplier-payment'])) ?>"><?= icon('services') ?>Pay Supplier</a>
        <a class="button secondary" target="_blank" href="<?= e(parties_page_url(['statement' => 1, 'party_id' => (int) ($selectedParty['id'] ?? 0)])) ?>"><?= icon('documents') ?>Send Statement</a>
        <a class="button secondary" href="<?= e(parties_page_url(['ptab' => 'ledger', 'party_id' => (int) ($selectedParty['id'] ?? 0)])) ?>"><?= icon('accounting') ?>View Party Ledger</a>
        <details class="reference-menu" <?= ($editParty || isset($_GET['create'])) ? 'open' : '' ?>>
            <summary>More Actions</summary>
            <form method="post" class="reference-party-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="save_party">
                <input type="hidden" name="party_id" value="<?= e((int) ($editParty['id'] ?? 0)) ?>">
                <label>Code<input type="text" name="code" maxlength="60" value="<?= e($editParty['code'] ?? '') ?>" placeholder="CUST-001" required></label>
                <label>Name<input type="text" name="name" maxlength="190" value="<?= e($editParty['name'] ?? '') ?>" required></label>
                <label>Type<select name="party_type"><?php foreach ($partyTypes as $type): ?><option value="<?= e($type) ?>" <?= ($editParty['party_type'] ?? 'both') === $type ? 'selected' : '' ?>><?= e(ucfirst($type)) ?></option><?php endforeach; ?></select></label>
                <label>Status<select name="status"><?php foreach ($statuses as $statusOption): ?><option value="<?= e($statusOption) ?>" <?= ($editParty['status'] ?? 'active') === $statusOption ? 'selected' : '' ?>><?= e(ucfirst($statusOption)) ?></option><?php endforeach; ?></select></label>
                <label>Linked ledger<select name="ledger_id"><option value="0">No linked ledger</option><?php foreach ($ledgers as $ledger): ?><option value="<?= e((int) $ledger['id']) ?>" <?= (int) ($editParty['ledger_id'] ?? 0) === (int) $ledger['id'] ? 'selected' : '' ?>><?= e($ledger['code'] . ' - ' . $ledger['name']) ?></option><?php endforeach; ?></select></label>
                <label>PAN / Tax No<input type="text" name="pan_no" maxlength="60" value="<?= e($editParty['pan_no'] ?? '') ?>"></label>
                <label>Email<input type="email" name="email" maxlength="190" value="<?= e($editParty['email'] ?? '') ?>"></label>
                <label>Phone<input type="text" name="phone" maxlength="80" value="<?= e($editParty['phone'] ?? '') ?>"></label>
                <label>Opening balance<input type="number" step="0.01" name="opening_balance" value="<?= e($editParty['opening_balance'] ?? '0.00') ?>"></label>
                <label>Opening side<select name="opening_balance_type"><option value="debit" <?= ($editParty['opening_balance_type'] ?? 'debit') === 'debit' ? 'selected' : '' ?>>Debit</option><option value="credit" <?= ($editParty['opening_balance_type'] ?? '') === 'credit' ? 'selected' : '' ?>>Credit</option></select></label>
                <label>Credit limit<input type="number" step="0.01" name="credit_limit" value="<?= e($editParty['credit_limit'] ?? '0.00') ?>"></label>
                <label class="span-2">Billing address<textarea name="billing_address"><?= e($editParty['billing_address'] ?? '') ?></textarea></label>
                <button type="submit"><?= icon('users') ?>Save party</button>
            </form>
        </details>
    </div>
    <form class="reference-filter-group" method="get" action="<?= e(url('admin/accounting-parties.php')) ?>">
        <?php if ($tab !== 'overview' && !in_array($tab, ['customers', 'suppliers'], true)): ?><input type="hidden" name="tab" value="<?= e($tab) ?>"><?php endif; ?>
        <?php if ($typeFilter !== ''): ?><input type="hidden" name="type" value="<?= e($typeFilter) ?>"><?php endif; ?>
        <label class="reference-date-field"><?= icon('compliance') ?><input type="date" name="from" value="<?= e($fromDate) ?>" aria-label="From date"></label>
        <label class="reference-date-field"><input type="date" name="to" value="<?= e($toDate) ?>" aria-label="To date"></label>
        <select name="status" aria-label="Status filter">
            <option value="">All statuses</option>
            <?php foreach (['open' => 'Open', 'paid' => 'Paid', 'overdue' => 'Overdue'] as $statusValue => $statusLabel): ?>
                <option value="<?= e($statusValue) ?>" <?= $statusFilter === $statusValue ? 'selected' : '' ?>><?= e($statusLabel) ?></option>
            <?php endforeach; ?>
        </select>
        <div class="reference-search"><?= icon('portal') ?><input type="search" name="q" value="<?= e($searchQuery) ?>" placeholder="Search invoice, party, or ref no."></div>
        <button class="button secondary" type="submit"><?= icon('settings') ?>Apply</button>
    </form>
</div>

<?php if ($panel === 'payment'): ?>
    <section id="panel-forms" class="mbw-card reference-panel-card">
        <div class="mbw-card-head"><h2>Record a Customer Payment</h2><div class="mbw-card-tools"><a class="mbw-view-all" href="<?= e(parties_page_url(['panel' => null])) ?>">Close</a></div></div>
        <form method="post" class="reference-party-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="record_payment">
            <label>Open invoice
                <select name="invoice_id" required>
                    <option value="">Select invoice</option>
                    <?php foreach ($documents as $document): ?>
                        <?php if ((float) $document['outstanding_amount'] > 0): ?>
                            <option value="<?= e((int) $document['id']) ?>"><?= e($document['voucher_no'] . ' · ' . $document['party_name'] . ' · due ' . site_currency_symbol() . number_format((float) $document['outstanding_amount'], 2)) ?></option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Amount received<input type="number" step="0.01" min="0.01" name="amount" required></label>
            <label>Received on<input type="date" name="received_on" value="<?= e(date('Y-m-d')) ?>"></label>
            <label>Method<select name="payment_method"><option>Cash</option><option>Bank Transfer</option><option>Cheque</option><option>eSewa / Wallet</option><option>Other</option></select></label>
            <label class="span-2">Notes<textarea name="notes" placeholder="Receipt reference, remarks"></textarea></label>
            <button type="submit"><?= icon('documents') ?>Record Payment</button>
            <a class="button secondary" href="<?= e(parties_page_url(['panel' => null])) ?>">Cancel</a>
        </form>
    </section>
<?php elseif ($panel === 'purchase'): ?>
    <section id="panel-forms" class="mbw-card reference-panel-card">
        <div class="mbw-card-head"><h2>Record a Purchase Bill</h2><div class="mbw-card-tools"><a class="mbw-view-all" href="<?= e(parties_page_url(['panel' => null])) ?>">Close</a></div></div>
        <?php if ($supplierParties === []): ?>
            <p class="muted">Create a supplier first (More Actions → Save party with type Supplier).</p>
        <?php else: ?>
            <form method="post" class="reference-party-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="record_purchase">
                <label>Supplier
                    <select name="party_id" required>
                        <option value="">Select supplier</option>
                        <?php foreach ($supplierParties as $supplier): ?>
                            <option value="<?= e((int) $supplier['id']) ?>"><?= e($supplier['code'] . ' · ' . $supplier['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Expense / asset ledger
                    <select name="expense_ledger_id" required>
                        <option value="">Select ledger</option>
                        <?php foreach (($expenseLedgers !== [] ? $expenseLedgers : $ledgers) as $ledger): ?>
                            <option value="<?= e((int) $ledger['id']) ?>"><?= e($ledger['code'] . ' - ' . $ledger['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Bill no.<input type="text" name="bill_no" maxlength="120" placeholder="Supplier bill reference"></label>
                <label>Bill date<input type="date" name="bill_date" value="<?= e(date('Y-m-d')) ?>"></label>
                <label>Amount (before VAT)<input type="number" step="0.01" min="0.01" name="amount" required></label>
                <label>VAT amount<input type="number" step="0.01" min="0" name="vat_amount" value="0.00"></label>
                <label>Paid via<select name="paid_via"><option value="credit">On credit (payable)</option><option value="cash">Cash / bank now</option></select></label>
                <label class="span-2">Notes<textarea name="notes" placeholder="What was purchased"></textarea></label>
                <button type="submit"><?= icon('documents') ?>Record Purchase</button>
                <a class="button secondary" href="<?= e(parties_page_url(['panel' => null])) ?>">Cancel</a>
            </form>
        <?php endif; ?>
    </section>
<?php elseif ($panel === 'supplier-payment'): ?>
    <section id="panel-forms" class="mbw-card reference-panel-card">
        <div class="mbw-card-head"><h2>Pay a Supplier</h2><div class="mbw-card-tools"><a class="mbw-view-all" href="<?= e(parties_page_url(['panel' => null])) ?>">Close</a></div></div>
        <?php if ($supplierParties === []): ?>
            <p class="muted">Create a supplier first (More Actions → Save party with type Supplier).</p>
        <?php else: ?>
            <form method="post" class="reference-party-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="record_supplier_payment">
                <label>Supplier
                    <select name="party_id" required>
                        <option value="">Select supplier</option>
                        <?php foreach ($supplierParties as $supplier): ?>
                            <option value="<?= e((int) $supplier['id']) ?>"><?= e($supplier['code'] . ' · ' . $supplier['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Amount paid<input type="number" step="0.01" min="0.01" name="amount" required></label>
                <label>Paid on<input type="date" name="paid_on" value="<?= e(date('Y-m-d')) ?>"></label>
                <label>Reference<input type="text" name="reference_no" maxlength="120" placeholder="Cheque / transfer reference"></label>
                <label class="span-2">Notes<textarea name="notes"></textarea></label>
                <button type="submit"><?= icon('services') ?>Record Supplier Payment</button>
                <a class="button secondary" href="<?= e(parties_page_url(['panel' => null])) ?>">Cancel</a>
            </form>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php
$statusPillTone = static function (string $status): string {
    return match (strtolower($status)) {
        'paid', 'completed', 'active' => 'green',
        'open', 'pending', 'partial', 'partially paid' => 'amber',
        'overdue', 'cancelled', 'inactive' => 'red',
        'draft' => 'blue',
        default => 'gray',
    };
};
$tabHeadings = [
    'overview' => 'Sales Invoices',
    'sales' => 'Sales Invoices',
    'purchases' => 'Purchase Bills',
    'collections' => 'Collections Received',
    'payments' => 'Supplier Payments',
    'customers' => 'Customers',
    'suppliers' => 'Suppliers',
    'aging' => 'Aging',
];
?>
<?php if ($tab !== 'aging'): ?>
<div class="reference-workspace">
    <main id="documents" class="mbw-card reference-table-card">
        <div class="mbw-card-head">
            <h2><?= e($tabHeadings[$tab] ?? 'Documents') ?></h2>
            <div class="mbw-card-tools"><a class="mbw-view-all" href="<?= e(parties_page_url(['tab' => 'aging', 'type' => null, 'page' => null])) ?>">Aging</a></div>
        </div>
        <?php if (in_array($tab, ['overview', 'sales'], true)): ?>
            <div style="overflow-x:auto">
            <table class="reference-table">
                <thead>
                    <tr>
                        <th><input type="checkbox" aria-label="Select all"></th>
                        <th>Invoice No.</th><th>Party Name</th><th>Type</th><th>Invoice Date</th><th>Due Date</th><th class="is-numeric">Amount</th><th class="is-numeric">Paid</th><th class="is-numeric">Outstanding</th><th>Status</th><th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($pagedRows === []): ?><tr><td colspan="11">No invoices match the current filters.</td></tr><?php endif; ?>
                    <?php foreach ($pagedRows as $document): ?>
                        <tr>
                            <td><input type="checkbox" aria-label="Select <?= e($document['voucher_no']) ?>"></td>
                            <td><a class="reference-link" href="<?= e(parties_page_url(['party_id' => (int) ($document['party_id'] ?? 0)])) ?>"><?= e($document['voucher_no']) ?></a></td>
                            <td><?= e($document['party_name'] ?: 'Direct entry') ?></td>
                            <td><span class="mbw-pill tone-blue"><?= e($document['display_type']) ?></span></td>
                            <td><?= e($document['invoice_date']) ?></td>
                            <td class="<?= $document['display_status'] === 'Overdue' ? 'text-danger' : '' ?>"><?= e($document['due_date']) ?></td>
                            <td class="is-numeric"><?= e(site_currency_symbol()) ?><?= e(number_format((float) $document['total_amount'], 2)) ?></td>
                            <td class="is-numeric"><?= e(site_currency_symbol()) ?><?= e(number_format((float) $document['paid_amount'], 2)) ?></td>
                            <td class="is-numeric"><?= e(site_currency_symbol()) ?><?= e(number_format((float) $document['outstanding_amount'], 2)) ?></td>
                            <td><span class="mbw-pill tone-<?= e($statusPillTone((string) $document['display_status'])) ?>"><?= e($document['display_status']) ?></span></td>
                            <td><div class="reference-row-actions"><a href="<?= e(parties_page_url(['party_id' => (int) ($document['party_id'] ?? 0), 'ptab' => 'ledger'])) ?>" title="View ledger"><?= icon('portal') ?></a><a href="<?= e(url('admin/export-invoice.php?id=' . (int) $document['id'])) ?>" title="Export invoice"><?= icon('documents') ?></a></div></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php elseif ($tab === 'purchases'): ?>
            <div style="overflow-x:auto">
            <table class="reference-table">
                <thead>
                    <tr><th>Bill No.</th><th>Supplier</th><th>Bill Date</th><th>Reference</th><th class="is-numeric">Amount</th><th class="is-numeric">Outstanding</th><th>Narration</th></tr>
                </thead>
                <tbody>
                    <?php if ($pagedRows === []): ?><tr><td colspan="7">No purchase bills recorded yet. Use Record Purchase to add one.</td></tr><?php endif; ?>
                    <?php foreach ($pagedRows as $bill): ?>
                        <tr>
                            <td><span class="reference-link"><?= e($bill['voucher_no']) ?></span></td>
                            <td><?= e($bill['party_name'] ?: 'Direct entry') ?></td>
                            <td><?= e(date('d M Y', strtotime((string) ($bill['voucher_date'] ?: $bill['created_at'])))) ?></td>
                            <td><?= e($bill['reference_no'] ?? '-') ?></td>
                            <td class="is-numeric"><?= e(site_currency_symbol()) ?><?= e(number_format((float) $bill['total_amount'], 2)) ?></td>
                            <td class="is-numeric <?= ($billOutstanding[(int) $bill['id']] ?? 0) > 0 ? 'text-danger' : '' ?>"><?= e(site_currency_symbol()) ?><?= e(number_format((float) ($billOutstanding[(int) $bill['id']] ?? 0), 2)) ?></td>
                            <td><?= e($bill['narration'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php elseif ($tab === 'collections'): ?>
            <div style="overflow-x:auto">
            <table class="reference-table">
                <thead>
                    <tr><th>Received On</th><th>Invoice</th><th>Party</th><th>Method</th><th class="is-numeric">Amount</th><th>Status</th><th>Notes</th></tr>
                </thead>
                <tbody>
                    <?php if ($pagedRows === []): ?><tr><td colspan="7">No payments received in this period.</td></tr><?php endif; ?>
                    <?php foreach ($pagedRows as $collection): ?>
                        <tr>
                            <td><?= e(date('d M Y', strtotime((string) ($collection['payment_received_on'] ?: $collection['requested_on'])))) ?></td>
                            <td><span class="reference-link"><?= e($collection['invoice_no']) ?></span></td>
                            <td><?= e($collection['party_name']) ?></td>
                            <td><?= e($collection['payment_method'] ?? '-') ?></td>
                            <td class="is-numeric"><?= e(site_currency_symbol()) ?><?= e(number_format((float) $collection['payment_amount'], 2)) ?></td>
                            <td><span class="mbw-pill tone-<?= e((string) $collection['status'] === 'paid' ? 'green' : 'amber') ?>"><?= e((string) $collection['status'] === 'partial' ? 'Partially Paid' : ucfirst((string) $collection['status'])) ?></span></td>
                            <td><?= e($collection['notes'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php elseif ($tab === 'payments'): ?>
            <div style="overflow-x:auto">
            <table class="reference-table">
                <thead>
                    <tr><th>Paid On</th><th>Voucher No.</th><th>Supplier</th><th>Reference</th><th class="is-numeric">Amount</th><th>Narration</th></tr>
                </thead>
                <tbody>
                    <?php if ($pagedRows === []): ?><tr><td colspan="6">No supplier payments in this period. Use Pay Supplier to add one.</td></tr><?php endif; ?>
                    <?php foreach ($pagedRows as $payment): ?>
                        <tr>
                            <td><?= e(date('d M Y', strtotime((string) ($payment['voucher_date'] ?: $payment['created_at'])))) ?></td>
                            <td><span class="reference-link"><?= e($payment['voucher_no']) ?></span></td>
                            <td><?= e($payment['party_name'] ?: 'Direct entry') ?></td>
                            <td><?= e($payment['reference_no'] ?? '-') ?></td>
                            <td class="is-numeric"><?= e(site_currency_symbol()) ?><?= e(number_format((float) $payment['total_amount'], 2)) ?></td>
                            <td><?= e($payment['narration'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php else: ?>
            <div style="overflow-x:auto">
            <table class="reference-table">
                <thead>
                    <tr><th>Code</th><th>Name</th><th>Type</th><th>Email</th><th>Phone</th><th class="is-numeric">Credit Limit</th><th>Status</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    <?php if ($pagedRows === []): ?><tr><td colspan="8">No <?= e($tab === 'suppliers' ? 'suppliers' : 'customers') ?> yet. Use More Actions to create one.</td></tr><?php endif; ?>
                    <?php foreach ($pagedRows as $party): ?>
                        <tr>
                            <td><span class="reference-link"><?= e($party['code']) ?></span></td>
                            <td><a class="reference-link" href="<?= e(parties_page_url(['party_id' => (int) $party['id']])) ?>"><?= e($party['name']) ?></a></td>
                            <td><span class="mbw-pill tone-blue"><?= e(ucfirst((string) $party['party_type'])) ?></span></td>
                            <td><?= e($party['email'] ?? '-') ?></td>
                            <td><?= e($party['phone'] ?? '-') ?></td>
                            <td class="is-numeric"><?= e(site_currency_symbol()) ?><?= e(number_format((float) $party['credit_limit'], 2)) ?></td>
                            <td><span class="mbw-pill tone-<?= e((string) $party['status'] === 'active' ? 'green' : 'red') ?>"><?= e(ucfirst((string) $party['status'])) ?></span></td>
                            <td>
                                <div class="reference-row-actions">
                                    <a href="<?= e(parties_page_url(['edit_id' => (int) $party['id']])) ?>" title="Edit"><?= icon('settings') ?></a>
                                    <form method="post" class="inline-form">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="toggle_party">
                                        <input type="hidden" name="party_id" value="<?= e((int) $party['id']) ?>">
                                        <button type="submit" title="Toggle status"><?= icon('theme') ?></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
        <div class="reference-pagination">
            <span>Showing <?= e((string) $showingFrom) ?> to <?= e((string) $showingTo) ?> of <?= e((string) $totalRows) ?> entries</span>
            <div>
                <?php foreach ([10, 25, 50] as $perPageOption): ?>
                    <a class="<?= $perPage === $perPageOption ? 'is-active' : '' ?>" href="<?= e(parties_page_url(['per_page' => $perPageOption, 'page' => null])) ?>"><?= e((string) $perPageOption) ?>/page</a>
                <?php endforeach; ?>
                <?php for ($pageNumber = 1; $pageNumber <= min($totalPages, 7); $pageNumber++): ?>
                    <a class="<?= $page === $pageNumber ? 'is-active' : '' ?>" href="<?= e(parties_page_url(['page' => $pageNumber])) ?>"><?= e((string) $pageNumber) ?></a>
                <?php endfor; ?>
                <?php if ($totalPages > 7): ?><span>…</span><a href="<?= e(parties_page_url(['page' => $totalPages])) ?>"><?= e((string) $totalPages) ?></a><?php endif; ?>
            </div>
        </div>
    </main>

    <aside id="party-panel" class="reference-party-panel">
        <?php if ($selectedParty): ?>
            <div class="party-panel-head">
                <div>
                    <h3><?= e($selectedParty['name']) ?></h3>
                    <span class="mbw-pill tone-blue"><?= e(ucfirst($selectedParty['party_type'])) ?></span>
                    <small>Since <?= e(date('M Y', strtotime((string) ($selectedParty['created_at'] ?? 'now')))) ?></small>
                </div>
                <a href="<?= e(url('admin/accounting-parties.php')) ?>" aria-label="Close">x</a>
            </div>
            <nav class="party-panel-tabs">
                <?php foreach (['profile' => 'Profile', 'ledger' => 'Ledger', 'documents' => 'Documents', 'notes' => 'Notes'] as $ptabKey => $ptabLabel): ?>
                    <a class="<?= $ptab === $ptabKey ? 'is-active' : '' ?>" href="<?= e(parties_page_url(['ptab' => $ptabKey, 'party_id' => (int) $selectedParty['id']])) ?>"><?= e($ptabLabel) ?></a>
                <?php endforeach; ?>
            </nav>
            <?php if ($ptab === 'profile'): ?>
                <section>
                    <h4>Contact Details <a href="<?= e(parties_page_url(['edit_id' => (int) $selectedParty['id']])) ?>">Edit</a></h4>
                    <dl><dt>Contact Person</dt><dd><?= e($selectedParty['name']) ?></dd><dt>Email</dt><dd><?= e($selectedParty['email'] ?? '-') ?></dd><dt>Phone</dt><dd><?= e($selectedParty['phone'] ?? '-') ?></dd><dt>Address</dt><dd><?= e($selectedParty['billing_address'] ?? '-') ?></dd></dl>
                </section>
                <section>
                    <h4>Financial Details</h4>
                    <dl><dt>Credit Limit</dt><dd><?= e(site_currency_symbol()) ?><?= e(number_format((float) $selectedParty['credit_limit'], 2)) ?></dd><dt>Current Balance</dt><dd class="text-danger"><?= e(site_currency_symbol()) ?><?= e(number_format((float) (end($partyLedgerRows)['balance'] ?? $selectedParty['opening_balance']), 2)) ?></dd><dt>Tax / VAT No.</dt><dd><?= e($selectedParty['pan_no'] ?? '-') ?></dd><dt>PAN No.</dt><dd><?= e($selectedParty['pan_no'] ?? '-') ?></dd></dl>
                </section>
                <section>
                    <h4 id="party-ledger">Account Summary <a href="<?= e(parties_page_url(['ptab' => 'ledger', 'party_id' => (int) $selectedParty['id']])) ?>">View Ledger</a></h4>
                    <dl><dt>Total Sales</dt><dd><?= e(site_currency_symbol()) ?><?= e(number_format(array_sum(array_map(static fn (array $row): float => (float) $row['total_amount'], $partyTransactions)), 2)) ?></dd><dt>Payments Received</dt><dd><?= e(site_currency_symbol()) ?><?= e(number_format(array_sum(array_map(static fn (array $row): float => (float) $row['paid_amount'], $partyTransactions)), 2)) ?></dd><dt>Outstanding</dt><dd class="text-danger"><?= e(site_currency_symbol()) ?><?= e(number_format(array_sum(array_map(static fn (array $row): float => (float) $row['outstanding_amount'], $partyTransactions)), 2)) ?></dd><dt>Last Invoice</dt><dd><?= e($partyTransactions[0]['invoice_date'] ?? '-') ?></dd></dl>
                </section>
            <?php elseif ($ptab === 'ledger'): ?>
                <section>
                    <h4 id="party-ledger">Party Ledger <a target="_blank" href="<?= e(parties_page_url(['statement' => 1, 'party_id' => (int) $selectedParty['id']])) ?>">Print</a></h4>
                    <?php if ($partyLedgerRows === []): ?>
                        <p class="muted">No transactions for this party in the selected period.</p>
                    <?php else: ?>
                        <table class="party-ledger-table">
                            <thead><tr><th>Date</th><th>Ref</th><th>Dr</th><th>Cr</th><th>Balance</th></tr></thead>
                            <tbody>
                                <?php foreach ($partyLedgerRows as $ledgerRow): ?>
                                    <tr>
                                        <td><?= e(date('d M', strtotime($ledgerRow['date']))) ?></td>
                                        <td title="<?= e($ledgerRow['label']) ?>"><?= e($ledgerRow['ref']) ?></td>
                                        <td><?= $ledgerRow['debit'] > 0 ? e(number_format($ledgerRow['debit'], 0)) : '-' ?></td>
                                        <td><?= $ledgerRow['credit'] > 0 ? e(number_format($ledgerRow['credit'], 0)) : '-' ?></td>
                                        <td><?= e(number_format($ledgerRow['balance'], 0)) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </section>
            <?php elseif ($ptab === 'documents'): ?>
                <section>
                    <h4>Party Documents</h4>
                    <?php if ($partyTransactions === []): ?>
                        <p class="muted">No invoices for this party yet.</p>
                    <?php else: ?>
                        <ul class="party-document-list">
                            <?php foreach (array_slice($partyTransactions, 0, 12) as $row): ?>
                                <li>
                                    <a class="reference-link" href="<?= e(url('admin/export-invoice.php?id=' . (int) $row['id'])) ?>"><?= e($row['voucher_no']) ?></a>
                                    <span><?= e($row['invoice_date']) ?> · <?= e(site_currency_symbol()) ?><?= e(number_format((float) $row['total_amount'], 2)) ?></span>
                                    <span class="mbw-pill tone-<?= e($statusPillTone((string) $row['display_status'])) ?>"><?= e($row['display_status']) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>
            <?php else: ?>
                <section>
                    <h4>Notes</h4>
                    <form method="post" class="party-notes-form">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="save_note">
                        <input type="hidden" name="party_id" value="<?= e((int) $selectedParty['id']) ?>">
                        <textarea name="notes" rows="6" placeholder="Internal notes about this party"><?= e($selectedParty['notes'] ?? '') ?></textarea>
                        <button type="submit">Save Note</button>
                    </form>
                </section>
            <?php endif; ?>
            <a class="button" href="<?= e(parties_page_url(['panel' => 'payment', 'party_id' => (int) $selectedParty['id']])) ?>"><?= icon('documents') ?>Record Payment</a>
            <a class="button secondary" target="_blank" href="<?= e(parties_page_url(['statement' => 1, 'party_id' => (int) $selectedParty['id']])) ?>"><?= icon('documents') ?>Send Statement</a>
        <?php else: ?>
            <section><h3>No party selected</h3><p class="muted">Create a customer or supplier to populate this panel.</p></section>
        <?php endif; ?>
    </aside>
</div>
<?php endif; ?>

<?php if ($tab === 'aging'): ?>
<div id="aging" class="reference-aging-grid">
    <?php foreach ([['Receivables Aging', $receivableAging, $summary['receivables']], ['Payables Aging', $payableAging, $summary['payables']]] as $agingCard): ?>
        <section class="mbw-card aging-card">
            <div class="mbw-card-head">
                <h2><?= e($agingCard[0]) ?></h2>
                <div class="mbw-card-tools"><span style="font-size:12px;color:var(--mbw-muted)">as of <?= e(date('d M Y')) ?></span></div>
            </div>
            <div class="aging-body">
                <?php
                $agingColors = ['0 - 30 Days' => '#22a861', '31 - 60 Days' => '#f2b705', '61 - 90 Days' => '#f57520', '90+ Days' => '#e83d3d'];
                $agingSum = array_sum(array_map('floatval', $agingCard[1]));
                if ($agingSum > 0) {
                    $agingStops = [];
                    $agingStart = 0.0;
                    foreach ($agingCard[1] as $agingLabel => $agingAmount) {
                        $agingEnd = $agingStart + ((float) $agingAmount / $agingSum) * 100;
                        $agingStops[] = ($agingColors[$agingLabel] ?? '#94a3b8') . ' ' . number_format($agingStart, 2, '.', '') . '% ' . number_format($agingEnd, 2, '.', '') . '%';
                        $agingStart = $agingEnd;
                    }
                    $agingStyle = 'background:conic-gradient(' . implode(',', $agingStops) . ')';
                } else {
                    $agingStyle = 'background:var(--mbw-gray-soft)';
                }
                ?>
                <div class="donut-chart" style="<?= e($agingStyle) ?>"></div>
                <div class="aging-legend">
                    <?php foreach ($agingCard[1] as $label => $amount): ?>
                        <div><span style="background:<?= e($agingColors[$label] ?? '#94a3b8') ?>"></span><?= e($label) ?><strong><?= e(site_currency_symbol()) ?><?= e(number_format((float) $amount, 2)) ?></strong></div>
                    <?php endforeach; ?>
                </div>
                <div class="aging-total"><small>Total</small><strong><?= e(site_currency_symbol()) ?><?= e(number_format((float) $agingCard[2], 2)) ?></strong><small>Overdue (&gt; 30 Days)</small><em><?= e(site_currency_symbol()) ?><?= e(number_format((float) (($agingCard[1]['31 - 60 Days'] ?? 0) + ($agingCard[1]['61 - 90 Days'] ?? 0) + ($agingCard[1]['90+ Days'] ?? 0)), 2)) ?></em></div>
            </div>
        </section>
    <?php endforeach; ?>
</div>
<section class="mbw-card">
    <div class="mbw-card-head"><h2>Detailed Aging</h2><div class="mbw-card-tools"><a class="mbw-view-all" href="<?= e(url('admin/reports-center.php?report=party-wise')) ?>">Receivables Aging Report</a></div></div>
    <p style="margin:0;color:var(--mbw-muted);font-size:13px">Open the Receivables Aging Report for customer-wise buckets, credit limits, and overdue percentages.</p>
</section>
<?php endif; ?>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
