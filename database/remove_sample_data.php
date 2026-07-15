<?php
declare(strict_types=1);

/**
 * Removes everything seed_sample_data.php created (SMP-/sample.* prefixed) so
 * the seed can be re-run cleanly, or so a production database can be stripped
 * of demo records before go-live. Safe to run repeatedly; every step is
 * guarded, so a half-seeded database cleans up fine too.
 *
 * Run: php database/remove_sample_data.php
 */

require __DIR__ . '/../app/bootstrap.php';

function unseed_say(string $line): void
{
    echo $line . "\n";
}

$cid = (int) db()->query("SELECT id FROM companies WHERE code = 'MBAACA' LIMIT 1")->fetchColumn();
if ($cid <= 0) {
    exit("Company MBAACA not found - nothing to do.\n");
}

$step = static function (string $label, callable $fn): void {
    try {
        $fn();
        unseed_say('  ok    ' . $label);
    } catch (Throwable $e) {
        unseed_say('  skip  ' . $label . ' (' . $e->getMessage() . ')');
    }
};

unseed_say("Removing SMP-/sample.* data from company #{$cid}...");

$sampleUserIds = array_map('intval', db()->query("SELECT id FROM users WHERE email IN ('sample.admin@mbista.local','sample.staff@mbista.local','sample.client@mbista.local')")->fetchAll(PDO::FETCH_COLUMN));
$sampleUserList = $sampleUserIds !== [] ? implode(',', $sampleUserIds) : '0';
$sampleClientIds = array_map('intval', db()->query("SELECT id FROM client_profiles WHERE client_code LIKE 'SMP%'")->fetchAll(PDO::FETCH_COLUMN));
$sampleClientList = $sampleClientIds !== [] ? implode(',', $sampleClientIds) : '0';
$samplePartyIds = array_map('intval', db()->query("SELECT id FROM accounting_parties WHERE company_id = $cid AND code LIKE 'SMP-%'")->fetchAll(PDO::FETCH_COLUMN));
$samplePartyList = $samplePartyIds !== [] ? implode(',', $samplePartyIds) : '0';
$sampleInvoiceIds = array_map('intval', db()->query("SELECT id FROM task_invoices WHERE invoice_no LIKE 'INV-SMP%'")->fetchAll(PDO::FETCH_COLUMN));
$sampleInvoiceList = $sampleInvoiceIds !== [] ? implode(',', $sampleInvoiceIds) : '0';
$sampleTaskIds = array_map('intval', db()->query("SELECT id FROM client_tasks WHERE client_id IN ($sampleClientList)")->fetchAll(PDO::FETCH_COLUMN));
$sampleTaskList = $sampleTaskIds !== [] ? implode(',', $sampleTaskIds) : '0';
// Inventory/manufacturing are seeded into MBAACA AND MBTAS — clean everywhere.
$sampleItemIds = array_map('intval', db()->query("SELECT id FROM inventory_items WHERE sku LIKE 'SMP-%'")->fetchAll(PDO::FETCH_COLUMN));
$sampleItemList = $sampleItemIds !== [] ? implode(',', $sampleItemIds) : '0';

