<?php
declare(strict_types=1);

/**
 * Sample-data seeder — fills every module with realistic demo rows so the
 * whole app can be verified end to end.
 *
 * Run from the project root:
 *   php database/seed_sample_data.php
 *
 * Creates (all clearly marked SMP-/sample.*):
 *   - Logins:  sample.admin@mbista.local / Sample@123   (admin, super admin)
 *              sample.staff@mbista.local / Sample@123   (staff)
 *              sample.client@mbista.local / Sample@123  (client portal)
 *   - Client profile + service contract + tasks + stages
 *   - Invoices (issued / paid / proforma) with line items, payment requests,
 *     a client-declared partial payment, receipts, and auto-posted vouchers
 *   - Extra vouchers (payment, journal, contra, purchase), a bank ledger,
 *     and reconciled bank entries
 *   - Customers & suppliers (accounting parties)
 *   - Inventory items + stock movements and a completed manufacturing order
 *   - Documents + a document request, compliance deadlines
 *   - A message thread, support tickets with a pending discount request and
 *     a negotiated partial-payment counter-offer
 *   - HR: attendance, a leave request, timesheets
 *   - A report schedule
 *   - Client books (provisioned company) with one posted voucher and one
 *     voucher waiting for the client's approval
 *
 * The script refuses to run twice (checks for sample.client@mbista.local).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require __DIR__ . '/../app/bootstrap.php';

function say(string $message): void
{
    echo $message . PHP_EOL;
}

/** Insert only the columns that actually exist in this database. */
function seed_insert(string $table, array $data): int
{
    $cols = [];
    foreach ($data as $col => $value) {
        if (column_exists($table, $col)) {
            $cols[$col] = $value;
        }
    }
    if ($cols === []) {
        return 0;
    }
    $names = implode(', ', array_keys($cols));
    $params = implode(', ', array_map(static fn (string $c): string => ':' . $c, array_keys($cols)));
    db()->prepare("INSERT INTO {$table} ({$names}) VALUES ({$params})")->execute($cols);
    return (int) db()->lastInsertId();
}

// ---------------------------------------------------------------------------
// Guard + context
// ---------------------------------------------------------------------------
$existing = db()->prepare('SELECT id FROM users WHERE email = :email');
$existing->execute(['email' => 'sample.client@mbista.local']);
if ($existing->fetch()) {
    exit("Sample data already seeded (sample.client@mbista.local exists). Nothing to do.\n");
}

$company = company_by_code('MBAACA');
if (!$company) {
    exit("Company MBAACA not found - run the schema first.\n");
}
$cid = (int) $company['id'];

$fyRows = fiscal_years_for_company($cid, false);
$fy = null;
foreach ($fyRows as $row) {
    if ((int) ($row['is_default'] ?? 0) === 1) {
        $fy = $row;
        break;
    }
}
$fy = $fy ?: ($fyRows[0] ?? null);
if (!$fy) {
    exit("No fiscal year for MBAACA - create one in Settings first.\n");
}
$fyId = (int) $fy['id'];
$fyStart = (string) $fy['start_date'];
$d = static fn (int $offsetDays): string => date('Y-m-d', strtotime($fyStart . ' +' . $offsetDays . ' days'));

// The auto-post helpers read the "current" company/fiscal year from the
// session; give the CLI run the same context so vouchers land in MBAACA.
$_SESSION['company_id'] = $cid;
$_SESSION['fiscal_year_id'] = $fyId;

say("Seeding into company MBAACA (id {$cid}), fiscal year {$fy['label']} ({$fyStart})...");

