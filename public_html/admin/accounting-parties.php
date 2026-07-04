<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/accounting_module_repair.php';

require_staff_or_admin();
require_company_context();

$repairErrors = accounting_module_repair_database();
$pageTitle = 'Accounting Parties';
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
}

$editParty = null;
$editId = (int) ($_GET['edit_id'] ?? 0);
if ($editId > 0) {
    $stmt = db()->prepare('SELECT * FROM accounting_parties WHERE id = :id AND company_id = :company_id LIMIT 1');
    $stmt->execute(['id' => $editId, 'company_id' => $companyId]);
    $editParty = $stmt->fetch() ?: null;
}

$ledgerStmt = db()->prepare("SELECT id, code, name FROM ledgers WHERE company_id = :company_id AND status = 'active' ORDER BY code ASC");
$ledgerStmt->execute(['company_id' => $companyId]);
$ledgers = $ledgerStmt->fetchAll();

$partyStmt = db()->prepare('
    SELECT p.*, l.code AS ledger_code, l.name AS ledger_name
    FROM accounting_parties p
    LEFT JOIN ledgers l ON l.id = p.ledger_id
    WHERE p.company_id = :company_id
    ORDER BY p.status ASC, p.name ASC
');
$partyStmt->execute(['company_id' => $companyId]);
$parties = $partyStmt->fetchAll();

$selectedPartyId = (int) ($_GET['party_id'] ?? ($editParty['id'] ?? ($parties[0]['id'] ?? 0)));
$selectedParty = null;
foreach ($parties as $partyRow) {
    if ((int) $partyRow['id'] === $selectedPartyId) {
        $selectedParty = $partyRow;
        break;
    }
}

$documents = [];
if (table_exists('task_invoices')) {
    $documentStmt = db()->prepare('
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
        ORDER BY ti.issued_on DESC, ti.id DESC
        LIMIT 120
    ');
    $documentStmt->execute(['company_id' => $companyId]);
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
];
$receivableAging = ['0 - 30 Days' => 0.0, '31 - 60 Days' => 0.0, '61 - 90 Days' => 0.0, '90+ Days' => 0.0];
$payableAging = $receivableAging;
$today = new DateTimeImmutable('today');

foreach ($documents as $documentIndex => &$document) {
    $voucherType = 'sales';
    $dateText = (string) ($document['issued_on'] ?: date('Y-m-d', strtotime((string) $document['posted_at'])));
    $invoiceDate = new DateTimeImmutable($dateText);
    $dueDate = !empty($document['due_on']) ? new DateTimeImmutable((string) $document['due_on']) : $invoiceDate->modify('+15 days');
    $amount = (float) $document['total_amount'];
    $isPurchase = false;
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

    if ($isPurchase) {
        $summary['purchases'] += $amount;
        $summary['payables'] += $outstanding;
        $payableAging[$bucket] += $outstanding;
    } else {
        $summary['sales'] += $amount;
        $summary['receivables'] += $outstanding;
        $receivableAging[$bucket] += $outstanding;
    }
    $summary['paid'] += $paid;
    if ($status === 'Overdue') {
        $summary['overdue'] += $outstanding;
        $summary['overdue_count']++;
    }
}
unset($document);

if (!$selectedParty && $documents !== []) {
    $selectedPartyId = $selectedPartyId > 0 ? $selectedPartyId : (int) ($documents[0]['party_id'] ?? 0);
    foreach ($parties as $partyRow) {
        if ((int) $partyRow['id'] === $selectedPartyId) {
            $selectedParty = $partyRow;
            break;
        }
    }
    if (!$selectedParty && $selectedPartyId > 0) {
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
                    'created_at' => $documentRow['client_since'] ?? null,
                ];
                break;
            }
        }
    }
}

$partyTransactions = array_values(array_filter($documents, static fn (array $document): bool => (int) ($document['party_id'] ?? 0) === (int) ($selectedParty['id'] ?? 0)));
$collectionEfficiency = ($summary['paid'] + $summary['receivables']) > 0 ? round(($summary['paid'] / ($summary['paid'] + $summary['receivables'])) * 100, 1) : 0.0;
$bodyClass = 'admin-layout accounting-module-page accounting-reference-layout';
include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
<div class="reference-head">
    <div>
        <h2>Sales, Purchases &amp; Party Management</h2>
        <p>Manage invoices, bills, parties, collections, payments and track outstanding balances.</p>
    </div>
    <div class="reference-head-actions">
        <a class="button secondary" href="<?= e(url('admin/accounting-parties.php?statement=1')) ?>"><?= icon('documents') ?>Send Statement</a>
        <a class="button secondary" href="<?= e(url('admin/accounting-parties.php?panel=ledger#party-ledger')) ?>"><?= icon('accounting') ?>View Party Ledger</a>
        <a class="button" href="<?= e(url('admin/invoice.php')) ?>"><?= icon('invoices') ?>Create Invoice</a>
    </div>
