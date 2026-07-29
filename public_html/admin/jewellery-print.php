<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/accounting_module_repair.php';
require_once __DIR__ . '/../../app/jewellery_reports.php';
require_once __DIR__ . '/../../app/export_engine.php';

/**
 * ONE preview / print / export controller for every jewellery document.
 *
 * A purchase, a sale, an order, a kaligad receipt and a settlement are all the
 * same shape once you stop looking at the table they came from: a header, some
 * lines, some totals. Giving each its own print page would be five templates
 * drifting apart, so they all build the same array of rows and hand it to the
 * shared export layer — which then renders it as a print view, a spreadsheet or
 * a CSV from the one description.
 *
 * Read-only. This file never writes.
 */
accounting_module_repair_database();
require_jewellery();

$company = current_company();
if (!$company) {
    flash('error', 'Company context required.');
    redirect('admin/accounting-dashboard.php');
}
$companyId = (int) $company['id'];
$sym = site_currency_symbol();

$docType = jw_enum($_GET['doc'] ?? null, ['sale', 'purchase', 'settlement', 'order', 'receipt'], 'sale');
$docId = (int) ($_GET['id'] ?? 0);
$format = jw_enum($_GET['format'] ?? null, ['print', 'pdf', 'csv', 'xlsx'], 'print');

// Exporting a document is reading it; the module's own view right is enough,
// but a spreadsheet leaves the building so it takes the export right.
if (in_array($format, ['csv', 'xlsx'], true)) {
    require_permission('jewellery', 'export');
}

$money = static fn ($n): string => number_format((float) $n, 2);
$weight = static fn ($n): string => number_format((float) $n, 4);

$rows = [];
$meta = [];
$title = '';
$basename = 'jewellery-document';

if ($docType === 'sale' || $docType === 'purchase') {
    $isSale = $docType === 'sale';
    $doc = $isSale ? jewellery_sale($companyId, $docId) : jewellery_purchase($companyId, $docId);
    if (!$doc) {
        flash('error', 'That document does not belong to this company.');
        redirect('admin/jewellery-trade.php');
    }
    $lines = $isSale ? jewellery_sale_line_rows($companyId, $docId) : jewellery_purchase_line_rows($companyId, $docId);
    $no = (string) ($isSale ? $doc['sale_no'] : $doc['purchase_no']);
    $title = ($isSale ? 'Sale ' : 'Purchase ') . $no;
    $basename = ($isSale ? 'sale-' : 'purchase-') . $no;

    $meta = [
        'Number' => $no,
        'Date' => app_date((string) ($isSale ? $doc['sale_date'] : $doc['purchase_date'])),
        'Party' => (string) ($doc['party_name'] ?? $doc['customer_name'] ?? ''),
        'Status' => ucfirst((string) $doc['status']),
    ];
    if ((string) ($doc['ref_no'] ?? '') !== '') {
        $meta['Reference'] = (string) $doc['ref_no'];
    }

    $rows[] = ['Item', 'Purity', 'Unit', 'Pieces', 'Gross wt', 'Stone wt', 'Net wt', 'Fine wt',
        'Rate', 'Metal', 'Wastage', 'Making', 'Stone', 'Tax', 'VAT', 'Line total'];
    // The line query already joins the item, so no per-line lookup.
    foreach ($lines as $line) {
        $rows[] = [
            (string) ($line['item_code'] ?? '') . ' — ' . (string) ($line['item_name'] ?? ''),
            (string) ($line['purity_code'] ?? ''), (string) ($line['unit_code'] ?? ''),
            $line['qty_pieces'], $weight($line['gross_weight']), $weight($line['stone_weight'] ?? 0),
            $weight($line['net_weight'] ?? $line['gross_weight']), $weight($line['fine_weight']),
            $money($line['rate']), $money($line['metal_amount']), $money($line['wastage_amount'] ?? 0),
            $money($line['making_amount']), $money($line['stone_amount']),
            $money($line['tax_amount'] ?? 0), $money($line['vat_amount']), $money($line['line_total']),
        ];
    }

    // Totals as their own labelled rows, so the same array prints and exports.
    // The wastage is INSIDE the metal figure — the total weight is priced as
    // one number — so its row says so, or the stack reads as if
    // Metal + Wastage + Making should add to the total when they must not.
    $totalRows = [
        ['Metal (wastage included)', $doc['metal_amount']],
        ['of which wastage', $doc['wastage_amount'] ?? 0],
        ['Making', $doc['making_amount']],
        ['Stone', $doc['stone_amount']],
        ['Other charges', $doc['other_charges']],
        ['Discount', -(float) $doc['discount']],
        ['Tax', $doc['tax_amount'] ?? 0],
        ['VAT', $doc['vat_amount']],
        ['TOTAL', $doc['total_amount']],
    ];
    if ($isSale) {
        $totalRows[] = ['Cash / bank received', $doc['received_amount']];
        $totalRows[] = ['Old gold in exchange', $doc['exchange_amount']];
        $totalRows[] = ['Advance applied', $doc['advance_amount'] ?? 0];
        $totalRows[] = ['Balance', $doc['balance_amount']];
    } else {
        $totalRows[] = ['Paid', $doc['paid_amount']];
        $totalRows[] = ['Balance', $doc['balance_amount']];
    }
    foreach ($totalRows as [$label, $value]) {
        if (abs((float) $value) < 0.005 && $label !== 'TOTAL') {
            continue;
        }
        $rows[] = ['', '', '', '', '', '', '', '', '', '', '', '', '', '', $label, $money($value)];
    }
}