// ---------------------------------------------------------------------------
// 1. Users, staff profile, client profile
// ---------------------------------------------------------------------------
$adminId = create_user(['name' => 'Sample Admin', 'email' => 'sample.admin@mbista.local', 'password' => 'Sample@123', 'role' => 'admin', 'access_level' => 'super_admin', 'status' => 'active', 'company_id' => $cid]);
$staffId = create_user(['name' => 'Sample Staff', 'email' => 'sample.staff@mbista.local', 'password' => 'Sample@123', 'role' => 'staff', 'access_level' => 'accountant', 'status' => 'active', 'company_id' => $cid, 'phone' => '+977-9800000001']);
$clientUserId = create_user(['name' => 'Sita Sharma', 'email' => 'sample.client@mbista.local', 'password' => 'Sample@123', 'role' => 'customer', 'status' => 'active', 'company_id' => $cid, 'company' => 'Sunrise Traders Pvt Ltd', 'phone' => '+977-9800000002']);

seed_insert('staff_profiles', ['user_id' => $staffId, 'team_category' => 'professional', 'employment_status' => 'active', 'designation' => 'Audit Associate']);

$clientId = seed_insert('client_profiles', [
    'user_id' => $clientUserId,
    'organization_name' => 'Sunrise Traders Pvt Ltd',
    'company_id' => $cid,
    'client_code' => 'SMP-001',
    'pan_no' => '609712345',
    'client_status' => 'active',
    'assigned_staff_user_id' => $staffId,
]);
say("Users + client profile created (client id {$clientId}).");

// ---------------------------------------------------------------------------
// 2. Accounting parties (customers & suppliers)
// ---------------------------------------------------------------------------
$partyCustomer = seed_insert('accounting_parties', ['company_id' => $cid, 'code' => 'SMP-CUST1', 'name' => 'Sunrise Traders Pvt Ltd', 'party_type' => 'customer', 'status' => 'active', 'phone' => '+977-9800000002']);
seed_insert('accounting_parties', ['company_id' => $cid, 'code' => 'SMP-CUST2', 'name' => 'Everest Retail Pvt Ltd', 'party_type' => 'customer', 'status' => 'active']);
$partySupplier = seed_insert('accounting_parties', ['company_id' => $cid, 'code' => 'SMP-SUP1', 'name' => 'Kathmandu Stationers', 'party_type' => 'supplier', 'status' => 'active']);
seed_insert('accounting_parties', ['company_id' => $cid, 'code' => 'SMP-SUP2', 'name' => 'Valley IT Supplies', 'party_type' => 'supplier', 'status' => 'active']);
if (function_exists('ensure_party_ledger')) {
    // Each party gets its own ledger under Trade Receivables / Payables.
    ensure_party_ledger($cid, $partyCustomer, 'receivable');
    ensure_party_ledger($cid, $partySupplier, 'payable');
}
say('Parties created (2 customers, 2 suppliers) with party ledgers.');

// ---------------------------------------------------------------------------
// 3. Contract, tasks, stages
// ---------------------------------------------------------------------------
$contractId = seed_insert('service_contracts', ['client_id' => $clientId, 'company_id' => $cid, 'title' => 'Annual audit & tax engagement', 'contract_no' => 'SMP-CON-001', 'status' => 'active', 'start_date' => $d(0), 'end_date' => $d(360)]);

$task1 = seed_insert('client_tasks', ['client_id' => $clientId, 'company_id' => $cid, 'contract_id' => $contractId, 'title' => 'Annual statutory audit', 'description' => 'Statutory audit for the current fiscal year.', 'status' => 'completed', 'priority' => 'high', 'assigned_staff_user_id' => $staffId, 'quoted_fee' => 80000]);
$task2 = seed_insert('client_tasks', ['client_id' => $clientId, 'company_id' => $cid, 'contract_id' => $contractId, 'title' => 'Monthly VAT filing', 'description' => 'Prepare and file the monthly VAT return.', 'status' => 'in_progress', 'priority' => 'normal', 'assigned_staff_user_id' => $staffId, 'quoted_fee' => 12000]);

