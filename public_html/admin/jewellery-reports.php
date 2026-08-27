<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/accounting_module_repair.php';
require_once __DIR__ . '/../../app/jewellery_reports.php';
require_once __DIR__ . '/../../app/export_engine.php';

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
$sym = site_currency_symbol();
$settings = jewellery_settings($companyId);
$currentUser = current_user();
$userId = (int) ($currentUser['id'] ?? 0);
$canExport = user_can_do('jewellery', 'export');
$canPost = user_can_do('jewellery', 'post');

$allowedViews = ['summary', 'sales', 'purchases', 'inventory', 'vat', 'karigar', 'statement', 'bills', 'uncollected',
    'orders', 'workshop', 'advreg', 'profit'];
$view = jw_enum($_GET['view'] ?? null, $allowedViews, 'summary');

$clampDate = static function (string $date) use ($fyStart, $fyEnd): string {
    if ($date === '' || strtotime($date) === false) {
        $date = date('Y-m-d');
    }
    return $date < $fyStart ? $fyStart : ($date > $fyEnd ? $fyEnd : $date);
};
// Default to the open year to date — what a jeweller almost always wants.
$from = $clampDate((string) ($_GET['from'] ?? $fyStart));
$to = $clampDate((string) ($_GET['to'] ?? min($fyEnd, date('Y-m-d'))));
if ($from > $to) {
    [$from, $to] = [$to, $from];
}
// Bill-wise by default: the register a counter recognises is one row per
// invoice, and the other cuts are what somebody goes looking for afterwards.
$groupBy = jw_enum($_GET['group'] ?? null, array_keys(jw_sales_group_options()), 'invoice');
$karigarId = (int) ($_GET['karigar'] ?? 0);
$karigars = jewellery_karigars_list($companyId);
// The rate the statement values metal at. Blank falls back to the rate board on
// the closing date, and then to the metal's own carrying value.
$statementRate = (float) ($_GET['fine_rate'] ?? 0);
$statement = null;
if ($view === 'statement' && $karigarId > 0) {
    $statement = jw_report_karigar_statement($companyId, $karigarId, $from, $to, ['fine_rate' => $statementRate]);
}
// The order-workflow reports' own filters. Status vocabulary matches the
// order enum; 'pending' narrows the workshop register to metal still out.
$orderStatusFilter = jw_enum($_GET['status'] ?? null,
    ['draft', 'confirmed', 'assigned', 'partially_received', 'received', 'invoiced', 'delivered', 'closed', 'cancelled', ''], '');
$pendingOnly = (string) ($_GET['pending'] ?? '') === '1';
$workshopGroup = jw_enum($_GET['wgroup'] ?? null, ['none', 'karigar', 'purity'], 'none');
$billPage = max(1, (int) ($_GET['bill_page'] ?? 1));
$billPageSize = 200;

// Revaluing writes to the ledger, so it is a POST like every other posting.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if ((string) ($_POST['action'] ?? '') === 'revalue_karigar_metal') {
        require_permission('jewellery', 'post');
        $result = jewellery_revalue_karigar_metal(
            $companyId,
            $fiscalYearId,
            (int) ($_POST['karigar_id'] ?? 0),
            $clampDate((string) ($_POST['as_of'] ?? '')),
            ['fine_rate' => (float) ($_POST['fine_rate'] ?? 0)],
            $userId
        );
        flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? ($result['note'] ?: 'Metal revalued: ' . number_format((float) $result['gap'], 2)
                . ' posted, so the trial balance now shows this holding at ' . number_format((float) $result['fine_rate'], 2) . '.')
            : $result['error']);
        redirect('admin/jewellery-reports.php?view=statement&karigar=' . (int) ($_POST['karigar_id'] ?? 0)
            . '&from=' . $from . '&to=' . $to . '&fine_rate=' . (float) ($_POST['fine_rate'] ?? 0));
    }
}

