<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/accounting_module_repair.php';
// jewellery_reports.php chains in the workshop, trade and stock engines.
require_once __DIR__ . '/../../app/jewellery_reports.php';

// Same server-side gate as every other jewellery page.
accounting_module_repair_database();
require_jewellery();

$company = current_company();
$fiscalYear = current_fiscal_year();
if (!$company || !$fiscalYear) {
    flash('error', 'Company and fiscal year context required.');
    redirect('admin/accounting-dashboard.php');
}
$companyId = (int) $company['id'];
$fiscalYearId = (int) $fiscalYear['id'];
$fyStart = (string) $fiscalYear['start_date'];
$fyEnd = (string) $fiscalYear['end_date'];
$currentUser = current_user();
$userId = (int) ($currentUser['id'] ?? 0);
$sym = site_currency_symbol();

$settings = jewellery_settings($companyId);
$canEdit = user_can_do('jewellery', 'edit');
$canPost = user_can_do('jewellery', 'post');

$allowedViews = ['purchases', 'sales', 'bills'];
$view = jw_enum($_GET['view'] ?? null, $allowedViews, 'purchases');

$clampDate = static function (string $date) use ($fyStart, $fyEnd): string {
    if ($date === '' || strtotime($date) === false) {
        $date = date('Y-m-d');
    }
    return $date < $fyStart ? $fyStart : ($date > $fyEnd ? $fyEnd : $date);
};
$todayInFy = $clampDate(date('Y-m-d'));

// ---------------------------------------------------------------------------
// Actions
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $back = 'admin/jewellery-trade.php?view=' . urlencode((string) ($_POST['back_view'] ?? $view));

    if ($action === 'save_purchase') {
        require_permission('jewellery', 'edit');
        try {
            $id = jewellery_save_purchase($companyId, $fiscalYearId, [
                'id' => (int) ($_POST['purchase_id'] ?? 0),
                'purchase_date' => $clampDate((string) ($_POST['purchase_date'] ?? '')),
                'party_id' => (int) ($_POST['party_id'] ?? 0),
                'source' => (string) ($_POST['source'] ?? 'supplier'),
                'ref_no' => (string) ($_POST['ref_no'] ?? ''),
                'narration' => (string) ($_POST['narration'] ?? ''),
                'other_charges' => (float) ($_POST['other_charges'] ?? 0),
                'manual_tax_amount' => ($_POST['manual_tax_amount'] ?? '') === '' ? null : (float) $_POST['manual_tax_amount'],
                'discount' => (float) ($_POST['discount'] ?? 0),
                'settle_mode' => (string) ($_POST['settle_mode'] ?? 'credit'),
                'settle_ledger_id' => (int) ($_POST['settle_ledger_id'] ?? 0),
            ], jw_posted_lines($_POST, 'l'), $userId);
            flash('success', 'Purchase saved as a draft.');
            redirect($back . '&edit=' . $id);
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect($back);
    }

    if ($action === 'save_sale') {
        require_permission('jewellery', 'edit');
        try {
            $id = jewellery_save_sale($companyId, $fiscalYearId, [
                'id' => (int) ($_POST['sale_id'] ?? 0),
                'sale_date' => $clampDate((string) ($_POST['sale_date'] ?? '')),
                'party_id' => (int) ($_POST['party_id'] ?? 0),
                'customer_name' => (string) ($_POST['customer_name'] ?? ''),
                'ref_no' => (string) ($_POST['ref_no'] ?? ''),
                'narration' => (string) ($_POST['narration'] ?? ''),
                'other_charges' => (float) ($_POST['other_charges'] ?? 0),
                'manual_tax_amount' => ($_POST['manual_tax_amount'] ?? '') === '' ? null : (float) $_POST['manual_tax_amount'],
                'discount' => (float) ($_POST['discount'] ?? 0),
                'received_amount' => (float) ($_POST['received_amount'] ?? 0),
                'deliver_order_id' => (int) ($_POST['deliver_order_id'] ?? 0),
                'advance_amount' => (float) ($_POST['advance_amount'] ?? 0),
                'settle_mode' => (string) ($_POST['settle_mode'] ?? 'cash'),
                'settle_ledger_id' => (int) ($_POST['settle_ledger_id'] ?? 0),
            ], jw_posted_lines($_POST, 'l'), jw_posted_lines($_POST, 'x'), $userId);
            // Selling an order closes it. Doing it here rather than on posting
            // means the counter sees the order leave the pending-delivery board
            // the moment the bill is raised, which is when the piece changes
            // hands.
            $deliverOrderId = (int) ($_POST['deliver_order_id'] ?? 0);
            $deliverNote = '';
            if ($deliverOrderId > 0) {
                $delivered = jewellery_deliver_order($companyId, $deliverOrderId, $id, $userId);
                $deliverNote = $delivered['ok']
                    ? ' Order marked delivered.'
                    : ' The sale was saved, but the order could not be closed: ' . $delivered['error'];
            }
            flash('success', 'Sale saved as a draft.' . $deliverNote);
            redirect($back . '&edit=' . $id);
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect($back);
    }

    if ($action === 'post_purchase' || $action === 'post_sale') {
        require_permission('jewellery', 'post');
        $id = (int) ($_POST['doc_id'] ?? 0);
        $result = $action === 'post_purchase'
            ? jewellery_post_purchase($companyId, $id, $userId)
            : jewellery_post_sale($companyId, $id, $userId);
        flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? ('Posted to the ledger (voucher #' . $result['voucher_id'] . ').') : $result['error']);
        redirect($back);
    }

    if ($action === 'unpost_purchase' || $action === 'unpost_sale') {
        require_permission('jewellery', 'post');
        $id = (int) ($_POST['doc_id'] ?? 0);
        $result = $action === 'unpost_purchase'
            ? jewellery_unpost_purchase($companyId, $id, $userId)
            : jewellery_unpost_sale($companyId, $id, $userId);
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'Returned to draft.' : $result['error']);
        redirect($back);
    }

    if ($action === 'delete_purchase' || $action === 'delete_sale') {
        require_permission('jewellery', 'edit');
        $id = (int) ($_POST['doc_id'] ?? 0);
        $removed = $action === 'delete_purchase'
            ? jewellery_delete_purchase($companyId, $id)
            : jewellery_delete_sale($companyId, $id);
        flash($removed ? 'success' : 'error', $removed ? 'Draft removed.' : 'Only a draft can be deleted.');
        redirect($back);
    }

    if ($action === 'save_settlement') {
        require_permission('jewellery', 'edit');
        try {
            $allocations = [];
            foreach ((array) ($_POST['alloc_bill_id'] ?? []) as $index => $billId) {
                if ((int) $billId > 0 && (float) ($_POST['alloc_amount'][$index] ?? 0) > 0) {
                    $allocations[] = ['bill_id' => (int) $billId, 'amount' => (float) $_POST['alloc_amount'][$index]];
                }
            }
            $id = jewellery_save_settlement($companyId, $fiscalYearId, [
                'settlement_date' => $clampDate((string) ($_POST['settlement_date'] ?? '')),
                'party_id' => (int) ($_POST['party_id'] ?? 0),
                'direction' => (string) ($_POST['direction'] ?? 'paid'),
                'mode' => (string) ($_POST['mode'] ?? 'cash'),
                'amount' => (float) ($_POST['amount'] ?? 0),
                'ledger_id' => (int) ($_POST['ledger_id'] ?? 0),
                'item_id' => (int) ($_POST['item_id'] ?? 0),
                'purity_id' => (int) ($_POST['purity_id'] ?? 0),
                'unit_id' => (int) ($_POST['unit_id'] ?? 0),
                'gross_weight' => (float) ($_POST['gross_weight'] ?? 0),
                'notes' => (string) ($_POST['notes'] ?? ''),
            ], $allocations, $userId);
            // A settlement is only useful once it reaches the ledger, so save
            // and post are one action here unless the user lacks the right.
            if ($canPost) {
                $result = jewellery_post_settlement($companyId, $id, $userId);
                flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'Settlement posted.' : $result['error']);
            } else {
                flash('success', 'Settlement saved as a draft — it needs the post permission to reach the ledger.');
            }
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect($back);
    }

    if ($action === 'unpost_settlement') {
        require_permission('jewellery', 'post');
        $result = jewellery_unpost_settlement($companyId, (int) ($_POST['doc_id'] ?? 0), $userId);
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'Settlement reversed.' : $result['error']);
        redirect($back);
    }

    redirect($back);
}