foreach ([['Planning', 1, 'completed', 20000], ['Fieldwork', 2, 'completed', 40000], ['Reporting', 3, 'completed', 20000]] as [$name, $seq, $status, $fee]) {
    seed_insert('task_stages', ['task_id' => $task1, 'company_id' => $cid, 'stage_name' => $name, 'sequence_no' => $seq, 'status' => $status, 'stage_fee' => $fee]);
}
seed_insert('task_stages', ['task_id' => $task2, 'company_id' => $cid, 'stage_name' => 'Data collection', 'sequence_no' => 1, 'status' => 'in_progress', 'stage_fee' => 12000]);
say('Contract, 2 tasks, 4 stages created.');

// ---------------------------------------------------------------------------
// 4. Invoices + payments + receipts + auto-posted vouchers
// ---------------------------------------------------------------------------
function seed_invoice(int $cid, int $taskId, string $no, float $amount, string $status, string $issuedOn, ?int $partyId, string $category = 'tax'): int
{
    $vat = round($amount * 0.13, 2);
    return seed_insert('task_invoices', [
        'company_id' => $cid,
        'task_id' => $taskId,
        'party_id' => $partyId,
        'invoice_no' => $no,
        'invoice_type' => 'task',
        'invoice_category' => $category,
        'amount' => $amount,
        'taxable_amount' => $amount,
        'vat_rate' => 13.00,
        'vat_amount' => $vat,
        'total_amount' => round($amount + $vat, 2),
        'status' => $status,
        'issued_on' => $issuedOn,
        'due_on' => date('Y-m-d', strtotime($issuedOn . ' +30 days')),
    ]);
}

$inv1 = seed_invoice($cid, $task1, 'INV-SMP-0001', 50000.00, 'issued', $d(10), $partyCustomer);
seed_insert('invoice_line_items', ['invoice_id' => $inv1, 'company_id' => $cid, 'description' => 'Statutory audit — planning & fieldwork', 'quantity' => 1, 'rate' => 50000, 'vat_rate' => 13, 'amount' => 50000]);
auto_post_task_invoice_voucher($inv1, $adminId);

$inv2 = seed_invoice($cid, $task1, 'INV-SMP-0002', 30000.00, 'issued', $d(20), $partyCustomer);
seed_insert('invoice_line_items', ['invoice_id' => $inv2, 'company_id' => $cid, 'description' => 'Statutory audit — reporting', 'quantity' => 1, 'rate' => 30000, 'vat_rate' => 13, 'amount' => 30000]);
auto_post_task_invoice_voucher($inv2, $adminId);

$inv3 = seed_invoice($cid, $task2, 'INV-SMP-0003', 12000.00, 'draft', $d(30), $partyCustomer, 'proforma');

// 4a. Payment request on INV-1 with a client-declared partial payment (for the
//     admin "verify & confirm" flow).
$pr1 = create_payment_request($inv1, $cid, $staffId, 20000.00, 'Bank transfer', 'First instalment for the audit fee.');
if ($pr1 && column_exists('invoice_payment_requests', 'client_declared_status')) {
    db()->prepare("UPDATE invoice_payment_requests SET client_declared_status = 'partial', client_declared_amount = 20000, client_declared_method = 'Bank transfer', client_declared_reference = 'NIC-778812', client_declared_on = :on, client_declared_note = 'Paid from our NIC Asia account.', client_declared_at = NOW() WHERE id = :id")
        ->execute(['on' => $d(15), 'id' => $pr1]);
}

// 4b. Fully settled payment on INV-2 → receipt + posted receipt voucher.
$pr2 = create_payment_request($inv2, $cid, $staffId, 33900.00, 'Bank transfer', 'Full settlement.');
if ($pr2) {
    db()->prepare("UPDATE invoice_payment_requests SET status = 'paid', payment_received_on = :on, payment_amount = 33900 WHERE id = :id")
        ->execute(['on' => $d(25), 'id' => $pr2]);
    db()->prepare("UPDATE task_invoices SET status = 'paid' WHERE id = :id")->execute(['id' => $inv2]);
    auto_post_invoice_payment_voucher($pr2, $adminId);
    seed_insert('invoice_payment_receipts', [
        'payment_request_id' => $pr2,
        'invoice_id' => $inv2,
        'company_id' => $cid,
        'client_id' => $clientId,
        'receipt_no' => next_receipt_number($cid),
        'amount_received' => 33900.00,
        'payment_method' => 'Bank transfer',
        'received_on' => $d(25),
        'created_by' => $adminId,
    ]);
}
say('Invoices INV-SMP-0001..0003 with payments, receipt, and posted vouchers.');