</div>

<?php if ($repairErrors !== []): ?>
    <div class="notice error">Accounting module repair warnings: <?= e(implode(' | ', $repairErrors)) ?></div>
<?php endif; ?>

<nav class="reference-tabs" aria-label="Sales and purchase sections">
    <a class="is-active" href="<?= e(url('admin/accounting-parties.php')) ?>">Overview</a>
    <a href="<?= e(url('admin/accounting-parties.php?tab=sales#documents')) ?>">Sales Invoices</a>
    <a href="<?= e(url('admin/accounting-parties.php?tab=purchases#documents')) ?>">Purchase Bills</a>
    <a href="<?= e(url('admin/accounting-parties.php?type=customer')) ?>">Customers</a>
    <a href="<?= e(url('admin/accounting-parties.php?type=supplier')) ?>">Suppliers</a>
    <a href="<?= e(url('admin/accounting-parties.php?tab=collections#documents')) ?>">Collections</a>
    <a href="<?= e(url('admin/accounting-parties.php?tab=payments#documents')) ?>">Payments</a>
    <a href="#aging">Aging</a>
</nav>

<div class="reference-stat-grid">
    <div class="reference-stat accent-green"><span><?= icon('users') ?></span><small>Outstanding Receivables</small><strong><?= e(site_currency_symbol()) ?><?= e(number_format($summary['receivables'], 2)) ?></strong><em>up from live vouchers</em></div>
    <div class="reference-stat accent-red"><span><?= icon('services') ?></span><small>Outstanding Payables</small><strong><?= e(site_currency_symbol()) ?><?= e(number_format($summary['payables'], 2)) ?></strong><em>supplier bills pending</em></div>
    <div class="reference-stat accent-blue"><span><?= icon('invoices') ?></span><small>This Month Sales</small><strong><?= e(site_currency_symbol()) ?><?= e(number_format($summary['sales'], 2)) ?></strong><em>sales invoices</em></div>
    <div class="reference-stat accent-purple"><span><?= icon('documents') ?></span><small>This Month Purchases</small><strong><?= e(site_currency_symbol()) ?><?= e(number_format($summary['purchases'], 2)) ?></strong><em>purchase bills</em></div>
    <div class="reference-stat accent-orange"><span><?= icon('tasks') ?></span><small>Overdue Invoices</small><strong><?= e(site_currency_symbol()) ?><?= e(number_format($summary['overdue'], 2)) ?></strong><em><?= e((string) $summary['overdue_count']) ?> invoices</em></div>
    <div class="reference-stat accent-green"><span><?= icon('dashboard') ?></span><small>Collection Efficiency</small><strong><?= e(number_format($collectionEfficiency, 1)) ?>%</strong><em>paid vs outstanding</em></div>
</div>

<div class="reference-toolbar">
    <div class="reference-toolbar-actions">
        <a class="button" href="<?= e(url('admin/invoice.php')) ?>"><?= icon('invoices') ?>Create Invoice</a>
        <a class="button secondary" href="<?= e(url('admin/accounting-parties.php?panel=payment#documents')) ?>"><?= icon('documents') ?>Record Payment</a>
        <a class="button secondary" href="<?= e(url('admin/accounting-parties.php?panel=purchase#documents')) ?>"><?= icon('documents') ?>Record Purchase</a>
        <details class="reference-menu" <?= ($editParty || isset($_GET['create'])) ? 'open' : '' ?>>
            <summary>More Actions</summary>
            <form method="post" class="reference-party-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="save_party">
                <input type="hidden" name="party_id" value="<?= e((int) ($editParty['id'] ?? 0)) ?>">
                <label>Code<input type="text" name="code" maxlength="60" value="<?= e($editParty['code'] ?? '') ?>" placeholder="CUST-001" required></label>
                <label>Name<input type="text" name="name" maxlength="190" value="<?= e($editParty['name'] ?? '') ?>" required></label>
                <label>Type<select name="party_type"><?php foreach ($partyTypes as $type): ?><option value="<?= e($type) ?>" <?= ($editParty['party_type'] ?? 'both') === $type ? 'selected' : '' ?>><?= e(ucfirst($type)) ?></option><?php endforeach; ?></select></label>
                <label>Status<select name="status"><?php foreach ($statuses as $status): ?><option value="<?= e($status) ?>" <?= ($editParty['status'] ?? 'active') === $status ? 'selected' : '' ?>><?= e(ucfirst($status)) ?></option><?php endforeach; ?></select></label>
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
    <div class="reference-filter-group">
        <button class="button secondary" type="button"><?= icon('compliance') ?>01 Jan 2026 - 31 Dec 2026</button>
        <button class="button secondary" type="button"><?= icon('settings') ?>Filters</button>
        <div class="reference-search"><?= icon('portal') ?><span>Search invoice, party, or ref no.</span></div>
    </div>
