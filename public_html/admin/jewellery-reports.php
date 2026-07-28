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

$allowedViews = ['summary', 'sales', 'purchases', 'inventory', 'vat', 'karigar', 'statement', 'bills', 'uncollected'];
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
$groupBy = jw_enum($_GET['group'] ?? null, ['item', 'category', 'metal', 'party', 'day'], 'item');
$karigarId = (int) ($_GET['karigar'] ?? 0);
$karigars = jewellery_karigars_list($companyId);
// The rate the statement values metal at. Blank falls back to the rate board on
// the closing date, and then to the metal's own carrying value.
$statementRate = (float) ($_GET['fine_rate'] ?? 0);
$statement = null;
if ($view === 'statement' && $karigarId > 0) {
    $statement = jw_report_karigar_statement($companyId, $karigarId, $from, $to, ['fine_rate' => $statementRate]);
}

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
    if ($view === 'sales') {
        $data = [['Date', 'Sale no', 'Party', 'Item', 'Purity', 'Pieces', 'Gross wt', 'Fine wt', 'Rate',
            'Metal', 'Making', 'Stone / diamond', 'VAT base', 'VAT', 'Revenue', 'COGS', 'Gross profit', 'GP %']];
        foreach (jw_report_sales_detail($companyId, $from, $to)['rows'] as $r) {
            $data[] = [$r['sale_date'], $r['sale_no'], $r['party_label'], $r['item_code'], $r['purity_code'],
                $r['qty_pieces'], $r['gross_weight'], $r['fine_weight'], $r['rate'], $r['metal_amount'],
                $r['making_amount'], $r['stone_side'], $r['vat_base'], $r['vat_amount'], $r['revenue'],
                $r['cogs_amount'], $r['gross_profit'], $r['gp_pct']];
        }
        export_dispatch($format, 'jewellery-sales-' . $stamp, $data, 'Sales Detailed', $meta);
    }
    if ($view === 'purchases') {
        $data = [['Date', 'Purchase no', 'Party', 'Source', 'Item', 'Purity', 'Pieces', 'Gross wt', 'Fine wt',
            'Rate', 'Metal', 'Making', 'Stone / diamond', 'VAT', 'Landed cost']];
        foreach (jw_report_purchase_detail($companyId, $from, $to)['rows'] as $r) {
            $data[] = [$r['purchase_date'], $r['purchase_no'], $r['party_label'], $r['source'], $r['item_code'],
                $r['purity_code'], $r['qty_pieces'], $r['gross_weight'], $r['fine_weight'], $r['rate'],
                $r['metal_amount'], $r['making_amount'], $r['stone_side'], $r['vat_amount'], $r['stock_amount']];
        }
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
        export_dispatch($format, 'jewellery-uncollected-' . $stamp, $data, 'Uncollected Orders', $meta);
    }
    if ($view === 'bills') {
        $data = [['Party', 'Bill no', 'Type', 'Date', 'Billed', 'Settled', 'Outstanding', 'Status']];
        foreach (jw_report_bill_outstanding($companyId) as $party) {
            foreach ($party['bills'] as $bill) {
                $data[] = [$party['party_name'], $bill['bill_no'], $bill['bill_type'], $bill['bill_date'],
                    $bill['bill_amount'], $bill['settled_amount'], $bill['outstanding'], $bill['status']];
            }
        }
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
        export_dispatch($format, 'kaligad-ledger-' . $stamp, $data, 'Kaligad Ledger', $meta);
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
    . '&from=' . $from . '&to=' . $to . '&karigar=' . $karigarId . '&fine_rate=' . $statementRate . '&export=' . $format);
?>

<nav class="mbw-tabbar" aria-label="Jewellery report sections" style="flex-wrap:wrap">
    <a class="mbw-tab" href="<?= e(url('admin/jewellery.php')) ?>"><?= icon('dashboard') ?> Jewellery Home</a>
    <?php foreach ([
        'summary' => 'Summary', 'sales' => 'Sales Detailed', 'purchases' => 'Purchase Detailed',
        'inventory' => 'Inventory Detailed', 'vat' => 'VAT Register', 'karigar' => 'Kaligad Ledger',
        'statement' => 'Kaligad Statement',
        'bills' => 'Bill-wise', 'uncollected' => 'Uncollected Orders',
    ] as $tabView => $tabLabel): ?>
        <a class="mbw-tab <?= $view === $tabView ? 'is-active' : '' ?>" href="<?= e(url('admin/jewellery-reports.php?view=' . $tabView . '&from=' . $from . '&to=' . $to)) ?>"><?= e($tabLabel) ?></a>
    <?php endforeach; ?>
</nav>