// ---------------------------------------------------------------------------
// 5. Bank ledger + extra vouchers (payment, journal, contra, purchase)
// ---------------------------------------------------------------------------
$cash = ledger_by_code($cid, 'CASH');
$ar = ledger_by_code($cid, 'AR');
$ap = ledger_by_code($cid, 'AP');
$rent = ledger_by_code($cid, 'RENT_EXPENSE');
$salary = ledger_by_code($cid, 'SALARY_EXPENSE');
$profFees = ledger_by_code($cid, 'PROFESSIONAL_FEES');

$bankLedgerId = 0;
if ($cash) {
    $bankLedgerId = seed_insert('ledgers', [
        'company_id' => $cid,
        'group_id' => (int) $cash['group_id'],
        'code' => coa_next_ledger_code($cid, (int) $cash['group_id']),
        'name' => 'NIC Asia Bank — Operating',
        'type' => 'asset',
        'status' => 'active',
        'bank_name' => 'NIC Asia Bank',
        'bank_account_no' => '0123456789012',
    ]);
}

$vouchers = [];
if ($cash && $rent) {
    $vouchers[] = ['SMP-PV-001', 'payment', 15000.00, 'Office rent paid in cash', [
        ['ledger_id' => (int) $rent['id'], 'entry_type' => 'debit', 'amount' => 15000.00],
        ['ledger_id' => (int) $cash['id'], 'entry_type' => 'credit', 'amount' => 15000.00],
    ], 5];
}
if ($salary && $ap) {
    $vouchers[] = ['SMP-JV-001', 'journal', 40000.00, 'Monthly salary accrual', [
        ['ledger_id' => (int) $salary['id'], 'entry_type' => 'debit', 'amount' => 40000.00],
        ['ledger_id' => (int) $ap['id'], 'entry_type' => 'credit', 'amount' => 40000.00],
    ], 8];
}
if ($cash && $bankLedgerId > 0) {
    $vouchers[] = ['SMP-CV-001', 'contra', 50000.00, 'Cash deposited to NIC Asia Bank', [
        ['ledger_id' => $bankLedgerId, 'entry_type' => 'debit', 'amount' => 50000.00],
        ['ledger_id' => (int) $cash['id'], 'entry_type' => 'credit', 'amount' => 50000.00],
    ], 12];
}
if ($profFees && $ap) {
    $vouchers[] = ['SMP-PUV-001', 'purchase', 8000.00, 'Stationery purchased on credit — Kathmandu Stationers', [
        ['ledger_id' => (int) $profFees['id'], 'entry_type' => 'debit', 'amount' => 8000.00],
        ['ledger_id' => (int) $ap['id'], 'entry_type' => 'credit', 'amount' => 8000.00],
    ], 14];
}

$contraVoucherId = 0;
foreach ($vouchers as [$no, $type, $total, $narration, $entries, $dayOffset]) {
    $vid = create_voucher_with_entries([
        'company_id' => $cid,
        'fiscal_year_id' => $fyId,
        'voucher_no' => $no,
        'voucher_type' => $type,
        'source_type' => 'sample_seed',
        'source_id' => null,
        'total_amount' => $total,
        'narration' => $narration,
        'status' => 'posted',
        'posted_by' => $adminId,
        'voucher_date' => $d($dayOffset),
        'party_id' => $type === 'purchase' ? $partySupplier : null,
    ], $entries);
    if ($type === 'contra') {
        $contraVoucherId = $vid;
    }
}