// ---------------------------------------------------------------------------
// CSV export — driven by the SAME query the screen renders, so an exported
// file and the page a user is looking at can never disagree.
// ---------------------------------------------------------------------------
if (isset($_GET['export']) && $canExport) {
    // One set of rows, three formats. The file a user downloads and the screen
    // they are looking at are built from the SAME query, so they cannot disagree.
    $stamp = $from . '_to_' . $to;
    $format = jw_enum($_GET['export'] ?? null, ['csv', 'xlsx', 'print', 'pdf'], 'csv');
    $meta = ['Period' => app_date($from) . ' to ' . app_date($to)];
    if ($view === 'summary') {
        // The consolidated page, flat: one row per figure with the section it
        // belongs to beside it, so a spreadsheet can pivot on it and a PDF reads
        // in the same order as the screen. The BASIS travels with each section —
        // a stock figure "as at" a date and a receivable "as it stands" are not
        // the same kind of number, and a column of bare amounts hides that.
        $consolidated = jw_report_consolidated($companyId, $from, $to);
        $data = jw_report_consolidated_export_rows($consolidated);
        $summaryMeta = $meta;
        $summaryMeta['Weights'] = $consolidated['base_unit'] !== ''
            ? ('fine ' . $consolidated['base_unit']) : 'fine, base unit';
        // NOT footed, and this is the one report that must not be. Its Value
        // column holds rupees, weights and counts one under the other, so a sum
        // down it is the single figure on the page that could not mean anything.
        // Each section already foots itself where a footing makes sense.
        export_dispatch($format, 'jewellery-summary-' . $stamp, $data, 'Consolidated Summary', $summaryMeta);
    }
    if ($view === 'sales') {
        // The file carries whichever cut is on screen, bifurcated the same way
        // and already footed by the engine -- so the two cannot disagree about
        // what was sold, and nobody has to add a column up by hand.
        $bifExport = jw_report_sales_bifurcated($companyId, $from, $to, $groupBy);
        $data = jw_report_sales_bifurcated_rows($bifExport);
        export_dispatch(
            $format,
            'jewellery-sales-' . $groupBy . '-' . $stamp,
            $data,
            mb_substr(jw_sales_group_options()[$groupBy], 0, 31),
            $meta + ['Grouped by' => jw_sales_group_options()[$groupBy]]
        );
    }
    if ($view === 'purchases') {
        $data = [['Date', 'Purchase no', 'Party', 'Source', 'Item', 'Purity', 'Pieces', 'Gross wt', 'Fine wt',
            'Rate', 'Metal', 'Making', 'Stone / diamond', 'VAT', 'Landed cost']];
        foreach (jw_report_purchase_detail($companyId, $from, $to)['rows'] as $r) {
            $data[] = [$r['purchase_date'], $r['purchase_no'], $r['party_label'], $r['source'], $r['item_code'],
                $r['purity_code'], $r['qty_pieces'], $r['gross_weight'], $r['fine_weight'], $r['rate'],
                $r['metal_amount'], $r['making_amount'], $r['stone_side'], $r['vat_amount'], $r['stock_amount']];
        }
        // Footed before it goes out: a register without its total is a list,
        // and the reader adds it up by hand and disagrees with the person
        // beside them. export_totals_row() leaves rates, dates and document
        // numbers alone — see its own note for why that list errs wide.
        $data = export_totals_row($data);
        export_dispatch($format, 'jewellery-purchases-' . $stamp, $data, 'Purchase Detailed', $meta);
    }
    if ($view === 'inventory') {
        $data = [['Item', 'Name', 'Metal', 'Purity', 'Opening fine', 'Opening value', 'In fine', 'In value',
            'Out fine', 'Out value', 'Closing fine', 'Closing value', 'Own fine', 'With others fine', 'Avg cost/fine']];
        foreach (jw_report_inventory_detail($companyId, $from, $to)['rows'] as $r) {
            $data[] = [$r['code'], $r['name'], $r['metal_name'], $r['purity_code'], $r['opening_fine'],
                $r['opening_value'], $r['in_fine'], $r['in_value'], $r['out_fine'], $r['out_value'],
                $r['closing_fine'], $r['closing_value'], $r['own_fine'], $r['with_others_fine'], $r['avg_fine_rate']];
        }
        // Footed before it goes out: a register without its total is a list,
        // and the reader adds it up by hand and disagrees with the person
        // beside them. export_totals_row() leaves rates, dates and document
        // numbers alone — see its own note for why that list errs wide.
        $data = export_totals_row($data);
        export_dispatch($format, 'jewellery-inventory-' . $stamp, $data, 'Inventory Detailed', $meta);
    }
    if ($view === 'vat') {
        $register = jw_report_vat_register($companyId, $from, $to);
        $data = [['Direction', 'Date', 'Document', 'Party', 'PAN', 'Item', 'VAT base', 'Taxable', 'Rate %', 'VAT']];
        foreach ([['Output', 'output_rows'], ['Input', 'input_rows']] as [$direction, $key]) {
            foreach ($register[$key] as $r) {
                $data[] = [$direction, $r['doc_date'], $r['doc_no'], $r['party_label'], $r['pan_no'], $r['item_code'],
                    $r['vat_base'], $r['taxable_amount'], $r['vat_rate'], $r['vat_amount']];
            }
        }
        // Every other tax, VAT included, summarised beneath — a filing needs the
        // Skills Development levy as much as it needs the VAT.
        $data[] = [];
        $data[] = ['Tax', 'Name', 'Sales base', 'Charged on sales', 'Purchase base', 'Paid on purchases', 'Net payable'];
        foreach ($register['by_tax'] as $t) {
            $data[] = [$t['tax_code'], $t['tax_name'], $t['output_base'], $t['output_amount'],
                $t['input_base'], $t['input_amount'], $t['net_payable']];
        }
        // Footed before it goes out: a register without its total is a list,
        // and the reader adds it up by hand and disagrees with the person
        // beside them. export_totals_row() leaves rates, dates and document
        // numbers alone — see its own note for why that list errs wide.
        $data = export_totals_row($data);
        export_dispatch($format, 'jewellery-vat-' . $stamp, $data, 'Tax Register', $meta);
    }
    if ($view === 'uncollected') {
        $uncollected = jewellery_overdue_orders($companyId, $to);
        $data = [['Order', 'Customer', 'Phone', 'Ordered', 'Promised', 'Days late', 'Weight', 'Unit',
            'Value', 'Advance', 'Still to collect', 'Status']];
        foreach ($uncollected['rows'] as $r) {
            $data[] = [$r['order_no'], $r['party_label'], $r['phone'], $r['order_date'], $r['delivery_date'],
                $r['days_late'], $r['expected_gross_weight'], $r['unit_code'],
                $r['total_amount'], $r['advance_amount'], $r['balance_due'], $r['status']];
        }
        // Footed before it goes out: a register without its total is a list,
        // and the reader adds it up by hand and disagrees with the person
        // beside them. export_totals_row() leaves rates, dates and document
        // numbers alone — see its own note for why that list errs wide.
        $data = export_totals_row($data);
        export_dispatch($format, 'jewellery-uncollected-' . $stamp, $data, 'Uncollected Orders', $meta);
    }
    if ($view === 'bills') {
        $data = [['Party', 'Bill no', 'Type', 'Date', 'Billed', 'Settled', 'Outstanding', 'Status']];
        $exportOffset = 0;
        do {
            $exportParties = jw_report_bill_outstanding($companyId, '', 5000, $exportOffset);
            $exportBillCount = 0;
            foreach ($exportParties as $party) {
                foreach ($party['bills'] as $bill) {
                    $data[] = [$party['party_name'], $bill['bill_no'], $bill['bill_type'], $bill['bill_date'],
                        $bill['bill_amount'], $bill['settled_amount'], $bill['outstanding'], $bill['status']];
                    $exportBillCount++;
                }
            }
            $exportOffset += $exportBillCount;
        } while ($exportBillCount === 5000);
        // Footed before it goes out: a register without its total is a list,
        // and the reader adds it up by hand and disagrees with the person
        // beside them. export_totals_row() leaves rates, dates and document
        // numbers alone — see its own note for why that list errs wide.
        $data = export_totals_row($data);
        export_dispatch($format, 'jewellery-bills-' . $stamp, $data, 'Bill-wise Outstanding', $meta);
    }
    if ($view === 'karigar' && $karigarId > 0) {
        $ledger = jw_report_karigar_ledger($companyId, $karigarId, $from, $to);
        $data = [['Date', 'Ref', 'Item', 'Purity', 'Direction', 'Gross wt', 'Fine wt', 'Unit', 'Amount', 'Balance fine']];
        foreach ($ledger['rows'] as $r) {
            $data[] = [$r['txn_date'], $r['ref_no'], $r['item_code'], $r['purity_code'], $r['direction'],
                $r['gross_weight'], $r['fine_weight'], $r['unit_code'], $r['amount'], $r['balance_fine']];
        }
        $meta['Kaligad'] = (string) ($ledger['karigar']['name'] ?? '');
        $meta['Opening fine'] = number_format((float) $ledger['opening_fine'], 4);
        $meta['Closing fine'] = number_format((float) $ledger['closing_fine'], 4);
        // Footed before it goes out: a register without its total is a list,
        // and the reader adds it up by hand and disagrees with the person
        // beside them. export_totals_row() leaves rates, dates and document
        // numbers alone — see its own note for why that list errs wide.
        $data = export_totals_row($data);
        export_dispatch($format, 'kaligad-ledger-' . $stamp, $data, 'Kaligad Ledger', $meta);
    }
    if ($view === 'orders') {
        $report = jw_report_order_status($companyId, $from, $to, ['status' => $orderStatusFilter]);
        $data = [['Order', 'Date', 'Customer', 'Items', 'Metal', 'Purity', 'Expected wt', 'Fine wt', 'Unit',
            'Quoted', 'Advance held', 'Advance applied', 'Unapplied', 'Bill', 'Billed', 'Balance due', 'Promised', 'Status']];
        foreach ($report['rows'] as $r) {
            $data[] = [$r['order_no'], $r['order_date'], $r['party_label'], $r['item_count'], $r['metal_name'],
                $r['purity_code'], $r['expected_gross_weight'], $r['expected_fine_weight'], $r['unit_code'],
                $r['total_amount'], $r['advance_held'], $r['advance_applied'], $r['advance_unapplied'],
                $r['sale_no'], $r['billed_amount'], $r['balance_amount'], $r['delivery_date'], $r['status']];
        }
        // Footed before it goes out: a register without its total is a list,
        // and the reader adds it up by hand and disagrees with the person
        // beside them. export_totals_row() leaves rates, dates and document
        // numbers alone — see its own note for why that list errs wide.
        $data = export_totals_row($data);
        export_dispatch($format, 'jewellery-order-status-' . $stamp, $data, 'Order Status', $meta);
    }
    if ($view === 'workshop') {
        $report = jw_report_workshop($companyId, $from, $to,
            ['karigar_id' => $karigarId, 'pending_only' => $pendingOnly]);
        $data = [['Issue', 'Date', 'Kaligad', 'Order', 'Item', 'Purity', 'Issued gross', 'Issued fine',
            'Receipt', 'Received on', 'Received gross', 'Received fine', 'Still out (fine)', 'Wastage fine', 'Wages', 'Status']];
        foreach ($report['rows'] as $r) {
            $data[] = [$r['issue_no'], $r['issue_date'], $r['karigar_code'] . ' — ' . $r['karigar_name'],
                $r['order_no'], $r['item_code'], $r['purity_code'], $r['issued_gross_weight'], $r['issued_fine_weight'],
                $r['receipt_no'], $r['receive_date'], $r['received_gross_weight'], $r['received_fine_weight'],
                $r['pending_fine'], $r['wastage_fine_weight'], $r['making_amount'], $r['status']];
        }
        // Footed before it goes out: a register without its total is a list,
        // and the reader adds it up by hand and disagrees with the person
        // beside them. export_totals_row() leaves rates, dates and document
        // numbers alone — see its own note for why that list errs wide.
        $data = export_totals_row($data);
        export_dispatch($format, 'jewellery-workshop-' . $stamp, $data,
            $pendingOnly ? 'Gold Pending Return' : 'Gold Issued to Kaligad', $meta);
    }
    if ($view === 'advreg') {
        $report = jw_report_advance_register($companyId, $from, $to);
        $data = [['Entry', 'Date', 'Customer', 'Order', 'Direction', 'Mode', 'Amount', 'Weight', 'Purity',
            'Funded bills', 'Still held']];
        foreach ($report['rows'] as $r) {
            $data[] = [$r['settlement_no'], $r['settlement_date'], $r['party_label'], $r['order_no'],
                $r['direction'], $r['mode'], $r['amount'], $r['gross_weight'], $r['purity_code'],
                $r['allocated'], $r['remaining']];
        }
        $data[] = [];
        $data[] = ['Adjustment: entry', 'Applied to bill', 'Bill date', 'Customer', 'Amount'];
        foreach ($report['adjustments'] as $a) {
            $data[] = [$a['settlement_no'], $a['sale_no'], $a['sale_date'], $a['party_label'], $a['amount']];
        }
        // Footed before it goes out: a register without its total is a list,
        // and the reader adds it up by hand and disagrees with the person
        // beside them. export_totals_row() leaves rates, dates and document
        // numbers alone — see its own note for why that list errs wide.
        $data = export_totals_row($data);
        export_dispatch($format, 'jewellery-advance-register-' . $stamp, $data, 'Advance Register', $meta);
    }
    if ($view === 'profit') {
        $report = jw_report_order_profitability($companyId, $from, $to);
        $data = [['Order', 'Ordered', 'Customer', 'Bill', 'Billed on', 'Revenue', 'COGS', 'Kaligad wages',
            'Wastage borne', 'Order profit', 'Margin %']];
        foreach ($report['rows'] as $r) {
            $data[] = [$r['order_no'], $r['order_date'], $r['party_label'], $r['sale_no'], $r['sale_date'],
                $r['revenue'], $r['cogs'], $r['karigar_wages'], $r['wastage_borne'], $r['profit'], $r['margin_pct']];
        }
        // Footed before it goes out: a register without its total is a list,
        // and the reader adds it up by hand and disagrees with the person
        // beside them. export_totals_row() leaves rates, dates and document
        // numbers alone — see its own note for why that list errs wide.
        $data = export_totals_row($data);
        export_dispatch($format, 'jewellery-order-profit-' . $stamp, $data, 'Order Profitability', $meta);
    }
    if ($view === 'statement' && $karigarId > 0) {
        // The statement exports as ONE table — metal, then money, then what it
        // comes to — because that is what gets put on the counter between the
        // shop and the kaligad.
        $st = jw_report_karigar_statement($companyId, $karigarId, $from, $to, ['fine_rate' => $statementRate]);
        $unit = (string) (($st['base_unit'] ?? [])['code'] ?? '');
        $data = [['Section', 'Date', 'Ref', 'Particulars', 'In', 'Out', 'Balance', 'At this rate']];
        $data[] = ['Metal', '', '', 'Opening', '', '', $st['metal']['opening_fine'], ''];
        foreach ($st['metal']['rows'] as $r) {
            $isIn = (string) $r['direction'] === 'in';
            $data[] = ['Metal', $r['txn_date'], $r['ref_no'], $r['item_code'] . ' ' . $r['purity_code'],
                $isIn ? $r['base_fine_weight'] : '', $isIn ? '' : $r['base_fine_weight'],
                $r['balance_fine'], $r['valued_amount']];
        }
        $data[] = ['Metal', '', '', 'Closing (fine ' . $unit . ')', $st['metal']['in_fine'], $st['metal']['out_fine'],
            $st['metal']['closing_fine'], round((float) $st['metal']['closing_fine'] * (float) $st['rate']['fine_rate'], 2)];
        $data[] = ['Money', '', '', 'Opening', '', '', $st['money']['opening'], ''];
        foreach ($st['money']['rows'] as $r) {
            $data[] = ['Money', $r['date'], $r['ref'], $r['particulars'], '', '', $r['balance'], $r['amount']];
        }
        $data[] = ['Money', '', '', 'Closing', $st['money']['billed'], $st['money']['paid'], $st['money']['closing'], ''];
        $data[] = ['Settlement', '', '', 'Wages owed', '', '', '', $st['settlement']['wages_payable']];
        $data[] = ['Settlement', '', '', 'Metal held, valued', '', '', '', $st['settlement']['metal_receivable_value']];
        $data[] = ['Settlement', '', '', 'Metal owed, valued', '', '', '', $st['settlement']['metal_payable_value']];
        $data[] = ['Settlement', '', '', 'Net payable', '', '', '', $st['settlement']['net_payable']];

        $meta['Kaligad'] = (string) ($st['karigar']['name'] ?? '');
        $meta['Metal valued at'] = number_format((float) $st['rate']['fine_rate'], 2) . ' per fine ' . $unit
            . ' (' . (string) $st['rate']['label'] . ')';
        // Footed before it goes out: a register without its total is a list,
        // and the reader adds it up by hand and disagrees with the person
        // beside them. export_totals_row() leaves rates, dates and document
        // numbers alone — see its own note for why that list errs wide.
        $data = export_totals_row($data);
        export_dispatch($format, 'kaligad-statement-' . $stamp, $data, 'Kaligad Statement', $meta);
    }
}

$pageTitle = 'Jewellery Reports';
$pageSubtitle = 'Sales, purchases, inventory, VAT register, kaligad ledgers and bill-wise outstanding — all derived from posted documents.';
$pageHero = ['icon' => 'reports'];
$bodyClass = 'admin-layout accounting-module-page';
$pageBreadcrumb = [['Home', 'admin/index.php'], ['Jewellery', 'admin/jewellery.php'], ['Reports', 'admin/jewellery-reports.php']];
include __DIR__ . '/../../app/views/partials/admin_header.php';
// The module skin. Without it this page fell back to the generic admin cascade,
// where .button.soft is fought over by five stylesheets — which is how the CSV,
// Excel and Print buttons ended up as three unreadable green rectangles.
require_once __DIR__ . '/../../app/views/partials/jewellery_page_head.php';
jw_page_styles();