</div>

<div class="reference-workspace">
    <main id="documents" class="reference-table-card">
        <table class="reference-table">
            <thead>
                <tr>
                    <th><input type="checkbox" aria-label="Select all"></th>
                    <th>Invoice No.</th><th>Party Name</th><th>Type</th><th>Invoice Date</th><th>Due Date</th><th>Amount</th><th>Paid</th><th>Outstanding</th><th>Status</th><th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($documents === []): ?><tr><td colspan="11">No invoices, bills, or parties are available yet.</td></tr><?php endif; ?>
                <?php foreach (array_slice($documents, 0, 10) as $document): ?>
                    <?php
                    $statusClass = strtolower((string) $document['display_status']);
                    $typeClass = str_contains(strtolower((string) $document['display_type']), 'purchase') ? 'purchase' : (str_contains(strtolower((string) $document['display_type']), 'service') ? 'service' : 'sales');
                    ?>
                    <tr>
                        <td><input type="checkbox" aria-label="Select <?= e($document['voucher_no']) ?>"></td>
                        <td><a class="reference-link" href="<?= e(url('admin/accounting-parties.php?party_id=' . (int) ($document['party_id'] ?? 0))) ?>"><?= e($document['voucher_no']) ?></a></td>
                        <td><?= e($document['party_name'] ?: 'Direct entry') ?></td>
                        <td><span class="reference-pill type-<?= e($typeClass) ?>"><?= e($document['display_type']) ?></span></td>
                        <td><?= e($document['invoice_date']) ?></td>
                        <td class="<?= $document['display_status'] === 'Overdue' ? 'text-danger' : '' ?>"><?= e($document['due_date']) ?></td>
                        <td><?= e(site_currency_symbol()) ?><?= e(number_format((float) $document['total_amount'], 2)) ?></td>
                        <td><?= e(site_currency_symbol()) ?><?= e(number_format((float) $document['paid_amount'], 2)) ?></td>
                        <td><?= e(site_currency_symbol()) ?><?= e(number_format((float) $document['outstanding_amount'], 2)) ?></td>
                        <td><span class="reference-pill status-<?= e($statusClass) ?>"><?= e($document['display_status']) ?></span></td>
                        <td><div class="reference-row-actions"><a href="<?= e(url('admin/accounting-parties.php?party_id=' . (int) ($document['party_id'] ?? 0) . '#party-ledger')) ?>" title="View"><?= icon('portal') ?></a><a href="<?= e(url('admin/accounting-parties.php?panel=actions&party_id=' . (int) ($document['party_id'] ?? 0) . '#documents')) ?>" title="More"><?= icon('settings') ?></a></div></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div class="reference-pagination">
            <span>Showing 1 to <?= e((string) min(10, count($documents))) ?> of <?= e((string) count($documents)) ?> entries</span>
            <div><button>10 per page</button><button>1</button><button>2</button><button>3</button><button>...</button></div>
        </div>
    </main>

    <aside class="reference-party-panel">
        <?php if ($selectedParty): ?>
            <div class="party-panel-head">
                <div>
                    <h3><?= e($selectedParty['name']) ?></h3>
                    <span class="reference-pill type-sales"><?= e(ucfirst($selectedParty['party_type'])) ?></span>
                    <small>Since <?= e(date('M Y', strtotime((string) ($selectedParty['created_at'] ?? 'now')))) ?></small>
                </div>
                <a href="<?= e(url('admin/accounting-parties.php')) ?>" aria-label="Close">x</a>
            </div>
            <nav class="party-panel-tabs"><a class="is-active">Profile</a><a>Ledger</a><a>Documents</a><a>Notes</a></nav>
            <section>
                <h4>Contact Details <a href="<?= e(url('admin/accounting-parties.php?edit_id=' . (int) $selectedParty['id'])) ?>">Edit</a></h4>
                <dl><dt>Contact Person</dt><dd><?= e($selectedParty['name']) ?></dd><dt>Email</dt><dd><?= e($selectedParty['email'] ?? '-') ?></dd><dt>Phone</dt><dd><?= e($selectedParty['phone'] ?? '-') ?></dd><dt>Address</dt><dd><?= e($selectedParty['billing_address'] ?? '-') ?></dd></dl>
            </section>
            <section>
                <h4>Financial Details</h4>
                <dl><dt>Credit Limit</dt><dd><?= e(site_currency_symbol()) ?><?= e(number_format((float) $selectedParty['credit_limit'], 2)) ?></dd><dt>Current Balance</dt><dd class="text-danger"><?= e(site_currency_symbol()) ?><?= e(number_format((float) $selectedParty['opening_balance'], 2)) ?></dd><dt>Tax / VAT No.</dt><dd><?= e($selectedParty['pan_no'] ?? '-') ?></dd><dt>PAN No.</dt><dd><?= e($selectedParty['pan_no'] ?? '-') ?></dd></dl>
            </section>
            <section>
                <h4 id="party-ledger">Account Summary <a href="<?= e(url('admin/accounting-parties.php?party_id=' . (int) $selectedParty['id'] . '#party-ledger')) ?>">View Ledger</a></h4>
                <dl><dt>Total Sales</dt><dd><?= e(site_currency_symbol()) ?><?= e(number_format(array_sum(array_map(static fn (array $row): float => (float) $row['total_amount'], $partyTransactions)), 2)) ?></dd><dt>Payments Received</dt><dd><?= e(site_currency_symbol()) ?><?= e(number_format(array_sum(array_map(static fn (array $row): float => (float) $row['paid_amount'], $partyTransactions)), 2)) ?></dd><dt>Outstanding</dt><dd class="text-danger"><?= e(site_currency_symbol()) ?><?= e(number_format(array_sum(array_map(static fn (array $row): float => (float) $row['outstanding_amount'], $partyTransactions)), 2)) ?></dd><dt>Last Invoice</dt><dd><?= e($partyTransactions[0]['invoice_date'] ?? '-') ?></dd></dl>
            </section>
            <a class="button" href="<?= e(url('admin/accounting-parties.php?panel=payment&party_id=' . (int) $selectedParty['id'] . '#documents')) ?>"><?= icon('documents') ?>Record Payment</a>
            <a class="button secondary" href="<?= e(url('admin/accounting-parties.php?statement=1')) ?>"><?= icon('documents') ?>Send Statement</a>
        <?php else: ?>
            <section><h3>No party selected</h3><p class="muted">Create a customer or supplier to populate this panel.</p></section>
        <?php endif; ?>
    </aside>