// ---------------------------------------------------------------------------
// Page data
// ---------------------------------------------------------------------------
$items = jewellery_items_list($companyId, ['active_only' => true]);
$units = jewellery_units_list($companyId);
$purities = jewellery_purities_list($companyId);
$baseUnit = jewellery_base_unit($companyId);

// What is left of each item, so the line grid can say so BEFORE the row is
// committed rather than refusing it afterwards with a negative-stock error.
$onHand = [];
foreach ($items as $itemRow) {
    $onHand[(int) $itemRow['id']] = jw_item_balance($companyId, (int) $itemRow['id'], date('Y-m-d'), 'stock');
}

$partyStmt = db()->prepare('SELECT id, code, name, party_type FROM accounting_parties WHERE company_id = :cid AND status = \'active\' ORDER BY name ASC');
$partyStmt->execute(['cid' => $companyId]);
$parties = $partyStmt->fetchAll(PDO::FETCH_ASSOC);

$ledgerStmt = db()->prepare('SELECT id, code, name FROM ledgers WHERE company_id = :cid ORDER BY code ASC, name ASC');
$ledgerStmt->execute(['cid' => $companyId]);
$ledgers = $ledgerStmt->fetchAll(PDO::FETCH_ASSOC);

$editDoc = null;
$editLines = [];
$editExchanges = [];
$docs = [];
if ($view === 'purchases') {
    $docs = jewellery_purchases_list($companyId, ['limit' => 100]);
    $editDoc = jewellery_purchase($companyId, (int) ($_GET['edit'] ?? 0));
    if ($editDoc) {
        $editLines = jewellery_purchase_line_rows($companyId, (int) $editDoc['id']);
    }
} elseif ($view === 'sales') {
    $docs = jewellery_sales_list($companyId, ['limit' => 100]);
    $editDoc = jewellery_sale($companyId, (int) ($_GET['edit'] ?? 0));
    if ($editDoc) {
        $editLines = jewellery_sale_line_rows($companyId, (int) $editDoc['id']);
        $editExchanges = jewellery_sale_exchange_rows($companyId, (int) $editDoc['id']);
    }
}

// Orders waiting to be collected by the customer on this sale. Somebody at the
// counter picking up their ring should not have to be asked which order it was.
$saleParty = (int) ($_GET['for_party'] ?? ($editDoc['party_id'] ?? 0));
$openOrders = [];
$orderPrefill = null;
if ($view === 'sales') {
    if ($saleParty > 0) {
        $openOrders = jewellery_open_orders_for_party($companyId, $saleParty);
    }
    // Selling an order: the line is filled in from the order, priced at the
    // rate that stood ON THE ORDER DATE, not today's.
    $sellOrderId = (int) ($_GET['sell_order'] ?? 0);
    if ($sellOrderId > 0) {
        $prefill = jewellery_order_sale_prefill($companyId, $sellOrderId);
        if ($prefill['ok']) {
            $orderPrefill = $prefill;
            $saleParty = (int) ($prefill['order']['party_id'] ?? $saleParty);
            $openOrders = jewellery_open_orders_for_party($companyId, $saleParty);
            if ($editLines === []) {
                $editLines = [$prefill['line']];
            }
        } else {
            flash('error', $prefill['error']);
        }
    }
}

