<?php
declare(strict_types=1);

/**
 * Every movement of one jewellery item, and what it leaves behind.
 *
 * "It is still showing in stock after I sold it" is a question about two
 * separate records that are supposed to agree: the STOCK REGISTER
 * (jewellery_stock_txns — weights and cost) and the STOCK LEDGER (the GL
 * account those costs are posted to). A piece can look wrong in three
 * different ways, and they need different fixes:
 *
 *   weight left over    the sale relieved a different item, or a smaller
 *                       weight than the receipt put in
 *   value left over     the sale relieved cost at the item's weighted-average
 *                       rate, and that average is not what this piece cost —
 *                       normal when other stock of the same item exists,
 *                       wrong when it is the only one
 *   register vs ledger  the two disagree, which is never right
 *
 * Read-only. Nothing here writes.
 *
 *   php database/trace_jewellery_item.php CH22
 *   php database/trace_jewellery_item.php 218
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/jewellery_reports.php';

$needle = '';
foreach (array_slice($argv ?? [], 1) as $arg) {
    if (strpos($arg, '--') !== 0) {
        $needle = $arg;
    }
}
if ($needle === '') {
    exit("Usage: php database/trace_jewellery_item.php <item code or id>\n");
}

$fmtMoney = static fn (float $n): string => number_format($n, 2);
$fmtWeight = static fn (float $n): string => number_format($n, 4);

$stmt = db()->prepare("SELECT i.id, i.sku, i.name, i.company_id, c.name AS company_name, c.code AS company_code,
        u.code AS unit_code
    FROM inventory_items i
    INNER JOIN companies c ON c.id = i.company_id
    LEFT JOIN jewellery_item_profiles jp ON jp.inventory_item_id = i.id
    LEFT JOIN jewellery_units u ON u.id = jp.unit_id
    WHERE i.sku = :needle OR i.id = :idneedle
    ORDER BY i.id ASC");
$stmt->execute(['needle' => $needle, 'idneedle' => (int) $needle]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($items === []) {
    exit("No item matches '$needle'.\n");
}

foreach ($items as $item) {
    $itemId = (int) $item['id'];
    $cid = (int) $item['company_id'];
    $unit = (string) ($item['unit_code'] ?? '');

    echo "\n=== " . $item['sku'] . ' — ' . $item['name'] . '  (' . $item['company_name']
        . ', ' . $item['company_code'] . ")\n\n";

    $rows = db()->prepare('SELECT * FROM jewellery_stock_txns WHERE company_id = :cid AND item_id = :iid
        ORDER BY txn_date ASC, id ASC');
    $rows->execute(['cid' => $cid, 'iid' => $itemId]);
    $movements = $rows->fetchAll(PDO::FETCH_ASSOC);

    if ($movements === []) {
        echo "  No movements at all — this item has never been stocked.\n";
        continue;
    }

    printf("  %-11s %-16s %-4s %-10s %12s %12s %14s  %s\n",
        'DATE', 'TYPE', 'DIR', 'HOLDER', 'GROSS', 'FINE', 'VALUE', 'REF');
    echo '  ' . str_repeat('-', 108) . "\n";

    // Running totals in GRAMS, the module's pivot — adding a tola to a gram is
    // the bug migration 082 exists to prevent, and a trace that did it would
    // "explain" a discrepancy it had invented itself.
    $fineGrams = 0.0;
    $value = 0.0;
    foreach ($movements as $m) {
        $sign = (string) $m['direction'] === 'in' ? 1 : -1;
        $fineGrams += $sign * (float) $m['fine_grams'];
        $value += $sign * (float) $m['amount'];
        printf("  %-11s %-16s %-4s %-10s %12s %12s %14s  %s\n",
            (string) $m['txn_date'],
            substr((string) $m['txn_type'], 0, 16),
            (string) $m['direction'],
            substr((string) $m['holder_type'] . ((int) $m['holder_id'] > 0 ? '#' . (int) $m['holder_id'] : ''), 0, 10),
            $fmtWeight((float) $m['gross_weight']),
            $fmtWeight((float) $m['fine_weight']),
            $fmtMoney((float) $m['amount']),
            (string) $m['ref_no'] . ' [' . (string) $m['source_type'] . ']');
    }

    // The closing position comes from jw_item_balance, not from the loop above:
    // that is the function every guard, report and COGS charge in the module
    // actually reads, so if it disagrees with this screen the screen is what is
    // wrong. The loop is here to show the working, not to be the answer.
    $all = jw_item_balance($cid, $itemId, null, '');
    $own = jw_item_balance($cid, $itemId, null, 'stock');
    echo "\n  On hand (every holder): " . $fmtWeight((float) $all['fine_weight']) . " fine $unit"
        . ', worth ' . $fmtMoney((float) $all['value'])
        . ', average ' . $fmtMoney((float) $all['avg_fine_rate']) . " per fine $unit\n";
    echo '  Of that, in the shop:   ' . $fmtWeight((float) $own['fine_weight']) . " fine $unit"
        . ', worth ' . $fmtMoney((float) $own['value']) . "\n";
    $withOthers = (float) $all['fine_weight'] - (float) $own['fine_weight'];
    if (abs($withOthers) > 0.00005) {
        echo '  Still OUT with a kaligad or refiner: ' . $fmtWeight($withOthers) . " fine $unit\n";
    }

    // The GL side. The register and the ledger are two records of one fact, and
    // a shop only finds out they disagree when somebody adds them up.
    $ledgerId = jw_item_stock_ledger_id($cid, jewellery_item($cid, $itemId) ?? []);
    if ($ledgerId > 0) {
        $ledStmt = db()->prepare("SELECT l.name,
                COALESCE(SUM(CASE WHEN e.entry_type = 'debit' THEN e.amount ELSE -e.amount END), 0) AS balance
            FROM ledgers l
            LEFT JOIN voucher_entries e ON e.ledger_id = l.id
            LEFT JOIN vouchers v ON v.id = e.voucher_id AND v.company_id = :cid
            WHERE l.id = :lid GROUP BY l.id, l.name");
        $ledStmt->execute(['cid' => $cid, 'lid' => $ledgerId]);
        $led = $ledStmt->fetch(PDO::FETCH_ASSOC);
        if ($led) {
            echo "\n  Stock ledger '" . $led['name'] . "' stands at " . $fmtMoney((float) $led['balance']) . "\n";
            echo "  (that ledger usually carries other items too, so it matches this item only when it is the only one on it)\n";
        }
    } else {
        echo "\n  No stock ledger is mapped for this item — nothing it costs can reach the books.\n";
    }

    // The two things people are actually looking for.
    if (abs((float) $all['fine_weight']) < 0.00005 && abs((float) $all['value']) > 0.005) {
        echo "\n  LOOK HERE: no weight left but " . $fmtMoney((float) $all['value']) . " of value still is.\n"
            . "  The sale relieved cost at the item's weighted average, and that average was not what this\n"
            . "  piece cost. Value stranded this way never comes off by selling — it needs a correction.\n";
    }
    if ((float) $all['fine_weight'] > 0.00005 && abs((float) $all['value']) < 0.005) {
        echo "\n  LOOK HERE: weight on hand but no value at all.\n"
            . "  Something came in priced at zero. On a kaligad receipt that is the work-order fault —\n"
            . "  run database/repair_kaligadh_receipts.php, which lists them.\n";
    }
    echo "\n";
}