// 1. Vouchers: explicit SMP- numbers plus everything auto-posted from sample
//    invoices, payments, inventory movements, and manufacturing orders.
$step('vouchers + entries', static function () use ($cid, $sampleInvoiceList, $sampleItemList): void {
    $voucherIds = array_map('intval', db()->query("
        SELECT id FROM vouchers WHERE company_id = $cid AND (
            voucher_no LIKE 'SMP-%'
            OR (source_type = 'task_invoice' AND source_id IN ($sampleInvoiceList))
            OR (source_type = 'invoice_payment_request' AND source_id IN (SELECT id FROM invoice_payment_requests WHERE invoice_id IN ($sampleInvoiceList)))
            OR (source_type = 'inventory_movement' AND source_id IN (SELECT id FROM inventory_transactions WHERE item_id IN ($sampleItemList)))
            OR (source_type = 'manufacturing_order' AND source_id IN (SELECT id FROM manufacturing_orders WHERE company_id = $cid AND order_no LIKE 'SMP-%'))
        )")->fetchAll(PDO::FETCH_COLUMN));
    if ($voucherIds !== []) {
        $list = implode(',', $voucherIds);
        db()->exec("DELETE FROM voucher_entries WHERE voucher_id IN ($list)");
        if (table_exists('bank_reconciliation_entries')) {
            db()->exec("DELETE FROM bank_reconciliation_entries WHERE voucher_entry_id NOT IN (SELECT id FROM voucher_entries)");
        }
        db()->exec("DELETE FROM vouchers WHERE id IN ($list)");
    }
});

// 2. Billing chain for sample invoices.
$step('payment receipts/requests + invoices', static function () use ($sampleInvoiceList): void {
    if (table_exists('invoice_payment_receipts')) {
        db()->exec("DELETE FROM invoice_payment_receipts WHERE invoice_id IN ($sampleInvoiceList)");
    }
    db()->exec("DELETE FROM invoice_payment_requests WHERE invoice_id IN ($sampleInvoiceList)");
    if (table_exists('invoice_line_items')) {
        db()->exec("DELETE FROM invoice_line_items WHERE invoice_id IN ($sampleInvoiceList)");
    }
    db()->exec("DELETE FROM task_invoices WHERE id IN ($sampleInvoiceList)");
});

// 3. Tickets, messages, documents, compliance for the sample client.
$step('tickets + messages + documents + compliance', static function () use ($cid, $sampleClientList, $sampleUserList): void {
    if (table_exists('support_tickets')) {
        $ticketIds = array_map('intval', db()->query("SELECT id FROM support_tickets WHERE client_id IN ($sampleClientList)")->fetchAll(PDO::FETCH_COLUMN));
        if ($ticketIds !== []) {
            $tl = implode(',', $ticketIds);
            if (table_exists('support_ticket_messages')) {
                db()->exec("DELETE FROM support_ticket_messages WHERE ticket_id IN ($tl)");
            }
            if (table_exists('support_ticket_requests')) {
                db()->exec("DELETE FROM support_ticket_requests WHERE ticket_id IN ($tl)");
            }
            db()->exec("DELETE FROM support_tickets WHERE id IN ($tl)");
        }
    }
    if (table_exists('message_threads')) {
        $threadIds = array_map('intval', db()->query("SELECT DISTINCT thread_id FROM message_thread_participants WHERE user_id IN ($sampleUserList)")->fetchAll(PDO::FETCH_COLUMN));
        if ($threadIds !== []) {
            $thl = implode(',', $threadIds);
            db()->exec("DELETE FROM messages WHERE thread_id IN ($thl)");
            db()->exec("DELETE FROM message_thread_participants WHERE thread_id IN ($thl)");
            db()->exec("DELETE FROM message_threads WHERE id IN ($thl)");
        }
    }
    if (table_exists('documents')) {
        db()->exec("DELETE FROM documents WHERE client_id IN ($sampleClientList)");
    }
    if (table_exists('document_requests')) {
        db()->exec("DELETE FROM document_requests WHERE client_id IN ($sampleClientList)");
    }
    if (table_exists('compliance_deadlines')) {
        db()->exec("DELETE FROM compliance_deadlines WHERE client_id IN ($sampleClientList)");
    }
});

// 4. HR trails of the sample staff.
$step('attendance + leave + timesheets', static function () use ($sampleUserList): void {
    foreach (['attendance_records' => 'staff_user_id', 'leave_requests' => 'staff_user_id', 'timesheet_entries' => 'staff_user_id'] as $table => $column) {
        if (table_exists($table)) {
            db()->exec("DELETE FROM $table WHERE $column IN ($sampleUserList)");
        }
    }
});

// 5. Inventory + manufacturing.
$step('inventory + manufacturing', static function () use ($sampleItemList): void {
    if (table_exists('manufacturing_orders')) {
        $moIds = array_map('intval', db()->query("SELECT id FROM manufacturing_orders WHERE order_no LIKE 'SMP-%'")->fetchAll(PDO::FETCH_COLUMN));
        if ($moIds !== []) {
            $mol = implode(',', $moIds);
            if (table_exists('manufacturing_order_components')) {
                db()->exec("DELETE FROM manufacturing_order_components WHERE order_id IN ($mol)");
            }
            if (table_exists('manufacturing_order_costs')) {
                db()->exec("DELETE FROM manufacturing_order_costs WHERE order_id IN ($mol)");
            }
            db()->exec("DELETE FROM manufacturing_orders WHERE id IN ($mol)");
        }
    }
    if (table_exists('inventory_cost_layers')) {
        db()->exec("DELETE FROM inventory_cost_layers WHERE item_id IN ($sampleItemList)");
    }
    if (table_exists('inventory_transactions')) {
        db()->exec("DELETE FROM inventory_transactions WHERE item_id IN ($sampleItemList)");
    }
    if (table_exists('inventory_nrv_allowances')) {
        db()->exec("DELETE FROM inventory_nrv_allowances WHERE item_id IN ($sampleItemList)");
    }
    db()->exec("DELETE FROM inventory_items WHERE id IN ($sampleItemList)");
});

// 6. Work-portal chain: stages, assignees, tasks, contracts.
$step('tasks + contracts', static function () use ($sampleClientList, $sampleTaskList): void {
    if (table_exists('client_task_assignees')) {
        db()->exec("DELETE FROM client_task_assignees WHERE task_id IN ($sampleTaskList)");
    }
    if (table_exists('task_assignment_events')) {
        db()->exec("DELETE FROM task_assignment_events WHERE task_id IN ($sampleTaskList)");
    }
    db()->exec("DELETE FROM task_stages WHERE task_id IN ($sampleTaskList)");
    db()->exec("DELETE FROM client_tasks WHERE id IN ($sampleTaskList)");
    db()->exec("DELETE FROM service_contracts WHERE client_id IN ($sampleClientList)");
});

// 7. Parties + their per-party ledgers, sample bank/cash ledgers.
$step('parties + sample ledgers', static function () use ($cid): void {
    $ledgerIds = [];
    foreach (db()->query("SELECT ledger_id, payable_ledger_id FROM accounting_parties WHERE company_id = $cid AND code LIKE 'SMP-%'")->fetchAll() as $row) {
        foreach (['ledger_id', 'payable_ledger_id'] as $col) {
            if (!empty($row[$col])) {
                $ledgerIds[] = (int) $row[$col];
            }
        }
    }
    db()->exec("DELETE FROM accounting_parties WHERE company_id = $cid AND code LIKE 'SMP-%'");
    $sampleLedgers = array_map('intval', db()->query("SELECT id FROM ledgers WHERE code LIKE 'SMP-%' OR name LIKE 'Sample %'")->fetchAll(PDO::FETCH_COLUMN));
    $ledgerIds = array_unique(array_merge($ledgerIds, $sampleLedgers));
    if ($ledgerIds !== []) {
        $ll = implode(',', $ledgerIds);
        // Only remove ledgers no voucher still references.
        db()->exec("DELETE FROM ledgers WHERE id IN ($ll) AND id NOT IN (SELECT DISTINCT ledger_id FROM voucher_entries)");
    }
});

// 8. Client profile + sample users last.
$step('client profile + users', static function () use ($sampleClientList, $sampleUserList): void {
    if (table_exists('client_portal_users')) {
        db()->exec("DELETE FROM client_portal_users WHERE client_id IN ($sampleClientList) OR user_id IN ($sampleUserList)");
    }
    if (table_exists('client_service_provider_entities')) {
        db()->exec("DELETE FROM client_service_provider_entities WHERE client_id IN ($sampleClientList)");
    }
    db()->exec("DELETE FROM client_profiles WHERE id IN ($sampleClientList)");
    foreach (['staff_profiles' => 'user_id', 'staff_permissions' => 'user_id', 'company_memberships' => 'user_id', 'staff_client_accounting_access' => 'staff_user_id'] as $table => $column) {
        if (table_exists($table)) {
            db()->exec("DELETE FROM $table WHERE $column IN ($sampleUserList)");
        }
    }
    db()->exec("DELETE FROM users WHERE id IN ($sampleUserList)");
});

unseed_say('Done. Re-run database/seed_sample_data.php for a fresh seed.');
