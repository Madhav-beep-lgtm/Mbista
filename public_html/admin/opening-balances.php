<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/accounting_module_repair.php';
require_once __DIR__ . '/../../app/opening_balance_engine.php';
require_once __DIR__ . '/../../app/inventory_valuation.php';

require_staff_admin_or_client_books();
require_company_context();
require_permission('opening_balance', 'view');
accounting_module_repair_database();

$company = current_company();
$companyId = (int) ($company['id'] ?? 0);
$fiscalYear = current_fiscal_year();
$fiscalYearId = (int) ($fiscalYear['id'] ?? 0);
$currentUser = current_user();
$userId = (int) ($currentUser['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $postFyId = (int) ($_POST['fiscal_year_id'] ?? $fiscalYearId);

    if ($action === 'generate') {
        require_permission('opening_balance', 'generate');
        $res = ob_generate_batch($companyId, $postFyId, $userId);
        if ($res['ok']) {
            flash('success', 'Opening balances generated from the previous fiscal year.' . (!empty($res['warning']) ? ' ' . $res['warning'] : ''));
        } else {
            flash('error', (string) ($res['error'] ?? 'Could not generate opening balances.'));
        }
        redirect('admin/opening-balances.php');
    }
    if ($action === 'review') {
        require_permission('opening_balance', 'generate');
        $batch = ob_get_batch($companyId, $postFyId);
        $res = $batch ? ob_review_batch((int) $batch['id'], $userId) : ['ok' => false, 'error' => 'Generate the batch first.'];
        flash($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Marked reviewed.' : (string) $res['error']);
        redirect('admin/opening-balances.php');
    }
    if ($action === 'finalize') {
        require_permission('opening_balance', 'finalize');
        $batch = ob_get_batch($companyId, $postFyId);
        $res = $batch ? ob_finalize_batch((int) $batch['id'], $userId) : ['ok' => false, 'error' => 'Generate the batch first.'];
        flash($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Opening balances finalized.' : (string) $res['error']);
        redirect('admin/opening-balances.php');
    }
    if ($action === 'lock') {
        require_permission('opening_balance', 'finalize');
        $batch = ob_get_batch($companyId, $postFyId);
        $res = $batch ? ob_lock_batch((int) $batch['id'], $userId) : ['ok' => false, 'error' => 'Finalize the batch first.'];
        flash($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Opening balances locked.' : (string) $res['error']);
        redirect('admin/opening-balances.php');
    }
    if ($action === 'unlock') {
        require_permission('opening_balance', 'finalize');
        $batch = ob_get_batch($companyId, $postFyId);
        $res = $batch ? ob_unlock_batch((int) $batch['id'], (string) ($_POST['reason'] ?? ''), $userId) : ['ok' => false, 'error' => 'No opening batch to unlock.'];
        flash($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Opening balances unlocked — corrections can be made through adjustments, then re-lock.' : (string) $res['error']);
        redirect('admin/opening-balances.php');
    }
    if ($action === 'backfill_inventory_opening') {
        // Post the missing opening-stock vouchers for items created before the
        // opening-GL fix (save_item now posts them; existing items only get
        // theirs on re-save — this does them all in one audited sweep).
        require_permission('opening_balance', 'adjust');
        $itemsStmt = db()->prepare("SELECT * FROM inventory_items WHERE company_id = :cid AND opening_qty > 0");
        $itemsStmt->execute(['cid' => $companyId]);
        $posted = 0; $kept = 0; $skipped = [];
        foreach ($itemsStmt->fetchAll(PDO::FETCH_ASSOC) as $invItem) {
            $before = (int) db()->query("SELECT COALESCE((SELECT id FROM vouchers WHERE source_type='inventory_opening' AND source_id=" . (int) $invItem['id'] . " LIMIT 1), 0)")->fetchColumn();
            $res = inv_post_item_opening_voucher($companyId, $invItem, $userId);
            if (($res['note'] ?? '') !== '') {
                $skipped[] = (string) $invItem['sku'];
            } elseif ((int) $res['voucher_id'] > 0 && (int) $res['voucher_id'] !== $before) {
                $posted++;
            } elseif ((int) $res['voucher_id'] > 0) {
                $kept++;
            }
        }
        log_activity('opening_balance', $companyId, 'inventory_backfill', "Opening-stock voucher backfill: $posted posted, $kept already current, " . count($skipped) . ' unmapped.', $userId);
        $msg = $posted . ' opening-stock voucher(s) posted, ' . $kept . ' already in the books.';
        if ($skipped !== []) {
            $msg .= ' Skipped (no Inventory Asset / Opening Equity mapping): ' . implode(', ', array_slice($skipped, 0, 8)) . (count($skipped) > 8 ? ' +' . (count($skipped) - 8) . ' more' : '') . ' — map their ledgers on the item, then run this again.';
        }
        flash($skipped === [] ? 'success' : 'error', $msg);
        redirect('admin/opening-balances.php');
    }

    if ($action === 'adjust') {
        require_permission('opening_balance', 'adjust');
        $batch = ob_get_batch($companyId, $postFyId);
        $lineId = (int) ($_POST['line_id'] ?? 0);
        $revDr = max(0.0, (float) ($_POST['revised_debit'] ?? 0));
        $revCr = max(0.0, (float) ($_POST['revised_credit'] ?? 0));
        $reason = (string) ($_POST['reason'] ?? '');
        $reference = trim((string) ($_POST['reference'] ?? ''));
        if (!$batch) {
            flash('error', 'Generate the opening batch before adjusting.');
        } else {
            $res = ob_apply_adjustment((int) $batch['id'], $lineId, $revDr, $revCr, $reason, $reference, $userId);
            flash($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Opening balance adjusted and a balanced adjustment journal was posted.' : (string) $res['error']);
        }
        redirect('admin/opening-balances.php');
    }
    redirect('admin/opening-balances.php');
}

// ---------------------------------------------------------------------------
// Read model for the selected fiscal year.
// ---------------------------------------------------------------------------
$prevFy = ob_previous_fiscal_year($companyId, $fiscalYearId);
$batch = ob_get_batch($companyId, $fiscalYearId);
$lines = $batch ? ob_get_lines((int) $batch['id']) : [];
$computedRows = ob_computed_opening_rows($companyId, $fiscalYearId);
$validation = $batch ? ob_validate_batch((int) $batch['id']) : ['balanced' => false, 'total_debit' => 0.0, 'total_credit' => 0.0, 'difference' => 0.0];
$recon = ob_subledger_reconciliation($companyId, $fiscalYearId);
$invOpen = ob_inventory_opening($companyId, $fiscalYearId);
$faOpen = ob_fixed_asset_opening($companyId, $fiscalYearId);
$auditLogs = $batch ? ob_audit_logs((int) $batch['id']) : [];

$canAdjust = user_can_do('opening_balance', 'adjust');
$canFinalize = user_can_do('opening_balance', 'finalize');
$canGenerate = user_can_do('opening_balance', 'generate');
$sym = site_currency_symbol();
$status = $batch ? (string) $batch['status'] : 'not_generated';
$isLocked = in_array($status, ['finalized', 'locked'], true);
// Adjustments are the controlled correction path and stay open until the batch
// is HARD-locked — this mirrors ob_apply_adjustment(), which blocks only the
// 'locked' state. A 'finalized' batch (including one just unlocked back from
// 'locked') is still adjustable by an authorised user, so gate the Adjust
// controls on this, not on $isLocked (which also covers 'finalized' and is used
// to stop regeneration). Without this the Adjust column vanished after unlock.
$canAdjustNow = $canAdjust && $batch && $status !== 'locked';

$filterType = (string) ($_GET['type'] ?? '');
$filterSide = (string) ($_GET['side'] ?? '');
$filterQuery = trim((string) ($_GET['q'] ?? ''));

$nameOf = static fn (?int $id): string => $id ? (string) (fiscal_year_by_id($id)['label'] ?? ('#' . $id)) : '—';
$userName = static function (?int $id): string {
    if (!$id) { return '—'; }
    $u = db()->prepare('SELECT name FROM users WHERE id = :id LIMIT 1');
    $u->execute(['id' => $id]);
    return (string) ($u->fetchColumn() ?: ('#' . $id));
};

$pageTitle = 'Opening Balances';
$pageSubtitle = 'Balances brought forward from the previous fiscal year — reviewed, adjusted and finalized.';
$bodyClass = 'admin-layout accounting-module-page';
include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
<section class="mbw-card">
    <div class="mbw-card-head">
        <h2>Opening balance status</h2>
        <div class="mbw-card-tools">
            <span class="mbw-pill <?= $status === 'locked' ? 'tone-green' : ($status === 'finalized' ? 'tone-green' : ($status === 'not_generated' ? 'tone-amber' : 'tone-blue')) ?>">
                <?= e(ucfirst(str_replace('_', ' ', $status))) ?>
            </span>
        </div>
    </div>
    <div style="overflow-x:auto">
    <table>
        <tbody>
            <tr><th style="text-align:left">Entity</th><td><?= e((string) ($company['name'] ?? '')) ?></td>
                <th style="text-align:left">Current fiscal year</th><td><?= e((string) ($fiscalYear['label'] ?? '')) ?> (<?= e((string) ($fiscalYear['start_date'] ?? '')) ?> to <?= e((string) ($fiscalYear['end_date'] ?? '')) ?>)</td></tr>
            <tr><th style="text-align:left">Previous fiscal year</th><td><?= e($prevFy ? (string) $prevFy['label'] : 'None (first year of the entity)') ?></td>
                <th style="text-align:left">Previous year closing date</th><td><?= e($prevFy ? (string) $prevFy['end_date'] : '—') ?></td></tr>
            <tr><th style="text-align:left">Current year start date</th><td><?= e((string) ($fiscalYear['start_date'] ?? '')) ?></td>
                <th style="text-align:left">Basis</th><td><?= e($batch ? ucfirst(str_replace('_', ' ', (string) $batch['basis'])) : ($prevFy ? 'Carried forward' : 'Initial manual')) ?></td></tr>
            <tr><th style="text-align:left">Generated by / at</th><td><?= e($batch ? $userName((int) ($batch['generated_by'] ?? 0)) . ' — ' . (string) ($batch['generated_at'] ?? '') : '—') ?></td>
                <th style="text-align:left">Finalized by / at</th><td><?= e($batch && $batch['finalized_by'] ? $userName((int) $batch['finalized_by']) . ' — ' . (string) $batch['finalized_at'] : '—') ?></td></tr>
            <tr><th style="text-align:left">Total opening debit</th><td><strong><?= e($sym . number_format((float) $validation['total_debit'], 2)) ?></strong></td>
                <th style="text-align:left">Total opening credit</th><td><strong><?= e($sym . number_format((float) $validation['total_credit'], 2)) ?></strong></td></tr>
            <tr><th style="text-align:left">Difference (must be 0)</th>
                <td colspan="3"><span class="mbw-pill <?= $validation['balanced'] ? 'tone-green' : 'tone-red' ?>"><?= e($sym . number_format((float) $validation['difference'], 2)) ?> <?= $validation['balanced'] ? '(balanced)' : '(unbalanced — resolve before finalizing)' ?></span></td></tr>
        </tbody>
    </table>
    </div>
    <div class="mbw-card-tools" style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap">
        <?php if ($canGenerate && !$isLocked): ?>
        <form method="post" style="display:inline" data-confirm="<?= $batch ? 'Refresh opening balances from the previous fiscal year? Existing admin adjustments are preserved; posted transactions are not touched.' : 'Generate opening balances from the previous fiscal year?' ?>">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="generate">
            <input type="hidden" name="fiscal_year_id" value="<?= e($fiscalYearId) ?>">
            <button type="submit"><?= icon('reconcile') ?><?= $batch ? 'Regenerate from previous year' : 'Generate opening balances' ?></button>
        </form>
        <?php endif; ?>
        <?php if ($canGenerate && $batch && $status === 'generated'): ?>
        <form method="post" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="review">
            <input type="hidden" name="fiscal_year_id" value="<?= e($fiscalYearId) ?>">
            <button type="submit" class="button secondary"><?= icon('badge-check') ?>Mark reviewed</button>
        </form>
        <?php endif; ?>
        <?php if ($canFinalize && $batch && in_array($status, ['generated', 'reviewed'], true)): ?>
        <form method="post" style="display:inline" data-confirm="Finalize the opening balances? Ordinary users will no longer be able to change them.">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="finalize">
            <input type="hidden" name="fiscal_year_id" value="<?= e($fiscalYearId) ?>">
            <button type="submit" <?= $validation['balanced'] ? '' : 'disabled title="Debits must equal credits before finalizing"' ?>><?= icon('badge-check') ?>Finalize</button>
        </form>
        <?php endif; ?>
        <?php if ($canFinalize && $batch && $status === 'finalized'): ?>
        <form method="post" style="display:inline" data-confirm="Lock the finalized opening balances? Corrections afterwards must be made through a new approved adjustment.">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="lock">
            <input type="hidden" name="fiscal_year_id" value="<?= e($fiscalYearId) ?>">
            <button type="submit" class="button secondary"><?= icon('key') ?>Lock</button>
        </form>
        <?php endif; ?>
        <?php if ($canFinalize && $batch && $status === 'locked'): ?>
        <details class="pr-adjust">
            <summary class="button secondary" style="display:inline-flex;align-items:center;gap:6px"><?= icon('key') ?>Unlock</summary>
            <form method="post" class="pr-adjust-form" style="min-width:260px">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="unlock">
                <input type="hidden" name="fiscal_year_id" value="<?= e($fiscalYearId) ?>">
                <label>Reason for unlocking (required, min 10 chars)<input type="text" name="reason" minlength="10" required placeholder="e.g. Prior-year audit adjustment received"></label>
                <button type="submit">Unlock opening balances</button>
                <small>Returns the batch to finalized so corrections can be made through adjustments; the lock/unlock is audited and re-lockable.</small>
            </form>
        </details>
        <?php endif; ?>
    </div>
    <?php if (!$prevFy): ?>
        <p class="muted" style="margin-top:10px">This is the entity's first fiscal year — there is no previous year to carry forward. Enter initial opening balances as authorised adjustments (or via each ledger's opening-balance field); the temporary Opening Balance Adjustments account holds any difference until the initial set is balanced.</p>
    <?php endif; ?>
</section>

<?php if ($batch): ?>
<section class="mbw-card">
    <div class="mbw-card-head"><h2>Opening balance detail</h2></div>
    <form method="get" class="workspace-form-grid" style="margin-bottom:12px">
        <label>Account type
            <select name="type" onchange="this.form.submit()">
                <option value="">All balance-sheet types</option>
                <?php foreach (['asset' => 'Assets', 'liability' => 'Liabilities', 'equity' => 'Equity'] as $tv => $tl): ?>
                    <option value="<?= e($tv) ?>" <?= $filterType === $tv ? 'selected' : '' ?>><?= e($tl) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Balance
            <select name="side" onchange="this.form.submit()">
                <option value="">All</option>
                <option value="debit" <?= $filterSide === 'debit' ? 'selected' : '' ?>>Debit balances</option>
                <option value="credit" <?= $filterSide === 'credit' ? 'selected' : '' ?>>Credit balances</option>
                <option value="adjusted" <?= $filterSide === 'adjusted' ? 'selected' : '' ?>>Adjusted balances</option>
            </select>
        </label>
        <label>Search<input type="search" name="q" value="<?= e($filterQuery) ?>" placeholder="Account code or name"></label>
        <div><button type="submit" class="button secondary">Apply</button></div>
    </form>
    <div style="overflow-x:auto">
    <table>
        <thead><tr>
            <th>Code</th><th>Account</th><th>Type</th>
            <th class="is-numeric">Prev. closing Dr</th><th class="is-numeric">Prev. closing Cr</th>
            <th class="is-numeric">System Dr</th><th class="is-numeric">System Cr</th>
            <th class="is-numeric">Adj. Dr</th><th class="is-numeric">Adj. Cr</th>
            <th class="is-numeric">Final Dr</th><th class="is-numeric">Final Cr</th>
            <th>Reason</th><?php if ($canAdjustNow): ?><th></th><?php endif; ?>
        </tr></thead>
        <tbody>
            <?php
            $shown = 0;
            foreach ($lines as $l):
                $applicable = (int) $l['is_applicable'] === 1;
                if ($filterType !== '' && (string) $l['account_type'] !== $filterType) { continue; }
                if ($filterQuery !== '' && stripos((string) $l['line_label'] . ' ' . (string) ($l['line_key']), $filterQuery) === false) { continue; }
                $finalDr = (float) $l['final_opening_debit']; $finalCr = (float) $l['final_opening_credit'];
                $hasAdj = abs((float) $l['adjustment_debit']) > 0.005 || abs((float) $l['adjustment_credit']) > 0.005;
                if ($filterSide === 'debit' && $finalDr <= 0.005) { continue; }
                if ($filterSide === 'credit' && $finalCr <= 0.005) { continue; }
                if ($filterSide === 'adjusted' && !$hasAdj) { continue; }
                $shown++;
            ?>
                <tr>
                    <td><?php
                        $lcode = '';
                        if ($l['ledger_id']) { $cs = db()->prepare('SELECT code FROM ledgers WHERE id = :id'); $cs->execute(['id' => (int) $l['ledger_id']]); $lcode = (string) ($cs->fetchColumn() ?: ''); }
                        echo e($lcode);
                    ?></td>
                    <td>
                        <?= e((string) $l['line_label']) ?>
                        <?php if ($l['ledger_id']): ?>
                            <a class="muted" style="font-size:11px" href="<?= e(url('admin/ledgers.php?ledger_id=' . (int) $l['ledger_id'])) ?>" title="Open ledger">↗ ledger</a>
                        <?php endif; ?>
                    </td>
                    <td><?= e(ucfirst((string) $l['account_type'])) ?></td>
                    <?php if (!$applicable): ?>
                        <td colspan="8" class="muted">—</td>
                    <?php else: ?>
                        <td class="is-numeric"><?= $l['previous_closing_debit'] > 0 ? e(number_format((float) $l['previous_closing_debit'], 2)) : '–' ?></td>
                        <td class="is-numeric"><?= $l['previous_closing_credit'] > 0 ? e(number_format((float) $l['previous_closing_credit'], 2)) : '–' ?></td>
                        <td class="is-numeric"><?= $l['system_opening_debit'] > 0 ? e(number_format((float) $l['system_opening_debit'], 2)) : '–' ?></td>
                        <td class="is-numeric"><?= $l['system_opening_credit'] > 0 ? e(number_format((float) $l['system_opening_credit'], 2)) : '–' ?></td>
                        <td class="is-numeric"><?= abs((float) $l['adjustment_debit']) > 0.005 ? e(number_format((float) $l['adjustment_debit'], 2)) : '–' ?></td>
                        <td class="is-numeric"><?= abs((float) $l['adjustment_credit']) > 0.005 ? e(number_format((float) $l['adjustment_credit'], 2)) : '–' ?></td>
                        <td class="is-numeric"><strong><?= $finalDr > 0 ? e(number_format($finalDr, 2)) : '–' ?></strong></td>
                        <td class="is-numeric"><strong><?= $finalCr > 0 ? e(number_format($finalCr, 2)) : '–' ?></strong></td>
                    <?php endif; ?>
                    <td style="font-size:11px"><?= e((string) ($l['adjustment_reason'] ?? '')) ?></td>
                    <?php if ($canAdjustNow): ?>
                        <td>
                            <?php if ($applicable && $l['ledger_id']): ?>
                            <details class="pr-adjust">
                                <summary class="button secondary" style="padding:4px 10px">Adjust</summary>
                                <form method="post" class="pr-adjust-form" style="min-width:240px">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="adjust">
                                    <input type="hidden" name="fiscal_year_id" value="<?= e($fiscalYearId) ?>">
                                    <input type="hidden" name="line_id" value="<?= e((int) $l['id']) ?>">
                                    <label>Revised debit<input type="number" step="0.01" min="0" name="revised_debit" value="<?= e(number_format($finalDr, 2, '.', '')) ?>"></label>
                                    <label>Revised credit<input type="number" step="0.01" min="0" name="revised_credit" value="<?= e(number_format($finalCr, 2, '.', '')) ?>"></label>
                                    <label>Reason (required, min 10 chars)<input type="text" name="reason" minlength="10" required></label>
                                    <label>Reference / document<input type="text" name="reference" placeholder="optional"></label>
                                    <button type="submit">Save adjustment</button>
                                    <small>A balanced adjustment journal is posted; a full audit record is kept.</small>
                                </form>
                            </details>
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            <?php if ($shown === 0): ?><tr><td colspan="<?= $canAdjustNow ? 13 : 12 ?>" class="muted">No lines match the filters.</td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
    <style>
    .pr-adjust{position:relative;display:inline-block}
    .pr-adjust>summary{list-style:none;cursor:pointer}.pr-adjust>summary::-webkit-details-marker{display:none}
    .pr-adjust-form{position:absolute;right:0;z-index:40;margin-top:6px;display:grid;gap:8px;padding:12px;text-align:left;
        background:var(--mbw-card,#fff);color:var(--mbw-ink,#12261f);border:1px solid var(--mbw-line,rgba(0,0,0,.16));border-radius:10px;box-shadow:0 14px 34px rgba(0,0,0,.22)}
    .pr-adjust-form label{display:grid;gap:3px;font-size:12px;font-weight:600}
    .pr-adjust-form input{min-height:34px}.pr-adjust-form small{color:var(--mbw-muted,#5b6b64);font-weight:400;font-size:11px}
    </style>
</section>

<section class="mbw-card">
    <div class="mbw-card-head"><h2>Sub-ledger reconciliation</h2></div>
    <div style="overflow-x:auto">
    <table>
        <thead><tr><th>Sub-ledger</th><th class="is-numeric">Sub-ledger total</th><th class="is-numeric">Control account opening</th><th>Status</th></tr></thead>
        <tbody>
            <?php foreach ([
                'receivable' => ['label' => 'Customers → Accounts Receivable', 'link' => 'receivable ledger (Parties → link customer)'],
                'payable' => ['label' => 'Suppliers → Accounts Payable', 'link' => 'payable ledger (Parties → link supplier)'],
            ] as $reconSide => $reconMeta):
                $reconRow = $recon[$reconSide];
                $reconOk = ob_near((float) $reconRow['subledger'], (float) $reconRow['control']);
            ?>
            <tr>
                <td><?= e($reconMeta['label']) ?></td>
                <td class="is-numeric"><?= e($sym . number_format((float) $reconRow['subledger'], 2)) ?></td>
                <td class="is-numeric"><?= e($sym . number_format((float) $reconRow['control'], 2)) ?></td>
                <td><span class="mbw-pill <?= $reconOk ? 'tone-green' : 'tone-red' ?>"><?= $reconOk ? 'Reconciled' : 'Investigate' ?></span></td>
            </tr>
            <?php if (!$reconOk && (($reconRow['control_ledgers'] ?? []) !== [] || ($reconRow['excluded'] ?? []) !== [])): ?>
            <tr>
                <td colspan="4" style="padding-top:0">
                    <details>
                        <summary style="cursor:pointer;font-size:12px;color:var(--mbw-muted)">Why the difference — control-ledger breakdown &amp; what to do</summary>
                        <table style="margin-top:6px;font-size:12.5px">
                            <thead><tr><th>Control ledger</th><th class="is-numeric">Opening</th><th>Linked party</th><th>Action</th></tr></thead>
                            <tbody>
                                <?php foreach (($reconRow['control_ledgers'] ?? []) as $cl): ?>
                                <tr>
                                    <td><a href="<?= e(url('admin/ledgers.php?ledger_id=' . (int) $cl['ledger_id'])) ?>"><?= e($cl['name']) ?></a></td>
                                    <td class="is-numeric"><?= e($sym . number_format((float) $cl['opening'], 2)) ?></td>
                                    <td><?= $cl['party'] !== null ? e($cl['party']) : '<span class="mbw-pill tone-amber">not linked</span>' ?></td>
                                    <td style="font-size:12px;color:var(--mbw-muted)"><?= $cl['party'] !== null
                                        ? 'Counted in the sub-ledger — reconciled by construction.'
                                        : 'Not counted in the sub-ledger: link this ledger to a party as its ' . e($reconMeta['link']) . ', or move the ledger out of the trade group if it is not a ' . ($reconSide === 'payable' ? 'supplier' : 'customer') . ' balance.' ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php foreach (($reconRow['excluded'] ?? []) as $ex): ?>
                                <tr>
                                    <td><?= e($ex['name']) ?></td>
                                    <td class="is-numeric"><?= e($sym . number_format((float) $ex['opening'], 2)) ?></td>
                                    <td><span class="mbw-pill tone-blue">utility</span></td>
                                    <td style="font-size:12px;color:var(--mbw-muted)">Inventory utility ledger (clearing / allowance) — excluded from the trade control; consider moving it to its own group.</td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </details>
                </td>
            </tr>
            <?php endif; ?>
            <?php endforeach; ?>
            <?php $invReconOk = ob_near((float) $invOpen['total_value'], (float) $invOpen['control_opening']); ?>
            <tr>
                <td>Inventory items → Inventory control</td>
                <td class="is-numeric"><?= e($sym . number_format((float) $invOpen['total_value'], 2)) ?></td>
                <td class="is-numeric"><?= e($sym . number_format((float) $invOpen['control_opening'], 2)) ?></td>
                <td><span class="mbw-pill <?= $invReconOk ? 'tone-green' : 'tone-amber' ?>"><?= $invReconOk ? 'Reconciled' : 'Review' ?></span></td>
            </tr>
            <?php if (!$invReconOk && $canAdjust): ?>
            <tr>
                <td colspan="4" style="padding-top:0">
                    <form method="post" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap" data-confirm="Post the missing opening-stock vouchers (Dr item stock ledger / Cr Opening Balance Equity) for every item with a master opening quantity? Already-posted items are left untouched.">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="backfill_inventory_opening">
                        <button type="submit" class="button secondary" style="min-height:34px"><?= icon('reconcile') ?>Post missing opening-stock vouchers</button>
                        <small style="color:var(--mbw-muted)">Items created before opening-stock GL posting existed have layers but no voucher — this backfills them (idempotent). Items valued at a zero purchase rate contribute nothing; set their rate first.</small>
                    </form>
                </td>
            </tr>
            <?php endif; ?>
            <tr>
                <td>Fixed assets (NBV) → PPE net control</td>
                <td class="is-numeric"><?= e($sym . number_format((float) $faOpen['total_nbv'], 2)) ?></td>
                <td class="is-numeric">cost <?= e(number_format((float) $faOpen['total_cost'], 2)) ?> − dep <?= e(number_format((float) $faOpen['total_accdep'], 2)) ?></td>
                <td><span class="mbw-pill tone-blue"><?= count($faOpen['assets']) ?> assets</span></td>
            </tr>
        </tbody>
    </table>
    </div>
    <p class="muted" style="margin-top:8px;font-size:12px">Each party posts to its own dedicated ledger, so the sub-ledger totals equal the control-account openings by construction. Inventory and fixed-asset openings are derived read-only from the perpetual sub-ledgers as at the fiscal-year start and reconciled to the general ledger.</p>
</section>

<?php if ($auditLogs !== []): ?>
<section class="mbw-card">
    <div class="mbw-card-head"><h2>Adjustment &amp; audit trail</h2></div>
    <div style="overflow-x:auto">
    <table>
        <thead><tr><th>When</th><th>Action</th><th>User</th><th>Reason</th><th>Details</th></tr></thead>
        <tbody>
            <?php foreach ($auditLogs as $log): ?>
                <tr>
                    <td style="font-size:11px"><?= e((string) $log['performed_at']) ?></td>
                    <td><span class="mbw-pill tone-blue"><?= e((string) $log['action']) ?></span></td>
                    <td><?= e((string) ($log['performed_by_name'] ?? ('#' . $log['performed_by']))) ?></td>
                    <td style="font-size:12px"><?= e((string) ($log['reason'] ?? '')) ?></td>
                    <td style="font-size:11px" class="muted"><?= e((string) ($log['new_values'] ?? '')) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>
<?php endif; ?>
<?php else: ?>
<section class="mbw-card">
    <div class="mbw-card-head"><h2>Preview — opening balances that would be generated</h2></div>
    <p class="muted">No opening-balance batch exists for this fiscal year yet. The figures below are the balances carried forward from the previous fiscal year (asset/liability/equity accounts only; income and expense accounts always open at zero). Click <strong>Generate opening balances</strong> above to create the reviewable batch.</p>
    <div style="overflow-x:auto">
    <table>
        <thead><tr><th>Code</th><th>Account</th><th>Type</th><th class="is-numeric">Opening Dr</th><th class="is-numeric">Opening Cr</th></tr></thead>
        <tbody>
            <?php foreach ($computedRows as $r): if (!$r['is_applicable']) { continue; } if (abs((float) $r['opening_net']) < 0.005) { continue; } ?>
                <tr>
                    <td><?= e((string) $r['code']) ?></td>
                    <td><?= e((string) $r['name']) ?></td>
                    <td><?= e(ucfirst((string) $r['nature'])) ?></td>
                    <td class="is-numeric"><?= (float) $r['opening_dr'] > 0 ? e(number_format((float) $r['opening_dr'], 2)) : '–' ?></td>
                    <td class="is-numeric"><?= (float) $r['opening_cr'] > 0 ? e(number_format((float) $r['opening_cr'], 2)) : '–' ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>
<?php endif; ?>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