$outstanding = [];
$settlements = [];
$settleParty = (int) ($_GET['party'] ?? 0);
$partyBills = [];
if ($view === 'bills') {
    $outstanding = jw_report_bill_outstanding($companyId);
    $settlements = jewellery_settlements_list($companyId, ['limit' => 50]);
    if ($settleParty > 0) {
        $partyBills = jewellery_open_bills($companyId, $settleParty);
    }
}

$pageTitle = 'Jewellery — Purchases, Sales & Bills';
$pageSubtitle = 'Counter sales with old-gold exchange, supplier purchases, per-item VAT and bill-wise party settlement.';
$pageHero = ['icon' => 'coins'];
$bodyClass = 'admin-layout accounting-module-page';
$pageBreadcrumb = [['Home', 'admin/index.php'], ['Jewellery', 'admin/jewellery.php'], ['Trading', 'admin/jewellery-trade.php']];
include __DIR__ . '/../../app/views/partials/admin_header.php';

$fmt = static fn (?float $n, int $p = 2): string => $n === null ? 'N/A' : number_format($n, $p);
$lineSlots = 5;
?>

<nav class="mbw-tabbar" aria-label="Jewellery trading sections" style="flex-wrap:wrap">
    <a class="mbw-tab" href="<?= e(url('admin/jewellery.php')) ?>"><?= icon('dashboard') ?> Jewellery Home</a>
    <?php foreach (['purchases' => ['Purchases', 'box'], 'sales' => ['Sales', 'receipt-voucher'], 'bills' => ['Bills &amp; Settlement', 'wallet']] as $tabView => [$tabLabel, $tabIcon]): ?>
        <a class="mbw-tab <?= $view === $tabView ? 'is-active' : '' ?>" href="<?= e(url('admin/jewellery-trade.php?view=' . $tabView)) ?>"><?= icon($tabIcon) ?> <?= $tabLabel ?></a>
    <?php endforeach; ?>
</nav>