// Mark the bank side of the contra voucher as reconciled (banking demo).
if ($contraVoucherId > 0 && $bankLedgerId > 0 && column_exists('voucher_entries', 'reconciled_at')) {
    db()->prepare('UPDATE voucher_entries SET reconciled_at = NOW(), reconciled_by = :by, statement_date = :sd WHERE voucher_id = :vid AND ledger_id = :lid')
        ->execute(['by' => $adminId, 'sd' => $d(13), 'vid' => $contraVoucherId, 'lid' => $bankLedgerId]);
}
say('Bank ledger + 4 vouchers posted (payment, journal, contra w/ reconciliation, purchase).');

// ---------------------------------------------------------------------------
// 6. Inventory + manufacturing (MBAACA and MBTAS so trading/manufacturing
//    portals have data wherever those modules are enabled)
// ---------------------------------------------------------------------------
foreach ([$cid, (int) (company_by_code('MBTAS')['id'] ?? 0)] as $invCompanyId) {
    if ($invCompanyId <= 0 || !table_exists('inventory_items')) {
        continue;
    }
    $paper = seed_insert('inventory_items', ['company_id' => $invCompanyId, 'sku' => 'SMP-ITM1', 'name' => 'A4 Paper Ream', 'item_type' => 'stock', 'unit' => 'pcs', 'purchase_rate' => 450, 'sales_rate' => 600, 'opening_qty' => 20, 'reorder_level' => 10, 'status' => 'active']);
    $toner = seed_insert('inventory_items', ['company_id' => $invCompanyId, 'sku' => 'SMP-ITM2', 'name' => 'Printer Toner', 'item_type' => 'consumable', 'unit' => 'pcs', 'purchase_rate' => 6500, 'sales_rate' => 8000, 'opening_qty' => 4, 'status' => 'active']);
    $binder = seed_insert('inventory_items', ['company_id' => $invCompanyId, 'sku' => 'SMP-ITM3', 'name' => 'Bound Report Set', 'item_type' => 'finished_good', 'unit' => 'set', 'purchase_rate' => 0, 'sales_rate' => 1500, 'status' => 'active']);

    if (table_exists('inventory_transactions')) {
        seed_insert('inventory_transactions', ['company_id' => $invCompanyId, 'item_id' => $paper, 'transaction_type' => 'opening', 'transaction_date' => $d(0), 'qty_in' => 20, 'rate' => 450, 'amount' => 9000]);
        seed_insert('inventory_transactions', ['company_id' => $invCompanyId, 'item_id' => $paper, 'transaction_type' => 'purchase', 'transaction_date' => $d(7), 'qty_in' => 30, 'rate' => 460, 'amount' => 13800]);
        seed_insert('inventory_transactions', ['company_id' => $invCompanyId, 'item_id' => $paper, 'transaction_type' => 'sale', 'transaction_date' => $d(18), 'qty_out' => 15, 'rate' => 600, 'amount' => 9000]);
        seed_insert('inventory_transactions', ['company_id' => $invCompanyId, 'item_id' => $toner, 'transaction_type' => 'opening', 'transaction_date' => $d(0), 'qty_in' => 4, 'rate' => 6500, 'amount' => 26000]);
    }

    if (table_exists('manufacturing_orders')) {
        $mo = seed_insert('manufacturing_orders', ['company_id' => $invCompanyId, 'order_no' => 'SMP-MO-001', 'finished_item_id' => $binder, 'quantity' => 10, 'status' => 'completed', 'started_on' => $d(9), 'completed_on' => $d(16)]);
        if ($mo > 0 && table_exists('manufacturing_order_inputs')) {
            seed_insert('manufacturing_order_inputs', ['manufacturing_order_id' => $mo, 'item_id' => $paper, 'quantity' => 5, 'rate' => 455]);
        }
        if ($mo > 0 && table_exists('inventory_transactions')) {
            seed_insert('inventory_transactions', ['company_id' => $invCompanyId, 'item_id' => $paper, 'transaction_type' => 'consume', 'transaction_date' => $d(16), 'qty_out' => 5, 'rate' => 455, 'amount' => 2275]);
            seed_insert('inventory_transactions', ['company_id' => $invCompanyId, 'item_id' => $binder, 'transaction_type' => 'produce', 'transaction_date' => $d(16), 'qty_in' => 10, 'rate' => 300, 'amount' => 3000]);
        }
    }
}
say('Inventory items, stock movements, and a completed manufacturing order.');