$fmt = static fn (?float $n, int $p = 2): string => $n === null ? 'N/A' : number_format($n, $p);
$baseUnitCode = (string) ((jewellery_base_unit($companyId) ?? [])['code'] ?? 'unit');
$exportUrl = static fn (string $v, string $format = 'csv'): string => url('admin/jewellery-reports.php?view=' . $v
    . '&from=' . $from . '&to=' . $to . '&karigar=' . $karigarId . '&fine_rate=' . $statementRate
    . '&status=' . urlencode($orderStatusFilter) . '&pending=' . ($pendingOnly ? '1' : '')
    . '&wgroup=' . $workshopGroup . '&export=' . $format);
$reportUrl = static fn (string $v): string => url('admin/jewellery-reports.php?view=' . $v
    . '&from=' . $from . '&to=' . $to . '&karigar=' . $karigarId . '&fine_rate=' . $statementRate
    . '&status=' . urlencode($orderStatusFilter) . '&pending=' . ($pendingOnly ? '1' : '')
    . '&wgroup=' . $workshopGroup);
?>

<style>
.jw-report-selector {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
    margin-bottom: 14px;
}
.jw-report-selector label {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    font-weight: 600;
}
.jw-report-selector select {
    min-height: 34px;
    padding: 6px 10px;
    min-width: 260px;
    max-width: 100%;
}
</style>

<section class="mbw-card" style="margin-bottom:14px">
    <form method="get" class="jw-report-selector">
        <label for="jw-report-view">Report</label>
        <select id="jw-report-view" name="view" aria-label="Choose report">
            <?php foreach ([
                'summary' => 'Summary', 'orders' => 'Order Status', 'workshop' => 'Gold Out / Workshop',
                'advreg' => 'Advance Register', 'profit' => 'Order Profitability',
                'sales' => 'Sales Detailed', 'purchases' => 'Purchase Detailed',
                'inventory' => 'Inventory Detailed', 'vat' => 'VAT Register', 'karigar' => 'Kaligad Ledger',
                'statement' => 'Kaligad Statement',
                'bills' => 'Bill-wise', 'uncollected' => 'Uncollected Orders',
            ] as $tabView => $tabLabel): ?>
                <option value="<?= e($tabView) ?>" data-url="<?= e($reportUrl($tabView)) ?>" <?= $view === $tabView ? 'selected' : '' ?>><?= e($tabLabel) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="hidden" name="from" value="<?= e($from) ?>">
        <input type="hidden" name="to" value="<?= e($to) ?>">
        <input type="hidden" name="karigar" value="<?= e((string) $karigarId) ?>">
        <input type="hidden" name="fine_rate" value="<?= e((string) $statementRate) ?>">
        <input type="hidden" name="status" value="<?= e($orderStatusFilter) ?>">
        <input type="hidden" name="pending" value="<?= e($pendingOnly ? '1' : '') ?>">
        <input type="hidden" name="wgroup" value="<?= e($workshopGroup) ?>">
        <noscript>
            <button type="submit" class="button">Go</button>
        </noscript>
    </form>
    <script>
    (function() {
        var viewSelect = document.getElementById('jw-report-view');
        if (!viewSelect) { return; }
        viewSelect.addEventListener('change', function () {
            var selected = viewSelect.options[viewSelect.selectedIndex];
            var url = selected && selected.dataset ? selected.dataset.url : null;
            if (url) {
                window.location = url;
            }
        });
    })();
    </script>
</section>

<section class="mbw-card">
    <form method="get" class="jw-report-filter">
        <input type="hidden" name="view" value="<?= e($view) ?>">
        <label>From<input type="date" name="from" value="<?= e($from) ?>" min="<?= e($fyStart) ?>" max="<?= e($fyEnd) ?>"></label>
        <label>To<input type="date" name="to" value="<?= e($to) ?>" min="<?= e($fyStart) ?>" max="<?= e($fyEnd) ?>"></label>
        <?php