<section class="mbw-card">
    <form method="get" class="jw-report-filter">
        <input type="hidden" name="view" value="<?= e($view) ?>">
        <label>From<input type="date" name="from" value="<?= e($from) ?>" min="<?= e($fyStart) ?>" max="<?= e($fyEnd) ?>"></label>
        <label>To<input type="date" name="to" value="<?= e($to) ?>" min="<?= e($fyStart) ?>" max="<?= e($fyEnd) ?>"></label>
        <?php if ($view === 'sales'): ?>
            <label>Group by
                <select name="group">
                    <?php foreach (['item' => 'Item', 'category' => 'Category', 'metal' => 'Metal', 'party' => 'Party', 'day' => 'Day'] as $k => $v): ?>
                        <option value="<?= e($k) ?>" <?= $groupBy === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php endif; ?>
        <?php if ($view === 'karigar' || $view === 'statement'): ?>
            <label>Kaligad
                <select name="karigar">
                    <option value="0">— choose —</option>
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
        <?php if ($canExport && in_array($view, ['sales', 'purchases', 'inventory', 'vat', 'bills', 'karigar', 'statement'], true)): ?>
            <?php
                // Their own class rather than .button.soft, whose colours five
                // stylesheets disagree about. Each says what it does in words as
                // well as a glyph — an icon-only button is a guess.
            ?>
            <span class="jw-report-exports">
                <a class="jw-export" href="<?= e($exportUrl($view, 'csv')) ?>"><?= icon('documents') ?><span>CSV</span></a>
                <a class="jw-export" href="<?= e($exportUrl($view, 'xlsx')) ?>"><?= icon('analytics') ?><span>Excel</span></a>
                <a class="jw-export" target="_blank" rel="noopener" href="<?= e($exportUrl($view, 'print')) ?>"><?= icon('printer') ?><span>PDF / Print</span></a>
            </span>
        <?php endif; ?>
    </form>
</section>

<?php if ($view === 'summary'): ?>
    <?php $s = jw_report_summary($companyId, $from, $to); ?>
    <section class="mbw-kpi-grid" style="margin-top:14px" aria-label="Jewellery summary">
        <?php foreach ([
            ['Sales revenue', $sym . $fmt($s['sales_revenue']), 'wallet', 'tone-blue'],
            ['Cost of goods sold', $sym . $fmt($s['sales_cogs']), 'box', 'tone-amber'],
            ['Gross profit', $sym . $fmt($s['gross_profit']), 'analytics', 'tone-green'],
            ['Gross profit %', $s['gp_pct'] === null ? 'N/A' : $fmt($s['gp_pct']) . '%', 'pie', 'tone-green'],
            ['Fine weight sold', $fmt($s['sales_fine'], 4), 'scale', 'tone-teal'],
            ['Purchases (landed)', $sym . $fmt($s['purchase_value']), 'box', 'tone-blue'],
            ['Fine weight bought', $fmt($s['purchase_fine'], 4), 'scale', 'tone-teal'],
            ['Output VAT', $sym . $fmt($s['vat_output']), 'receipt-voucher', 'tone-gray'],
            ['Input VAT', $sym . $fmt($s['vat_input']), 'receipt-voucher', 'tone-gray'],
            ['Net VAT', $sym . $fmt($s['vat_net']), 'reconcile', $s['vat_net'] > 0 ? 'tone-red' : 'tone-green'],
            ['Fine in own stock', $fmt($s['own_fine'], 4), 'scale', 'tone-green'],
            ['Fine with others', $fmt($s['out_fine'], 4), 'handshake', $s['out_fine'] > 0 ? 'tone-amber' : 'tone-gray'],
            ['Stock value', $sym . $fmt($s['stock_value']), 'wallet', 'tone-blue'],
            ['Receivable', $sym . $fmt($s['receivable']), 'card', 'tone-blue'],
            ['Payable', $sym . $fmt($s['payable']), 'card', 'tone-amber'],
            ['Open orders', (string) $s['open_orders'], 'journal', 'tone-teal'],
            ['Awaiting collection', (string) $s['pending_delivery'], 'box', $s['pending_delivery'] > 0 ? 'tone-amber' : 'tone-gray'],
        ] as [$kpiLabel, $kpiValue, $kpiIcon, $kpiTone]): ?>
            <article class="mbw-kpi"><div><span class="mbw-kpi-label"><?= e($kpiLabel) ?></span><div class="mbw-kpi-value" style="font-size:1.02rem"><?= e($kpiValue) ?></div></div><span class="mbw-chip <?= e($kpiTone) ?>"><?= icon($kpiIcon) ?></span></article>
        <?php endforeach; ?>
    </section>