if ($docType === 'settlement') {
    $doc = jewellery_settlement($companyId, $docId);
    if (!$doc) {
        flash('error', 'That settlement does not belong to this company.');
        redirect('admin/jewellery-trade.php?view=bills');
    }
    $title = 'Settlement ' . (string) $doc['settlement_no'];
    $basename = 'settlement-' . (string) $doc['settlement_no'];
    $meta = [
        'Number' => (string) $doc['settlement_no'],
        'Date' => app_date((string) $doc['settlement_date']),
        'Party' => (string) ($doc['party_name'] ?? ''),
        'Direction' => (string) $doc['direction'] === 'paid' ? 'Paid out' : 'Received',
        'Mode' => (string) $doc['mode'],
        'Status' => ucfirst((string) $doc['status']),
    ];
    if ((int) ($doc['is_advance'] ?? 0) === 1) {
        $meta['Kind'] = 'Advance against an order';
    }

    $rows[] = ['Bill', 'Bill date', 'Billed', 'Allocated'];
    $allocated = 0.0;
    foreach (jewellery_settlement_allocations($companyId, $docId) as $alloc) {
        $allocated += (float) $alloc['amount'];
        $rows[] = [(string) $alloc['bill_no'], app_date((string) $alloc['bill_date']),
            $money($alloc['bill_amount']), $money($alloc['amount'])];
    }
    if ($allocated < (float) $doc['amount'] - 0.005) {
        $rows[] = ['(unallocated — on account)', '', '', $money((float) $doc['amount'] - $allocated)];
    }
    if ((string) $doc['mode'] === 'metal') {
        $rows[] = ['Metal handed over', '', $weight($doc['gross_weight']) . ' gross', $weight($doc['fine_weight']) . ' fine'];
    }
    $rows[] = ['', '', 'TOTAL', $money($doc['amount'])];
}