// The long report tables are shown A PAGE AT A TIME. The Inventory Detailed
// list was 1.7 MB of table on a two-thousand-item shop and the sales detail
// 660 KB — sizes nobody reads down, and the browser pays for all of it.
//
// The EXPORT is untouched: it runs off the same query further up this file and
// returns before any of this, so a downloaded report still carries every row.
$reportPerPage = (int) ($_GET['r_per'] ?? 100);
if (!in_array($reportPerPage, [50, 100, 250, 500], true)) {
    $reportPerPage = 100;
}
$reportPageNo = max(1, (int) ($_GET['r_page'] ?? 1));
/** @return array{0: array, 1: int, 2: int} rows for this page, page, page count */
$reportSlice = static function (array $rows) use ($reportPerPage, $reportPageNo): array {
    $count = max(1, (int) ceil(count($rows) / $reportPerPage));
    $page = max(1, min($count, $reportPageNo));

    return [array_slice($rows, ($page - 1) * $reportPerPage, $reportPerPage), $page, $count];
};
$reportPageUrl = static function (array $overrides) use ($view, $from, $to, $reportPerPage): string {
    return url('admin/jewellery-reports.php?' . http_build_query(array_merge([
        'view' => $view, 'from' => $from, 'to' => $to, 'r_per' => (string) $reportPerPage,
    ], $overrides)));
};
$reportPager = static function (int $page, int $count, int $total) use ($reportPageUrl, $reportPerPage): void {
    if ($count <= 1) {
        return;
    }
    ?>
    <nav class="actions" style="margin-top:12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap" aria-label="Report pages">
        <?php if ($page > 1): ?><a class="button secondary" href="<?= e($reportPageUrl(['r_page' => $page - 1])) ?>">Previous</a><?php endif; ?>
        <span>Page <?= $page ?> of <?= $count ?> · <?= $total ?> rows · export gives them all</span>
        <?php if ($page < $count): ?><a class="button secondary" href="<?= e($reportPageUrl(['r_page' => $page + 1])) ?>">Next</a><?php endif; ?>
        <span style="margin-left:auto;display:flex;gap:6px;align-items:center">Rows
            <?php foreach ([50, 100, 250, 500] as $size): ?>
                <a class="button soft" style="<?= $size === $reportPerPage ? 'font-weight:700' : '' ?>"
                   href="<?= e($reportPageUrl(['r_per' => (string) $size, 'r_page' => 1])) ?>"><?= $size ?></a>
            <?php endforeach; ?>
        </span>
    </nav>
    <?php
};
?>
<?php if ($view === 'sales'): ?>
            <label>Group by
                <select name="group">
                    <?php foreach (jw_sales_group_options() as $k => $v): ?>
                        <option value="<?= e($k) ?>" <?= $groupBy === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php endif; ?>
        <?php if ($view === 'orders'): ?>
            <label>Status
                <select name="status">
                    <option value="">— all —</option>
                    <?php foreach (['draft' => 'Draft', 'confirmed' => 'Confirmed', 'assigned' => 'With kaligad',
                        'partially_received' => 'Partially received', 'received' => 'Received',
                        'invoiced' => 'Invoiced', 'delivered' => 'Delivered', 'closed' => 'Closed',
                        'cancelled' => 'Cancelled'] as $k => $v): ?>
                        <option value="<?= e($k) ?>" <?= $orderStatusFilter === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php endif; ?>
        <?php if ($view === 'workshop'): ?>
            <label>Show
                <select name="pending">
                    <option value="">Everything issued</option>
                    <option value="1" <?= $pendingOnly ? 'selected' : '' ?>>Only metal still out</option>
                </select>
            </label>
            <label>Group by
                <select name="wgroup">
                    <?php foreach (['none' => 'Each issue', 'karigar' => 'Kaligad-wise', 'purity' => 'Purity-wise'] as $k => $v): ?>
                        <option value="<?= e($k) ?>" <?= $workshopGroup === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php endif; ?>
        <?php if ($view === 'karigar' || $view === 'statement' || $view === 'workshop'): ?>
            <label>Kaligad
                <select name="karigar">
                    <option value="0">— <?= $view === 'workshop' ? 'all' : 'choose' ?> —</option>
                    <?php foreach ($karigars as $k): ?>
                        <option value="<?= (int) $k['id'] ?>" <?= $karigarId === (int) $k['id'] ? 'selected' : '' ?>><?= e($k['code'] . ' — ' . $k['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php endif; ?>
        <?php if ($view === 'statement'): ?>
            <label>Value metal at (per fine <?= e((string) ($baseUnitCode ?? 'unit')) ?>)
                <input type="number" name="fine_rate" step="0.0001" min="0" placeholder="rate board"
                       value="<?= $statementRate > 0 ? e((string) $statementRate) : '' ?>">
            </label>
        <?php endif; ?>
        <button type="submit" class="jw-report-apply"><?= icon('search') ?> Apply</button>
        <?php if ($canExport && in_array($view, ['summary', 'sales', 'purchases', 'inventory', 'vat', 'bills', 'karigar', 'statement',
            'uncollected', 'orders', 'workshop', 'advreg', 'profit'], true)): ?>
            <?php
                // Their own class rather than .button.soft, whose colours five
                // stylesheets disagree about. Each says what it does in words as
                // well as a glyph — an icon-only button is a guess.
            ?>
            <span class="jw-report-exports">
                <a class="jw-export" href="<?= e($exportUrl($view, 'csv')) ?>" aria-label="Export CSV" title="Export CSV"><?= icon('download') ?></a>
                <a class="jw-export" href="<?= e($exportUrl($view, 'xlsx')) ?>" aria-label="Export Excel" title="Export Excel"><?= icon('analytics') ?></a>
                <a class="jw-export" target="_blank" rel="noopener" href="<?= e($exportUrl($view, 'print')) ?>" aria-label="Export PDF" title="Export PDF"><?= icon('documents') ?></a>
            </span>
        <?php endif; ?>
    </form>
</section>

<?php if ($view === 'summary'): ?>
    <?php
        $consolidated = jw_report_consolidated($companyId, $from, $to);
        // One formatter for both the screen and the file. The row says what kind
        // of number it is; nothing here has to remember which column was money.
        $cellValue = static function (array $row) use ($sym, $fmt): string {
            return match ((string) $row['kind']) {
                'money' => $sym . $fmt((float) $row['value']),
                'weight' => $fmt((float) $row['value'], 4),
                'count' => $fmt((float) $row['value'], (float) $row['value'] == (int) $row['value'] ? 0 : 3),
                'percent' => $row['value'] === null ? 'N/A' : $fmt((float) $row['value']) . '%',
                default => (string) $row['value'],
            };
        };
    ?>
    <?php // ONE REPORT, NOT FIVE BOXES. Five cards side by side made the reader
          // do the joining: nothing lined up, the eye had to jump between
          // columns, and it printed as five islands on a page. A single table
          // reads top to bottom in one column, foots each section as it goes,
          // and comes out of the PDF looking like the statement it is.
          //
          // Each section keeps its own heading row carrying THE DATE IT IS TRUE
          // ON, because two bases live on this page — stock and advances rolled
          // back to a date, receivables as they stand — and a reader who cannot
          // see which is which will average them in their head. ?>
    <section class="mbw-card" style="margin-top:14px">
        <div class="mbw-card-head">
            <h2><?= icon('analytics') ?>Consolidated Summary</h2>
            <div class="mbw-card-tools">
                <span style="opacity:.7;font-size:12.5px;margin-right:10px"><?= e(app_date($from) . ' to ' . app_date($to)) ?></span>
                <?php if ($canExport): ?>
                    <?php // The same three formats every other report offers, on the
                          // report itself as well as up in the filter bar — this is
                          // the page somebody prints for a partner or a bank. ?>
                    <a class="jw-export" href="<?= e($exportUrl('summary', 'csv')) ?>" aria-label="Export CSV" title="Export CSV"><?= icon('download') ?></a>
                    <a class="jw-export" href="<?= e($exportUrl('summary', 'xlsx')) ?>" aria-label="Export Excel" title="Export Excel"><?= icon('analytics') ?></a>
                    <a class="jw-export" target="_blank" rel="noopener" href="<?= e($exportUrl('summary', 'print')) ?>" aria-label="Export PDF" title="Export PDF"><?= icon('documents') ?></a>
                <?php endif; ?>
            </div>
        </div>
        <div class="mbw-tablewrap"><table>
            <thead><tr><th>Figure</th><th class="is-numeric">Value</th></tr></thead>
            <tbody>
                <?php foreach ($consolidated['sections'] as $section): ?>
                    <tr class="jw-summary-section">
                        <th colspan="2" scope="rowgroup" style="text-align:left;padding-top:14px">
                            <?= icon($section['icon']) ?><?= e($section['title']) ?>
                            <span style="opacity:.65;font-weight:400;font-size:12.5px">— <?= e($section['note']) ?></span>
                        </th>
                    </tr>
                    <?php foreach ($section['rows'] as $row): ?>
                        <tr>
                            <td><?= $row['strong'] ? '<strong>' . e((string) $row['label']) . '</strong>' : e((string) $row['label']) ?></td>
                            <td class="is-numeric"><?= $row['strong'] ? '<strong>' . e($cellValue($row)) . '</strong>' : e($cellValue($row)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>

<?php elseif ($view === 'sales'): ?>
    <?php
    $report = jw_report_sales_detail($companyId, $from, $to);
    $bif = jw_report_sales_bifurcated($companyId, $from, $to, $groupBy);
    $bifTotals = $bif['totals'];
    // The money columns, in the order the total is built up. Kept in one list
    // so the heading, the rows and the foot cannot drift apart.
    // The terms that MAKE UP the total, in the order it is built. Wastage is
    // not one of them -- the metal is priced on a weight that already includes
    // it -- so it is drawn beside metal as a memo and never added.
    $bifMoney = [
        ['metal_amount', 'Metal', true],
        ['wastage_amount', 'of which wastage', false],
        ['making_amount', 'Making', true],
        ['stone_side', 'Stone / diamond', true],
        ['allocated_adjust', 'Charges − Disc.', true],
        ['net_before_tax', 'Net before SPT / VAT', true],
        ['tax_amount', 'SPT', true],
        ['vat_amount', 'VAT', true],
    ];
    ?>
    <section class="mbw-card" data-collapsible style="margin-top:14px">
        <div class="mbw-card-head">
            <h2><?= e(jw_sales_group_options()[$groupBy]) ?> (<?= count($bif['rows']) ?>)</h2>
        </div>
        <p style="margin:0 0 10px;color:var(--mbw-muted);font-size:12.5px">
            What the bill total is made of, and it adds across:
            <strong>Metal + Making + Stone/diamond + (Other charges − Discount) = Net before SPT / VAT</strong>,
            then <strong>+ SPT + VAT = TOTAL</strong>.
            <strong>Net before SPT / VAT is the figure that reaches the profit and loss</strong> — SPT and VAT are
            collected for the government and never earned, which is also why gross profit is measured against the net
            rather than the total.
            Wastage is <em>not</em> a separate term: the metal is priced on a weight that already includes it, so the
            column shows how much of the metal amount it is, never an addition.
            Weight and purity sit beside the money as context — they are what the metal was measured in.
            Other charges and discount are entered per bill, so an item or category row carries the share that bill
            allocated to it.
        </p>
        <div style="overflow-x:auto"><table>
            <thead>
                <tr>
                    <th><?= e($bif['group_label']) ?></th>
                    <?php if ($bif['per_bill']): ?>
                        <th>Date</th><th>Customer (billed as)</th><th>Invoice ref.</th><th>Status</th>
                    <?php endif; ?>
                    <th class="is-numeric">Pieces</th>
                    <th class="is-numeric">Gross wt</th>
                    <th class="is-numeric">Fine wt</th>
                    <?php foreach ($bifMoney as [$key, $label, $adds]): ?>
                        <th class="is-numeric<?= $adds ? '' : ' jw-memo-col' ?>"><?= e($label) ?></th>
                    <?php endforeach; ?>
                    <th class="is-numeric">TOTAL</th>
                    <th class="is-numeric">COGS</th>
                    <th class="is-numeric">Gross profit</th>
                    <th class="is-numeric">GP %</th>
                </tr>
            </thead>
            <tbody>
                <?php $bifCols = 8 + count($bifMoney) + ($bif['per_bill'] ? 4 : 0); ?>
                <?php if ($bif['rows'] === []): ?>
                    <tr><td colspan="<?= $bifCols ?>">No posted sales in this period.</td></tr>
                <?php endif; ?>
                <?php foreach ($bif['rows'] as $g): ?>
                    <tr>
                        <td><?= e((string) $g['group']) ?></td>
                        <?php if ($bif['per_bill']): ?>
                            <td><?= e(app_date((string) $g['sale_date'])) ?></td>
                            <td><?= e((string) $g['bill_name']) ?></td>
                            <td><?= (string) $g['ref_no'] !== '' ? e((string) $g['ref_no']) : '—' ?></td>
                            <td><span class="mbw-pill tone-green"><?= e(ucfirst((string) $g['status'])) ?></span></td>
                        <?php endif; ?>
                        <td class="is-numeric"><?= $fmt((float) $g['pieces'], 3) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $g['gross_weight'], 4) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $g['fine_weight'], 4) ?></td>
                        <?php foreach ($bifMoney as [$key, $label, $adds]): ?>
                            <td class="is-numeric<?= $adds ? '' : ' jw-memo-col' ?>"><?= $fmt((float) $g[$key]) ?></td>
                        <?php endforeach; ?>
                        <td class="is-numeric"><strong><?= $fmt((float) $g['line_total']) ?></strong></td>
                        <td class="is-numeric"><?= $fmt((float) $g['cogs_amount']) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $g['gross_profit']) ?></td>
                        <td class="is-numeric"><?= $g['gp_pct'] === null ? '—' : $fmt((float) $g['gp_pct']) . '%' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <?php if ($bif['rows'] !== []): ?>
            <tfoot>
                <tr>
                    <th>TOTAL</th>
                    <?php if ($bif['per_bill']): ?><th></th><th></th><th></th><th></th><?php endif; ?>
                    <th class="is-numeric"><?= $fmt((float) $bifTotals['pieces'], 3) ?></th>
                    <th class="is-numeric"><?= $fmt((float) $bifTotals['gross_weight'], 4) ?></th>
                    <th class="is-numeric"><?= $fmt((float) $bifTotals['fine_weight'], 4) ?></th>
                    <?php foreach ($bifMoney as [$key, $label, $adds]): ?>
                        <th class="is-numeric<?= $adds ? '' : ' jw-memo-col' ?>"><?= $fmt((float) $bifTotals[$key]) ?></th>
                    <?php endforeach; ?>
                    <th class="is-numeric"><?= $fmt((float) $bifTotals['line_total']) ?></th>
                    <th class="is-numeric"><?= $fmt((float) $bifTotals['cogs_amount']) ?></th>
                    <th class="is-numeric"><?= $fmt((float) $bifTotals['gross_profit']) ?></th>
                    <th class="is-numeric"><?= $bifTotals['gp_pct'] === null ? '—' : $fmt((float) $bifTotals['gp_pct']) . '%' ?></th>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table></div>
    </section>

    <section class="mbw-card" data-collapsible style="margin-top:14px">
        <div class="mbw-card-head"><h2>Sales Detailed (<?= count($report['rows']) ?> lines)</h2></div>
        <div style="overflow-x:auto"><table>
            <thead><tr><th>Date</th><th>Sale</th><th>Customer</th><th>Item</th><th>Purity</th><th class="is-numeric">Gross</th><th class="is-numeric">Fine</th><th class="is-numeric">Rate</th><th class="is-numeric">Metal</th><th class="is-numeric">Making</th><th class="is-numeric">Stone / diamond</th><th class="is-numeric">VAT</th><th class="is-numeric">COGS</th><th class="is-numeric">GP</th></tr></thead>
            <tbody>
                <?php [$salesPageRows, $salesRptPage, $salesRptCount] = $reportSlice($report['rows']); ?>
                <?php if ($salesPageRows === []): ?><tr><td colspan="14">No posted sales in this period.</td></tr><?php endif; ?>
                <?php foreach ($salesPageRows as $r): ?>
                    <tr>
                        <td><?= e(app_date((string) $r['sale_date'])) ?></td>
                        <td><?= e($r['sale_no']) ?></td>
                        <td><?= e($r['party_label']) ?></td>
                        <td><?= e($r['item_code']) ?></td>
                        <td><?= e($r['purity_code']) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $r['gross_weight'], 4) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $r['fine_weight'], 4) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $r['rate']) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $r['metal_amount']) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $r['making_amount']) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $r['stone_side']) ?></td>
                        <td class="is-numeric"><?= (float) $r['vat_amount'] > 0 ? $fmt((float) $r['vat_amount']) . '<br><small>' . e(str_replace('_', ' ', (string) $r['vat_base'])) . '</small>' : '—' ?></td>
                        <td class="is-numeric"><?= $fmt((float) $r['cogs_amount']) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $r['gross_profit']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot><tr>
                <th colspan="6">Totals</th>
                <th class="is-numeric"><?= $fmt($report['totals']['fine_weight'], 4) ?></th>
                <th></th>
                <th class="is-numeric"><?= $fmt($report['totals']['metal_amount']) ?></th>
                <th class="is-numeric"><?= $fmt($report['totals']['making_amount']) ?></th>
                <th class="is-numeric"><?= $fmt($report['totals']['stone_side']) ?></th>
                <th class="is-numeric"><?= $fmt($report['totals']['vat_amount']) ?></th>
                <th class="is-numeric"><?= $fmt($report['totals']['cogs_amount']) ?></th>
                <th class="is-numeric"><?= $fmt($report['totals']['gross_profit']) ?></th>
            </tr></tfoot>
        </table>
        <?php $reportPager($salesRptPage, $salesRptCount, count($report['rows'])); ?></div>
    </section>