// ---------------------------------------------------------------------------
// 7. Documents + document request, compliance deadlines
// ---------------------------------------------------------------------------
if (table_exists('documents')) {
    seed_insert('documents', ['company_id' => $cid, 'client_id' => $clientId, 'title' => 'PAN Registration Certificate', 'category' => 'registration_kyc', 'visibility' => 'client', 'approval_status' => 'approved', 'original_file_name' => 'pan-certificate.pdf', 'stored_file_name' => 'sample-pan-certificate.pdf', 'file_path' => 'uploads/documents/sample-pan-certificate.pdf', 'uploaded_by' => $staffId, 'version_number' => 1]);
    seed_insert('documents', ['company_id' => $cid, 'client_id' => $clientId, 'title' => 'Audited Financial Statements (draft)', 'category' => 'audit_documents', 'visibility' => 'client', 'approval_status' => 'under_review', 'original_file_name' => 'afs-draft.pdf', 'stored_file_name' => 'sample-afs-draft.pdf', 'file_path' => 'uploads/documents/sample-afs-draft.pdf', 'uploaded_by' => $staffId, 'version_number' => 1]);
}
if (table_exists('document_requests')) {
    seed_insert('document_requests', ['company_id' => $cid, 'client_id' => $clientId, 'title' => 'Bank statements (last quarter)', 'description' => 'Please upload statements for all operating accounts.', 'status' => 'requested', 'requested_by' => $staffId]);
}

if (table_exists('compliance_deadlines') && table_exists('compliance_types')) {
    $typeStmt = db()->prepare('SELECT id, name FROM compliance_types WHERE company_id = :cid ORDER BY id ASC LIMIT 3');
    $typeStmt->execute(['cid' => $cid]);
    $types = $typeStmt->fetchAll();
    $statuses = ['filed', 'in_progress', 'upcoming'];
    foreach ($types as $i => $type) {
        seed_insert('compliance_deadlines', [
            'company_id' => $cid,
            'client_id' => $clientId,
            'compliance_type_id' => (int) $type['id'],
            'applicable_period' => 'FY ' . date('Y'),
            'statutory_due_date' => $d(40 + $i * 30),
            'status' => $statuses[$i] ?? 'upcoming',
            'filing_date' => ($statuses[$i] ?? '') === 'filed' ? $d(35) : null,
            'filing_reference' => ($statuses[$i] ?? '') === 'filed' ? 'IRD-SMP-1001' : null,
        ]);
    }
}
say('Documents, document request, and compliance deadlines.');

// ---------------------------------------------------------------------------
// 8. Messages + support tickets with billing requests
// ---------------------------------------------------------------------------
if (function_exists('create_message_thread')) {
    try {
        create_message_thread($cid, $clientId, 'Welcome to your client portal', $staffId, 'Namaste! Your portal is ready — you can see invoices, documents, and raise requests here. Reply any time.');
    } catch (Throwable $e) {
        say('  (message thread skipped: ' . $e->getMessage() . ')');
    }
}

// Ticket A: pending discount request on INV-SMP-0001.
$ticketA = seed_insert('support_tickets', ['company_id' => $cid, 'client_id' => $clientId, 'ticket_no' => next_ticket_number($cid), 'subject' => 'Discount request for invoice INV-SMP-0001', 'category' => 'billing', 'priority' => 'normal', 'status' => 'open', 'description' => 'We have been a loyal client — requesting a 10% discount on the audit fee.']);
seed_insert('support_ticket_requests', ['ticket_id' => $ticketA, 'company_id' => $cid, 'client_id' => $clientId, 'request_type' => 'discount_request', 'target_task_id' => $task1, 'target_invoice_id' => $inv1, 'requested_percent' => 10, 'decision_status' => 'pending']);