if ($docType === 'order') {
    $doc = jewellery_order($companyId, $docId);
    if (!$doc) {
        flash('error', 'That order does not belong to this company.');
        redirect('admin/jewellery-workshop.php');
    }
    $title = 'Order ' . (string) $doc['order_no'];
    $basename = 'order-' . (string) $doc['order_no'];
    $meta = [
        'Number' => (string) $doc['order_no'],
        'Ordered' => app_date((string) $doc['order_date']),
        'Customer' => (string) ($doc['party_name'] ?? $doc['customer_name'] ?? ''),
        'Status' => ucwords(str_replace('_', ' ', (string) $doc['status'])),
    ];
    if ((string) ($doc['delivery_date'] ?? '') !== '') {
        $meta['Promised'] = app_date((string) $doc['delivery_date']);
    }
    if ((string) ($doc['customer_phone'] ?? '') !== '') {
        $meta['Phone'] = (string) $doc['customer_phone'];
    }

    if ((string) ($doc['design_no'] ?? '') !== '') {
        $meta['Design no'] = (string) $doc['design_no'];
    }

    // The order is multi-item now, and the paper has to say what the screen
    // says: every piece with its own weights, stones, kaligad and money —
    // the customer's copy of what was agreed, item by item.
    $orderLines = jewellery_order_line_rows($companyId, $docId);
    if ($orderLines !== []) {
        $rows[] = ['Item', 'Purity', 'Unit', 'Pieces', 'Gross wt', 'Stone wt', 'Net wt', 'Fine wt',
            'Rate', 'Metal', 'Making', 'Stone / diamond', 'VAT', 'Line total', 'Kaligad', 'Promised'];
        foreach ($orderLines as $line) {
            $rows[] = [
                (string) ($line['item_code'] ?? '') . ' — ' . (string) ($line['item_name'] ?? ''),
                (string) ($line['purity_code'] ?? ''), (string) ($line['unit_code'] ?? ''),
                $line['qty_pieces'], $weight($line['gross_weight']), $weight($line['stone_weight'] ?? 0),
                $weight($line['net_weight'] ?? $line['gross_weight']), $weight($line['fine_weight']),
                $money($line['rate']), $money($line['metal_amount']), $money($line['making_amount']),
                $money((float) ($line['stone_amount'] ?? 0) + (float) ($line['diamond_amount'] ?? 0)
                    + (float) ($line['other_diamond_amount'] ?? 0)),
                $money($line['vat_amount'] ?? 0), $money($line['line_total']),
                (string) ($line['karigar_code'] ?? ''),
                ($line['delivery_date'] ?? null) ? app_date((string) $line['delivery_date']) : '',
            ];
        }
        // The quote, as its own labelled rows in the last two columns the way
        // the sale print does it — one array serves paper and spreadsheet.
        $quoteRows = [
            ['Metal', $doc['metal_amount'] ?? 0],
            ['Making', $doc['making_amount'] ?? 0],
            ['Stone / diamond', (float) ($doc['stone_amount'] ?? 0) + (float) ($doc['diamond_amount'] ?? 0)],
            ['Other charges', $doc['other_charges'] ?? 0],
            ['Discount', -(float) ($doc['discount'] ?? 0)],
            ['Skills Promotion Tax', $doc['tax_amount'] ?? 0],
            ['VAT', $doc['vat_amount'] ?? 0],
            ['QUOTED TOTAL', $doc['total_amount'] ?? 0],
        ];
        foreach ($quoteRows as [$label, $value]) {
            if (abs((float) $value) < 0.005 && $label !== 'QUOTED TOTAL') {
                continue;
            }
            $rows[] = ['', '', '', '', '', '', '', '', '', '', '', '', '', '', $label, $money($value)];
        }
        $advanceCols = static fn (string $label, float $value): array =>
            ['', '', '', '', '', '', '', '', '', '', '', '', '', '', $label, number_format($value, 2)];
    } else {
        // An order taken before orders had line items — print what the header
        // knows, the way this page always did for it.
        $rows[] = ['What', 'Detail'];
        $rows[] = ['Item', (string) ($doc['item_name'] ?? $doc['description'] ?? '')];
        $rows[] = ['Purity', (string) ($doc['purity_code'] ?? '')];
        $rows[] = ['Expected weight', $weight($doc['expected_gross_weight']) . ' ' . (string) ($doc['unit_code'] ?? '')];
        $rows[] = ['Making basis', (string) $doc['making_basis'] . ' @ ' . $money($doc['making_rate'])];
        $advanceCols = static fn (string $label, float $value): array => [$label, number_format($value, 2)];
    }

    $advances = jewellery_order_advances($companyId, $docId);
    foreach ($advances['rows'] as $adv) {
        $rows[] = $advanceCols(((string) $adv['direction'] === 'paid' ? 'Advance refunded' : 'Advance received')
            . ' (' . jw_tender_mode_label((string) $adv['mode']) . ') ' . (string) $adv['settlement_no'],
            (float) $adv['amount']);
    }
    $rows[] = $advanceCols('Advance held', (float) $advances['total']);
    $rows[] = $advanceCols('Advance still unapplied', jewellery_order_advance_available($companyId, $docId));
}