<?php elseif ($view === 'purchases'): ?>
    <?php $report = jw_report_purchase_detail($companyId, $from, $to); ?>
    <section class="mbw-card" data-collapsible style="margin-top:14px">
        <div class="mbw-card-head"><h2>Purchase Detailed (<?= count($report['rows']) ?> lines)</h2></div>
        <div style="overflow-x:auto"><table>
            <thead><tr><th>Date</th><th>Purchase</th><th>Party</th><th>Source</th><th>Item</th><th>Purity</th><th class="is-numeric">Gross</th><th class="is-numeric">Fine</th><th class="is-numeric">Rate</th><th class="is-numeric">Metal</th><th class="is-numeric">Making</th><th class="is-numeric">Stone / diamond</th><th class="is-numeric">VAT</th><th class="is-numeric">Landed cost</th></tr></thead>
            <tbody>
                <?php [$purchasePageRows, $purchaseRptPage, $purchaseRptCount] = $reportSlice($report['rows']); ?>
                <?php if ($purchasePageRows === []): ?><tr><td colspan="14">No posted purchases in this period.</td></tr><?php endif; ?>
                <?php foreach ($purchasePageRows as $r): ?>
                    <tr>
                        <td><?= e(app_date((string) $r['purchase_date'])) ?></td>
                        <td><?= e($r['purchase_no']) ?></td>
                        <td><?= e($r['party_label']) ?></td>
                        <td><?= (string) $r['source'] === 'customer_old_gold' ? '<span class="mbw-pill tone-amber">Old gold</span>' : 'Supplier' ?></td>
                        <td><?= e($r['item_code']) ?></td>
                        <td><?= e($r['purity_code']) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $r['gross_weight'], 4) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $r['fine_weight'], 4) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $r['rate']) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $r['metal_amount']) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $r['making_amount']) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $r['stone_side']) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $r['vat_amount']) ?></td>
                        <td class="is-numeric"><strong><?= $fmt((float) $r['stock_amount']) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot><tr>
                <th colspan="7">Totals</th>
                <th class="is-numeric"><?= $fmt($report['totals']['fine_weight'], 4) ?></th>
                <th></th>
                <th class="is-numeric"><?= $fmt($report['totals']['metal_amount']) ?></th>
                <th class="is-numeric"><?= $fmt($report['totals']['making_amount']) ?></th>
                <th class="is-numeric"><?= $fmt($report['totals']['stone_side']) ?></th>
                <th class="is-numeric"><?= $fmt($report['totals']['vat_amount']) ?></th>
                <th class="is-numeric"><?= $fmt($report['totals']['stock_amount']) ?></th>
            </tr></tfoot>
        </table>
        <?php $reportPager($purchaseRptPage, $purchaseRptCount, count($report['rows'])); ?></div>
    </section>

<?php elseif ($view === 'inventory'): ?>
    <?php $report = jw_report_inventory_detail($companyId, $from, $to); ?>
    <section class="mbw-card" data-collapsible style="margin-top:14px">
        <div class="mbw-card-head"><h2>Inventory Detailed (<?= count($report['rows']) ?> items)</h2></div>
        <div style="overflow-x:auto"><table>
            <thead><tr><th>Item</th><th>Metal / Purity</th><th class="is-numeric">Opening fine</th><th class="is-numeric">Opening value</th><th class="is-numeric">In fine</th><th class="is-numeric">Out fine</th><th class="is-numeric">Closing fine</th><th class="is-numeric">Closing value</th><th class="is-numeric">Own</th><th class="is-numeric">With others</th><th class="is-numeric">Avg cost/fine</th></tr></thead>
            <tbody>
                <?php [$invPageRows, $invRptPage, $invRptCount] = $reportSlice($report['rows']); ?>
                <?php if ($invPageRows === []): ?><tr><td colspan="11">No stock movement in this period.</td></tr><?php endif; ?>
                <?php foreach ($invPageRows as $r): ?>
                    <tr>
                        <td><?= e($r['code']) ?><br><small><?= e($r['name']) ?></small></td>
                        <td><?= e($r['metal_name'] . ' · ' . $r['purity_code']) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $r['opening_fine'], 4) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $r['opening_value']) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $r['in_fine'], 4) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $r['out_fine'], 4) ?></td>
                        <td class="is-numeric"><strong><?= $fmt((float) $r['closing_fine'], 4) ?></strong></td>
                        <td class="is-numeric"><strong><?= $fmt((float) $r['closing_value']) ?></strong></td>
                        <td class="is-numeric"><?= $fmt((float) $r['own_fine'], 4) ?></td>
                        <td class="is-numeric"><?= (float) $r['with_others_fine'] > 0 ? '<span class="mbw-pill tone-amber">' . $fmt((float) $r['with_others_fine'], 4) . '</span>' : '—' ?></td>
                        <td class="is-numeric"><?= $fmt((float) $r['avg_fine_rate']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot><tr>
                <th colspan="2">Totals</th>
                <th class="is-numeric"><?= $fmt($report['totals']['opening_fine'], 4) ?></th>
                <th class="is-numeric"><?= $fmt($report['totals']['opening_value']) ?></th>
                <th class="is-numeric"><?= $fmt($report['totals']['in_fine'], 4) ?></th>
                <th class="is-numeric"><?= $fmt($report['totals']['out_fine'], 4) ?></th>
                <th class="is-numeric"><?= $fmt($report['totals']['closing_fine'], 4) ?></th>
                <th class="is-numeric"><?= $fmt($report['totals']['closing_value']) ?></th>
                <th></th>
                <th class="is-numeric"><?= $fmt($report['totals']['with_others_fine'], 4) ?></th>
                <th></th>
            </tr></tfoot>
        </table>
        <?php $reportPager($invRptPage, $invRptCount, count($report['rows'])); ?></div>
    </section>

<?php elseif ($view === 'vat'): ?>
    <?php $register = jw_report_vat_register($companyId, $from, $to); ?>
    <section class="mbw-kpi-grid" style="margin-top:14px" aria-label="VAT summary">
        <?php foreach ([
            ['Taxable sales', $sym . $fmt($register['output']['taxable']), 'wallet', 'tone-blue'],
            ['Output VAT', $sym . $fmt($register['output']['vat']), 'receipt-voucher', 'tone-amber'],
            ['Taxable purchases', $sym . $fmt($register['input']['taxable']), 'box', 'tone-blue'],
            ['Input VAT', $sym . $fmt($register['input']['vat']), 'receipt-voucher', 'tone-teal'],
            ['Net VAT', $sym . $fmt($register['net_payable']), 'reconcile', $register['net_payable'] > 0 ? 'tone-red' : 'tone-green'],
        ] as [$kpiLabel, $kpiValue, $kpiIcon, $kpiTone]): ?>
            <article class="mbw-kpi"><div><span class="mbw-kpi-label"><?= e($kpiLabel) ?></span><div class="mbw-kpi-value" style="font-size:1.02rem"><?= e($kpiValue) ?></div></div><span class="mbw-chip <?= e($kpiTone) ?>"><?= icon($kpiIcon) ?></span></article>
        <?php endforeach; ?>
    </section>

    <section class="mbw-card" data-collapsible style="margin-top:14px">
        <div class="mbw-card-head"><h2>Every tax charged this period (<?= count($register['by_tax']) ?>)</h2></div>
        <div style="overflow-x:auto"><table>
            <thead><tr>
                <th>Tax</th><th class="is-numeric">Sales base</th><th class="is-numeric">Charged on sales</th>
                <th class="is-numeric">Purchase base</th><th class="is-numeric">Paid on purchases</th>
                <th class="is-numeric">Net payable</th>
            </tr></thead>
            <tbody>
                <?php if ($register['by_tax'] === []): ?>
                    <tr><td colspan="6">No tax was charged on a posted document in this period.</td></tr>
                <?php endif; ?>
                <?php foreach ($register['by_tax'] as $t): ?>
                    <tr>
                        <td><strong><?= e($t['tax_code']) ?></strong> — <?= e($t['tax_name']) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $t['output_base']) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $t['output_amount']) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $t['input_base']) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $t['input_amount']) ?></td>
                        <td class="is-numeric"><strong><?= $fmt((float) $t['net_payable']) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>

    <?php foreach ([['Output VAT (sales)', $register['output_rows'], $register['output']], ['Input VAT (purchases)', $register['input_rows'], $register['input']]] as [$title, $rows, $sums]): ?>
        <section class="mbw-card" data-collapsible style="margin-top:14px">
            <div class="mbw-card-head"><h2><?= e($title) ?> (<?= count($rows) ?>)</h2></div>
            <div style="overflow-x:auto"><table>
                <thead><tr><th>Date</th><th>Document</th><th>Party</th><th>PAN</th><th>Item</th><th>VAT base</th><th class="is-numeric">Taxable</th><th class="is-numeric">Rate</th><th class="is-numeric">VAT</th></tr></thead>
                <tbody>
                    <?php if ($rows === []): ?><tr><td colspan="9">Nothing in this period.</td></tr><?php endif; ?>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?= e(app_date((string) $r['doc_date'])) ?></td>
                            <td><?= e($r['doc_no']) ?></td>
                            <td><?= e($r['party_label']) ?></td>
                            <td><?= e((string) ($r['pan_no'] ?? '—')) ?></td>
                            <td><?= e($r['item_code']) ?></td>
                            <td><?= e(str_replace('_', ' ', (string) $r['vat_base'])) ?></td>
                            <td class="is-numeric"><?= $fmt((float) $r['taxable_amount']) ?></td>
                            <td class="is-numeric"><?= $fmt((float) $r['vat_rate']) ?>%</td>
                            <td class="is-numeric"><?= $fmt((float) $r['vat_amount']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot><tr><th colspan="6">Total</th><th class="is-numeric"><?= $fmt($sums['taxable']) ?></th><th></th><th class="is-numeric"><?= $fmt($sums['vat']) ?></th></tr></tfoot>
            </table></div>
        </section>
    <?php endforeach; ?>

<?php elseif ($view === 'karigar'): ?>
    <?php $wages = jw_report_karigar_wages($companyId, $from, $to); ?>
    <section class="mbw-card" data-collapsible style="margin-top:14px">
        <div class="mbw-card-head"><h2>Kaligad Wages &amp; Wastage (<?= count($wages) ?>)</h2></div>
        <div style="overflow-x:auto"><table>
            <thead><tr><th>Kaligad</th><th>Engagement</th><th class="is-numeric">Jobs</th><th class="is-numeric">Received fine</th><th class="is-numeric">Wastage fine</th><th class="is-numeric">Wastage %</th><th class="is-numeric">Excess fine</th><th class="is-numeric">Making</th><th class="is-numeric">Recovered</th><th class="is-numeric">Net payable</th></tr></thead>
            <tbody>
                <?php if ($wages === []): ?><tr><td colspan="10">No completed kaligad jobs in this period.</td></tr><?php endif; ?>
                <?php foreach ($wages as $w): ?>
                    <tr>
                        <td><?= e($w['code'] . ' — ' . $w['name']) ?></td>
                        <td><?= e(ucfirst((string) $w['engagement_type'])) ?></td>
                        <td class="is-numeric"><?= (int) $w['jobs'] ?></td>
                        <td class="is-numeric"><?= $fmt((float) $w['received_fine'], 4) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $w['wastage_fine'], 4) ?></td>
                        <td class="is-numeric"><?= $w['wastage_pct'] === null ? '—' : $fmt((float) $w['wastage_pct'], 3) . '%' ?></td>
                        <td class="is-numeric"><?= (float) $w['excess_fine'] > 0 ? '<span class="mbw-pill tone-red">' . $fmt((float) $w['excess_fine'], 4) . '</span>' : '—' ?></td>
                        <td class="is-numeric"><?= $fmt((float) $w['making_amount']) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $w['recovery_amount']) ?></td>
                        <td class="is-numeric"><strong><?= $fmt((float) $w['net_payable']) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>

    <?php if ($karigarId > 0): ?>
        <?php $ledger = jw_report_karigar_ledger($companyId, $karigarId, $from, $to); ?>
        <?php if ($ledger['karigar']): ?>
        <section class="mbw-card" data-collapsible style="margin-top:14px">
            <div class="mbw-card-head"><h2>Metal Ledger — <?= e((string) $ledger['karigar']['name']) ?></h2></div>
            <div style="overflow-x:auto"><table>
                <thead><tr><th>Date</th><th>Type</th><th>Ref</th><th>Item</th><th>Purity</th><th class="is-numeric">In (fine)</th><th class="is-numeric">Out (fine)</th><th class="is-numeric">Balance (fine)</th></tr></thead>
                <tbody>
                    <tr><td colspan="7"><strong>Opening</strong></td><td class="is-numeric"><strong><?= $fmt($ledger['opening_fine'], 4) ?></strong></td></tr>
                    <?php foreach ($ledger['rows'] as $r): ?>
                        <tr>
                            <td><?= e(app_date((string) $r['txn_date'])) ?></td>
                            <td><?= e(jw_stock_txn_types()[$r['txn_type']] ?? $r['txn_type']) ?></td>
                            <td><?= e((string) ($r['ref_no'] ?? '')) ?></td>
                            <td><?= e($r['item_code']) ?></td>
                            <td><?= e($r['purity_code']) ?></td>
                            <td class="is-numeric"><?= (string) $r['direction'] === 'in' ? $fmt((float) $r['fine_weight'], 4) : '' ?></td>
                            <td class="is-numeric"><?= (string) $r['direction'] === 'out' ? $fmt((float) $r['fine_weight'], 4) : '' ?></td>
                            <td class="is-numeric"><?= $fmt((float) $r['balance_fine'], 4) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr><td colspan="7"><strong>Closing</strong></td><td class="is-numeric"><strong><?= $fmt($ledger['closing_fine'], 4) ?></strong></td></tr>
                </tbody>
            </table></div>
            <?php if ($ledger['bills'] !== []): ?>
                <div class="mbw-card-head" style="margin-top:16px"><h2>Wage Bills</h2></div>
                <div style="overflow-x:auto"><table>
                    <thead><tr><th>Bill</th><th>Date</th><th class="is-numeric">Amount</th><th class="is-numeric">Settled</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($ledger['bills'] as $b): ?>
                            <tr>
                                <td><?= e($b['bill_no']) ?></td>
                                <td><?= e(app_date((string) $b['bill_date'])) ?></td>
                                <td class="is-numeric"><?= $fmt((float) $b['bill_amount']) ?></td>
                                <td class="is-numeric"><?= $fmt((float) $b['settled_amount']) ?></td>
                                <td><span class="mbw-pill <?= (string) $b['status'] === 'settled' ? 'tone-green' : 'tone-amber' ?>"><?= e(str_replace('_', ' ', (string) $b['status'])) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>
        </section>
        <?php endif; ?>
    <?php else: ?>
        <div class="notice" style="margin-top:14px">Choose a kaligad above to see their metal ledger and wage bills.</div>
    <?php endif; ?>

