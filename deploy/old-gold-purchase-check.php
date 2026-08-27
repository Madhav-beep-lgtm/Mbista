<?php
declare(strict_types=1);

/**
 * Why a piece of old gold is not in the Purchase report.
 *
 *   php deploy/old-gold-purchase-check.php [company_id]
 *
 * A jewellery shop takes metal in by three doors, and all three belong in the
 * purchase register:
 *
 *   a purchase bill raised against the customer
 *   metal handed over against a sale (the exchange leg)
 *   metal taken in settlement, or left as an advance on an order
 *
 * When one of them does not appear, it is almost never the report. It is one of
 * a short list of conditions on the document itself -- most often that it was
 * SAVED BUT NEVER POSTED, which is what happens when the person entering it
 * does not hold posting rights.
 *
 * This walks every metal leg on the database and prints, for each, either that
 * the register can see it or exactly which condition is keeping it out. It
 * reads and reports. It changes nothing.
 */
if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}
require dirname(__DIR__) . '/app/bootstrap.php';

$companyId = (int) ($argv[1] ?? 0);
$scope = $companyId > 0 ? ' AND st.company_id = ' . $companyId : '';

/** Does one row exist? Asked often enough here to be worth naming. */
$has = static function (string $table, string $column, $value): bool {
    if ((int) $value <= 0) {
        return false;
    }
    $stmt = db()->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` = ?");
    $stmt->execute([(int) $value]);

    return (int) $stmt->fetchColumn() > 0;
};

// ---------------------------------------------------------------------------
// 1. Settlements and advances
// ---------------------------------------------------------------------------
// A settlement carries its metal EITHER in a tender breakdown (the "how it is
// paid" grid) or in its own columns, never both. The LEFT JOIN shows whichever
// it used.
$rows = db()->query("SELECT st.id, st.company_id, st.settlement_no, st.settlement_date, st.status,
        st.direction, st.mode, st.is_advance, st.order_id,
        t.id AS tender_id, t.item_id AS t_item, t.purity_id AS t_purity, t.unit_id AS t_unit,
        t.fine_weight AS t_fine, t.amount AS t_amount,
        st.item_id AS s_item, st.purity_id AS s_purity, st.unit_id AS s_unit,
        st.fine_weight AS s_fine, st.amount AS s_amount
    FROM jewellery_settlements st
    LEFT JOIN jewellery_settlement_tenders t ON t.settlement_id = st.id AND t.mode = 'metal'
    WHERE (st.mode = 'metal' OR t.id IS NOT NULL){$scope}
    ORDER BY st.company_id, st.settlement_date, st.id")->fetchAll(PDO::FETCH_ASSOC);

echo "OLD GOLD TAKEN IN SETTLEMENT OR AS AN ADVANCE\n";
echo str_repeat('=', 108), "\n";
if ($rows === []) {
    echo "  Nothing recorded.\n";
}
printf("%-6s %-14s %-12s %-9s %-9s %-10s %s\n",
    'CO', 'DOCUMENT', 'DATE', 'STATUS', 'DIRECTION', 'FINE WT', 'IN THE PURCHASE REPORT?');
echo str_repeat('-', 108), "\n";

$held = [];
$shown = 0;
foreach ($rows as $row) {
    $viaTender = $row['tender_id'] !== null;
    $itemId = (int) ($viaTender ? $row['t_item'] : $row['s_item']);
    $purityId = (int) ($viaTender ? $row['t_purity'] : $row['s_purity']);
    $unitId = (int) ($viaTender ? $row['t_unit'] : $row['s_unit']);
    $fine = (float) ($viaTender ? $row['t_fine'] : $row['s_fine']);
    $amount = (float) ($viaTender ? $row['t_amount'] : $row['s_amount']);

    $why = [];
    if ((string) $row['status'] !== 'posted') {
        $why[] = 'NOT POSTED (' . $row['status'] . ') — open it and post it';
    }
    if ((string) $row['direction'] !== 'received') {
        $why[] = 'this is metal PAID OUT, which is not a purchase';
    }
    if ($itemId <= 0) {
        $why[] = 'no old-gold item was chosen on the row';
    } elseif (!$has('inventory_items', 'id', $itemId)) {
        $why[] = "the item (#{$itemId}) has been deleted";
    }
    if ($fine <= 0 && $amount <= 0) {
        $why[] = 'weight and amount are both nought — nothing was actually entered';
    }

    if ($why === []) {
        $shown++;
    } else {
        $held[] = $why[0];
    }
    printf("%-6s %-14s %-12s %-9s %-9s %-10s %s\n",
        $row['company_id'], $row['settlement_no'], $row['settlement_date'], $row['status'],
        $row['direction'], number_format($fine, 4),
        $why === [] ? 'yes' : 'NO — ' . implode('; ', $why));
}

// ---------------------------------------------------------------------------
// 2. Old gold handed over against a sale
// ---------------------------------------------------------------------------
$where = $companyId > 0 ? ' AND e.company_id = ' . $companyId : '';
$exchanges = db()->query("SELECT e.id, e.company_id, e.item_id, e.fine_weight, e.amount,
        s.sale_no, s.sale_date, s.status
    FROM jewellery_sale_exchanges e
    INNER JOIN jewellery_sales s ON s.id = e.sale_id
    WHERE 1 = 1{$where}
    ORDER BY e.company_id, s.sale_date, e.id")->fetchAll(PDO::FETCH_ASSOC);

echo "\n\nOLD GOLD HANDED OVER AGAINST A SALE\n";
echo str_repeat('=', 108), "\n";
if ($exchanges === []) {
    echo "  Nothing recorded.\n";
}
foreach ($exchanges as $row) {
    $why = [];
    if ((string) $row['status'] !== 'posted') {
        $why[] = 'the SALE is not posted (' . $row['status'] . ') — post the sale';
    }
    if (!$has('inventory_items', 'id', (int) $row['item_id'])) {
        $why[] = 'the item has been deleted';
    }
    if ($why === []) {
        $shown++;
    } else {
        $held[] = $why[0];
    }
    printf("%-6s %-14s %-12s %-9s %-9s %-10s %s\n",
        $row['company_id'], $row['sale_no'], $row['sale_date'], $row['status'], 'received',
        number_format((float) $row['fine_weight'], 4),
        $why === [] ? 'yes' : 'NO — ' . implode('; ', $why));
}

// ---------------------------------------------------------------------------
// 3. What to do about it
// ---------------------------------------------------------------------------
$total = count($rows) + count($exchanges);
echo "\n", str_repeat('=', 108), "\n";
echo "  {$shown} of {$total} old-gold legs reach the Purchase report.\n";

if ($held !== []) {
    echo "\n  Held out by:\n";
    $tally = array_count_values($held);
    arsort($tally);
    foreach ($tally as $reason => $count) {
        echo '    ', $count, ' x ', $reason, "\n";
    }
    echo "\n  WHAT TO DO\n";
    echo "    Not posted   Jewellery > Workshop (for an order advance) or Trading > Settlements.\n";
    echo "                 Open the document and post it. Saving records it; POSTING is what\n";
    echo "                 moves the metal into stock and puts it in the register. A user\n";
    echo "                 without posting rights can only save, and the screen says so.\n";
    echo "    Paid out     Correct as it stands. Metal leaving to settle a payable is not a\n";
    echo "                 purchase, and is deliberately not counted as one.\n";
    echo "    Nothing in   The row was left with no weight and no amount. Delete it or fill\n";
    echo "                 it in.\n";
}

echo "\n  And check the DATE RANGE on the report: the register shows the period asked for,\n";
echo "  and an advance taken today is not in last month's figures.\n";