// Ticket B: partial-payment request already answered with a counter-offer,
// so the client's "Accept counter-offer" button can be demonstrated.
$ticketB = seed_insert('support_tickets', ['company_id' => $cid, 'client_id' => $clientId, 'ticket_no' => next_ticket_number($cid), 'subject' => 'Partial payment for invoice INV-SMP-0001', 'category' => 'billing', 'priority' => 'normal', 'status' => 'waiting_for_client', 'description' => 'Cash flow is tight this month — can we pay Rs. 10,000 now and the rest next month?']);
seed_insert('support_ticket_requests', ['ticket_id' => $ticketB, 'company_id' => $cid, 'client_id' => $clientId, 'request_type' => 'partial_payment_request', 'target_task_id' => $task1, 'target_invoice_id' => $inv1, 'requested_amount' => 10000, 'decision_status' => 'negotiation', 'approved_amount' => 15000, 'admin_note' => 'We can accept Rs. 15,000 now with the balance within 30 days.', 'processed_by' => $adminId, 'processed_on' => date('Y-m-d H:i:s')]);
if (table_exists('support_ticket_messages')) {
    seed_insert('support_ticket_messages', ['ticket_id' => $ticketB, 'sender_id' => $adminId, 'body' => "COUNTER-OFFER on your Partial Payment Request (amount Rs. 15,000.00).\nIf you agree with these terms, open this ticket in your portal and press \"Accept counter-offer\" — it will be applied immediately."]);
}
say('Message thread + 2 billing tickets (pending discount, negotiated partial payment).');

// ---------------------------------------------------------------------------
// 9. HR: attendance, leave, timesheets
// ---------------------------------------------------------------------------
if (table_exists('attendance')) {
    for ($i = 5; $i >= 1; $i--) {
        $day = date('Y-m-d', strtotime("-{$i} days"));
        seed_insert('attendance', ['company_id' => $cid, 'staff_user_id' => $staffId, 'attendance_date' => $day, 'check_in_time' => $day . ' 09:05:00', 'check_out_time' => $day . ' 17:30:00']);
    }
}
if (table_exists('leave_requests') && table_exists('leave_types')) {
    $leaveType = db()->prepare('SELECT id FROM leave_types WHERE company_id = :cid ORDER BY id ASC LIMIT 1');
    $leaveType->execute(['cid' => $cid]);
    $leaveTypeId = (int) ($leaveType->fetchColumn() ?: 0);
    if ($leaveTypeId > 0) {
        seed_insert('leave_requests', ['company_id' => $cid, 'staff_user_id' => $staffId, 'leave_type_id' => $leaveTypeId, 'start_date' => date('Y-m-d', strtotime('+7 days')), 'end_date' => date('Y-m-d', strtotime('+8 days')), 'total_days' => 2, 'reason' => 'Family event in Pokhara.', 'status' => 'pending']);
    }
}
if (table_exists('timesheet_entries')) {
    seed_insert('timesheet_entries', ['company_id' => $cid, 'staff_user_id' => $staffId, 'entry_date' => date('Y-m-d', strtotime('-2 days')), 'description' => 'Audit fieldwork — Sunrise Traders', 'start_time' => '09:30:00', 'end_time' => '13:00:00', 'status' => 'submitted']);
    seed_insert('timesheet_entries', ['company_id' => $cid, 'staff_user_id' => $staffId, 'entry_date' => date('Y-m-d', strtotime('-1 day')), 'description' => 'VAT return preparation', 'start_time' => '10:00:00', 'end_time' => '12:30:00', 'status' => 'approved']);
}
say('Attendance (5 days), leave request, timesheets.');