<?php elseif ($view === 'statement'): ?>
    <?php if (!$statement || !$statement['karigar']): ?>
        <section class="mbw-card"></section>
    <?php else: ?>
    <?php
        $st = $statement;
        $metal = $st['metal'];
        $money = $st['money'];
        $settle = $st['settlement'];
        $unitCode = (string) (($st['base_unit'] ?? [])['code'] ?? '');
    ?>
    <section class="mbw-card" data-collapsible>
        <div class="mbw-card-head">
            <h2><?= e((string) $st['karigar']['name']) ?> — statement</h2>
        </div>

        <?php foreach ($st['mismatch'] as $mismatchNote): ?>
            <div class="mbw-note tone-amber" style="margin:0 0 10px"><?= e($mismatchNote) ?></div>
        <?php endforeach; ?>

        <div class="mbw-stat-row" style="margin-bottom:14px">
            <div class="mbw-stat"><span>Metal still with them</span><strong><?= $fmt((float) $metal['closing_fine'], 4) ?> fine <?= e($unitCode) ?></strong></div>
            <div class="mbw-stat"><span>Valued at this rate</span><strong><?= e($sym) ?> <?= $fmt((float) $settle['metal_receivable_value'] - (float) $settle['metal_payable_value']) ?></strong></div>
            <div class="mbw-stat"><span>Carried in the books at</span><strong><?= e($sym) ?> <?= $fmt((float) $settle['carrying_value']) ?></strong></div>
            <div class="mbw-stat"><span>Revaluation gap</span><strong><?= e($sym) ?> <?= $fmt((float) $settle['revaluation']) ?></strong></div>
            <div class="mbw-stat"><span>Wages owed</span><strong><?= e($sym) ?> <?= $fmt((float) $settle['wages_payable']) ?></strong></div>
            <div class="mbw-stat <?= (float) $settle['net_payable'] >= 0 ? '' : 'tone-amber' ?>">
                <span><?= (float) $settle['net_payable'] >= 0 ? 'Net to pay them' : 'Net they owe you' ?></span>
                <strong><?= e($sym) ?> <?= $fmt(abs((float) $settle['net_payable'])) ?></strong>
            </div>
        </div>

        <?php if ($canPost): ?>
        <form method="post" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:12px">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="revalue_karigar_metal">
            <input type="hidden" name="karigar_id" value="<?= (int) $st['karigar']['id'] ?>">
            <input type="hidden" name="as_of" value="<?= e($to) ?>">
            <input type="hidden" name="fine_rate" value="<?= e((string) $st['rate']['fine_rate']) ?>">
            <button type="submit" class="button secondary" <?= abs((float) $settle['revaluation']) < 0.005 ? 'disabled' : '' ?>>
                Post this revaluation
            </button>
        </form>
        <?php endif; ?>

        <div class="workspace-split" style="display:grid;gap:14px;grid-template-columns:repeat(auto-fit,minmax(420px,1fr))">
            <div>
                <h3 style="margin:0 0 8px">Metal</h3>
                <div style="overflow-x:auto"><table>
                    <thead><tr><th>Date</th><th>Ref</th><th>Item</th><th class="is-numeric">In</th><th class="is-numeric">Out</th><th class="is-numeric">Balance</th><th class="is-numeric">At this rate</th></tr></thead>
                    <tbody>
                        <tr><td colspan="5"><em>Opening</em></td>
                            <td class="is-numeric"><?= $fmt((float) $metal['opening_fine'], 4) ?></td>
                            <td class="is-numeric"><?= $fmt((float) $metal['opening_fine'] * (float) $st['rate']['fine_rate']) ?></td></tr>
                        <?php foreach ($metal['rows'] as $row): ?>
                            <?php $isIn = (string) $row['direction'] === 'in'; ?>
                            <tr>
                                <td><?= e(app_date((string) $row['txn_date'])) ?></td>
                                <td><?= e((string) $row['ref_no']) ?></td>
                                <td><?= e((string) $row['item_code']) ?> <small><?= e((string) $row['purity_code']) ?></small></td>
                                <td class="is-numeric"><?= $isIn ? $fmt((float) $row['base_fine_weight'], 4) : '' ?></td>
                                <td class="is-numeric"><?= $isIn ? '' : $fmt((float) $row['base_fine_weight'], 4) ?></td>
                                <td class="is-numeric"><?= $fmt((float) $row['balance_fine'], 4) ?></td>
                                <td class="is-numeric"><?= $fmt((float) $row['valued_amount']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($metal['rows'] === []): ?>
                            <tr><td colspan="7" class="frm-optional">No metal moved in this period.</td></tr>
                        <?php endif; ?>
                    </tbody>
                    <tfoot><tr>
                        <th colspan="3">Closing</th>
                        <th class="is-numeric"><?= $fmt((float) $metal['in_fine'], 4) ?></th>
                        <th class="is-numeric"><?= $fmt((float) $metal['out_fine'], 4) ?></th>
                        <th class="is-numeric"><?= $fmt((float) $metal['closing_fine'], 4) ?></th>
                        <th class="is-numeric"><?= $fmt((float) $metal['closing_fine'] * (float) $st['rate']['fine_rate']) ?></th>
                    </tr></tfoot>
                </table></div>
            </div>

            <div>
                <h3 style="margin:0 0 8px">Money</h3>
                <div style="overflow-x:auto"><table>
                    <thead><tr><th>Date</th><th>Ref</th><th>Particulars</th><th class="is-numeric">Amount</th><th class="is-numeric">Balance</th></tr></thead>
                    <tbody>
                        <tr><td colspan="4"><em>Opening</em></td><td class="is-numeric"><?= $fmt((float) $money['opening']) ?></td></tr>
                        <?php foreach ($money['rows'] as $row): ?>
                            <tr>
                                <td><?= e(app_date((string) $row['date'])) ?></td>
                                <td><?= e((string) $row['ref']) ?></td>
                                <td><?= e((string) $row['particulars']) ?></td>
                                <td class="is-numeric"><?= $fmt((float) $row['amount']) ?></td>
                                <td class="is-numeric"><?= $fmt((float) $row['balance']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($money['rows'] === []): ?>
                            <tr><td colspan="5" class="frm-optional">No wages billed and nothing paid in this period.</td></tr>
                        <?php endif; ?>
                    </tbody>
                    <tfoot><tr>
                        <th colspan="3">Closing</th>
                        <th class="is-numeric"><?= $fmt((float) $money['billed'] - (float) $money['paid']) ?></th>
                        <th class="is-numeric"><?= $fmt((float) $money['closing']) ?></th>
                    </tr></tfoot>
                </table></div>
            </div>
        </div>
    </section>
    <?php endif; ?>

<?php elseif ($view === 'bills'): ?>
    <?php $billTotals = jewellery_open_bill_totals($companyId); $billTotal = (int) $billTotals['bill_count'];
        $outstanding = jw_report_bill_outstanding($companyId, '', $billPageSize, ($billPage - 1) * $billPageSize); ?>
    <section class="mbw-card" data-collapsible style="margin-top:14px">
        <div class="mbw-card-head"><h2>Bill-wise Outstanding (<?= count($outstanding) ?> parties)</h2></div>
        <div style="overflow-x:auto"><table>
            <thead><tr><th>Party</th><th>Bill</th><th>Type</th><th>Date</th><th class="is-numeric">Billed</th><th class="is-numeric">Settled</th><th class="is-numeric">Outstanding</th><th>Status</th></tr></thead>
            <tbody>
                <?php if ($outstanding === []): ?><tr><td colspan="8">Every bill is settled.</td></tr><?php endif; ?>
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
                <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php $billPages=max(1,(int)ceil($billTotal/$billPageSize)); if($billPages>1):?><nav class="actions" style="margin-top:12px" aria-label="Outstanding bill pages"><?php if($billPage>1):?><a class="button secondary" href="<?=e(url('admin/jewellery-reports.php?view=bills&from='.$from.'&to='.$to.'&bill_page='.($billPage-1)))?>">Previous</a><?php endif;?><span>Page <?=e((string)$billPage)?> of <?=e((string)$billPages)?> · <?=e((string)$billTotal)?> bills</span><?php if($billPage<$billPages):?><a class="button secondary" href="<?=e(url('admin/jewellery-reports.php?view=bills&from='.$from.'&to='.$to.'&bill_page='.($billPage+1)))?>">Next</a><?php endif;?></nav><?php endif;?>
    </section>

<?php elseif ($view === 'uncollected'): ?>
    <?php
        // Orders past their promised date that nobody has come in for. The piece
        // is made, the gold is in the safe, and it is the customer's — the shop
        // is insuring metal it cannot sell and holding an advance it cannot use.
        $uncollected = jewellery_overdue_orders($companyId, $to);
    ?>
    <section class="mbw-kpi-grid" style="margin-top:14px" aria-label="Uncollected orders summary">
        <?php foreach ([
            ['Orders waiting', (string) $uncollected['totals']['orders'], 'journal', 'tone-amber'],
            ['Value held', $sym . $fmt($uncollected['totals']['value']), 'wallet', 'tone-blue'],
            ['Advance taken', $sym . $fmt($uncollected['totals']['advance']), 'receipt-voucher', 'tone-teal'],
            ['Still to collect', $sym . $fmt($uncollected['totals']['balance']), 'reconcile',
                $uncollected['totals']['balance'] > 0 ? 'tone-red' : 'tone-green'],
        ] as [$kpiLabel, $kpiValue, $kpiIcon, $kpiTone]): ?>
            <article class="mbw-kpi"><div><span class="mbw-kpi-label"><?= e($kpiLabel) ?></span><div class="mbw-kpi-value" style="font-size:1.02rem"><?= e($kpiValue) ?></div></div><span class="mbw-chip <?= e($kpiTone) ?>"><?= icon($kpiIcon) ?></span></article>
        <?php endforeach; ?>
    </section>

    <section class="mbw-card" data-collapsible style="margin-top:14px">
        <div class="mbw-card-head">
            <h2>Promised on or before <?= e(app_date($uncollected['as_of'])) ?>, not collected (<?= count($uncollected['rows']) ?>)</h2>
            <a class="mbw-view-all" href="<?= e($exportUrl('uncollected')) ?>" aria-label="Export CSV" title="Export CSV"><?= icon('download') ?></a>
        </div>
        <div style="overflow-x:auto"><table>
            <thead><tr>
                <th>Order</th><th>Customer</th><th>Phone</th><th>Ordered</th><th>Promised</th>
                <th class="is-numeric">Days late</th><th class="is-numeric">Weight</th>
                <th class="is-numeric">Value</th><th class="is-numeric">Advance</th>
                <th class="is-numeric">Still to collect</th><th>Status</th>
            </tr></thead>
            <tbody>
                <?php if ($uncollected['rows'] === []): ?>
                    <tr><td colspan="11">Nothing is waiting to be collected.</td></tr>
                <?php endif; ?>
                <?php foreach ($uncollected['rows'] as $r): ?>
                    <?php $late = (int) $r['days_late']; ?>
                    <tr>
                        <td><a href="<?= e(url('admin/jewellery-workshop.php?view=orders&edit=' . (int) $r['id'])) ?>"><?= e($r['order_no']) ?></a></td>
                        <td><?= e($r['party_label']) ?></td>
                        <td><?= e($r['phone'] !== '' ? $r['phone'] : '—') ?></td>
                        <td><?= e(app_date((string) $r['order_date'])) ?></td>
                        <td><?= e(app_date((string) $r['delivery_date'])) ?></td>
                        <td class="is-numeric">
                            <span class="mbw-pill <?= $late >= 30 ? 'tone-red' : ($late >= 7 ? 'tone-amber' : 'tone-gray') ?>"><?= $late ?></span>
                        </td>
                        <td class="is-numeric"><?= $fmt((float) $r['expected_gross_weight'], 4) ?> <small><?= e($r['unit_code']) ?></small></td>
                        <td class="is-numeric"><?= $fmt((float) $r['total_amount']) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $r['advance_amount']) ?></td>
                        <td class="is-numeric"><strong><?= $fmt((float) $r['balance_due']) ?></strong></td>
                        <td><span class="mbw-pill tone-gray"><?= e(ucwords(str_replace('_', ' ', (string) $r['status']))) ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot><tr>
                <th colspan="7">Total</th>
                <th class="is-numeric"><?= $fmt($uncollected['totals']['value']) ?></th>
                <th class="is-numeric"><?= $fmt($uncollected['totals']['advance']) ?></th>
                <th class="is-numeric"><?= $fmt($uncollected['totals']['balance']) ?></th>
                <th></th>
            </tr></tfoot>
        </table></div>
    </section>

<?php elseif ($view === 'orders'): ?>
    <?php $report = jw_report_order_status($companyId, $from, $to, ['status' => $orderStatusFilter]); ?>
    <section class="mbw-card" data-collapsible style="margin-top:14px">
        <div class="mbw-card-head"><h2>Order Status (<?= count($report['rows']) ?> orders<?=
            $orderStatusFilter !== '' ? ', ' . e(str_replace('_', ' ', $orderStatusFilter)) . ' only' : '' ?>)</h2></div>
        <?php if ($report['totals']['by_status'] !== []): ?>
            <p style="margin:0 0 10px;color:var(--mbw-muted,#64748b)">
                <?php foreach ($report['totals']['by_status'] as $bsKey => $bsCount): ?>
                    <span class="mbw-pill tone-gray" style="margin-right:6px"><?= e(ucwords(str_replace('_', ' ', (string) $bsKey))) ?>: <?= (int) $bsCount ?></span>
                <?php endforeach; ?>
            </p>
        <?php endif; ?>
        <div style="overflow-x:auto"><table>
            <thead><tr>
                <th>Order</th><th>Date</th><th>Customer</th><th class="is-numeric">Items</th><th>Purity</th>
                <th class="is-numeric">Expected wt</th><th class="is-numeric">Quoted</th>
                <th class="is-numeric">Advance held</th><th class="is-numeric">Applied</th>
                <th>Bill</th><th class="is-numeric">Balance due</th><th>Promised</th><th>Status</th>
            </tr></thead>
            <tbody>
                <?php if ($report['rows'] === []): ?><tr><td colspan="13">No orders in this period.</td></tr><?php endif; ?>
                <?php foreach ($report['rows'] as $r): ?>
                    <tr>
                        <td><a href="<?= e(url('admin/jewellery-workshop.php?view=orders&edit=' . (int) $r['id'])) ?>"><?= e($r['order_no']) ?></a></td>
                        <td><?= e(app_date((string) $r['order_date'])) ?></td>
                        <td><?= e($r['party_label']) ?></td>
                        <td class="is-numeric"><?= (int) $r['item_count'] ?: 1 ?></td>
                        <td><?= e($r['purity_code']) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $r['expected_gross_weight'], 4) ?> <small><?= e($r['unit_code']) ?></small>
                            <br><small><?= $fmt((float) $r['expected_fine_weight'], 4) ?> fine</small></td>
                        <td class="is-numeric"><?= $fmt((float) $r['total_amount']) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $r['advance_held']) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $r['advance_applied']) ?><?php
                            if ((float) $r['advance_unapplied'] > 0.005): ?><br><small class="tone-amber"><?= $fmt((float) $r['advance_unapplied']) ?> unapplied</small><?php endif; ?></td>
                        <td><?= $r['sale_no'] !== null ? e((string) $r['sale_no']) : '—' ?></td>
                        <td class="is-numeric"><?= $r['balance_amount'] !== null ? $fmt((float) $r['balance_amount']) : '—' ?></td>
                        <td><?= ($r['delivery_date'] ?? null) ? e(app_date((string) $r['delivery_date'])) : '—' ?></td>
                        <td><span class="mbw-pill tone-gray"><?= e(ucwords(str_replace('_', ' ', (string) $r['status']))) ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot><tr>
                <th colspan="5">Total</th>
                <th class="is-numeric"><?= $fmt($report['totals']['expected_fine'], 4) ?> fine</th>
                <th class="is-numeric"><?= $fmt($report['totals']['total_amount']) ?></th>
                <th class="is-numeric"><?= $fmt($report['totals']['advance_held']) ?></th>
                <th class="is-numeric"><?= $fmt($report['totals']['advance_applied']) ?></th>
                <th colspan="4"></th>
            </tr></tfoot>
        </table></div>
    </section>

