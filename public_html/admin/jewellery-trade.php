<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/accounting_module_repair.php';
// jewellery_reports.php chains in the workshop, trade and stock engines.
require_once __DIR__ . '/../../app/jewellery_reports.php';
// The module skin, the summary rail, and the two ways of filling the grid
// without typing. Required up here because the page body uses them while
// preparing data, not only while rendering.
require_once __DIR__ . '/../../app/views/partials/jewellery_page_head.php';
require_once __DIR__ . '/../../app/views/partials/jewellery_summary_rail.php';
require_once __DIR__ . '/../../app/jewellery_line_io.php';

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
    // A toolbar button inside the document form says which of the two it is;
    // without this the form-level action would always win and the import
    // would be read as a save.
    $action = (string) ($_POST['grid_action'] ?? $_POST['action'] ?? '');
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
                'sales_person' => (string) ($_POST['sales_person'] ?? ''),
                'customer_ref' => (string) ($_POST['customer_ref'] ?? ''),
                'tran_date_bs' => (string) ($_POST['tran_date_bs'] ?? ''),
                'remarks' => (string) ($_POST['remarks'] ?? ''),
                'received_amount' => (float) ($_POST['received_amount'] ?? 0),
                'paid_cash' => (float) ($_POST['paid_cash'] ?? 0),
                'paid_card' => (float) ($_POST['paid_card'] ?? 0),
                'paid_cheque' => (float) ($_POST['paid_cheque'] ?? 0),
                'paid_qr' => (float) ($_POST['paid_qr'] ?? 0),
                'deliver_order_id' => (int) ($_POST['deliver_order_id'] ?? 0),
                'deliver_order_ids' => array_map('intval', (array) ($_POST['deliver_order_ids'] ?? [])),
                'advance_amount' => (float) ($_POST['advance_amount'] ?? 0),
                // WHICH advance entries pay this bill — the rows the user put
                // an amount against on the picker. The engine validates each
                // against what the entry still holds and takes their sum as
                // the advance applied.
                //
                // NO PICKER AT ALL is not the same as a picker cleared to
                // zero. When the form never rendered the fieldset (no open
                // advances listed — a state a half-run upgrade can produce),
                // re-saving the draft must keep the allocations it already
                // has, not silently strip the advance off the bill. Clearing
                // an advance is done by zeroing its row ON the picker.
                'advance_allocations' => (static function () use ($companyId): array {
                    if (!isset($_POST['adv_alloc_id'])) {
                        $draftId = (int) ($_POST['sale_id'] ?? 0);
                        if ($draftId > 0) {
                            $kept = [];
                            foreach (jewellery_sale_advance_allocations($companyId, $draftId) as $existingRow) {
                                $kept[] = ['settlement_id' => (int) $existingRow['settlement_id'],
                                    'amount' => (float) $existingRow['amount']];
                            }
                            return $kept;
                        }
                        return [];
                    }
                    $rows = [];
                    foreach ((array) $_POST['adv_alloc_id'] as $index => $settlementId) {
                        $rows[] = ['settlement_id' => (int) $settlementId,
                            'amount' => (float) ($_POST['adv_alloc_amount'][$index] ?? 0)];
                    }
                    return $rows;
                })(),
                'settle_mode' => (string) ($_POST['settle_mode'] ?? 'cash'),
                'settle_ledger_id' => (int) ($_POST['settle_ledger_id'] ?? 0),
            ], jw_posted_lines($_POST, 'l'), jw_posted_lines($_POST, 'x'), $userId);
            // Delivery happens at POSTING, not here. This used to try now and
            // fail every time: goods leave the shop only against a POSTED
            // bill (jewellery_deliver_order refuses drafts), the save always
            // produces a draft, and nothing ever tried again — so an order
            // sold through the normal save-then-post flow sat on the
            // ready-to-deliver board forever. The save stores the link on the
            // sale; the post_sale handler below finishes the job.
            $deliverCount = count(array_filter(array_map('intval', (array) ($_POST['deliver_order_ids'] ?? []))))
                ?: ((int) ($_POST['deliver_order_id'] ?? 0) > 0 ? 1 : 0);
            $deliverNote = $deliverCount > 0
                ? ' Posting the bill will mark ' . ($deliverCount === 1 ? 'the order' : $deliverCount . ' orders') . ' delivered.'
                : '';
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
        $deliverNote = '';
        if ($action === 'post_sale' && $result['ok']) {
            // The bill is posted, so the goods can go out on it. At the
            // counter the two are one breath: the customer pays and walks out
            // with the piece. The order the sale was raised against —
            // remembered on the sale itself since the save — is delivered
            // now, and closed in the same stroke when nothing is left to
            // collect.
            // EVERY order this bill carried. delivered_sale_id is the
            // authoritative link and holds them all; the sale's own order_id
            // column has room for one, so handing over on that alone left the
            // rest of a multi-order bill sitting on the delivery board with
            // their goods already in the customer's hand.
            $linkedStmt = db()->prepare('SELECT id FROM jewellery_orders
                WHERE company_id = :cid AND delivered_sale_id = :sid ORDER BY id ASC');
            $linkedStmt->execute(['cid' => $companyId, 'sid' => $id]);
            $deliverOrderIds = array_map('intval', $linkedStmt->fetchAll(PDO::FETCH_COLUMN));
            $postedSale = jewellery_sale($companyId, $id);
            $fallbackOrderId = (int) ($postedSale['order_id'] ?? 0);
            if ($fallbackOrderId > 0 && !in_array($fallbackOrderId, $deliverOrderIds, true)) {
                $deliverOrderIds[] = $fallbackOrderId;
            }
            $deliveredCount = 0;
            $deliverProblems = [];
            foreach ($deliverOrderIds as $deliverOrderId) {
                $delivered = jewellery_deliver_order($companyId, $deliverOrderId, $id, $userId);
                if ($delivered['ok']) {
                    $deliveredCount++;
                } else {
                    $deliverProblems[] = $delivered['error'];
                }
            }
            if ($deliveredCount > 0) {
                $deliverNote = ' ' . ($deliveredCount === 1 ? 'Order' : $deliveredCount . ' orders')
                    . ' marked delivered.';
            }
            if ($deliverProblems !== []) {
                $deliverNote .= ' Not every order could be marked delivered: ' . implode(' ', array_unique($deliverProblems));
            }
        }
        flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? ('Posted to the ledger (voucher #' . $result['voucher_id'] . ').' . $deliverNote) : $result['error']);
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

    // --- filling the grid without typing -----------------------------------
    //
    // A template and an import both end in the SAME place: rows parked in the
    // session, rendered into the grid on the next request, and then saved by
    // the ordinary save action. Neither writes a document, so neither can put
    // anything in the books that the normal validation would have refused.
    if ($action === 'apply_template') {
        require_permission('jewellery', 'edit');
        $docType = (string) ($_POST['doc_type'] ?? 'sale');
        $rows = jw_template_lines($companyId, (int) ($_POST['template_id'] ?? 0));
        if ($rows === []) {
            flash('error', 'That template is empty or does not belong to this company.');
        } else {
            $_SESSION['jw_staged_lines'][$docType] = $rows;
            flash('success', count($rows) . ' item(s) loaded from the template. Nothing is saved until you save the document.');
        }
        redirect($back);
    }

    if ($action === 'save_template') {
        require_permission('jewellery', 'edit');
        $docType = (string) ($_POST['doc_type'] ?? 'sale');
        $saved = jw_template_save($companyId, $docType, (string) ($_POST['template_name'] ?? ''),
            jw_posted_lines($_POST, 'l'), $userId);
        flash($saved['ok'] ? 'success' : 'error', $saved['ok']
            ? ('Template saved with ' . $saved['count'] . ' item(s).') : $saved['error']);
        redirect($back);
    }

    if ($action === 'import_lines') {
        require_permission('jewellery', 'edit');
        $docType = (string) ($_POST['doc_type'] ?? 'sale');
        $upload = $_FILES['lines_file'] ?? null;
        if (!$upload || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])) {
            flash('error', 'Choose a .csv or .xlsx file to import.');
            redirect($back);
        }
        $extension = strtolower(pathinfo((string) $upload['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, ['csv', 'xlsx'], true)) {
            flash('error', 'Only .csv and .xlsx files can be imported.');
            redirect($back);
        }
        $imported = jw_import_lines($companyId, (string) $upload['tmp_name'], $extension);
        if ($imported['rows'] !== []) {
            $_SESSION['jw_staged_lines'][$docType] = $imported['rows'];
        }
        // Both halves are reported: what came in, and what did not. An import
        // that silently drops three rows is worse than one that refuses.
        $note = $imported['matched'] . ' item(s) read from the file.';
        if ($imported['errors'] !== []) {
            $note .= ' ' . count($imported['errors']) . ' row(s) skipped: '
                . implode(' ', array_slice($imported['errors'], 0, 3));
        }
        flash($imported['ok'] ? 'success' : 'error',
            $imported['ok'] ? $note . ' Nothing is saved until you save the document.' : implode(' ', $imported['errors']));
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
// What the list is filtered by. Everything travels in the query string, so a
// filtered list can be bookmarked or sent to somebody and come back the same.
$filterSearch = trim((string) ($_GET['q'] ?? ''));
$filterFrom = (string) ($_GET['from'] ?? '');
$filterTo = (string) ($_GET['to'] ?? '');
$filterStatus = (string) ($_GET['status'] ?? '');
$filterParty = (int) ($_GET['party'] ?? 0);
$filterSource = (string) ($_GET['source'] ?? '');
$docFilters = [
    'limit' => 300,
    'search' => $filterSearch,
    'status' => $filterStatus,
    'party_id' => $filterParty,
];
if ($filterFrom !== '' && $filterTo !== '') {
    $docFilters['from'] = $filterFrom;
    $docFilters['to'] = $filterTo;
}
$advancedInUse = $filterStatus !== '' || $filterParty > 0 || $filterSource !== '';

if ($view === 'purchases') {
    $docs = jewellery_purchases_list($companyId, $docFilters + ['source' => $filterSource]);
    $editDoc = jewellery_purchase($companyId, (int) ($_GET['edit'] ?? 0));
    if ($editDoc) {
        $editLines = jewellery_purchase_line_rows($companyId, (int) $editDoc['id']);
    }
} elseif ($view === 'sales') {
    $docs = jewellery_sales_list($companyId, $docFilters);
    $editDoc = jewellery_sale($companyId, (int) ($_GET['edit'] ?? 0));
    if ($editDoc) {
        $editLines = jewellery_sale_line_rows($companyId, (int) $editDoc['id']);
        $editExchanges = jewellery_sale_exchange_rows($companyId, (int) $editDoc['id']);
    }
}

// Rows a template or an import just put on the form. They REPLACE whatever the
// grid would otherwise show, and are taken out of the session as they are read
// so a refresh does not apply them again — a page that keeps re-filling itself
// is impossible to correct.
$stagedDocType = $view === 'purchases' ? 'purchase' : 'sale';
if (!empty($_SESSION['jw_staged_lines'][$stagedDocType])) {
    $editLines = (array) $_SESSION['jw_staged_lines'][$stagedDocType];
    unset($_SESSION['jw_staged_lines'][$stagedDocType]);
}
$lineTemplates = in_array($view, ['purchases', 'sales'], true)
    ? jw_templates_list($companyId, $stagedDocType) : [];

// Orders waiting to be collected by the customer on this sale. Somebody at the
// counter picking up their ring should not have to be asked which order it was.
$saleParty = (int) ($_GET['for_party'] ?? ($editDoc['party_id'] ?? 0));
$openOrders = [];
$orderPrefill = null;
$openAdvances = [];
$editAdvanceAllocs = [];
if ($view === 'sales') {
    if ($saleParty > 0) {
        $openOrders = jewellery_open_orders_for_party($companyId, $saleParty);
    }
    // Selling an order: the line is filled in from the order, priced at the
    // rate that stood ON THE ORDER DATE, not today's.
    // One order (the old "Sell this" link) or several ticked together — a
    // customer collecting a locket and a ring on one visit gets one bill.
    $sellOrderIds = array_map('intval', (array) ($_GET['sell_orders'] ?? []));
    if ((int) ($_GET['sell_order'] ?? 0) > 0) {
        $sellOrderIds[] = (int) $_GET['sell_order'];
    }
    $sellOrderIds = array_values(array_unique(array_filter($sellOrderIds, static fn (int $id): bool => $id > 0)));
    if ($sellOrderIds !== []) {
        $prefill = jewellery_orders_sale_prefill($companyId, $sellOrderIds);
        if ($prefill['ok']) {
            $orderPrefill = $prefill;
            $saleParty = (int) ($prefill['party_id'] ?? $saleParty);
            $openOrders = jewellery_open_orders_for_party($companyId, $saleParty);
            if ($editLines === []) {
                // EVERY line, not just the first. An order for a ring AND a
                // chain was filled in as the ring alone — quietly dropping half
                // of what the shop agreed to sell, which is the exact thing
                // jewellery_order_sale_prefill() builds the full list to avoid.
                $editLines = $prefill['lines'];
            }
        } else {
            flash('error', $prefill['error']);
        }
    }
    $sellingOrderIds = $orderPrefill ? array_map('intval', (array) $orderPrefill['order_ids']) : [];
    // Every advance this customer still holds, entry by entry — this order's,
    // a previous order's, all of it. The user ticks WHICH entries pay this
    // bill; nothing is applied on their behalf.
    if ($saleParty > 0) {
        $openAdvances = jewellery_open_advances($companyId, $saleParty, (int) ($editDoc['id'] ?? 0));
        if (!empty($editDoc['id'])) {
            foreach (jewellery_sale_advance_allocations($companyId, (int) $editDoc['id']) as $allocRow) {
                $editAdvanceAllocs[(int) $allocRow['settlement_id']] = (float) $allocRow['amount'];
            }
        }
    }
}

// Posting is confirmed with the mapping ON THE SCREEN. The Post button leads
// here, the draft is dry-posted and rolled back, and the ledger legs and
// stock movements it WOULD write are laid out for the user to confirm.
// Nothing reaches the ledger sight unseen.
$confirmPost = null;
if (in_array($view, ['sales', 'purchases'], true) && $canPost) {
    $confirmPostId = (int) ($_GET['confirm_post'] ?? 0);
    if ($confirmPostId > 0) {
        $confirmDocType = $view === 'sales' ? 'sale' : 'purchase';
        $confirmDoc = $view === 'sales'
            ? jewellery_sale($companyId, $confirmPostId)
            : jewellery_purchase($companyId, $confirmPostId);
        if ($confirmDoc && (string) $confirmDoc['status'] === 'draft') {
            $confirmPost = [
                'doc' => $confirmDoc,
                'doc_type' => $confirmDocType,
                'no' => (string) ($confirmDoc[$confirmDocType . '_no'] ?? ''),
                'preview' => jewellery_preview_posting($companyId, $confirmDocType, $confirmPostId),
            ];
        } elseif ($confirmDoc) {
            flash('error', 'That document is already posted.');
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

<?php
// The line grid is shared with the order form — one grid, one set of
// column names, so a field added to a sale reaches an order too.
require_once __DIR__ . '/../../app/views/partials/jewellery_line_grid.php';
require_once __DIR__ . '/../../app/views/partials/jewellery_filter_bar.php';
// The module skin: the document header, the summary rail and the strip
// that closes the page.
jw_line_grid_styles();
jw_filter_bar_styles();
?>

<nav class="mbw-tabbar" aria-label="Jewellery trading sections" style="flex-wrap:wrap">
    <a class="mbw-tab" href="<?= e(url('admin/jewellery.php')) ?>"><?= icon('dashboard') ?> Jewellery Home</a>
    <?php foreach (['purchases' => ['Purchases', 'box'], 'sales' => ['Sales', 'receipt-voucher'], 'bills' => ['Bills &amp; Settlement', 'wallet']] as $tabView => [$tabLabel, $tabIcon]): ?>
        <a class="mbw-tab <?= $view === $tabView ? 'is-active' : '' ?>" href="<?= e(url('admin/jewellery-trade.php?view=' . $tabView)) ?>"><?= icon($tabIcon) ?> <?= $tabLabel ?></a>
    <?php endforeach; ?>
</nav>

<?php if ($confirmPost !== null): ?>
<?php // The mapping, confirmed before it is committed. The legs below are the
      // REAL posting, dry-run and rolled back — not a hand-built imitation of
      // it — so what the user confirms is what the ledger receives. ?>
<section class="mbw-card" style="margin-bottom:14px;border:2px solid var(--mbw-accent,#0f766e)">
    <div class="mbw-card-head">
        <h2>Post <?= e($confirmPost['no']) ?> — confirm where it lands</h2>
        <a class="mbw-view-all" href="<?= e(url('admin/jewellery-trade.php?view=' . $view)) ?>">Cancel</a>
    </div>
    <?php if (!$confirmPost['preview']['ok']): ?>
        <div class="notice"><strong>This document cannot be posted:</strong> <?= e((string) $confirmPost['preview']['error']) ?></div>
    <?php else: ?>
        <div style="overflow-x:auto"><table>
            <thead><tr><th>Ledger</th><th class="is-numeric">Debit (<?= e($sym) ?>)</th><th class="is-numeric">Credit (<?= e($sym) ?>)</th><th>Against</th></tr></thead>
            <tbody>
                <?php foreach ($confirmPost['preview']['legs'] as $leg): ?>
                    <tr>
                        <td><?= e(((string) ($leg['ledger_code'] ?? '') !== '' ? $leg['ledger_code'] . ' — ' : '') . (string) $leg['ledger_name']) ?></td>
                        <td class="is-numeric"><?= (string) $leg['entry_type'] === 'debit' ? $fmt((float) $leg['amount']) : '' ?></td>
                        <td class="is-numeric"><?= (string) $leg['entry_type'] === 'credit' ? $fmt((float) $leg['amount']) : '' ?></td>
                        <td><small><?= e((string) ($leg['memo'] ?? '')) ?></small></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot><tr>
                <th>Balanced</th>
                <th class="is-numeric"><?= $fmt((float) $confirmPost['preview']['debit_total']) ?></th>
                <th class="is-numeric"><?= $fmt((float) $confirmPost['preview']['credit_total']) ?></th>
                <th></th>
            </tr></tfoot>
        </table></div>
        <?php if ($confirmPost['preview']['stock'] !== []): ?>
            <h3 style="margin:12px 0 6px;font-size:0.95rem">And in stock</h3>
            <div style="overflow-x:auto"><table>
                <thead><tr><th>Item</th><th>Purity</th><th>Direction</th>
                    <th class="is-numeric">Gross</th><th class="is-numeric">Fine</th><th class="is-numeric">Value (<?= e($sym) ?>)</th></tr></thead>
                <tbody>
                    <?php foreach ($confirmPost['preview']['stock'] as $move): ?>
                        <tr>
                            <td><?= e((string) $move['item_code']) ?> <small><?= e((string) $move['item_name']) ?></small></td>
                            <td><?= e((string) ($move['purity_code'] ?? '')) ?></td>
                            <td><span class="mbw-pill <?= (string) $move['direction'] === 'in' ? 'tone-green' : 'tone-amber' ?>"><?= (string) $move['direction'] === 'in' ? 'Into stock' : 'Out of stock' ?></span></td>
                            <td class="is-numeric"><?= $fmt((float) $move['gross_weight'], 4) ?> <small><?= e((string) ($move['unit_code'] ?? '')) ?></small></td>
                            <td class="is-numeric"><?= $fmt((float) $move['fine_weight'], 4) ?></td>
                            <td class="is-numeric"><?= $fmt((float) $move['amount']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
        <form method="post" style="margin-top:12px;display:flex;gap:10px;align-items:center">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="<?= $confirmPost['doc_type'] === 'sale' ? 'post_sale' : 'post_purchase' ?>">
            <input type="hidden" name="back_view" value="<?= e($view) ?>">
            <input type="hidden" name="doc_id" value="<?= (int) $confirmPost['doc']['id'] ?>">
            <button type="submit" class="button">Confirm &amp; Post — exactly the entries above</button>
            <a class="button soft" href="<?= e(url('admin/jewellery-trade.php?view=' . $view)) ?>">Cancel</a>
        </form>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php
/**
 * The grid's toolbar: load a saved set of lines, save the current one, or read
 * them out of a spreadsheet.
 *
 * These controls live INSIDE the document form — a form cannot be nested inside
 * another, and putting them in one of their own would have meant the import
 * losing everything already typed on the page.
 *
 * So they share the form and say which button was pressed through grid_action,
 * which the dispatcher reads BEFORE the form's own hidden action. Relying on a
 * second field called "action" overriding the first by document order would
 * have worked, and would have been a trap for whoever moved a field later.
 */
$renderGridToolbar = static function (string $docType) use ($lineTemplates): string {
    ob_start(); ?>
    <input type="hidden" name="doc_type" value="<?= e($docType) ?>">
    <?php if ($lineTemplates !== []): ?>
        <label class="sr-only" for="jw-tpl-<?= e($docType) ?>">Quick template</label>
        <select id="jw-tpl-<?= e($docType) ?>" name="template_id">
            <option value="">Quick templates…</option>
            <?php foreach ($lineTemplates as $tpl): ?>
                <option value="<?= (int) $tpl['id'] ?>"><?= e($tpl['name']) ?> (<?= (int) $tpl['line_count'] ?>)</option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="button soft" name="grid_action" value="apply_template" formnovalidate>Load</button>
    <?php endif; ?>
    <label class="sr-only" for="jw-tplname-<?= e($docType) ?>">Save these lines as</label>
    <input id="jw-tplname-<?= e($docType) ?>" type="text" name="template_name" maxlength="120" placeholder="Save as template…">
    <button type="submit" class="button soft" name="grid_action" value="save_template" formnovalidate>Save set</button>
    <label class="sr-only" for="jw-imp-<?= e($docType) ?>">Spreadsheet of items</label>
    <input id="jw-imp-<?= e($docType) ?>" type="file" name="lines_file" accept=".csv,.xlsx">
    <button type="submit" class="button soft" name="grid_action" value="import_lines" formnovalidate><?= icon('upload') ?>Import</button>
    <?php
    return (string) ob_get_clean();
};

$renderLineRows = static function (string $prefix, array $existing, int $slots, string $legend, string $headActions = '') use ($items, $purities, $units, $baseUnit, $fmt, $onHand): void {
    jw_render_line_grid($prefix, $existing, $slots, $legend, [
        'items' => $items, 'purities' => $purities, 'units' => $units,
        'base_unit' => $baseUnit, 'fmt' => $fmt, 'on_hand' => $onHand,
        'head_actions' => $headActions,
    ]);
};
?>

<?php if ($view === 'purchases'): ?>
    <?php jw_page_head('Jewellery Purchases (Metal, Stones & Other Items)',
        'Record what was bought for the shop — purity, weight, wastage and making charges.', 'box'); ?>
    <?php if ($canEdit): ?>
        <?php if ($editDoc && (string) $editDoc['status'] !== 'draft'): ?>
        <section class="jw-card">
            <div class="jw-card-head"><h2><?= icon('box') ?>Purchase <?= e((string) $editDoc['purchase_no']) ?></h2></div>
            <div class="notice">This purchase is posted and can no longer be edited. Unpost it first.</div>
        </section>
        <?php else: ?>
        <?php // The FORM is the two-column grid, so the rail is a real sibling
              // of the working area rather than something floated beside it. ?>
        <form method="post" class="jw-layout" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_purchase">
            <input type="hidden" name="back_view" value="purchases">
            <input type="hidden" name="purchase_id" value="<?= (int) ($editDoc['id'] ?? 0) ?>">
          <div>
            <section class="jw-card">
            <div class="jw-card-head">
                <h2><?= icon('box') ?><?= $editDoc ? 'Edit Draft Purchase — ' . e((string) $editDoc['purchase_no']) : 'New Purchase' ?></h2>
                <?php if ($editDoc): ?><span class="jw-card-head-actions">
                    <?php // Tagging belongs where stock arrives: the pieces on this
                          // purchase are exactly the ones that need a tag before they
                          // reach the showcase, so the ids go straight to the tag
                          // screen already ticked.
                          $tagIds = [];
                          foreach ($editLines as $tagLine) {
                              $tagItemId = (int) ($tagLine['item_id'] ?? 0);
                              if ($tagItemId > 0) { $tagIds[$tagItemId] = $tagItemId; }
                          }
                    ?>
                    <?php if ($tagIds !== []): ?>
                        <a class="button soft" href="<?= e(url('admin/jewellery-tags.php?items=' . implode(',', $tagIds))) ?>">
                            <?= icon('documents') ?>Print tags (<?= count($tagIds) ?>)
                        </a>
                    <?php endif; ?>
                    <a class="button soft" href="<?= e(url('admin/jewellery-trade.php?view=purchases')) ?>">New purchase</a>
                </span><?php endif; ?>
            </div>
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
                        <option value="0">— new party →</option>
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
                </label>
                <label style="grid-column:1/-1">Narration<input type="text" name="narration" maxlength="255" value="<?= e((string) ($editDoc['narration'] ?? '')) ?>"></label>
            </div>
            <?php $renderLineRows('l', $editLines, max($lineSlots, count($editLines) + 1), 'Purchase lines', $renderGridToolbar('purchase')); ?>
            </section>
          </div>

            <?php
                // The rail is the form's SECOND grid column, and being inside
                // the form is what lets its buttons submit it and the totals
                // script scope itself to exactly these fields.
                ob_start(); ?>
                <button type="submit" class="button" <?= $items === [] ? 'disabled' : '' ?>><?= icon('tasks') ?>Save Draft</button>
                <?php if ($editDoc): ?>
                    <a class="button soft" href="<?= e(url('admin/jewellery-trade.php?view=purchases')) ?>">Cancel</a>
                <?php endif; ?>
                <?php jw_summary_rail([
                    'currency' => $sym,
                    'actions' => (string) ob_get_clean(),
                    'shortcuts' => [
                        'Metal rates' => url('admin/jewellery.php?view=rates'),
                        'Items &amp; purity' => url('admin/jewellery.php?view=items'),
                        'Posting ledgers' => url('admin/jewellery.php?view=settings'),
                    ],
                ]); ?>
        </form>
        <?php endif; ?>
    <?php endif; ?>

    <section class="mbw-card" data-collapsible style="margin-top:14px">
        <div class="mbw-card-head"><h2>Purchases (<?= count($docs) ?>)</h2></div>
        <?php jw_render_filter_bar([
            'hidden' => ['view' => 'purchases'],
            'search' => $filterSearch, 'from' => $filterFrom, 'to' => $filterTo,
            'min_date' => $fyStart, 'max_date' => $fyEnd,
            'reset' => url('admin/jewellery-trade.php?view=purchases'),
            'advanced_in_use' => $advancedInUse,
            'advanced' => [
                ['label' => 'Status', 'html' => jw_filter_select('status', $filterStatus,
                    ['draft' => 'Draft', 'posted' => 'Posted'])],
                ['label' => 'Party', 'html' => jw_filter_select('party', (string) $filterParty,
                    array_column($parties, 'name', 'id'))],
                ['label' => 'Source', 'html' => jw_filter_select('source', $filterSource, [
                    'supplier' => 'Supplier', 'customer' => 'Old gold from a customer', 'refinery' => 'Refinery',
                ])],
            ],
        ]); ?>

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
                            <a class="button soft" style="min-height:30px;padding:3px 10px" target="_blank" rel="noopener" href="<?= e(url('admin/jewellery-print.php?doc=purchase&id=' . (int) $row['id'])) ?>">Preview</a>
                            <?php if ($canExport): ?>
                                <a class="button soft" style="min-height:30px;padding:3px 10px" href="<?= e(url('admin/jewellery-print.php?doc=purchase&id=' . (int) $row['id'] . '&format=xlsx')) ?>">Excel</a>
                            <?php endif; ?>
                            <?php if ($isDraft && $canPost): ?>
                                <?php // Posting goes through the confirmation card above: the
                                      // ledger legs are shown, then committed — never blind. ?>
                                <a class="button secondary" style="min-height:30px;padding:3px 10px"
                                   href="<?= e(url('admin/jewellery-trade.php?view=purchases&confirm_post=' . (int) $row['id'])) ?>">Post…</a>
                            <?php elseif (!$isDraft && $canPost): ?>
                                <form method="post" style="display:inline" data-confirm="Unpost this purchase? Its voucher and stock movements will be removed.">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="unpost_purchase">
                                    <input type="hidden" name="back_view" value="purchases">
                                    <input type="hidden" name="doc_id" value="<?= (int) $row['id'] ?>">
                                    <button type="submit" class="button soft" style="min-height:30px;padding:3px 10px">Unpost</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($isDraft && $canEdit): ?>
                                <form method="post" style="display:inline"
                                      onsubmit="return confirm('Delete purchase <?= e((string) $row['purchase_no']) ?>?')">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="delete_purchase">
                                    <input type="hidden" name="back_view" value="purchases">
                                    <input type="hidden" name="doc_id" value="<?= (int) $row['id'] ?>">
                                    <button type="submit" class="button soft" style="min-height:30px;padding:3px 8px;color:var(--mbw-red,#e5484d)" title="Delete this draft purchase"><?= icon('trash') ?></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>

<?php elseif ($view === 'sales'): ?>
    <?php jw_page_head('Jewellery Sales (Counter Billing)',
        'Raise the bill, take old gold in exchange, and settle what is left.', 'receipt-voucher'); ?>
    <?php if ($canEdit): ?>
        <?php if ($editDoc && (string) $editDoc['status'] !== 'draft'): ?>
        <section class="jw-card">
            <div class="jw-card-head"><h2><?= icon('receipt-voucher') ?>Sale <?= e((string) $editDoc['sale_no']) ?></h2></div>
            <div class="notice">This sale is posted and can no longer be edited. Unpost it first.</div>
        </section>
        <?php else: ?>
        <form method="post" class="jw-layout" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_sale">
            <input type="hidden" name="back_view" value="sales">
            <input type="hidden" name="sale_id" value="<?= (int) ($editDoc['id'] ?? 0) ?>">
          <div>
            <section class="jw-card">
            <div class="jw-card-head">
                <h2><?= icon('receipt-voucher') ?><?= $editDoc ? 'Edit Draft Sale — ' . e((string) $editDoc['sale_no']) : 'New Sale' ?></h2>
                <?php if ($editDoc): ?><span class="jw-card-head-actions"><a class="button soft" href="<?= e(url('admin/jewellery-trade.php?view=sales')) ?>">New sale</a></span><?php endif; ?>
            </div>
            <div class="workspace-form-grid">
                <label>Date<input type="date" name="sale_date" value="<?= e((string) ($editDoc['sale_date'] ?? $todayInFy)) ?>" min="<?= e($fyStart) ?>" max="<?= e($fyEnd) ?>" required></label>
                <label>Existing customer
                    <select name="party_id" id="jw-sale-party"
                            data-orders-url="<?= e(url('admin/jewellery-trade.php?view=sales')) ?>">
                        <option value="0">— new customer →</option>
                        <?php foreach ($parties as $p): ?>
                            <option value="<?= (int) $p['id'] ?>" <?= $saleParty === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
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
                <label>Sales person<input type="text" name="sales_person" maxlength="120" value="<?= e((string) ($editDoc['sales_person'] ?? '')) ?>"></label>
                <label>Customer id / ref.<input type="text" name="customer_ref" maxlength="60" value="<?= e((string) ($editDoc['customer_ref'] ?? '')) ?>"></label>
                <label>Tran. date (B.S.)<input type="text" name="tran_date_bs" maxlength="20" placeholder="2082-03-15" value="<?= e((string) ($editDoc['tran_date_bs'] ?? '')) ?>"></label>
                <label>Other charges (<?= e($sym) ?>)<input type="number" name="other_charges" step="0.01" min="0" value="<?= e((string) ($editDoc['other_charges'] ?? '0')) ?>"></label>
                <label>Discount (<?= e($sym) ?>)<input type="number" name="discount" step="0.01" min="0" value="<?= e((string) ($editDoc['discount'] ?? '0')) ?>"></label>
                <label>Skills Promotion Tax (<?= e($sym) ?>)<input type="number" name="manual_tax_amount" step="0.01" min="0" placeholder="auto" value="<?= e((string) ($editDoc['manual_tax_amount'] ?? '')) ?>">
                </label>
                <label style="grid-column:1/-1">Narration<input type="text" name="narration" maxlength="255" value="<?= e((string) ($editDoc['narration'] ?? '')) ?>"></label>
                <label style="grid-column:1/-1">Remarks (printed on the bill)<input type="text" name="remarks" maxlength="255" value="<?= e((string) ($editDoc['remarks'] ?? '')) ?>"></label>
            </div>
            <?php if ($openOrders !== []): ?>
            <fieldset style="border:1px solid var(--mbw-border,#d9e2ec);border-radius:10px;padding:12px;margin:12px 0">
                <legend style="padding:0 6px;font-weight:600">Orders this customer is here to collect</legend>
                <div style="overflow-x:auto"><table>
                    <thead><tr><th style="width:34px"></th><th>Order</th><th>Ordered</th><th>Item</th><th class="is-numeric">Weight</th><th class="is-numeric">Advance</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php
                        // Only a piece that is actually BACK can be handed over.
                        // Ticking one still out with the kaligad would bill a
                        // customer for gold nobody has yet.
                        $collectTotalAdvance = 0.0;
                        foreach ($openOrders as $ord):
                            $isSelling = in_array((int) $ord['id'], $sellingOrderIds, true);
                            $isReady = (string) $ord['status'] === 'received';
                            if ($isSelling) { $collectTotalAdvance += (float) $ord['advance_amount']; }
                        ?>
                            <tr<?= $isSelling ? ' style="background:var(--mbw-accent-soft,#eef7f1)"' : '' ?>>
                                <td><input type="checkbox" class="jw-collect-tick" form="jw-collect-form"
                                           name="sell_orders[]" value="<?= (int) $ord['id'] ?>"
                                           data-advance="<?= e((string) (float) $ord['advance_amount']) ?>"
                                           <?= $isSelling ? 'checked' : '' ?>
                                           <?= $isReady ? '' : 'disabled title="Still out with the kaligad"' ?>></td>
                                <td><strong><?= e((string) $ord['order_no']) ?></strong>
                                    <?= $isSelling ? '<span class="mbw-pill tone-green">Being sold</span>' : '' ?>
                                </td>
                                <td><?= e(app_date((string) $ord['order_date'])) ?></td>
                                <td><?= e((string) ($ord['item_name'] ?? $ord['description'] ?? '—')) ?>
                                    <small><?= e((string) $ord['purity_code']) ?></small></td>
                                <td class="is-numeric"><?= $fmt((float) $ord['expected_gross_weight'], 4) ?> <?= e((string) $ord['unit_code']) ?></td>
                                <td class="is-numeric"><?= $fmt((float) $ord['advance_amount']) ?></td>
                                <td><span class="mbw-pill <?= $isReady ? 'tone-green' : 'tone-gray' ?>"><?= e(ucfirst((string) $ord['status'])) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot><tr>
                        <th colspan="5" style="text-align:right">Ticked</th>
                        <th class="is-numeric"><span id="jw-collect-count"><?= count($sellingOrderIds) ?></span> order(s),
                            advance <?= e($sym) ?><span id="jw-collect-advance"><?= $fmt($collectTotalAdvance) ?></span></th>
                        <th></th>
                    </tr></tfoot>
                </table></div>
                <?php // Its own GET form: ticking re-fills the grid below from the
                      // orders chosen, which is a fresh page, not a sale being saved. ?>
                <form method="get" id="jw-collect-form" style="margin-top:8px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                    <input type="hidden" name="view" value="sales">
                    <input type="hidden" name="for_party" value="<?= (int) $saleParty ?>">
                    <button type="submit" class="button secondary"><?= icon('journal') ?>Bill the ticked orders</button>
                    <small style="color:var(--mbw-muted,#64748b)">Anything left unticked stays waiting for its own bill.</small>
                </form>
                <?php if ($orderPrefill): ?>
                    <?php foreach ($sellingOrderIds as $sellingId): ?>
                        <input type="hidden" name="deliver_order_ids[]" value="<?= (int) $sellingId ?>">
                    <?php endforeach; ?>
                    <div class="mbw-note tone-green" style="margin-top:10px">
                        <p style="margin:0">
                            <strong><?= e(implode(', ', array_map(
                                static fn (array $o): string => (string) $o['order_no'],
                                (array) $orderPrefill['orders']
                            ))) ?> <?= count($sellingOrderIds) === 1 ? 'is' : 'are' ?> filled in below.</strong>
                            <?= e((string) $orderPrefill['rate_note']) ?>
                            <?php if ($orderPrefill['received']): ?>
                                The weight billed is what actually came back from the kaligad, not the estimate.
                            <?php endif; ?>
                            Posting this sale marks the order delivered — goods leave the shop only against a posted bill.
                        </p>
                    </div>
                <?php endif; ?>
            </fieldset>
            <?php endif; ?>
            <?php if ($openAdvances !== []): ?>
            <fieldset style="border:1px solid var(--mbw-border,#d9e2ec);border-radius:10px;padding:12px;margin:12px 0">
                <legend style="padding:0 6px;font-weight:600">Advances this customer holds — tick what pays this bill</legend>
                <p style="margin:0 0 8px;color:var(--mbw-muted,#64748b)">
                    Every entry still held is listed — this order's, earlier orders', all of it.
                    Nothing is applied unless you put an amount against it.
                </p>
                <div style="overflow-x:auto"><table>
                    <thead><tr><th>Date</th><th>Ref</th><th>Order</th><th>How it was paid</th>
                        <th class="is-numeric">Still held</th><th class="is-numeric" style="min-width:140px">Apply (<?= e($sym) ?>)</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($openAdvances as $adv): ?>
                        <tr>
                            <td><?= e(app_date((string) $adv['settlement_date'])) ?></td>
                            <td><?= e((string) $adv['settlement_no']) ?></td>
                            <td><?= e((string) ($adv['order_no'] ?? '—')) ?></td>
                            <td><?= e(jw_tender_mode_label((string) $adv['mode'])) ?>
                                <?php if ((string) $adv['mode'] === 'metal'): ?>
                                    <small><?= e((string) ($adv['item_code'] ?? '')) ?> <?= e((string) ($adv['purity_code'] ?? '')) ?></small>
                                <?php endif; ?></td>
                            <td class="is-numeric"><?= $fmt((float) $adv['remaining']) ?></td>
                            <td class="is-numeric">
                                <input type="hidden" name="adv_alloc_id[]" value="<?= (int) $adv['id'] ?>">
                                <input type="number" name="adv_alloc_amount[]" class="jw-advalloc-amount"
                                       step="0.01" min="0" max="<?= e((string) $adv['remaining']) ?>"
                                       value="<?= e((string) ($editAdvanceAllocs[(int) $adv['id']] ?? '0')) ?>"
                                       style="max-width:130px">
                            </td>
                            <td><button type="button" class="button soft jw-advalloc-fill" data-remaining="<?= e((string) $adv['remaining']) ?>"
                                        style="min-height:28px;padding:2px 10px" title="Apply all of this entry">All</button></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table></div>
                <div style="text-align:right;margin-top:8px">
                    <strong>Advance applied: <?= e($sym) ?> <span id="jw-advalloc-total">0.00</span></strong>
                </div>
            </fieldset>
            <?php endif; ?>
            <?php $renderLineRows('l', $editLines, max($lineSlots, count($editLines) + 1), 'Items sold', $renderGridToolbar('sale')); ?>
            <?php $renderLineRows('x', $editExchanges, max(2, count($editExchanges) + 1), 'Old gold taken in exchange (metal-to-metal)'); ?>
            </section>
          </div>

            <?php
                ob_start(); ?>
                <button type="submit" class="button" <?= $items === [] ? 'disabled' : '' ?>><?= icon('tasks') ?>Save Draft</button>
                <?php if ($editDoc): ?>
                    <a class="button soft" target="_blank" rel="noopener" href="<?= e(url('admin/jewellery-invoice.php?id=' . (int) $editDoc['id'])) ?>"><?= icon('receipt-voucher') ?>Invoice</a>
                    <a class="button soft" href="<?= e(url('admin/jewellery-trade.php?view=sales')) ?>">Cancel</a>
                <?php endif; ?>
                <?php
                // How the money was tendered belongs with the money, so it
                // sits in the rail beside the total rather than halfway down
                // the form. It is still inside the document form, so its
                // fields post with everything else.
                ob_start(); ?>
                <details<?= ((float) ($editDoc['paid_cash'] ?? 0) + (float) ($editDoc['paid_card'] ?? 0)
                    + (float) ($editDoc['paid_cheque'] ?? 0) + (float) ($editDoc['paid_qr'] ?? 0)) > 0 ? ' open' : '' ?>>
                    <summary>How the money was tendered</summary>
                    <div class="workspace-form-grid">
                        <?php foreach (['paid_cash' => 'Cash', 'paid_card' => 'Card', 'paid_cheque' => 'Cheque', 'paid_qr' => 'QR / transfer'] as $tenderField => $tenderLabel): ?>
                            <label><?= e($tenderLabel) ?> (<?= e($sym) ?>)<input type="number" name="<?= e($tenderField) ?>" step="0.01" min="0"
                                value="<?= e((string) ($editDoc[$tenderField] ?? '0')) ?>"></label>
                        <?php endforeach; ?>
                    </div>
                </details>
                <?php $tenderPanel = (string) ob_get_clean(); ?>
                <?php jw_summary_rail([
                    'currency' => $sym,
                    'with_old_gold' => true,
                    'extra' => $tenderPanel,
                    'actions' => (string) ob_get_clean(),
                    'shortcuts' => [
                        'Metal rates' => url('admin/jewellery.php?view=rates'),
                        'Orders to deliver' => url('admin/jewellery-workshop.php?view=delivery'),
                        'Bills &amp; settlement' => url('admin/jewellery-trade.php?view=bills'),
                    ],
                ]); ?>
        </form>
        <?php endif; ?>
    <?php endif; ?>

    <section class="mbw-card" data-collapsible style="margin-top:14px">
        <div class="mbw-card-head"><h2>Sales (<?= count($docs) ?>)</h2></div>
        <?php jw_render_filter_bar([
            'hidden' => ['view' => 'sales'],
            'search' => $filterSearch, 'from' => $filterFrom, 'to' => $filterTo,
            'min_date' => $fyStart, 'max_date' => $fyEnd,
            'reset' => url('admin/jewellery-trade.php?view=sales'),
            'advanced_in_use' => $advancedInUse,
            'advanced' => [
                ['label' => 'Status', 'html' => jw_filter_select('status', $filterStatus,
                    ['draft' => 'Draft', 'posted' => 'Posted'])],
                ['label' => 'Customer', 'html' => jw_filter_select('party', (string) $filterParty,
                    array_column($parties, 'name', 'id'))],
            ],
        ]); ?>

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
                            <?php // The invoice exists most of all AFTER posting — viewing and
                                  // exporting it must never require the edit right, and a
                                  // posted bill is exactly the one a customer asks a copy of. ?>
                            <a class="button soft" style="min-height:30px;padding:3px 10px" target="_blank" rel="noopener" href="<?= e(url('admin/jewellery-print.php?doc=sale&id=' . (int) $row['id'])) ?>">Preview</a>
                            <a class="button soft" style="min-height:30px;padding:3px 10px" target="_blank" rel="noopener" href="<?= e(url('admin/jewellery-invoice.php?id=' . (int) $row['id'])) ?>">Invoice</a>
                            <?php if ($canExport): ?>
                                <a class="button soft" style="min-height:30px;padding:3px 10px" href="<?= e(url('admin/jewellery-print.php?doc=sale&id=' . (int) $row['id'] . '&format=xlsx')) ?>">Excel</a>
                            <?php endif; ?>
                            <?php if ($isDraft && $canPost): ?>
                                <?php // Posting goes through the confirmation card above: the
                                      // ledger legs are shown, then committed — never blind. ?>
                                <a class="button secondary" style="min-height:30px;padding:3px 10px"
                                   href="<?= e(url('admin/jewellery-trade.php?view=sales&confirm_post=' . (int) $row['id'])) ?>">Post…</a>
                            <?php elseif (!$isDraft && $canPost): ?>
                                <form method="post" style="display:inline" data-confirm="Unpost this sale? Its voucher, COGS and stock movements will be removed.">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="unpost_sale">
                                    <input type="hidden" name="back_view" value="sales">
                                    <input type="hidden" name="doc_id" value="<?= (int) $row['id'] ?>">
                                    <button type="submit" class="button soft" style="min-height:30px;padding:3px 10px">Unpost</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($isDraft && $canEdit): ?>
                                <?php // A posted sale is unposted first — deleting one would leave its voucher behind. ?>
                                <form method="post" style="display:inline"
                                      onsubmit="return confirm('Delete sale <?= e((string) $row['sale_no']) ?>?')">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="delete_sale">
                                    <input type="hidden" name="back_view" value="sales">
                                    <input type="hidden" name="doc_id" value="<?= (int) $row['id'] ?>">
                                    <button type="submit" class="button soft" style="min-height:30px;padding:3px 8px;color:var(--mbw-red,#e5484d)" title="Delete this draft sale"><?= icon('trash') ?></button>
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

    <section class="mbw-card" data-collapsible style="margin-top:14px">
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
    <section class="mbw-card" data-collapsible data-draggable style="margin-top:14px">
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

    <section class="mbw-card" data-collapsible style="margin-top:14px">
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
                            <a class="button soft" style="min-height:30px;padding:3px 10px" target="_blank" rel="noopener" href="<?= e(url('admin/jewellery-print.php?doc=settlement&id=' . (int) $row['id'])) ?>">Preview</a>
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

// The advance picker: the running total of what the user has chosen to apply,
// and an "All" button per entry that fills in what that entry still holds.
// Applying an advance is a choice made row by row — there is no field that
// applies money without naming where it comes from.
(function () {
    function total() {
        var out = document.getElementById("jw-advalloc-total");
        if (!out) { return; }
        var sum = 0;
        Array.prototype.forEach.call(document.querySelectorAll(".jw-advalloc-amount"), function (amount) {
            sum += parseFloat(amount.value) || 0;
        });
        out.textContent = sum.toFixed(2);
    }
    document.addEventListener("input", function (event) {
        if (event.target.classList.contains("jw-advalloc-amount")) { total(); }
    });
    document.addEventListener("click", function (event) {
        var fill = event.target.closest(".jw-advalloc-fill");
        if (!fill) { return; }
        var row = fill.closest("tr");
        var amount = row && row.querySelector(".jw-advalloc-amount");
        if (amount) { amount.value = fill.getAttribute("data-remaining") || "0"; total(); }
    });
    total();
})();

// The collect panel: how many orders are ticked and what advance they carry,
// counted as the ticks change rather than only after the page comes back. The
// figures are read off the checkboxes themselves, so nothing here can disagree
// with what will actually be submitted.
(function () {
    function collected() {
        var count = document.getElementById("jw-collect-count");
        var advance = document.getElementById("jw-collect-advance");
        if (!count || !advance) { return; }
        var n = 0;
        var sum = 0;
        Array.prototype.forEach.call(document.querySelectorAll(".jw-collect-tick"), function (tick) {
            if (!tick.checked) { return; }
            n += 1;
            sum += parseFloat(tick.getAttribute("data-advance")) || 0;
        });
        count.textContent = String(n);
        advance.textContent = sum.toFixed(2);
    }
    document.addEventListener("change", function (event) {
        if (event.target.classList && event.target.classList.contains("jw-collect-tick")) { collected(); }
    });
    collected();
})();
</script>

<?php
// The grid buttons, and the live totals in the summary rail.
jw_line_grid_scripts();
jw_summary_rail_script();
include __DIR__ . '/../../app/views/partials/admin_footer.php';
?>