// ---------------------------------------------------------------------------
// 10. Report schedule
// ---------------------------------------------------------------------------
if (table_exists('report_schedules')) {
    seed_insert('report_schedules', ['company_id' => $cid, 'report_key' => 'trial_balance', 'recipient_email' => 'reports@mbista.local', 'frequency' => 'monthly', 'export_format' => 'both', 'next_run_on' => date('Y-m-d', strtotime('first day of next month')), 'is_active' => 1, 'created_by' => $adminId]);
}

// ---------------------------------------------------------------------------
// 11. Client books: provision + one posted voucher + one awaiting client approval
// ---------------------------------------------------------------------------
if (function_exists('provision_client_books')) {
    try {
        $booksCompanyId = provision_client_books($clientId);
        if ($booksCompanyId > 0) {
            $booksFyStmt = db()->prepare('SELECT id, start_date FROM fiscal_years WHERE company_id = :cid ORDER BY is_default DESC, id ASC LIMIT 1');
            $booksFyStmt->execute(['cid' => $booksCompanyId]);
            $booksFy = $booksFyStmt->fetch();
            $ledgerStmt = db()->prepare("SELECT id FROM ledgers WHERE company_id = :cid AND status = 'active' ORDER BY id ASC LIMIT 2");
            $ledgerStmt->execute(['cid' => $booksCompanyId]);
            $bookLedgers = $ledgerStmt->fetchAll(PDO::FETCH_COLUMN);

            if ($booksFy && count($bookLedgers) >= 2) {
                create_voucher_with_entries([
                    'company_id' => $booksCompanyId,
                    'fiscal_year_id' => (int) $booksFy['id'],
                    'voucher_no' => 'SMP-CB-001',
                    'voucher_type' => 'journal',
                    'source_type' => 'sample_seed',
                    'source_id' => null,
                    'total_amount' => 25000.00,
                    'narration' => 'Opening adjustment entry (client books demo).',
                    'status' => 'posted',
                    'posted_by' => $staffId,
                ], [
                    ['ledger_id' => (int) $bookLedgers[0], 'entry_type' => 'debit', 'amount' => 25000.00],
                    ['ledger_id' => (int) $bookLedgers[1], 'entry_type' => 'credit', 'amount' => 25000.00],
                ]);

                $pendingVoucherId = create_voucher_with_entries([
                    'company_id' => $booksCompanyId,
                    'fiscal_year_id' => (int) $booksFy['id'],
                    'voucher_no' => 'SMP-CB-002',
                    'voucher_type' => 'journal',
                    'source_type' => 'sample_seed',
                    'source_id' => null,
                    'total_amount' => 5000.00,
                    'narration' => 'Bank charges — waiting for your approval (demo).',
                    'status' => 'draft',
                    'approval_state' => 'pending_approval',
                    'submitted_by' => $staffId,
                ], [
                    ['ledger_id' => (int) $bookLedgers[0], 'entry_type' => 'debit', 'amount' => 5000.00],
                    ['ledger_id' => (int) $bookLedgers[1], 'entry_type' => 'credit', 'amount' => 5000.00],
                ]);
                if ($pendingVoucherId > 0 && column_exists('vouchers', 'requires_client_approval')) {
                    db()->prepare('UPDATE vouchers SET requires_client_approval = 1 WHERE id = :id')->execute(['id' => $pendingVoucherId]);
                }
            }
            say("Client books provisioned (company id {$booksCompanyId}) with 1 posted + 1 pending-approval voucher.");
        }
    } catch (Throwable $e) {
        say('  (client books skipped: ' . $e->getMessage() . ')');
    }
}

say('');
say('DONE. Sample logins (all password Sample@123):');
say('  Admin:  sample.admin@mbista.local');
say('  Staff:  sample.staff@mbista.local');
say('  Client: sample.client@mbista.local');
say('Everything is prefixed SMP- / sample.* so it is easy to recognise and remove.');