<?php elseif ($view === 'workshop'): ?>
    <?php $report = jw_report_workshop($companyId, $from, $to,
        ['karigar_id' => $karigarId, 'pending_only' => $pendingOnly]); ?>
    <section class="mbw-card" data-collapsible style="margin-top:14px">
        <div class="mbw-card-head"><h2><?= $pendingOnly ? 'Gold Pending Return' : 'Gold Issued to Kaligad' ?>
            (<?= count($report['rows']) ?> issues)</h2></div>
        <?php if ($workshopGroup !== 'none'): ?>
            <?php $groups = $workshopGroup === 'karigar' ? $report['by_karigar'] : $report['by_purity']; ?>
            <div style="overflow-x:auto"><table>
                <thead><tr>
                    <th><?= $workshopGroup === 'karigar' ? 'Kaligad' : 'Purity' ?></th>
                    <th class="is-numeric">Issues</th><th class="is-numeric">Issued fine</th>
                    <th class="is-numeric">Received fine</th><th class="is-numeric">Still out</th>
                    <th class="is-numeric">Wastage fine</th><th class="is-numeric">Wages</th>
                </tr></thead>
                <tbody>
                    <?php if ($groups === []): ?><tr><td colspan="7">Nothing issued in this period.</td></tr><?php endif; ?>
                    <?php foreach ($groups as $g): ?>
                        <tr>
                            <td><?= e($g['label']) ?></td>
                            <td class="is-numeric"><?= (int) $g['issues'] ?></td>
                            <td class="is-numeric"><?= $fmt(jw_round_weight($g['issued_fine']), 4) ?></td>
                            <td class="is-numeric"><?= $fmt(jw_round_weight($g['received_fine']), 4) ?></td>
                            <td class="is-numeric"><strong><?= $fmt(jw_round_weight($g['pending_fine']), 4) ?></strong></td>
                            <td class="is-numeric"><?= $fmt(jw_round_weight($g['wastage_fine']), 4) ?></td>
                            <td class="is-numeric"><?= $fmt(jw_round_money($g['making_amount'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php else: ?>
            <div style="overflow-x:auto"><table>
                <thead><tr>
                    <th>Issue</th><th>Date</th><th>Kaligad</th><th>Order</th><th>Item</th><th>Purity</th>
                    <th class="is-numeric">Issued</th><th class="is-numeric">Received back</th>
                    <th class="is-numeric">Still out (fine)</th><th class="is-numeric">Wastage</th>
                    <th class="is-numeric">Wages</th><th>Status</th>
                </tr></thead>
                <tbody>
                    <?php if ($report['rows'] === []): ?><tr><td colspan="12">Nothing issued in this period.</td></tr><?php endif; ?>
                    <?php foreach ($report['rows'] as $r): ?>
                        <tr>
                            <td><?= e($r['issue_no']) ?></td>
                            <td><?= e(app_date((string) $r['issue_date'])) ?></td>
                            <td><?= e($r['karigar_code']) ?> <small><?= e($r['karigar_name']) ?></small></td>
                            <td><?= $r['order_no'] !== null ? e((string) $r['order_no']) : '—' ?></td>
                            <td><?= e($r['item_code']) ?></td>
                            <td><?= e($r['purity_code']) ?></td>
                            <td class="is-numeric"><?= $fmt((float) $r['issued_gross_weight'], 4) ?> <small><?= e($r['unit_code']) ?></small>
                                <br><small><?= $fmt((float) $r['issued_fine_weight'], 4) ?> fine</small></td>
                            <td class="is-numeric"><?php if ($r['receipt_no'] !== null): ?>
                                <?= $fmt((float) $r['received_gross_weight'], 4) ?>
                                <br><small><?= $fmt((float) $r['received_fine_weight'], 4) ?> fine</small>
                            <?php else: ?>—<?php endif; ?></td>
                            <td class="is-numeric"><?= (float) $r['pending_fine'] > 0 ? '<strong>' . $fmt((float) $r['pending_fine'], 4) . '</strong>' : '—' ?></td>
                            <td class="is-numeric"><?= $r['wastage_fine_weight'] !== null ? $fmt((float) $r['wastage_fine_weight'], 4) : '—' ?></td>
                            <td class="is-numeric"><?= $r['making_amount'] !== null ? $fmt((float) $r['making_amount']) : '—' ?></td>
                            <td><span class="mbw-pill <?= (string) $r['status'] === 'issued' ? 'tone-amber' : 'tone-green' ?>"><?= e(ucfirst((string) $r['status'])) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot><tr>
                    <th colspan="6">Total</th>
                    <th class="is-numeric"><?= $fmt($report['totals']['issued_fine'], 4) ?> fine</th>
                    <th class="is-numeric"><?= $fmt($report['totals']['received_fine'], 4) ?> fine</th>
                    <th class="is-numeric"><?= $fmt($report['totals']['pending_fine'], 4) ?></th>
                    <th class="is-numeric"><?= $fmt($report['totals']['wastage_fine'], 4) ?></th>
                    <th class="is-numeric"><?= $fmt($report['totals']['making_amount']) ?></th>
                    <th></th>
                </tr></tfoot>
            </table></div>
        <?php endif; ?>
    </section>

<?php elseif ($view === 'advreg'): ?>
    <?php $report = jw_report_advance_register($companyId, $from, $to); ?>
    <section class="mbw-card" data-collapsible style="margin-top:14px">
        <div class="mbw-card-head"><h2>Advance Register (<?= count($report['rows']) ?> entries)</h2></div>
        <div style="overflow-x:auto"><table>
            <thead><tr>
                <th>Entry</th><th>Date</th><th>Customer</th><th>Order</th><th>Direction</th><th>How paid</th>
                <th class="is-numeric">Amount</th><th class="is-numeric">Weight</th>
                <th class="is-numeric">Funded bills</th><th class="is-numeric">Still held</th>
            </tr></thead>
            <tbody>
                <?php if ($report['rows'] === []): ?><tr><td colspan="10">No advances in this period.</td></tr><?php endif; ?>
                <?php foreach ($report['rows'] as $r): ?>
                    <?php $isRefund = (string) $r['direction'] === 'paid'; ?>
                    <tr>
                        <td><?= e($r['settlement_no']) ?></td>
                        <td><?= e(app_date((string) $r['settlement_date'])) ?></td>
                        <td><?= e($r['party_label']) ?></td>
                        <td><?= $r['order_no'] !== null ? e((string) $r['order_no']) : '—' ?></td>
                        <td><span class="mbw-pill <?= $isRefund ? 'tone-red' : 'tone-green' ?>"><?= $isRefund ? 'Refunded' : 'Received' ?></span></td>
                        <td><?= e(jw_tender_mode_label((string) $r['mode'])) ?><?php if ((string) $r['mode'] === 'metal'): ?>
                            <small><?= e((string) ($r['item_code'] ?? '')) ?> <?= e((string) ($r['purity_code'] ?? '')) ?></small><?php endif; ?></td>
                        <td class="is-numeric"><?= $fmt((float) $r['amount']) ?></td>
                        <td class="is-numeric"><?= (float) $r['gross_weight'] > 0
                            ? $fmt((float) $r['gross_weight'], 4) . ' <small>' . e((string) ($r['unit_code'] ?? '')) . '</small><br><small>'
                                . $fmt((float) $r['fine_weight'], 4) . ' fine</small>'
                            : '—' ?></td>
                        <td class="is-numeric"><?= $isRefund ? '—' : $fmt((float) $r['allocated']) ?></td>
                        <td class="is-numeric"><?= $isRefund ? '—' : '<strong>' . $fmt((float) $r['remaining']) . '</strong>' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot><tr>
                <th colspan="6">Received <?= $fmt($report['totals']['received']) ?> · Refunded <?= $fmt($report['totals']['refunded']) ?></th>
                <th colspan="2"></th>
                <th class="is-numeric"><?= $fmt($report['totals']['allocated']) ?></th>
                <th class="is-numeric"><?= $fmt($report['totals']['remaining']) ?></th>
            </tr></tfoot>
        </table></div>
    </section>
    <section class="mbw-card" data-collapsible style="margin-top:14px">
        <div class="mbw-card-head"><h2>Advance Adjustment Register (<?= count($report['adjustments']) ?> applications)</h2></div>
        <p style="margin:0 0 10px;color:var(--mbw-muted,#64748b)">
            Each row is one decision made at billing: this bill took this much from that advance entry.
            Nothing here is ever deleted — a reversed bill releases the money, and the entry shows it held again.
        </p>
        <div style="overflow-x:auto"><table>
            <thead><tr>
                <th>Advance entry</th><th>Applied to bill</th><th>Bill date</th><th>Customer</th>
                <th class="is-numeric">Amount</th><th>Bill status</th>
            </tr></thead>
            <tbody>
                <?php if ($report['adjustments'] === []): ?><tr><td colspan="6">No advances have been applied in this period.</td></tr><?php endif; ?>
                <?php foreach ($report['adjustments'] as $a): ?>
                    <tr>
                        <td><?= e($a['settlement_no']) ?></td>
                        <td><?= e($a['sale_no']) ?></td>
                        <td><?= e(app_date((string) $a['sale_date'])) ?></td>
                        <td><?= e($a['party_label']) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $a['amount']) ?></td>
                        <td><span class="mbw-pill <?= (string) $a['sale_status'] === 'posted' ? 'tone-green' : 'tone-gray' ?>"><?= e(ucfirst((string) $a['sale_status'])) ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>

<?php elseif ($view === 'profit'): ?>
    <?php $report = jw_report_order_profitability($companyId, $from, $to); ?>
    <section class="mbw-card" data-collapsible style="margin-top:14px">
        <div class="mbw-card-head"><h2>Order Profitability (<?= count($report['rows']) ?> delivered orders)</h2></div>
        <p style="margin:0 0 10px;color:var(--mbw-muted,#64748b)">
            Revenue and cost of metal come from the bill; kaligad wages and the wastage the shop bore come
            from the workshop receipts of the same order. The making charge was meant to cover the wages —
            here the two sit side by side.
        </p>
        <div style="overflow-x:auto"><table>
            <thead><tr>
                <th>Order</th><th>Customer</th><th>Bill</th><th>Billed on</th>
                <th class="is-numeric">Revenue</th><th class="is-numeric">COGS</th>
                <th class="is-numeric">Kaligad wages</th><th class="is-numeric">Wastage borne</th>
                <th class="is-numeric">Order profit</th><th class="is-numeric">Margin %</th>
            </tr></thead>
            <tbody>
                <?php if ($report['rows'] === []): ?><tr><td colspan="10">No orders were billed in this period.</td></tr><?php endif; ?>
                <?php foreach ($report['rows'] as $r): ?>
                    <tr>
                        <td><a href="<?= e(url('admin/jewellery-workshop.php?view=orders&edit=' . (int) $r['id'])) ?>"><?= e($r['order_no']) ?></a></td>
                        <td><?= e($r['party_label']) ?></td>
                        <td><?= e($r['sale_no']) ?></td>
                        <td><?= e(app_date((string) $r['sale_date'])) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $r['revenue']) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $r['cogs']) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $r['karigar_wages']) ?></td>
                        <td class="is-numeric"><?= $fmt((float) $r['wastage_borne']) ?></td>
                        <td class="is-numeric"><strong><?= $fmt((float) $r['profit']) ?></strong></td>
                        <td class="is-numeric"><?= $r['margin_pct'] !== null ? $fmt((float) $r['margin_pct']) : 'N/A' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot><tr>
                <th colspan="4">Total</th>
                <th class="is-numeric"><?= $fmt($report['totals']['revenue']) ?></th>
                <th class="is-numeric"><?= $fmt($report['totals']['cogs']) ?></th>
                <th class="is-numeric"><?= $fmt($report['totals']['karigar_wages']) ?></th>
                <th class="is-numeric"><?= $fmt($report['totals']['wastage_borne']) ?></th>
                <th class="is-numeric"><?= $fmt($report['totals']['profit']) ?></th>
                <th class="is-numeric"><?= $report['totals']['margin_pct'] !== null ? $fmt((float) $report['totals']['margin_pct']) : 'N/A' ?></th>
            </tr></tfoot>
        </table></div>
    </section>
<?php endif; ?>

<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