<?php
/** Render the shared line-entry grid used by both the purchase and sale forms. */
$renderLineRows = function (string $prefix, array $existing, int $slots, string $legend) use ($items, $purities, $units, $baseUnit, $sym, $fmt, $onHand): void { ?>
    <fieldset style="border:1px solid var(--mbw-border,#d9e2ec);border-radius:10px;padding:12px;margin:12px 0">
        <legend style="padding:0 6px;font-weight:600"><?= $legend ?></legend>
        <div style="overflow-x:auto"><table>
            <thead><tr>
                <th style="min-width:200px">Item</th><th>Purity</th><th>Unit</th><th>Pieces</th>
                <th>Gross wt</th><th>Stone wt</th><th>Rate</th>
                <?php if ($prefix !== 'x'): ?><th>Wastage %</th><?php endif; ?>
                <?php if ($prefix === 'l'): ?><th>Making</th><th>Stone value</th><?php endif; ?>
            </tr></thead>
            <tbody>
            <?php for ($i = 0; $i < $slots; $i++): $row = $existing[$i] ?? null; ?>
                <tr>
                    <td>
                        <select name="<?= $prefix ?>_item_id[]">
                            <option value="0">—</option>
                            <?php foreach ($items as $it): ?>
                                <?php
                                    // What is actually left, shown on the option itself: the
                                    // shop needs to know before it commits the line, not after
                                    // the negative-stock guard refuses it.
                                    $stock = $onHand[(int) $it['id']] ?? null;
                                    $left = $stock
                                        ? '  ·  ' . $fmt((float) $stock['qty_pieces'], 0) . ' pc, '
                                            . $fmt((float) $stock['fine_weight'], 3) . ' fine left'
                                        : '';
                                ?>
                                <option value="<?= (int) $it['id'] ?>" <?= (int) ($row['item_id'] ?? 0) === (int) $it['id'] ? 'selected' : '' ?>><?= e($it['code'] . ' — ' . $it['name'] . $left) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <select name="<?= $prefix ?>_purity_id[]">
                            <?php foreach ($purities as $p): ?>
                                <option value="<?= (int) $p['id'] ?>" <?= (int) ($row['purity_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['metal_code'] . '·' . $p['code']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <select name="<?= $prefix ?>_unit_id[]">
                            <?php foreach ($units as $u): ?>
                                <option value="<?= (int) $u['id'] ?>" <?= (int) ($row['unit_id'] ?? (int) ($baseUnit['id'] ?? 0)) === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['code']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td><input type="number" name="<?= $prefix ?>_qty_pieces[]" step="0.001" min="0" style="width:80px" value="<?= e((string) ($row['qty_pieces'] ?? '0')) ?>"></td>
                    <td><input type="number" name="<?= $prefix ?>_gross_weight[]" step="0.0001" min="0" style="width:100px" value="<?= e((string) ($row['gross_weight'] ?? '0')) ?>"></td>
                    <td><input type="number" name="<?= $prefix ?>_stone_weight[]" class="jw-stone-wt" step="0.0001" min="0" style="width:95px" value="<?= e((string) ($row['stone_weight'] ?? '0')) ?>"></td>
                    <td><input type="number" name="<?= $prefix ?>_rate[]" step="0.0001" min="0" style="width:110px" value="<?= e((string) ($row['rate'] ?? '0')) ?>"></td>
                    <?php if ($prefix !== 'x'): ?>
                        <td><input type="number" name="<?= $prefix ?>_wastage_pct[]" class="jw-wastage-pct" step="0.001" min="0" style="width:90px" value="<?= e((string) ($row['wastage_pct'] ?? '0')) ?>"></td>
                    <?php endif; ?>
                    <?php if ($prefix === 'l'): ?>
                        <td><input type="number" name="<?= $prefix ?>_making_amount[]" step="0.01" min="0" style="width:100px" value="<?= e((string) ($row['making_amount'] ?? '0')) ?>"></td>
                        <td><input type="number" name="<?= $prefix ?>_stone_amount[]" step="0.01" min="0" style="width:100px" value="<?= e((string) ($row['stone_amount'] ?? '0')) ?>"></td>
                    <?php endif; ?>
                </tr>
            <?php endfor; ?>
            </tbody>
        </table></div>
        <?php if ($prefix !== 'x'): ?>
        <div class="workspace-form-grid" style="margin-top:10px">
            <label>Apply wastage %
                <input type="number" class="jw-bulk-wastage" step="0.001" min="0" value="0" style="width:110px">
            </label>
            <label>to
                <select class="jw-bulk-wastage-scope">
                    <option value="all">Every line with an item</option>
                    <option value="empty">Only lines still at zero</option>
                </select>
            </label>
            <div style="align-self:end">
                <button type="button" class="button secondary jw-bulk-wastage-apply">Apply</button>
            </div>
        </div>
        <?php endif; ?>
        <p class="frm-optional" style="margin:8px 0 0">Gross includes stones; stone wt is deducted and the rate charged on the rest. Rate 0 prices from the daily board. Blank rows are ignored.</p>
    </fieldset>
<?php };
?>

<?php if ($view === 'purchases'): ?>
    <?php if ($canEdit): ?>
    <section class="mbw-card" data-draggable>
        <div class="mbw-card-head">
            <h2><?= $editDoc ? 'Edit Draft Purchase — ' . e((string) $editDoc['purchase_no']) : 'New Purchase' ?></h2>
            <?php if ($editDoc): ?><a class="mbw-view-all" href="<?= e(url('admin/jewellery-trade.php?view=purchases')) ?>">New purchase</a><?php endif; ?>
        </div>
        <?php if ($editDoc && (string) $editDoc['status'] !== 'draft'): ?>
            <div class="notice">This purchase is posted and can no longer be edited. Unpost it first.</div>
        <?php else: ?>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_purchase">
            <input type="hidden" name="back_view" value="purchases">
            <input type="hidden" name="purchase_id" value="<?= (int) ($editDoc['id'] ?? 0) ?>">
            <div class="workspace-form-grid">
                <label>Date<input type="date" name="purchase_date" value="<?= e((string) ($editDoc['purchase_date'] ?? $todayInFy)) ?>" min="<?= e($fyStart) ?>" max="<?= e($fyEnd) ?>" required></label>
                <label>Source
                    <select name="source">
                        <option value="supplier" <?= (string) ($editDoc['source'] ?? '') === 'supplier' ? 'selected' : '' ?>>Supplier purchase</option>
                        <option value="customer_old_gold" <?= (string) ($editDoc['source'] ?? '') === 'customer_old_gold' ? 'selected' : '' ?>>Old gold from a customer</option>
                    </select>
                </label>
                <label>Existing party
                    <select name="party_id">
                        <option value="0">— new party, type the name →</option>
                        <?php foreach ($parties as $p): ?>
                            <option value="<?= (int) $p['id'] ?>" <?= (int) ($editDoc['party_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Party name<input type="text" name="party_name" maxlength="190" placeholder="Creates the party and its ledger"></label>
                <label>Phone<input type="text" name="party_phone" maxlength="60"></label>
                <label>Settlement
                    <select name="settle_mode">
                        <?php foreach (['credit' => 'On credit (opens a bill)', 'cash' => 'Paid in cash', 'bank' => 'Paid by bank'] as $k => $v): ?>
                            <option value="<?= e($k) ?>" <?= (string) ($editDoc['settle_mode'] ?? 'credit') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Paid from ledger
                    <select name="settle_ledger_id">
                        <option value="0">— not applicable —</option>
                        <?php foreach ($ledgers as $l): ?>
                            <option value="<?= (int) $l['id'] ?>" <?= (int) ($editDoc['settle_ledger_id'] ?? 0) === (int) $l['id'] ? 'selected' : '' ?>><?= e(($l['code'] ? $l['code'] . ' — ' : '') . $l['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Supplier bill no.<input type="text" name="ref_no" maxlength="120" value="<?= e((string) ($editDoc['ref_no'] ?? '')) ?>"></label>
                <label>Other charges (<?= e($sym) ?>)<input type="number" name="other_charges" step="0.01" min="0" value="<?= e((string) ($editDoc['other_charges'] ?? '0')) ?>"></label>
                <label>Discount (<?= e($sym) ?>)<input type="number" name="discount" step="0.01" min="0" value="<?= e((string) ($editDoc['discount'] ?? '0')) ?>"></label>
                <label>Skills Promotion Tax (<?= e($sym) ?>)<input type="number" name="manual_tax_amount" step="0.01" min="0" placeholder="auto" value="<?= e((string) ($editDoc['manual_tax_amount'] ?? '')) ?>">
                    <span class="frm-optional">Left blank it is worked out for you at the rate on the tax register. Punch a figure to override it.</span>
                </label>
                <label style="grid-column:1/-1">Narration<input type="text" name="narration" maxlength="255" value="<?= e((string) ($editDoc['narration'] ?? '')) ?>"></label>
            </div>
            <?php $renderLineRows('l', $editLines, max($lineSlots, count($editLines) + 1), 'Purchase lines'); ?>
            <button type="submit" class="button" <?= $items === [] ? 'disabled' : '' ?>>Save Draft</button>
            <?php if ($items === []): ?><p class="frm-optional">Add an item first.</p><?php endif; ?>
        </form>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <section class="mbw-card" style="margin-top:14px">
        <div class="mbw-card-head"><h2>Purchases (<?= count($docs) ?>)</h2></div>
        <div style="overflow-x:auto"><table>
            <thead><tr><th>No.</th><th>Date</th><th>Party</th><th>Source</th><th class="is-numeric">Metal</th><th class="is-numeric">VAT</th><th class="is-numeric">Total</th><th>Settlement</th><th>Status</th><th></th></tr></thead>
            <tbody>
                <?php if ($docs === []): ?><tr><td colspan="10">No purchases yet.</td></tr><?php endif; ?>
                <?php foreach ($docs as $row): ?>
                    <?php $isDraft = (string) $row['status'] === 'draft'; ?>
                    <tr>
                        <td><?= e($row['purchase_no']) ?></td>
                        <td><?= e(app_date((string) $row['purchase_date'])) ?></td>
                        <td><?= e((string) ($row['party_name'] ?? 'Walk-in')) ?></td>
                        <td><?= (string) $row['source'] === 'customer_old_gold' ? '<span class="mbw-pill tone-amber">Old gold</span>' : 'Supplier' ?></td>
                        <td class="is-numeric"><?= $fmt((float) $row['metal_amount']) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $row['vat_amount']) ?></td>
                        <td class="is-numeric"><strong><?= e($sym) ?><?= $fmt((float) $row['total_amount']) ?></strong></td>
                        <td><?= e(ucfirst((string) $row['settle_mode'])) ?></td>
                        <td><span class="mbw-pill <?= $isDraft ? 'tone-amber' : 'tone-green' ?>"><?= $isDraft ? 'Draft' : 'Posted' ?></span></td>
                        <td style="white-space:nowrap">
                            <?php if ($isDraft && $canEdit): ?>
                                <a class="button soft" style="min-height:30px;padding:3px 10px" href="<?= e(url('admin/jewellery-trade.php?view=purchases&edit=' . (int) $row['id'])) ?>">Edit</a>
                            <?php endif; ?>
                            <?php if ($isDraft && $canPost): ?>
                                <form method="post" style="display:inline">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="post_purchase">
                                    <input type="hidden" name="back_view" value="purchases">
                                    <input type="hidden" name="doc_id" value="<?= (int) $row['id'] ?>">
                                    <button type="submit" class="button secondary" style="min-height:30px;padding:3px 10px">Post</button>
                                </form>
                            <?php elseif (!$isDraft && $canPost): ?>
                                <form method="post" style="display:inline" data-confirm="Unpost this purchase? Its voucher and stock movements will be removed.">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="unpost_purchase">
                                    <input type="hidden" name="back_view" value="purchases">
                                    <input type="hidden" name="doc_id" value="<?= (int) $row['id'] ?>">
                                    <button type="submit" class="button soft" style="min-height:30px;padding:3px 10px">Unpost</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>

<?php elseif ($view === 'sales'): ?>
    <?php if ($canEdit): ?>
    <section class="mbw-card" data-draggable>
        <div class="mbw-card-head">
            <h2><?= $editDoc ? 'Edit Draft Sale — ' . e((string) $editDoc['sale_no']) : 'New Sale' ?></h2>
            <?php if ($editDoc): ?><a class="mbw-view-all" href="<?= e(url('admin/jewellery-trade.php?view=sales')) ?>">New sale</a><?php endif; ?>
        </div>
        <?php if ($editDoc && (string) $editDoc['status'] !== 'draft'): ?>
            <div class="notice">This sale is posted and can no longer be edited. Unpost it first.</div>
        <?php else: ?>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_sale">
            <input type="hidden" name="back_view" value="sales">
            <input type="hidden" name="sale_id" value="<?= (int) ($editDoc['id'] ?? 0) ?>">
            <div class="workspace-form-grid">
                <label>Date<input type="date" name="sale_date" value="<?= e((string) ($editDoc['sale_date'] ?? $todayInFy)) ?>" min="<?= e($fyStart) ?>" max="<?= e($fyEnd) ?>" required></label>
                <label>Existing customer
                    <select name="party_id" id="jw-sale-party"
                            data-orders-url="<?= e(url('admin/jewellery-trade.php?view=sales')) ?>">
                        <option value="0">— new customer, type the name →</option>
                        <?php foreach ($parties as $p): ?>
                            <option value="<?= (int) $p['id'] ?>" <?= $saleParty === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="frm-optional">Pick the customer to see any orders they are here to collect.</span>
                </label>
                <label>Customer name<input type="text" name="customer_name" maxlength="190" value="<?= e((string) ($editDoc['customer_name'] ?? '')) ?>" placeholder="Creates the customer and their ledger"></label>
                <label>Phone<input type="text" name="party_phone" maxlength="60"></label>
                <label>Address<input type="text" name="party_address" maxlength="255"></label>
                <label>Cash / bank received (<?= e($sym) ?>)<input type="number" name="received_amount" step="0.01" min="0" value="<?= e((string) ($editDoc['received_amount'] ?? '0')) ?>"></label>
                <label>Received into ledger
                    <select name="settle_ledger_id">
                        <option value="0">— none —</option>
                        <?php foreach ($ledgers as $l): ?>
                            <option value="<?= (int) $l['id'] ?>" <?= (int) ($editDoc['settle_ledger_id'] ?? 0) === (int) $l['id'] ? 'selected' : '' ?>><?= e(($l['code'] ? $l['code'] . ' — ' : '') . $l['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Bill ref.<input type="text" name="ref_no" maxlength="120" value="<?= e((string) ($editDoc['ref_no'] ?? '')) ?>"></label>
                <label>Other charges (<?= e($sym) ?>)<input type="number" name="other_charges" step="0.01" min="0" value="<?= e((string) ($editDoc['other_charges'] ?? '0')) ?>"></label>
                <label>Discount (<?= e($sym) ?>)<input type="number" name="discount" step="0.01" min="0" value="<?= e((string) ($editDoc['discount'] ?? '0')) ?>"></label>
                <label>Skills Promotion Tax (<?= e($sym) ?>)<input type="number" name="manual_tax_amount" step="0.01" min="0" placeholder="auto" value="<?= e((string) ($editDoc['manual_tax_amount'] ?? '')) ?>">
                    <span class="frm-optional">Left blank it is worked out for you at the rate on the tax register. Punch a figure to override it.</span>
                </label>
                <label style="grid-column:1/-1">Narration<input type="text" name="narration" maxlength="255" value="<?= e((string) ($editDoc['narration'] ?? '')) ?>"></label>
            </div>
            <?php if ($openOrders !== []): ?>
            <fieldset style="border:1px solid var(--mbw-border,#d9e2ec);border-radius:10px;padding:12px;margin:12px 0">
                <legend style="padding:0 6px;font-weight:600">Orders this customer is here to collect</legend>
                <div style="overflow-x:auto"><table>
                    <thead><tr><th>Order</th><th>Ordered</th><th>Item</th><th class="is-numeric">Weight</th><th class="is-numeric">Advance</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($openOrders as $ord): ?>
                            <?php $isSelling = $orderPrefill && (int) $orderPrefill['order']['id'] === (int) $ord['id']; ?>
                            <tr<?= $isSelling ? ' style="background:var(--mbw-accent-soft,#eef7f1)"' : '' ?>>
                                <td><strong><?= e((string) $ord['order_no']) ?></strong>
                                    <?= $isSelling ? '<span class="mbw-pill tone-green">Being sold</span>' : '' ?>
                                </td>
                                <td><?= e(app_date((string) $ord['order_date'])) ?></td>
                                <td><?= e((string) ($ord['item_name'] ?? $ord['description'] ?? '—')) ?>
                                    <small><?= e((string) $ord['purity_code']) ?></small></td>
                                <td class="is-numeric"><?= $fmt((float) $ord['expected_gross_weight'], 4) ?> <?= e((string) $ord['unit_code']) ?></td>
                                <td class="is-numeric"><?= $fmt((float) $ord['advance_amount']) ?></td>
                                <td><span class="mbw-pill <?= (string) $ord['status'] === 'received' ? 'tone-green' : 'tone-gray' ?>"><?= e(ucfirst((string) $ord['status'])) ?></span></td>
                                <td><?php if (!$isSelling): ?>
                                    <a class="button secondary" style="min-height:30px;padding:3px 10px"
                                       href="<?= e(url('admin/jewellery-trade.php?view=sales&sell_order=' . (int) $ord['id'])) ?>">Sell this</a>
                                <?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table></div>
                <?php if ($orderPrefill): ?>
                    <input type="hidden" name="deliver_order_id" value="<?= (int) $orderPrefill['order']['id'] ?>">
                    <?php $advanceHeld = jewellery_order_advance_available($companyId, (int) $orderPrefill['order']['id'], (int) ($editDoc['id'] ?? 0)); ?>
                    <?php if ($advanceHeld > 0.005): ?>
                    <label style="display:block;margin-top:10px">Advance to apply (<?= e($sym) ?>)
                        <input type="number" name="advance_amount" step="0.01" min="0" max="<?= e((string) $advanceHeld) ?>"
                               value="<?= e((string) ($editDoc['advance_amount'] ?? $advanceHeld)) ?>" style="max-width:220px">
                        <span class="frm-optional"><?= e($sym) ?> <?= $fmt($advanceHeld) ?> held. Refund any excess from the order screen.</span>
                    </label>
                    <?php endif; ?>
                    <div class="mbw-note tone-green" style="margin-top:10px">
                        <p style="margin:0">
                            <strong><?= e((string) $orderPrefill['order']['order_no']) ?> is filled in below.</strong>
                            <?= e((string) $orderPrefill['rate_note']) ?>
                            <?php if ($orderPrefill['received']): ?>
                                The weight billed is what actually came back from the kaligad, not the estimate.
                            <?php endif; ?>
                            <?php if ((float) $orderPrefill['advance_amount'] > 0): ?>
                                An advance of <?= e($sym) ?> <?= $fmt((float) $orderPrefill['advance_amount']) ?> was taken on this
                                order — enter it as cash received, or leave it on the customer's balance to net off.
                            <?php endif; ?>
                            Saving this sale marks the order delivered.
                        </p>
                    </div>
                <?php endif; ?>
            </fieldset>
            <?php endif; ?>
            <?php $renderLineRows('l', $editLines, max($lineSlots, count($editLines) + 1), 'Items sold'); ?>
            <?php $renderLineRows('x', $editExchanges, max(2, count($editExchanges) + 1), 'Old gold taken in exchange (metal-to-metal)'); ?>
            <button type="submit" class="button" <?= $items === [] ? 'disabled' : '' ?>>Save Draft</button>
        </form>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <section class="mbw-card" style="margin-top:14px">
        <div class="mbw-card-head"><h2>Sales (<?= count($docs) ?>)</h2></div>
        <div style="overflow-x:auto"><table>
            <thead><tr><th>No.</th><th>Date</th><th>Customer</th><th class="is-numeric">Total</th><th class="is-numeric">Cash</th><th class="is-numeric">Exchange</th><th class="is-numeric">Balance</th><th class="is-numeric">COGS</th><th>Status</th><th></th></tr></thead>
            <tbody>
                <?php if ($docs === []): ?><tr><td colspan="10">No sales yet.</td></tr><?php endif; ?>
                <?php foreach ($docs as $row): ?>
                    <?php $isDraft = (string) $row['status'] === 'draft'; ?>
                    <tr>
                        <td><?= e($row['sale_no']) ?></td>
                        <td><?= e(app_date((string) $row['sale_date'])) ?></td>
                        <td><?= e((string) ($row['party_name'] ?? $row['customer_name'] ?? 'Walk-in')) ?></td>
                        <td class="is-numeric"><strong><?= e($sym) ?><?= $fmt((float) $row['total_amount']) ?></strong></td>
                        <td class="is-numeric"><?= $fmt((float) $row['received_amount']) ?></td>
                        <td class="is-numeric"><?= (float) $row['exchange_amount'] > 0 ? '<span class="mbw-pill tone-amber">' . $fmt((float) $row['exchange_amount']) . '</span>' : '—' ?></td>
                        <td class="is-numeric"><?= $fmt((float) $row['balance_amount']) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $row['cogs_amount']) ?></td>
                        <td><span class="mbw-pill <?= $isDraft ? 'tone-amber' : 'tone-green' ?>"><?= $isDraft ? 'Draft' : 'Posted' ?></span></td>
                        <td style="white-space:nowrap">
                            <?php if ($isDraft && $canEdit): ?>
                                <a class="button soft" style="min-height:30px;padding:3px 10px" href="<?= e(url('admin/jewellery-trade.php?view=sales&edit=' . (int) $row['id'])) ?>">Edit</a>
                            <?php endif; ?>
                            <?php if ($isDraft && $canPost): ?>
                                <form method="post" style="display:inline">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="post_sale">
                                    <input type="hidden" name="back_view" value="sales">
                                    <input type="hidden" name="doc_id" value="<?= (int) $row['id'] ?>">
                                    <button type="submit" class="button secondary" style="min-height:30px;padding:3px 10px">Post</button>
                                </form>
                            <?php elseif (!$isDraft && $canPost): ?>
                                <form method="post" style="display:inline" data-confirm="Unpost this sale? Its voucher, COGS and stock movements will be removed.">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="unpost_sale">
                                    <input type="hidden" name="back_view" value="sales">
                                    <input type="hidden" name="doc_id" value="<?= (int) $row['id'] ?>">
                                    <button type="submit" class="button soft" style="min-height:30px;padding:3px 10px">Unpost</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>

<?php elseif ($view === 'bills'): ?>
    <?php
    $totalReceivable = 0.0; $totalPayable = 0.0;
    foreach ($outstanding as $party) {
        foreach ($party['bills'] as $bill) {
            if ((string) $bill['bill_type'] === 'sale') { $totalReceivable += (float) $bill['outstanding']; }
            else { $totalPayable += (float) $bill['outstanding']; }
        }
    }
    ?>
    <section class="mbw-kpi-grid" aria-label="Bill summary">
        <?php foreach ([
            ['Receivable (sales)', $sym . $fmt($totalReceivable), 'wallet', 'tone-blue'],
            ['Payable (purchases &amp; karigar)', $sym . $fmt($totalPayable), 'card', 'tone-amber'],
            ['Parties with open bills', (string) count($outstanding), 'handshake', 'tone-teal'],
        ] as [$kpiLabel, $kpiValue, $kpiIcon, $kpiTone]): ?>
            <article class="mbw-kpi"><div><span class="mbw-kpi-label"><?= $kpiLabel ?></span><div class="mbw-kpi-value" style="font-size:1.02rem"><?= e($kpiValue) ?></div></div><span class="mbw-chip <?= e($kpiTone) ?>"><?= icon($kpiIcon) ?></span></article>
        <?php endforeach; ?>
    </section>

    <section class="mbw-card" style="margin-top:14px">
        <div class="mbw-card-head"><h2>Bill-wise Outstanding</h2></div>
        <div style="overflow-x:auto"><table>
            <thead><tr><th>Party</th><th>Bill</th><th>Type</th><th>Date</th><th class="is-numeric">Billed</th><th class="is-numeric">Settled</th><th class="is-numeric">Outstanding</th><th>Status</th></tr></thead>
            <tbody>
                <?php if ($outstanding === []): ?><tr><td colspan="8">Nothing outstanding — every bill is settled.</td></tr><?php endif; ?>
                <?php foreach ($outstanding as $party): ?>
                    <?php foreach ($party['bills'] as $index => $bill): ?>
                        <tr>
                            <td><?php if ($index === 0): ?><strong><?= e($party['party_name']) ?></strong><?php endif; ?></td>
                            <td><?= e($bill['bill_no']) ?></td>
                            <td><?= e(ucfirst((string) $bill['bill_type'])) ?></td>
                            <td><?= e(app_date((string) $bill['bill_date'])) ?></td>
                            <td class="is-numeric"><?= $fmt((float) $bill['bill_amount']) ?></td>
                            <td class="is-numeric"><?= $fmt((float) $bill['settled_amount']) ?></td>
                            <td class="is-numeric"><strong><?= $fmt((float) $bill['outstanding']) ?></strong></td>
                            <td><span class="mbw-pill <?= (string) $bill['status'] === 'open' ? 'tone-amber' : 'tone-blue' ?>"><?= e(str_replace('_', ' ', (string) $bill['status'])) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr><td></td><td colspan="5"><em>Total for <?= e($party['party_name']) ?></em></td>
                        <td class="is-numeric"><strong><?= $fmt($party['outstanding']) ?></strong></td>
                        <td><a class="mbw-view-all" href="<?= e(url('admin/jewellery-trade.php?view=bills&party=' . (int) $party['party_id'])) ?>">Settle →</a></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>

    <?php if ($canEdit && $settleParty > 0): ?>
    <section class="mbw-card" data-draggable style="margin-top:14px">
        <div class="mbw-card-head">
            <h2>Settle Bills</h2>
            <a class="mbw-view-all" href="<?= e(url('admin/jewellery-trade.php?view=bills')) ?>">Cancel</a>
        </div>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_settlement">
            <input type="hidden" name="back_view" value="bills">
            <input type="hidden" name="party_id" value="<?= $settleParty ?>">
            <div class="workspace-form-grid">
                <label>Date<input type="date" name="settlement_date" value="<?= e($todayInFy) ?>" min="<?= e($fyStart) ?>" max="<?= e($fyEnd) ?>" required></label>
                <label>Direction
                    <select name="direction">
                        <option value="paid">Paid to them</option>
                        <option value="received">Received from them</option>
                    </select>
                </label>
                <label>Mode
                    <select name="mode">
                        <option value="cash">Cash</option>
                        <option value="bank">Bank</option>
                        <option value="metal">Metal</option>
                        <option value="adjustment">Adjustment</option>
                    </select>
                </label>
                <label>Amount (<?= e($sym) ?>)<input type="number" name="amount" step="0.01" min="0.01" required></label>
                <label>Cash / bank ledger
                    <select name="ledger_id">
                        <option value="0">— none —</option>
                        <?php foreach ($ledgers as $l): ?>
                            <option value="<?= (int) $l['id'] ?>"><?= e(($l['code'] ? $l['code'] . ' — ' : '') . $l['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Metal item (mode = metal)
                    <select name="item_id">
                        <option value="0">— none —</option>
                        <?php foreach ($items as $it): ?>
                            <option value="<?= (int) $it['id'] ?>"><?= e($it['code']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Metal gross weight<input type="number" name="gross_weight" step="0.0001" min="0" value="0"></label>
                <label>Metal unit
                    <select name="unit_id">
                        <?php foreach ($units as $u): ?>
                            <option value="<?= (int) $u['id'] ?>" <?= (int) $u['id'] === (int) ($baseUnit['id'] ?? 0) ? 'selected' : '' ?>><?= e($u['code']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label style="grid-column:1/-1">Notes<input type="text" name="notes" maxlength="255"></label>
            </div>
            <fieldset style="border:1px solid var(--mbw-border,#d9e2ec);border-radius:10px;padding:12px;margin:12px 0">
                <legend style="padding:0 6px;font-weight:600">Allocate against bills</legend>
                <div style="overflow-x:auto"><table>
                    <thead><tr><th>Bill</th><th>Date</th><th class="is-numeric">Outstanding</th><th>Allocate</th></tr></thead>
                    <tbody>
                        <?php if ($partyBills === []): ?><tr><td colspan="4">This party has no open bills.</td></tr><?php endif; ?>
                        <?php foreach ($partyBills as $bill): ?>
                            <tr>
                                <td><?= e($bill['bill_no']) ?> <small>(<?= e((string) $bill['bill_type']) ?>)</small><input type="hidden" name="alloc_bill_id[]" value="<?= (int) $bill['id'] ?>"></td>
                                <td><?= e(app_date((string) $bill['bill_date'])) ?></td>
                                <td class="is-numeric"><?= $fmt((float) $bill['outstanding']) ?></td>
                                <td><input type="number" name="alloc_amount[]" step="0.01" min="0" max="<?= e((string) $bill['outstanding']) ?>" value="0" style="width:120px"></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table></div>
            </fieldset>
            <button type="submit" class="button" <?= $partyBills === [] ? 'disabled' : '' ?>>Save &amp; Post Settlement</button>
        </form>
    </section>
    <?php endif; ?>

    <section class="mbw-card" style="margin-top:14px">
        <div class="mbw-card-head"><h2>Recent Settlements (<?= count($settlements) ?>)</h2></div>
        <div style="overflow-x:auto"><table>
            <thead><tr><th>No.</th><th>Date</th><th>Party</th><th>Direction</th><th>Mode</th><th class="is-numeric">Amount</th><th>Status</th><th></th></tr></thead>
            <tbody>
                <?php if ($settlements === []): ?><tr><td colspan="8">No settlements yet.</td></tr><?php endif; ?>
                <?php foreach ($settlements as $row): ?>
                    <tr>
                        <td><?= e($row['settlement_no']) ?></td>
                        <td><?= e(app_date((string) $row['settlement_date'])) ?></td>
                        <td><?= e($row['party_name']) ?></td>
                        <td><?= (string) $row['direction'] === 'paid' ? 'Paid' : 'Received' ?></td>
                        <td><?= e(ucfirst((string) $row['mode'])) ?></td>
                        <td class="is-numeric"><?= e($sym) ?><?= $fmt((float) $row['amount']) ?></td>
                        <td><span class="mbw-pill <?= (string) $row['status'] === 'posted' ? 'tone-green' : 'tone-amber' ?>"><?= e(ucfirst((string) $row['status'])) ?></span></td>
                        <td>
                            <?php if ((string) $row['status'] === 'posted' && $canPost): ?>
                                <form method="post" data-confirm="Reverse this settlement? The bills it settled will reopen.">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="unpost_settlement">
                                    <input type="hidden" name="back_view" value="bills">
                                    <input type="hidden" name="doc_id" value="<?= (int) $row['id'] ?>">
                                    <button type="submit" class="button soft" style="min-height:30px;padding:3px 10px">Reverse</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>
<?php endif; ?>

<script>
// Picking a customer reloads the sale form with their open orders listed, so
// somebody collecting a finished piece is recognised at the counter rather than
// having to be asked which order it was.
document.addEventListener("change", function (event) {
    var select = event.target.closest("#jw-sale-party");
    if (!select) { return; }
    var base = select.getAttribute("data-orders-url") || "";
    var party = parseInt(select.value, 10) || 0;
    if (!base) { return; }
    window.location.href = base + (party > 0 ? "&for_party=" + party : "");
});
</script>

<script>
// Fill the wastage column in one go. Typing the same percentage down twelve
// lines is where mistakes come from; each line stays editable afterwards.
document.addEventListener("click", function (event) {
    var button = event.target.closest(".jw-bulk-wastage-apply");
    if (!button) { return; }
    var box = button.closest("fieldset");
    if (!box) { return; }
    var pctField = box.querySelector(".jw-bulk-wastage");
    var pct = parseFloat(pctField ? pctField.value : "0");
    if (!isFinite(pct) || pct < 0) { return; }
    var scope = box.querySelector(".jw-bulk-wastage-scope");
    var onlyEmpty = scope && scope.value === "empty";
    Array.prototype.forEach.call(box.querySelectorAll("tbody tr"), function (row) {
        var item = row.querySelector("select[name$=\"_item_id[]\"]");
        var field = row.querySelector(".jw-wastage-pct");
        if (!item || !field || parseInt(item.value, 10) <= 0) { return; }
        if (onlyEmpty && parseFloat(field.value || "0") > 0) { return; }
        field.value = pct;
    });
});
</script>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