<?php elseif ($view === 'sales'): ?>
    <?php $report = jw_report_sales_detail($companyId, $from, $to); $groups = jw_report_sales_grouped($companyId, $from, $to, $groupBy); ?>
    <section class="mbw-card" data-collapsible style="margin-top:14px">
        <div class="mbw-card-head"><h2>Sales by <?= e(ucfirst($groupBy)) ?> (<?= count($groups) ?>)</h2></div>
        <div style="overflow-x:auto"><table>
            <thead><tr><th><?= e(ucfirst($groupBy)) ?></th><th class="is-numeric">Pieces</th><th class="is-numeric">Fine wt</th><th class="is-numeric">Revenue</th><th class="is-numeric">VAT</th><th class="is-numeric">COGS</th><th class="is-numeric">Gross profit</th><th class="is-numeric">GP %</th></tr></thead>
            <tbody>
                <?php if ($groups === []): ?><tr><td colspan="8">No posted sales in this period.</td></tr><?php endif; ?>
                <?php foreach ($groups as $g): ?>
                    <tr>
                        <td><?= e($g['group']) ?></td>
                        <td class="is-numeric"><?= $fmt($g['pieces'], 3) ?></td>
                        <td class="is-numeric"><?= $fmt($g['fine_weight'], 4) ?></td>
                        <td class="is-numeric"><?= $fmt($g['revenue']) ?></td>
                        <td class="is-numeric"><?= $fmt($g['vat_amount']) ?></td>
                        <td class="is-numeric"><?= $fmt($g['cogs_amount']) ?></td>
                        <td class="is-numeric"><?= $fmt($g['gross_profit']) ?></td>
                        <td class="is-numeric"><?= $g['gp_pct'] === null ? '—' : $fmt($g['gp_pct']) . '%' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>

    <section class="mbw-card" data-collapsible style="margin-top:14px">
        <div class="mbw-card-head"><h2>Sales Detailed (<?= count($report['rows']) ?> lines)</h2></div>
        <div style="overflow-x:auto"><table>
            <thead><tr><th>Date</th><th>Sale</th><th>Customer</th><th>Item</th><th>Purity</th><th class="is-numeric">Gross</th><th class="is-numeric">Fine</th><th class="is-numeric">Rate</th><th class="is-numeric">Metal</th><th class="is-numeric">Making</th><th class="is-numeric">Stone / diamond</th><th class="is-numeric">VAT</th><th class="is-numeric">COGS</th><th class="is-numeric">GP</th></tr></thead>
            <tbody>
                <?php if ($report['rows'] === []): ?><tr><td colspan="14">No posted sales in this period.</td></tr><?php endif; ?>
                <?php foreach ($report['rows'] as $r): ?>
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
        </table></div>
    </section>

<?php elseif ($view === 'purchases'): ?>
    <?php $report = jw_report_purchase_detail($companyId, $from, $to); ?>
    <section class="mbw-card" data-collapsible style="margin-top:14px">
        <div class="mbw-card-head"><h2>Purchase Detailed (<?= count($report['rows']) ?> lines)</h2></div>
        <div style="overflow-x:auto"><table>
            <thead><tr><th>Date</th><th>Purchase</th><th>Party</th><th>Source</th><th>Item</th><th>Purity</th><th class="is-numeric">Gross</th><th class="is-numeric">Fine</th><th class="is-numeric">Rate</th><th class="is-numeric">Metal</th><th class="is-numeric">Making</th><th class="is-numeric">Stone / diamond</th><th class="is-numeric">VAT</th><th class="is-numeric">Landed cost</th></tr></thead>
            <tbody>
                <?php if ($report['rows'] === []): ?><tr><td colspan="14">No posted purchases in this period.</td></tr><?php endif; ?>
                <?php foreach ($report['rows'] as $r): ?>
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
        </table></div>
    </section>

<?php elseif ($view === 'inventory'): ?>
    <?php $report = jw_report_inventory_detail($companyId, $from, $to); ?>
    <section class="mbw-card" data-collapsible style="margin-top:14px">
        <div class="mbw-card-head"><h2>Inventory Detailed (<?= count($report['rows']) ?> items)</h2></div>
        <div style="overflow-x:auto"><table>
            <thead><tr><th>Item</th><th>Metal / Purity</th><th class="is-numeric">Opening fine</th><th class="is-numeric">Opening value</th><th class="is-numeric">In fine</th><th class="is-numeric">Out fine</th><th class="is-numeric">Closing fine</th><th class="is-numeric">Closing value</th><th class="is-numeric">Own</th><th class="is-numeric">With others</th><th class="is-numeric">Avg cost/fine</th></tr></thead>
            <tbody>
                <?php if ($report['rows'] === []): ?><tr><td colspan="11">No stock movement in this period.</td></tr><?php endif; ?>
                <?php foreach ($report['rows'] as $r): ?>
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
        </table></div>
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
    <?php $outstanding = jw_report_bill_outstanding($companyId); ?>
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
            <a class="mbw-view-all" href="<?= e($exportUrl('uncollected')) ?>">Export CSV</a>
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
<?php endif; ?>

<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