if ($docType === 'receipt') {
    $stmt = db()->prepare('SELECT r.*, a.issue_no, a.issued_gross_weight, a.issued_amount,
            k.name AS karigar_name, k.code AS karigar_code, o.order_no,
            i.sku AS item_code, i.name AS item_name, u.code AS unit_code
        FROM jewellery_order_receipts r
        INNER JOIN jewellery_order_assignments a ON a.id = r.assignment_id
        INNER JOIN jewellery_karigars k ON k.id = a.karigar_id
        LEFT JOIN jewellery_orders o ON o.id = a.order_id
        INNER JOIN inventory_items i ON i.id = r.received_item_id
        INNER JOIN jewellery_units u ON u.id = r.unit_id
        WHERE r.id = :id AND r.company_id = :cid LIMIT 1');
    $stmt->execute(['id' => $docId, 'cid' => $companyId]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$doc) {
        flash('error', 'That receipt does not belong to this company.');
        redirect('admin/jewellery-workshop.php?view=assignments');
    }
    $title = 'Kaligad Receipt ' . (string) $doc['receipt_no'];
    $basename = 'receipt-' . (string) $doc['receipt_no'];
    $meta = [
        'Number' => (string) $doc['receipt_no'],
        'Date' => app_date((string) $doc['receive_date']),
        'Kaligad' => (string) $doc['karigar_code'] . ' — ' . (string) $doc['karigar_name'],
        'Against issue' => (string) $doc['issue_no'],
    ];
    if ((string) ($doc['order_no'] ?? '') !== '') {
        $meta['Order'] = (string) $doc['order_no'];
    }

    $unit = (string) $doc['unit_code'];
    $rows[] = ['What', 'Weight / Amount'];
    $rows[] = ['Issued gross', $weight($doc['issued_gross_weight']) . ' ' . $unit];
    $rows[] = ['Received gross', $weight($doc['received_gross_weight']) . ' ' . $unit];
    $rows[] = ['Received fine', $weight($doc['received_fine_weight']) . ' ' . $unit];
    $rows[] = ['Wastage', $weight($doc['wastage_fine_weight']) . ' fine'];
    $rows[] = ['Wastage allowed', $weight($doc['wastage_allowed_fine']) . ' fine'];
    $rows[] = ['Excess wastage', $weight($doc['excess_wastage_fine']) . ' fine'];
    $rows[] = ['Wastage value', $money($doc['wastage_amount'])];
    $rows[] = ['Recovered from wages', $money($doc['recovery_amount'])];
    $rows[] = ['Making charge', $money($doc['making_amount'])];
    $rows[] = ['NET PAYABLE', $money($doc['net_payable'])];
}

if ($rows === []) {
    flash('error', 'Nothing to preview for that document.');
    redirect('admin/jewellery.php');
}

$meta['Currency'] = $sym;
export_dispatch($format, $basename, $rows, $title, $meta);
