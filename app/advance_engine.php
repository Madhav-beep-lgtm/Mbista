<?php
declare(strict_types=1);

/**
 * Client advances: money a client pays before a task begins.
 *
 * Firm books:   Dr Bank/Cash            Cr Advances from Customers (liability)
 * Client books (mirror, pending approval):
 *               Dr <Provider> Advance Paid (asset)   Cr Cash
 *
 * When an invoice is later raised for the task, the advance is applied against
 * it (Dr Advances from Customers / Cr the invoice's receivable) through a normal
 * payment request, so the receivable shown to the firm is automatically net of
 * the advance. Every posting is idempotent on its (source_type, source_id).
 */

/** "Advances from Customers" liability ledger for a company (found by code, else created). */
function ensure_customer_advance_ledger(int $companyId): int
{
    if ($companyId <= 0 || !table_exists('ledgers')) {
        return 0;
    }
    $stmt = db()->prepare("SELECT id FROM ledgers WHERE company_id = :cid AND code = 'CUST-ADV' LIMIT 1");
    $stmt->execute(['cid' => $companyId]);
    $ledgerId = (int) ($stmt->fetchColumn() ?: 0);
    if ($ledgerId > 0) {
        return $ledgerId;
    }
    $groupStmt = db()->prepare("SELECT id FROM ledger_groups
        WHERE company_id = :cid AND is_active = 1 AND is_cash_or_bank = 0
          AND (code = 'CURR_LIAB' OR master_key = 'current_liability')
        ORDER BY (code = 'CURR_LIAB') DESC, id ASC LIMIT 1");
    $groupStmt->execute(['cid' => $companyId]);
    $groupId = (int) ($groupStmt->fetchColumn() ?: 0);
    if ($groupId <= 0) {
        db()->prepare("INSERT INTO ledger_groups (company_id, code, name, master_key, is_cash_or_bank, is_system) VALUES (:cid, :code, 'Current Liabilities', 'current_liability', 0, 1)")
            ->execute(['cid' => $companyId, 'code' => coa_next_group_code($companyId, 'current_liability')]);
        $groupId = (int) db()->lastInsertId();
    }
    db()->prepare("INSERT INTO ledgers (company_id, group_id, code, name, type, is_system, status) VALUES (:cid, :gid, 'CUST-ADV', 'Advances from Customers', 'liability', 1, 'active')")
        ->execute(['cid' => $companyId, 'gid' => $groupId]);
    return (int) db()->lastInsertId();
}

/** Resolve a task's client party + mirror target. Returns null if the task is unusable. */
function advance_task_context(int $companyId, int $taskId): ?array
{
    if (!table_exists('client_tasks')) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM client_tasks WHERE id = :id AND company_id = :cid LIMIT 1');
    $stmt->execute(['id' => $taskId, 'cid' => $companyId]);
    $task = $stmt->fetch();
    if (!$task) {
        return null;
    }
    $partyId = (int) ($task['client_id'] ?? 0) > 0 ? ensure_party_for_client($companyId, (int) $task['client_id']) : 0;
    return ['task' => $task, 'party_id' => $partyId, 'client_id' => (int) ($task['client_id'] ?? 0)];
}

/** Total advance posted for a task (Cr to the advance-liability ledger). */
function task_advance_posted(int $companyId, int $taskId): float
{
    $stmt = db()->prepare("SELECT COALESCE(total_amount, 0) FROM vouchers
        WHERE company_id = :cid AND source_type = 'task_advance' AND source_id = :tid LIMIT 1");
    $stmt->execute(['cid' => $companyId, 'tid' => $taskId]);
    return round((float) ($stmt->fetchColumn() ?: 0), 2);
}

/** Advance already applied against this task's invoices. */
function task_advance_applied(int $companyId, int $taskId): float
{
    if (!table_exists('invoice_payment_requests')) {
        return 0.0;
    }
    $stmt = db()->prepare("SELECT COALESCE(SUM(pr.payment_amount), 0)
        FROM invoice_payment_requests pr
        INNER JOIN task_invoices ti ON ti.id = pr.invoice_id
        WHERE ti.company_id = :cid AND ti.task_id = :tid AND pr.payment_method = 'Advance applied'
          AND pr.status IN ('paid', 'partial')");
    $stmt->execute(['cid' => $companyId, 'tid' => $taskId]);
    return round((float) ($stmt->fetchColumn() ?: 0), 2);
}

/** Unapplied advance still available to offset this task's invoices. */
function task_advance_available(int $companyId, int $taskId): float
{
    return round(max(0.0, task_advance_posted($companyId, $taskId) - task_advance_applied($companyId, $taskId)), 2);
}

/**
 * Record a client advance for a task: post the firm receipt and mirror it into
 * the client's books. One advance per task (idempotent on task_advance/taskId).
 * Returns ['ok'=>bool, 'voucher_id'=>int, 'error'=>?string].
 */
function record_task_advance(int $companyId, int $taskId, float $amount, string $method, string $date, ?int $userId): array
{
    $amount = round($amount, 2);
    if ($amount <= 0) {
        return ['ok' => false, 'voucher_id' => 0, 'error' => 'Enter a positive advance amount.'];
    }
    if (task_advance_posted($companyId, $taskId) > 0) {
        return ['ok' => false, 'voucher_id' => 0, 'error' => 'An advance has already been recorded for this task.'];
    }
    $ctx = advance_task_context($companyId, $taskId);
    if (!$ctx) {
        return ['ok' => false, 'voucher_id' => 0, 'error' => 'Task not found for this company.'];
    }
    $cashLedger = get_mapped_ledger($companyId, 'default_cash_bank');
    $advanceLedgerId = ensure_customer_advance_ledger($companyId);
    $fiscalYearId = current_fiscal_year_id() ?: (int) (($def = resolve_default_company_and_fiscal_year()) ? $def['fiscal_year']['id'] : 0);
    if (!$cashLedger || $advanceLedgerId <= 0 || $fiscalYearId <= 0) {
        return ['ok' => false, 'voucher_id' => 0, 'error' => 'Map a cash/bank ledger in Settings before recording an advance.'];
    }
    $date = $date !== '' ? $date : date('Y-m-d');
    $taskTitle = (string) ($ctx['task']['title'] ?? ('task #' . $taskId));

    $pdo = db();
    $ownsTx = !$pdo->inTransaction();
    if ($ownsTx) {
        $pdo->beginTransaction();
    }
    try {
        $voucherId = create_voucher_with_entries([
            'company_id' => $companyId,
            'fiscal_year_id' => $fiscalYearId,
            'voucher_no' => 'ADV-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3))),
            'voucher_type' => 'receipt',
            'source_type' => 'task_advance',
            'source_id' => $taskId,
            'party_id' => $ctx['party_id'] > 0 ? $ctx['party_id'] : null,
            'voucher_date' => $date,
            'narration' => 'Advance received before commencement of ' . $taskTitle . ($method !== '' ? ' (' . $method . ')' : ''),
            'total_amount' => $amount,
            'status' => 'posted',
            'posted_by' => $userId,
        ], [
            ['ledger_id' => (int) $cashLedger['id'], 'entry_type' => 'debit', 'amount' => $amount, 'memo' => 'Advance received'],
            ['ledger_id' => $advanceLedgerId, 'entry_type' => 'credit', 'amount' => $amount, 'memo' => 'Advance from customer for ' . $taskTitle],
        ]);
        if ($ownsTx) {
            $pdo->commit();
        }
    } catch (Throwable $exception) {
        if ($ownsTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'voucher_id' => 0, 'error' => $exception->getMessage()];
    }

    // Mirror into the client's own books (Dr advance-to-provider asset / Cr cash),
    // pending the client's approval — best effort, never blocks the firm posting.
    try {
        if (function_exists('client_mirror_target') && !client_mirror_voucher_exists('task_advance_mirror', $taskId)) {
            $target = client_mirror_target(['company_id' => $companyId, 'task_id' => $taskId]);
            if ($target) {
                $assetLedger = client_mirror_ledger((int) $target['books_company_id'], 'advance_to_provider', $target['provider_name'] ?? null);
                $clientCash = client_mirror_ledger((int) $target['books_company_id'], 'cash');
                if ($assetLedger && $clientCash) {
                    client_mirror_create_voucher($target, 'task_advance_mirror', $taskId, 'payment',
                        'ADV-' . $taskId, $date, 'Advance paid to ' . ($target['provider_name'] ?? 'provider') . ' for ' . $taskTitle, $amount, [
                            ['ledger_id' => (int) $assetLedger['id'], 'entry_type' => 'debit', 'amount' => $amount, 'memo' => 'Advance paid'],
                            ['ledger_id' => (int) $clientCash['id'], 'entry_type' => 'credit', 'amount' => $amount, 'memo' => 'Paid from cash/bank'],
                        ], $userId);
                }
            }
        }
    } catch (Throwable $mirrorError) {
        // mirror is best-effort; the firm-side advance is already posted
    }

    return ['ok' => true, 'voucher_id' => $voucherId, 'error' => null];
}

/**
 * Apply any unapplied task advance against a freshly-issued invoice for that
 * task: records a payment (method "Advance applied") and posts a receipt that
 * moves the advance liability onto the invoice's receivable, so the outstanding
 * shown is net of the advance. Idempotent per invoice (advance_applied/invoiceId).
 */
function apply_task_advance_to_invoice(int $invoiceId, ?int $userId = null): void
{
    if ($invoiceId <= 0 || !table_exists('task_invoices') || !table_exists('invoice_payment_requests')) {
        return;
    }
    // Already applied to this invoice?
    $dup = db()->prepare("SELECT id FROM vouchers WHERE source_type = 'advance_applied' AND source_id = :id LIMIT 1");
    $dup->execute(['id' => $invoiceId]);
    if ($dup->fetch()) {
        return;
    }
    $invStmt = db()->prepare("SELECT ti.*,
            COALESCE((SELECT SUM(COALESCE(pr.payment_amount,0)) FROM invoice_payment_requests pr
                      WHERE pr.invoice_id = ti.id AND pr.status IN ('paid','partial')), 0) AS paid_amount
        FROM task_invoices ti WHERE ti.id = :id AND ti.status <> 'cancelled' LIMIT 1");
    $invStmt->execute(['id' => $invoiceId]);
    $invoice = $invStmt->fetch();
    if (!$invoice || (int) ($invoice['task_id'] ?? 0) <= 0) {
        return;
    }
    $companyId = (int) $invoice['company_id'];
    $taskId = (int) $invoice['task_id'];
    // Cheap pre-check: no advance on this task -> nothing to do (avoid opening a tx).
    if (task_advance_posted($companyId, $taskId) <= 0) {
        return;
    }
    $advanceLedgerId = ensure_customer_advance_ledger($companyId);
    $fiscalYearId = current_fiscal_year_id() ?: (int) (($def = resolve_default_company_and_fiscal_year()) ? $def['fiscal_year']['id'] : 0);
    if ($advanceLedgerId <= 0 || $fiscalYearId <= 0) {
        return;
    }
    // The receivable the invoice's sales voucher debited (same resolution as receipts).
    $arStmt = db()->prepare("SELECT ve.ledger_id FROM vouchers v
        INNER JOIN voucher_entries ve ON ve.voucher_id = v.id
        WHERE v.source_type = 'task_invoice' AND v.source_id = :iid AND v.status = 'posted' AND ve.entry_type = 'debit' LIMIT 1");
    $arStmt->execute(['iid' => $invoiceId]);
    $receivableLedgerId = (int) ($arStmt->fetchColumn() ?: 0);
    if ($receivableLedgerId <= 0) {
        $partyId = invoice_party_id(['party_id' => $invoice['party_id'] ?? null, 'task_id' => $taskId], $companyId);
        $receivableLedgerId = $partyId > 0 ? ensure_party_ledger($companyId, $partyId, 'receivable') : 0;
    }
    if ($receivableLedgerId <= 0) {
        return;
    }
    // Match the sales voucher's period so both legs land in the same fiscal year,
    // and a lock on an unrelated current period cannot block the offset.
    $voucherDate = (string) ($invoice['issued_on'] ?? '') !== '' ? (string) $invoice['issued_on'] : date('Y-m-d');

    $pdo = db();
    $ownsTx = !$pdo->inTransaction();
    if ($ownsTx) {
        $pdo->beginTransaction();
    }
    try {
        // Serialize concurrent applications for this task's advance: two invoices
        // issued at once must not both read the full available balance. Locking the
        // task_advance voucher row first makes the second caller block until the
        // first commits, then re-read the (now larger) applied total below.
        $lock = $pdo->prepare("SELECT id FROM vouchers WHERE company_id = :cid AND source_type = 'task_advance' AND source_id = :tid FOR UPDATE");
        $lock->execute(['cid' => $companyId, 'tid' => $taskId]);
        if (!$lock->fetch()) {
            if ($ownsTx) { $pdo->commit(); }
            return;
        }
        // Re-assert (under lock) that this invoice was not already settled by an advance.
        $dup2 = $pdo->prepare("SELECT id FROM vouchers WHERE source_type = 'advance_applied' AND source_id = :id LIMIT 1");
        $dup2->execute(['id' => $invoiceId]);
        if ($dup2->fetch()) {
            if ($ownsTx) { $pdo->commit(); }
            return;
        }
        // Recompute available + the invoice outstanding UNDER the lock.
        $available = task_advance_available($companyId, $taskId);
        $paidStmt = $pdo->prepare("SELECT COALESCE(SUM(payment_amount),0) FROM invoice_payment_requests WHERE invoice_id = :id AND status IN ('paid','partial')");
        $paidStmt->execute(['id' => $invoiceId]);
        $paid = round((float) $paidStmt->fetchColumn(), 2);
        $total = round((float) (($invoice['total_amount'] ?? 0) > 0 ? $invoice['total_amount'] : ($invoice['amount'] ?? 0)), 2);
        $outstanding = round($total - $paid, 2);
        $apply = min($available, $outstanding);
        if ($apply <= 0) {
            if ($ownsTx) { $pdo->commit(); }
            return;
        }
        $status = $apply >= $outstanding ? 'paid' : 'partial';
        $pdo->prepare('INSERT INTO invoice_payment_requests
                (invoice_id, company_id, requested_by, amount_requested, payment_method, status, payment_received_on, payment_amount, notes)
            VALUES (:iid, :cid, :by, :amt, :method, :status, :on, :amt2, :notes)')
            ->execute([
                'iid' => $invoiceId, 'cid' => $companyId, 'by' => $userId ?: null, 'amt' => $apply,
                'method' => 'Advance applied', 'status' => $status, 'on' => $voucherDate, 'amt2' => $apply,
                'notes' => 'Client advance applied to this invoice.',
            ]);
        create_voucher_with_entries([
            'company_id' => $companyId,
            'fiscal_year_id' => $fiscalYearId,
            'voucher_no' => 'ADVAPP-' . date('Ymd') . '-' . str_pad((string) $invoiceId, 6, '0', STR_PAD_LEFT),
            'voucher_type' => 'journal',
            'source_type' => 'advance_applied',
            'source_id' => $invoiceId,
            'reference_no' => $invoice['invoice_no'] ?? null,
            'voucher_date' => $voucherDate,
            'narration' => 'Client advance applied to invoice ' . ($invoice['invoice_no'] ?? ('#' . $invoiceId)),
            'total_amount' => $apply,
            'status' => 'posted',
            'posted_by' => $userId,
        ], [
            ['ledger_id' => $advanceLedgerId, 'entry_type' => 'debit', 'amount' => $apply, 'memo' => 'Advance applied'],
            ['ledger_id' => $receivableLedgerId, 'entry_type' => 'credit', 'amount' => $apply, 'memo' => 'Receivable settled by advance'],
        ]);
        if ($status === 'paid') {
            $pdo->prepare("UPDATE task_invoices SET status = 'paid' WHERE id = :id AND company_id = :cid AND status <> 'cancelled'")
                ->execute(['id' => $invoiceId, 'cid' => $companyId]);
        }
        if ($ownsTx) {
            $pdo->commit();
        }
    } catch (Throwable $exception) {
        if ($ownsTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // Surface a stranded advance rather than swallowing it silently.
        if (function_exists('log_activity')) {
            log_activity('task_invoice', $invoiceId, 'advance_apply_failed', 'Client advance could not be applied: ' . $exception->getMessage(), $userId);
        }
    }
}

/**
 * Residual invoice amount a caller should collect after any client advance has
 * been auto-applied — so a "mark paid on issue" shortcut doesn't charge the full
 * total on top of the advance (which would drive the receivable negative).
 */
function invoice_amount_after_advance(int $invoiceId): float
{
    if ($invoiceId <= 0 || !table_exists('task_invoices')) {
        return 0.0;
    }
    $stmt = db()->prepare("SELECT ti.total_amount, ti.amount,
            COALESCE((SELECT SUM(COALESCE(pr.payment_amount,0)) FROM invoice_payment_requests pr
                      WHERE pr.invoice_id = ti.id AND pr.status IN ('paid','partial')), 0) AS paid_amount
        FROM task_invoices ti WHERE ti.id = :id LIMIT 1");
    $stmt->execute(['id' => $invoiceId]);
    $row = $stmt->fetch();
    if (!$row) {
        return 0.0;
    }
    $total = round((float) (($row['total_amount'] ?? 0) > 0 ? $row['total_amount'] : ($row['amount'] ?? 0)), 2);
    return round(max(0.0, $total - (float) $row['paid_amount']), 2);
}