</div>

<div id="aging" class="reference-aging-grid">
    <?php foreach ([['Receivables Aging', $receivableAging, $summary['receivables']], ['Payables Aging', $payableAging, $summary['payables']]] as $agingCard): ?>
        <section class="aging-card">
            <h3><?= e($agingCard[0]) ?> <small>(as of <?= e(date('d M Y')) ?>)</small></h3>
            <div class="aging-body">
                <div class="donut-chart"></div>
                <div class="aging-legend">
                    <?php foreach ($agingCard[1] as $label => $amount): ?>
                        <div><span></span><?= e($label) ?><strong><?= e(site_currency_symbol()) ?><?= e(number_format((float) $amount, 2)) ?></strong></div>
                    <?php endforeach; ?>
                </div>
                <div class="aging-total"><small>Total</small><strong><?= e(site_currency_symbol()) ?><?= e(number_format((float) $agingCard[2], 2)) ?></strong><small>Overdue (&gt; 30 Days)</small><em><?= e(site_currency_symbol()) ?><?= e(number_format((float) (($agingCard[1]['31 - 60 Days'] ?? 0) + ($agingCard[1]['61 - 90 Days'] ?? 0) + ($agingCard[1]['90+ Days'] ?? 0)), 2)) ?></em></div>
            </div>
        </section>
    <?php endforeach; ?>
</div>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
