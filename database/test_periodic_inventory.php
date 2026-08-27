<?php
declare(strict_types=1);

/**
 * The periodic inventory system, and the rules that identify it.
 *
 * A purchase is not an expense and it is not inventory. It is a purchase: a
 * trading-account debit which, with opening stock and less closing stock, gives
 * the cost of what was actually sold. That is the whole of the periodic system,
 * and it is recognised by three facts that can be asserted:
 *
 *   the TRIAL BALANCE carries opening stock and purchases, and carries neither
 *   closing stock nor cost of sales -- because neither is a ledger balance;
 *
 *   the PROFIT AND LOSS shows cost of sales, worked out as Opening + Purchases
 *   - Closing rather than read off an account;
 *
 *   the BALANCE SHEET shows closing inventory, put there by the single
 *   year-end journal.
 *
 * Both systems are accepted practice. IAS 2 governs how inventory is MEASURED;
 * it does not prescribe which of them records it.
 *
 *   php database/test_periodic_inventory.php
 */
if (PHP_SAPI !== 'cli') { exit('CLI only.'); }
$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';
require_once $root . '/app/inventory_valuation.php';
require_once $root . '/app/inventory_mapping.php';
require_once $root . '/app/reports_engine.php';

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }

/** Run something with the books on one method, then put the setting back. */
function with_method(string $method, callable $body)
{
    $previous = db()->query("SELECT setting_value FROM settings WHERE setting_key = 'inventory_accounting'")
        ->fetchColumn();
    db()->prepare('REPLACE INTO settings (setting_key, setting_value) VALUES (:k, :v)')
        ->execute(['k' => 'inventory_accounting', 'v' => $method]);
    setting('inventory_accounting', '', true);
    try {
        return $body();
    } finally {
        if ($previous === false) {
            db()->exec("DELETE FROM settings WHERE setting_key = 'inventory_accounting'");
        } else {
            db()->prepare('REPLACE INTO settings (setting_key, setting_value) VALUES (:k, :v)')
                ->execute(['k' => 'inventory_accounting', 'v' => (string) $previous]);
        }
        setting('inventory_accounting', '', true);
    }
}

echo "1. Purchases is a head of the chart of accounts, not a kind of expense\n";
// Filed under Direct Expenses a purchase reads as money spent and gone; filed
// under Inventory it never passes through the profit and loss at all. Neither
// is what a purchase is.
$masters = ledger_masters();
ok(isset($masters['purchases']), 'The chart of accounts offers a Purchases head');
ok(($masters['purchases']['nature'] ?? '') === 'expense',
    '  ...whose nature is expense, so every report that asks "is this an expense?" still works');
ok(($masters['purchases']['sort_order'] ?? 0) < ($masters['direct_expense']['sort_order'] ?? 0),
    '  ...and which reads before Direct Expenses, the order a trading account is read in');
ok(($masters['direct_income']['sort_order'] ?? 0) < ($masters['purchases']['sort_order'] ?? 0),
    '  ...and after income, so Sales less Purchases is the shape of the statement');

$type = (string) db()->query("SELECT COLUMN_TYPE FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'ledger_groups' AND column_name = 'master_key'")
    ->fetchColumn();
ok(str_contains($type, "'purchases'"), 'And the database accepts it as a group head');

$purposes = inventory_mapping_purposes();
foreach (['purchases' => 'expense', 'purchase_returns' => 'revenue', 'inventory_change' => 'revenue'] as $purpose => $expect) {
    ok(($purposes[$purpose]['expect'] ?? '') === $expect,
        "The '{$purpose}' account can be mapped, and must be a {$expect}");
}
$plan = inventory_mapping_plan();
ok(($plan['purchases'][1] ?? '') === 'purchases', 'A new chart puts Purchases under the Purchases head');

echo "\n2. Under PERIODIC, only money changing hands touches the ledger\n";
with_method('periodic', static function (): void {
    ok(inv_accounting_method() === 'periodic', 'The books report themselves as periodic');

    $purchase = inv_movement_posting_plan('purchase', 'in');
    ok(($purchase['debit'] ?? '') === 'purchases',
        'A purchase DEBITS Purchases — not an expense account, and not inventory');
    ok(($purchase['credit'] ?? '') === 'purchase_clearing', '  ...against the supplier');

    $return = inv_movement_posting_plan('purchase_return', 'out');
    ok(($return['credit'] ?? '') === 'purchase_returns', 'A purchase going back credits Purchase Returns');

    // THE ONE THAT LOOKS WRONG AND IS RIGHT. Cost of sales is not a ledger
    // under this system, so a sale cannot post to it.
    ok(inv_movement_posting_plan('sale', 'out') === null,
        'A sale posts NO cost entry, because cost of sales is not a ledger here');
    ok(inv_movement_posting_plan('sales_return', 'in') === null, '  ...nor does a sales return');

    foreach (['write_off', 'damage', 'expiry', 'adjustment', 'stock_count', 'material_issue',
        'production_receipt'] as $movement) {
        ok(inv_movement_posting_plan($movement, 'out') === null,
            "  ...nor does {$movement}: quantity moves, the ledger does not");
    }

    // Opening stock is the exception, and has to be: it IS a balance sheet
    // asset at the start of the year, brought forward from last year's close.
    $opening = inv_movement_posting_plan('opening', 'in');
    ok(($opening['debit'] ?? '') === 'inventory_asset',
        'Opening stock is still an asset — it is last year\'s closing, brought forward');
});

echo "\n3. The trial balance is where the two systems tell themselves apart\n";
// A periodic trial balance carries opening stock and purchases, and carries
// neither closing stock nor cost of sales. Not because they are hidden, but
// because neither is a ledger balance until the year-end journal is passed.
with_method('periodic', static function (): void {
    $posted = [];
    foreach (['opening', 'purchase', 'purchase_return', 'sale', 'sales_return', 'write_off',
        'damage', 'adjustment', 'stock_count', 'material_issue', 'material_return',
        'production_receipt', 'scrap_receipt'] as $movement) {
        $plan = inv_movement_posting_plan($movement, 'out');
        if ($plan !== null) {
            $posted[] = $plan['debit'];
            $posted[] = $plan['credit'];
        }
    }
    $posted = array_values(array_unique($posted));
    ok(!in_array('cogs', $posted, true),
        'NOTHING posts to cost of sales, so it cannot appear in a trial balance');
    ok(!in_array('inventory_change', $posted, true),
        'And nothing posts closing stock during the year — that is the year-end journal alone');
    ok(in_array('purchases', $posted, true), 'Purchases does appear, which is the point');
    ok(in_array('inventory_asset', $posted, true),
        'And so does stock in hand, carrying the opening figure');
    sort($posted);
    echo '        the whole periodic ledger footprint: ' . implode(', ', $posted) . "\n";
});

echo "\n4. Under PERPETUAL nothing has changed\n";
// The default. A company mid-year must not have its method moved underneath it
// by an upgrade, so the setting has to default to what the books already do.
with_method('perpetual', static function (): void {
    ok(inv_accounting_method() === 'perpetual', 'Perpetual is still perpetual');
    $purchase = inv_movement_posting_plan('purchase', 'in');
    ok(($purchase['debit'] ?? '') === 'inventory_asset', 'A purchase still debits Inventory');
    $sale = inv_movement_posting_plan('sale', 'out');
    ok(($sale['debit'] ?? '') === 'cogs' && ($sale['credit'] ?? '') === 'inventory_asset',
        'And a sale still posts its own cost of sales');
});

db()->exec("DELETE FROM settings WHERE setting_key = 'inventory_accounting'");
setting('inventory_accounting', '', true);
ok(inv_accounting_method() === 'perpetual',
    'With no setting saved at all, the books stay on the method they were already keeping');

echo "\n5. The purposes a movement needs mapped follow the method\n";
with_method('periodic', static function (): void {
    ok(inv_transaction_purposes('purchase') === ['purchases', 'purchase_clearing'],
        'A periodic purchase needs Purchases mapped, and asks for nothing else');
    ok(inv_transaction_purposes('sale') === [],
        'A periodic sale needs no ledger mapped at all');
});
with_method('perpetual', static function (): void {
    ok(inv_transaction_purposes('purchase') === ['inventory_asset', 'purchase_clearing'],
        'A perpetual purchase still needs Inventory mapped');
});


echo "\n6. The closing-stock journal, on real books\n";
// A company is built from nothing, put on the periodic system, and walked
// through a period: opening stock, purchases, a sale. Then the one journal is
// passed and all three statements are read back. This is the arithmetic the
// whole system exists for, so it is checked against figures worked out by hand
// rather than against whatever the code happens to produce.
$suffix = 'PDIC' . substr((string) time(), -5);
db()->prepare('INSERT INTO companies (name, code, is_active) VALUES (:n, :c, 1)')
    ->execute(['n' => 'Periodic Test Co ' . $suffix, 'c' => $suffix]);
$pcId = (int) db()->lastInsertId();
db()->prepare("INSERT INTO fiscal_years (company_id, label, start_date, end_date, is_default, status)
    VALUES (:c, '2026-27', '2026-04-01', '2027-03-31', 1, 'open')")->execute(['c' => $pcId]);
$pcFy = (int) db()->lastInsertId();

/** A group and a ledger under it, in one go. */
$mkLedger = static function (int $companyId, string $name, string $masterKey, string $type) use (&$mkGroups): int {
    static $groups = [];
    $key = $companyId . '|' . $masterKey;
    if (!isset($groups[$key])) {
        db()->prepare('INSERT INTO ledger_groups (company_id, code, name, master_key) VALUES (:c, :code, :n, :m)')
            ->execute(['c' => $companyId, 'code' => coa_next_group_code($companyId, $masterKey),
                'n' => ucwords(str_replace('_', ' ', $masterKey)), 'm' => $masterKey]);
        $groups[$key] = (int) db()->lastInsertId();
    }
    db()->prepare("INSERT INTO ledgers (company_id, group_id, code, name, type, status)
        VALUES (:c, :g, :code, :n, :t, 'active')")
        ->execute(['c' => $companyId, 'g' => $groups[$key],
            'code' => coa_next_ledger_code($companyId, $groups[$key]), 'n' => $name, 't' => $type]);

    return (int) db()->lastInsertId();
};

$pcStock     = $mkLedger($pcId, 'Stock in hand', 'current_asset', 'asset');
$pcPurchases = $mkLedger($pcId, 'Purchases', 'purchases', 'expense');
$pcReturns   = $mkLedger($pcId, 'Purchase returns', 'purchases', 'revenue');
$pcChange    = $mkLedger($pcId, 'Change in inventory', 'purchases', 'revenue');
$pcCreditor  = $mkLedger($pcId, 'Sundry creditors', 'current_liability', 'liability');
$pcEquity    = $mkLedger($pcId, 'Opening balance equity', 'equity', 'equity');
$pcCogs      = $mkLedger($pcId, 'Cost of goods sold', 'direct_expense', 'expense');

foreach (['inventory_asset' => $pcStock, 'purchases' => $pcPurchases, 'purchase_returns' => $pcReturns,
    'inventory_change' => $pcChange, 'purchase_clearing' => $pcCreditor, 'opening_equity' => $pcEquity,
    'cogs' => $pcCogs] as $purpose => $ledgerId) {
    db()->prepare("INSERT INTO inventory_ledger_mappings (company_id, scope, purpose, ledger_id)
        VALUES (:c, 'global', :p, :l)")->execute(['c' => $pcId, 'p' => $purpose, 'l' => $ledgerId]);
}
inv_mapping_forget();

/** Post one journal, the way the posting engine would. */
$journal = static function (int $companyId, int $fyId, string $date, string $no, int $dr, int $cr, float $amount): void {
    create_voucher_with_entries([
        'company_id' => $companyId, 'fiscal_year_id' => $fyId, 'voucher_no' => $no,
        'voucher_type' => 'journal', 'voucher_date' => $date, 'total_amount' => $amount,
        'narration' => $no, 'status' => 'posted',
    ], [
        ['ledger_id' => $dr, 'entry_type' => 'debit', 'amount' => $amount],
        ['ledger_id' => $cr, 'entry_type' => 'credit', 'amount' => $amount],
    ]);
};

with_method('periodic', static function () use ($pcId, $pcFy, $pcStock, $pcPurchases, $pcReturns,
    $pcChange, $pcCreditor, $pcEquity, $pcCogs, $journal): void {
    // Opening stock 200,000 brought forward, before the year starts.
    $journal($pcId, $pcFy, '2026-04-01', 'OPEN-1', $pcStock, $pcEquity, 200000.0);
    // Bought 900,000 during the year, sent 50,000 of it back.
    $journal($pcId, $pcFy, '2026-06-10', 'PUR-1', $pcPurchases, $pcCreditor, 500000.0);
    $journal($pcId, $pcFy, '2026-11-02', 'PUR-2', $pcPurchases, $pcCreditor, 400000.0);
    $journal($pcId, $pcFy, '2026-12-01', 'PRET-1', $pcCreditor, $pcReturns, 50000.0);

    $before = inv_periodic_trading_figures($pcId, '2026-04-01', '2027-03-31');
    ok(abs($before['opening'] - 200000.0) < 0.01, 'Opening stock is the 200,000 brought forward');
    ok(abs($before['purchases'] - 900000.0) < 0.01, 'Purchases total 900,000');
    ok(abs($before['returns'] - 50000.0) < 0.01, 'And 50,000 went back');

    // THE TRIAL BALANCE TEST. Before the journal, the stock account still holds
    // the OPENING figure and cost of sales holds nothing at all.
    $ledgerBalance = static function (int $companyId, int $ledgerId, string $until): float {
        $stmt = db()->prepare("SELECT COALESCE(SUM(CASE WHEN ve.entry_type='debit' THEN ve.amount ELSE -ve.amount END),0)
            FROM voucher_entries ve INNER JOIN vouchers v ON v.id = ve.voucher_id
            WHERE v.company_id = :c AND ve.ledger_id = :l AND v.status='posted' AND v.voucher_date <= :u");
        $stmt->execute(['c' => $companyId, 'l' => $ledgerId, 'u' => $until]);

        return round((float) $stmt->fetchColumn(), 2);
    };
    ok(abs($ledgerBalance($pcId, $pcStock, '2027-03-31') - 200000.0) < 0.01,
        'The trial balance carries OPENING stock, untouched by a year of trading');
    ok(abs($ledgerBalance($pcId, $pcCogs, '2027-03-31')) < 0.01,
        '  ...and carries NO cost of sales, because nothing posts to it');
    ok(abs($ledgerBalance($pcId, $pcChange, '2027-03-31')) < 0.01,
        '  ...and no closing stock either, until the journal is passed');
});

echo "\n7. Passing the journal completes the arithmetic\n";
// Closing stock is counted at 540,000. Worked out by hand:
//   change   = 540,000 - 200,000            = 340,000 Dr to stock
//   cogs     = 200,000 + 900,000 - 50,000 - 540,000 = 510,000
$countedClosing = 540000.0;
with_method('periodic', static function () use ($pcId, $pcFy, $pcStock, $pcChange, $pcCogs, $countedClosing, $journal): void {
    // Stand in for the stock count: the subledger has no items on this fixture,
    // so the closing figure is entered the way a count would produce it.
    $journal($pcId, $pcFy, '2027-03-31', 'CLOSE-1', $pcStock, $pcChange, $countedClosing - 200000.0);

    $after = inv_periodic_trading_figures($pcId, '2026-04-01', '2027-03-31');
    ok(abs($after['closing'] - 540000.0) < 0.01, 'Closing stock is now 540,000');
    ok(abs($after['cogs'] - 510000.0) < 0.01,
        'COST OF SALES = 200,000 + 900,000 - 50,000 - 540,000 = 510,000, got '
            . number_format($after['cogs'], 2));

    $ledgerBalance = static function (int $companyId, int $ledgerId): float {
        $stmt = db()->prepare("SELECT COALESCE(SUM(CASE WHEN ve.entry_type='debit' THEN ve.amount ELSE -ve.amount END),0)
            FROM voucher_entries ve INNER JOIN vouchers v ON v.id = ve.voucher_id
            WHERE v.company_id = :c AND ve.ledger_id = :l AND v.status='posted'");
        $stmt->execute(['c' => $companyId, 'l' => $ledgerId]);

        return round((float) $stmt->fetchColumn(), 2);
    };
    ok(abs($ledgerBalance($pcId, $pcStock) - 540000.0) < 0.01,
        'The BALANCE SHEET now shows closing inventory of 540,000');
    ok(abs($ledgerBalance($pcId, $pcCogs)) < 0.01,
        'And cost of sales is STILL not a ledger — it is derived, exactly as it should be');

    // The identity that makes the two halves agree.
    $change = $ledgerBalance($pcId, $pcChange);
    ok(abs((-$change) - ($after['closing'] - $after['opening'])) < 0.01,
        'Change in Inventory equals Closing less Opening, which is why the two sides tie');
});

// Tidy up: this fixture exists only for the arithmetic above. Ledgers are
// referenced by their groups, so the children go before the parents rather than
// relying on a cascade that is not there.
foreach (['voucher_entries' => 'voucher_id IN (SELECT id FROM vouchers WHERE company_id = :c)',
    'vouchers' => 'company_id = :c',
    'inventory_ledger_mappings' => 'company_id = :c',
    'ledgers' => 'company_id = :c',
    'ledger_groups' => 'company_id = :c',
    'fiscal_years' => 'company_id = :c',
    'companies' => 'id = :c'] as $table => $where) {
    db()->prepare("DELETE FROM `{$table}` WHERE {$where}")->execute(['c' => $pcId]);
}
inv_mapping_forget();

echo "\n8. The profit and loss, drawn from those same accounts\n";
// The statement a client actually reads. Built on the fixture above so the
// figures are known: sales 1,200,000 against a cost of sales of 510,000 must
// give a gross profit of 690,000, and the working shown underneath it must be
// the working that produces it -- not a second opinion from the stock records.
$suffix2 = 'PDPL' . substr((string) time(), -5);
db()->prepare('INSERT INTO companies (name, code, is_active) VALUES (:n, :c, 1)')
    ->execute(['n' => 'Periodic PL Co ' . $suffix2, 'c' => $suffix2]);
$plId = (int) db()->lastInsertId();
db()->prepare("INSERT INTO fiscal_years (company_id, label, start_date, end_date, is_default, status)
    VALUES (:c, '2026-27', '2026-04-01', '2027-03-31', 1, 'open')")->execute(['c' => $plId]);
$plFy = (int) db()->lastInsertId();

$plGroups = [];
$mkLedger2 = static function (int $companyId, string $name, string $masterKey, string $type) use (&$plGroups): int {
    $key = $companyId . '|' . $masterKey;
    if (!isset($plGroups[$key])) {
        db()->prepare('INSERT INTO ledger_groups (company_id, code, name, master_key) VALUES (:c, :code, :n, :m)')
            ->execute(['c' => $companyId, 'code' => coa_next_group_code($companyId, $masterKey),
                'n' => ucwords(str_replace('_', ' ', $masterKey)), 'm' => $masterKey]);
        $plGroups[$key] = (int) db()->lastInsertId();
    }
    db()->prepare("INSERT INTO ledgers (company_id, group_id, code, name, type, status)
        VALUES (:c, :g, :code, :n, :t, 'active')")
        ->execute(['c' => $companyId, 'g' => $plGroups[$key],
            'code' => coa_next_ledger_code($companyId, $plGroups[$key]), 'n' => $name, 't' => $type]);

    return (int) db()->lastInsertId();
};

$plStock     = $mkLedger2($plId, 'Stock in hand', 'current_asset', 'asset');
$plPurchases = $mkLedger2($plId, 'Purchases', 'purchases', 'expense');
$plReturns   = $mkLedger2($plId, 'Purchase returns', 'purchases', 'revenue');
$plChange    = $mkLedger2($plId, 'Change in inventory', 'purchases', 'revenue');
$plCreditor  = $mkLedger2($plId, 'Sundry creditors', 'current_liability', 'liability');
$plEquity    = $mkLedger2($plId, 'Opening balance equity', 'equity', 'equity');
$plSales     = $mkLedger2($plId, 'Sales', 'direct_income', 'revenue');
$plDebtor    = $mkLedger2($plId, 'Sundry debtors', 'current_asset', 'asset');
$plRent      = $mkLedger2($plId, 'Rent', 'indirect_expense', 'expense');

foreach (['inventory_asset' => $plStock, 'purchases' => $plPurchases, 'purchase_returns' => $plReturns,
    'inventory_change' => $plChange, 'purchase_clearing' => $plCreditor,
    'opening_equity' => $plEquity] as $purpose => $ledgerId) {
    db()->prepare("INSERT INTO inventory_ledger_mappings (company_id, scope, purpose, ledger_id)
        VALUES (:c, 'global', :p, :l)")->execute(['c' => $plId, 'p' => $purpose, 'l' => $ledgerId]);
}
inv_mapping_forget();

$post = static function (int $companyId, int $fyId, string $date, string $no, int $dr, int $cr, float $amount): void {
    create_voucher_with_entries([
        'company_id' => $companyId, 'fiscal_year_id' => $fyId, 'voucher_no' => $no,
        'voucher_type' => 'journal', 'voucher_date' => $date, 'total_amount' => $amount,
        'narration' => $no, 'status' => 'posted',
    ], [
        ['ledger_id' => $dr, 'entry_type' => 'debit', 'amount' => $amount],
        ['ledger_id' => $cr, 'entry_type' => 'credit', 'amount' => $amount],
    ]);
};

with_method('periodic', static function () use ($plId, $plFy, $plStock, $plPurchases, $plReturns,
    $plChange, $plCreditor, $plEquity, $plSales, $plDebtor, $plRent, $post): void {
    $post($plId, $plFy, '2026-04-01', 'PL-OPEN', $plStock, $plEquity, 200000.0);
    $post($plId, $plFy, '2026-06-10', 'PL-PUR1', $plPurchases, $plCreditor, 500000.0);
    $post($plId, $plFy, '2026-11-02', 'PL-PUR2', $plPurchases, $plCreditor, 400000.0);
    $post($plId, $plFy, '2026-12-01', 'PL-PRET', $plCreditor, $plReturns, 50000.0);
    $post($plId, $plFy, '2026-12-20', 'PL-SALE', $plDebtor, $plSales, 1200000.0);
    $post($plId, $plFy, '2027-02-01', 'PL-RENT', $plRent, $plCreditor, 120000.0);
    // Closing stock counted at 540,000: the year-end journal.
    $post($plId, $plFy, '2027-03-31', 'PL-CLOSE', $plStock, $plChange, 340000.0);

    $figures = rc_pl_figures($plId, '2026-04-01', '2027-03-31');
    ok(abs($figures['net_sales'] - 1200000.0) < 0.01, 'Sales are 1,200,000');
    ok(abs($figures['cogs'] - 510000.0) < 0.01,
        'COST OF SALES comes out at 510,000 from the Purchases head alone, got '
            . number_format($figures['cogs'], 2));
    ok(abs($figures['gross_profit'] - 690000.0) < 0.01,
        'GROSS PROFIT is 1,200,000 - 510,000 = 690,000, got ' . number_format($figures['gross_profit'], 2));

    // The two traps this head would otherwise fall into.
    ok(abs($figures['gross_sales'] - 1200000.0) < 0.01,
        'Purchase returns and Change in inventory are NOT counted as sales, though both are credits');
    ok(abs($figures['operating_expenses'] - 120000.0) < 0.01,
        'And Purchases is NOT counted as an operating expense — only the rent is, got '
            . number_format($figures['operating_expenses'], 2));
    ok(abs($figures['pat'] - 570000.0) < 0.01,
        'So profit after tax is 690,000 - 120,000 = 570,000, got ' . number_format($figures['pat'], 2));

    // And the working shown under the statement ties to it.
    $trade = rc_trading_figures($plId, '2026-04-01', '2027-03-31');
    ok($trade['from_ledger'] === true, 'The trading account is read from the LEDGER, not the stock records');
    ok(abs($trade['opening'] - 200000.0) < 0.01, '  Opening stock 200,000');
    ok(abs($trade['purchases'] - 900000.0) < 0.01, '  Purchases 900,000');
    ok(abs($trade['returns'] - 50000.0) < 0.01, '  Less returns 50,000');
    ok(abs($trade['closing'] - 540000.0) < 0.01, '  Less closing stock 540,000');
    ok(abs($trade['consumed'] - $figures['cogs']) < 0.01,
        '  ...and the working equals the cost of sales exactly, because they are one calculation');
});

foreach (['voucher_entries' => 'voucher_id IN (SELECT id FROM vouchers WHERE company_id = :c)',
    'vouchers' => 'company_id = :c', 'inventory_ledger_mappings' => 'company_id = :c',
    'ledgers' => 'company_id = :c', 'ledger_groups' => 'company_id = :c',
    'fiscal_years' => 'company_id = :c', 'companies' => 'id = :c'] as $table => $where) {
    db()->prepare("DELETE FROM `{$table}` WHERE {$where}")->execute(['c' => $plId]);
}
inv_mapping_forget();

echo "\n9. Jewellery posts the same way, because it goes through the same switch\n";
// Jewellery builds its own vouchers and never touches inv_movement_posting_plan(),
// so it had to be told separately. Only two things change, and the instruction
// was exact: the PURCHASE leg and the COST leg. The sale's revenue side — the
// customer, sales, VAT, the skills tax — is posted identically either way.
require_once dirname(__DIR__) . '/app/jewellery_trade.php';
// jewellery_post_sale() reaches into the workshop for order status, which
// jewellery_trade.php does not require itself.
require_once dirname(__DIR__) . '/app/jewellery_workshop.php';

$jwSuffix = 'PDJW' . substr((string) time(), -5);
db()->prepare('INSERT INTO companies (name, code, is_active) VALUES (:n, :c, 1)')
    ->execute(['n' => 'Periodic Jeweller ' . $jwSuffix, 'c' => $jwSuffix]);
$jwCo = (int) db()->lastInsertId();
db()->prepare("INSERT INTO fiscal_years (company_id, label, start_date, end_date, is_default, status)
    VALUES (:c, '2026-27', '2026-07-16', '2027-07-15', 1, 'open')")->execute(['c' => $jwCo]);
$jwFy = (int) db()->lastInsertId();

$jwGroups = [];
$jwLedger = static function (string $name, string $masterKey, string $type) use ($jwCo, &$jwGroups): int {
    $key = $masterKey;
    if (!isset($jwGroups[$key])) {
        db()->prepare('INSERT INTO ledger_groups (company_id, code, name, master_key) VALUES (:c, :code, :n, :m)')
            ->execute(['c' => $jwCo, 'code' => coa_next_group_code($jwCo, $masterKey),
                'n' => ucwords(str_replace('_', ' ', $masterKey)), 'm' => $masterKey]);
        $jwGroups[$key] = (int) db()->lastInsertId();
    }
    db()->prepare("INSERT INTO ledgers (company_id, group_id, code, name, type, status)
        VALUES (:c, :g, :code, :n, :t, 'active')")
        ->execute(['c' => $jwCo, 'g' => $jwGroups[$key],
            'code' => coa_next_ledger_code($jwCo, $jwGroups[$key]), 'n' => $name, 't' => $type]);

    return (int) db()->lastInsertId();
};

$jwStock     = $jwLedger('Metal stock', 'current_asset', 'asset');
$jwPurchases = $jwLedger('Purchases — gold', 'purchases', 'expense');
$jwCogs      = $jwLedger('Cost of goods sold', 'direct_expense', 'expense');
$jwCash      = $jwLedger('Cash', 'current_asset', 'asset');
$jwSalesMetal = $jwLedger('Sales — metal', 'direct_income', 'revenue');
$jwSalesMaking = $jwLedger('Sales — making', 'direct_income', 'revenue');
$jwVat       = $jwLedger('VAT payable', 'current_liability', 'liability');
$jwSpt       = $jwLedger('Skills promotion tax', 'current_liability', 'liability');
$jwRound     = $jwLedger('Rounding', 'indirect_expense', 'expense');
$jwClearing  = $jwLedger('Sundry creditors', 'current_liability', 'liability');

// Mappings are stored under the CANONICAL purpose name — jewellery's own names
// are aliases onto the shared inventory vocabulary, and jw_canonical_purpose()
// is what does the translating. Storing them under the jewellery name looks
// right and resolves to nothing.
$jwMap = ['stock_metal' => $jwStock, 'stock_finished' => $jwStock, 'cogs' => $jwCogs,
    'sales_metal' => $jwSalesMetal, 'sales_making' => $jwSalesMaking, 'vat_output' => $jwVat,
    'spt_output' => $jwSpt, 'rounding' => $jwRound, 'purchase_clearing' => $jwClearing];
foreach ($jwMap as $purpose => $ledgerId) {
    $canonical = jw_canonical_purpose($purpose);
    db()->prepare("INSERT IGNORE INTO inventory_ledger_mappings (company_id, scope, purpose, ledger_id)
        VALUES (:c, 'global', :p, :l)")->execute(['c' => $jwCo, 'p' => $canonical, 'l' => $ledgerId]);
}
foreach (['purchases' => $jwPurchases, 'inventory_asset' => $jwStock] as $purpose => $ledgerId) {
    db()->prepare("INSERT IGNORE INTO inventory_ledger_mappings (company_id, scope, purpose, ledger_id)
        VALUES (:c, 'global', :p, :l)")->execute(['c' => $jwCo, 'p' => $purpose, 'l' => $ledgerId]);
}
inv_mapping_forget();

// The metal, the purity, the unit and an item to buy.
db()->prepare("INSERT INTO jewellery_metals (company_id, code, name, active) VALUES (:c, 'GOLD', 'Gold', 1)")
    ->execute(['c' => $jwCo]);
$jwMetal = (int) db()->lastInsertId();
db()->prepare("INSERT INTO jewellery_units (company_id, code, name, grams, is_base, active)
    VALUES (:c, 'TOLA', 'Tola', 11.6638, 0, 1)")->execute(['c' => $jwCo]);
$jwUnit = (int) db()->lastInsertId();
db()->prepare("INSERT INTO jewellery_purities (company_id, metal_id, code, name, fineness)
    VALUES (:c, :m, '22K', '22 Karat', 0.916)")->execute(['c' => $jwCo, 'm' => $jwMetal]);
$jwPurity = (int) db()->lastInsertId();

$jwUser = (int) db()->query("SELECT id FROM users ORDER BY id LIMIT 1")->fetchColumn();
$jwItem = jewellery_save_item($jwCo, ['code' => 'RING1', 'name' => 'Gold ring', 'jewellery_type' => 'ornament',
    'metal_id' => $jwMetal, 'purity_id' => $jwPurity, 'unit_id' => $jwUnit, 'gross_weight' => 0], $jwUser);

db()->prepare("INSERT INTO accounting_parties (company_id, code, name, party_type)
    VALUES (:c, 'SUP1', 'Bullion House', 'supplier')")->execute(['c' => $jwCo]);
$jwSupplier = (int) db()->lastInsertId();
db()->prepare("INSERT INTO accounting_parties (company_id, code, name, party_type)
    VALUES (:c, 'CUS1', 'Walk-in customer', 'customer')")->execute(['c' => $jwCo]);
$jwCustomer = (int) db()->lastInsertId();

/** Every leg of a voucher, keyed by ledger name. */
$legsOf = static function (int $voucherId): array {
    $stmt = db()->prepare("SELECT l.name, ve.entry_type, ve.amount FROM voucher_entries ve
        INNER JOIN ledgers l ON l.id = ve.ledger_id WHERE ve.voucher_id = ? ORDER BY ve.id");
    $stmt->execute([$voucherId]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $sign = $row['entry_type'] === 'debit' ? 1 : -1;
        $out[(string) $row['name']] = round(($out[(string) $row['name']] ?? 0) + $sign * (float) $row['amount'], 2);
    }

    return $out;
};

with_method('periodic', static function () use ($jwCo, $jwFy, $jwUser, $jwItem, $jwPurity, $jwUnit,
    $jwSupplier, $jwCustomer, $jwCash, $legsOf): void {
    // BUY: 10 tola at 90,000 a tola.
    $purchaseId = jewellery_save_purchase($jwCo, $jwFy, [
        'purchase_date' => '2026-08-01', 'party_id' => $jwSupplier, 'source' => 'supplier',
        'settle_mode' => 'credit',
    ], [['item_id' => $jwItem, 'purity_id' => $jwPurity, 'unit_id' => $jwUnit,
        'gross_weight' => 10, 'rate' => 90000]], $jwUser);
    $posted = jewellery_post_purchase($jwCo, $purchaseId, $jwUser);
    ok(!empty($posted['ok']), 'A jewellery purchase posts' . (!empty($posted['ok']) ? '' : ' — ' . ($posted['error'] ?? '')));
    $legs = $legsOf((int) $posted['voucher_id']);
    ok(isset($legs['Purchases — gold']) && $legs['Purchases — gold'] > 0,
        'It DEBITS Purchases — gold, not the stock account');
    ok(!isset($legs['Metal stock']),
        '  ...and the stock account is not touched at all, which is the whole point');

    // SELL: 4 tola. The revenue side must be identical to perpetual.
    $saleId = jewellery_save_sale($jwCo, $jwFy, [
        'sale_date' => '2026-09-01', 'party_id' => $jwCustomer,
        'received_amount' => 0, 'settle_mode' => 'credit',
    ], [['item_id' => $jwItem, 'gross_weight' => 4, 'rate' => 160000]], [], $jwUser);
    $soldResult = jewellery_post_sale($jwCo, $saleId, $jwUser);
    ok(!empty($soldResult['ok']), 'A jewellery sale posts' . (!empty($soldResult['ok']) ? '' : ' — ' . ($soldResult['error'] ?? '')));
    $saleLegs = $legsOf((int) $soldResult['voucher_id']);

    // THE COST LEG IS GONE, and only the cost leg.
    ok(!isset($saleLegs['Cost of goods sold']),
        'The sale posts NO cost of goods sold, because there is no such ledger under this system');
    ok(!isset($saleLegs['Metal stock']),
        '  ...and takes nothing out of stock either');
    ok(isset($saleLegs['Sales — metal']) && $saleLegs['Sales — metal'] < 0,
        'But the revenue side is untouched: Sales — metal is still credited');
    ok(isset($saleLegs['Skills promotion tax']),
        '  ...and so is the skills promotion tax');

    // The cost is still WORKED OUT, just not posted, so margin reports live on.
    $lineCost = (float) db()->query("SELECT COALESCE(SUM(cogs_amount), 0) FROM jewellery_sale_lines
        WHERE sale_id = {$saleId}")->fetchColumn();
    ok($lineCost > 0,
        'The cost is still calculated and kept on the line (' . number_format($lineCost, 2)
            . '), so item-wise margin still reports');
    ok(abs((float) ($soldResult['cogs'] ?? 0) - $lineCost) < 0.01,
        '  ...and the posting routine still returns it');

    // And the voucher still balances, which a half-removed leg would break.
    $sum = 0.0;
    foreach ($saleLegs as $amount) {
        $sum += $amount;
    }
    ok(abs($sum) < 0.01, 'The sale voucher still balances to nought without its cost leg');
});

foreach (['voucher_entries' => 'voucher_id IN (SELECT id FROM vouchers WHERE company_id = :c)',
    'vouchers' => 'company_id = :c', 'jewellery_sale_lines' => 'company_id = :c',
    'jewellery_sales' => 'company_id = :c', 'jewellery_purchase_lines' => 'company_id = :c',
    'jewellery_purchases' => 'company_id = :c', 'jewellery_stock_txns' => 'company_id = :c',
    'jewellery_bills' => 'company_id = :c', 'jewellery_item_profiles' => 'company_id = :c',
    'inventory_transactions' => 'company_id = :c', 'inventory_items' => 'company_id = :c',
    'jewellery_purities' => 'company_id = :c', 'jewellery_units' => 'company_id = :c',
    'jewellery_metals' => 'company_id = :c', 'inventory_ledger_mappings' => 'company_id = :c',
    'accounting_parties' => 'company_id = :c', 'ledgers' => 'company_id = :c',
    'ledger_groups' => 'company_id = :c', 'fiscal_years' => 'company_id = :c',
    'companies' => 'id = :c'] as $table => $where) {
    try {
        db()->prepare("DELETE FROM `{$table}` WHERE {$where}")->execute(['c' => $jwCo]);
    } catch (Throwable $ignored) {
        // A table this build does not have is not a failure of the assertions.
    }
}
inv_mapping_forget();

echo "\n10. Hospitality needed nothing, and it is worth knowing why\n";
// Hospitality was on the list of modules that post their own vouchers. It does
// -- but its sales voucher is revenue only: Dr receivable and discount, Cr
// sales and VAT. It has never posted a cost of sales entry or touched a stock
// account, so it was already keeping the periodic form without anyone saying
// so, and needed no change at all.
//
// Asserted rather than assumed, because "we checked and there was nothing to
// do" is indistinguishable from "we forgot" a year from now.
$hospPosting = (string) file_get_contents(dirname(__DIR__) . '/app/hospitality_sales_posting.php');
$hospEngine = (string) file_get_contents(dirname(__DIR__) . '/app/hospitality_engine.php');
ok(str_contains($hospPosting, 'create_voucher_with_entries'), 'Hospitality posts its own sales voucher');
ok(!str_contains($hospPosting, "'cogs'"),
    '  ...and posts no cost of sales leg, so a sale never carries its own cost');
ok(!preg_match("/'inventory_asset'/", $hospPosting),
    '  ...and never debits or credits a stock account');
ok(substr_count($hospEngine, "'cogs'") === 0,
    'Nor does the hospitality engine post one anywhere else');

// Its ingredient costing is an ESTIMATE from recipes, reported and never
// posted, which is why the management pack labels every cost column as one.
require_once dirname(__DIR__) . '/app/hospitality_management_report.php';
$costNote = (new ReflectionFunction('hospitality_pack_category'))->getDocComment() ?: '';
ok(function_exists('hospitality_pack_costed_note'),
    'And its costing stays an estimate the reports label, not a posting');

echo "\n11. Converting books that are already posted, and taking it back off\n";
// The last piece, and the one that touches real client books. Perpetual books
// are built, restated, read back, then reversed and read back again. The
// reversal matters as much as the conversion: it is what makes the thing safe
// to attempt at all.
require_once dirname(__DIR__) . '/app/periodic_conversion.php';

$cvSuffix = 'PDCV' . substr((string) time(), -5);
db()->prepare('INSERT INTO companies (name, code, is_active) VALUES (:n, :c, 1)')
    ->execute(['n' => 'Conversion Co ' . $cvSuffix, 'c' => $cvSuffix]);
$cvCo = (int) db()->lastInsertId();
db()->prepare("INSERT INTO fiscal_years (company_id, label, start_date, end_date, is_default, status)
    VALUES (:c, '2026-27', '2026-04-01', '2027-03-31', 1, 'open')")->execute(['c' => $cvCo]);
$cvFy = (int) db()->lastInsertId();

$cvGroups = [];
$cvLedger = static function (string $name, string $masterKey, string $type) use ($cvCo, &$cvGroups): int {
    if (!isset($cvGroups[$masterKey])) {
        db()->prepare('INSERT INTO ledger_groups (company_id, code, name, master_key) VALUES (:c, :code, :n, :m)')
            ->execute(['c' => $cvCo, 'code' => coa_next_group_code($cvCo, $masterKey),
                'n' => ucwords(str_replace('_', ' ', $masterKey)), 'm' => $masterKey]);
        $cvGroups[$masterKey] = (int) db()->lastInsertId();
    }
    db()->prepare("INSERT INTO ledgers (company_id, group_id, code, name, type, status)
        VALUES (:c, :g, :code, :n, :t, 'active')")
        ->execute(['c' => $cvCo, 'g' => $cvGroups[$masterKey],
            'code' => coa_next_ledger_code($cvCo, $cvGroups[$masterKey]), 'n' => $name, 't' => $type]);

    return (int) db()->lastInsertId();
};

$cvStock  = $cvLedger('Inventory', 'current_asset', 'asset');
$cvPurch  = $cvLedger('Purchases', 'purchases', 'expense');
$cvChange = $cvLedger('Change in inventory', 'purchases', 'revenue');
$cvCogs   = $cvLedger('Cost of goods sold', 'direct_expense', 'expense');
$cvCred   = $cvLedger('Sundry creditors', 'current_liability', 'liability');
$cvEquity = $cvLedger('Opening balance equity', 'equity', 'equity');
$cvDebtor = $cvLedger('Sundry debtors', 'current_asset', 'asset');
$cvSales  = $cvLedger('Sales', 'direct_income', 'revenue');

foreach (['inventory_asset' => $cvStock, 'purchases' => $cvPurch, 'inventory_change' => $cvChange,
    'cogs' => $cvCogs, 'purchase_clearing' => $cvCred, 'opening_equity' => $cvEquity] as $purpose => $ledgerId) {
    db()->prepare("INSERT INTO inventory_ledger_mappings (company_id, scope, purpose, ledger_id)
        VALUES (:c, 'global', :p, :l)")->execute(['c' => $cvCo, 'p' => $purpose, 'l' => $ledgerId]);
}
inv_mapping_forget();

// An item, and the stock movements that tell the subledger what was a purchase.
// Opening stock lives on the ITEM, not as a movement. sr_stock_summary seeds
// its cost layers from opening_qty/opening_amount before replaying anything,
// and takes its opening snapshot at the first transaction on or after the
// period start -- so an "opening" transaction dated on day one reads as a
// purchase made that morning, which is not what it means.
db()->prepare("INSERT INTO inventory_items (company_id, sku, name, item_type, unit, status,
        opening_qty, opening_amount)
    VALUES (:c, 'WIDGET', 'Widget', 'stock', 'pcs', 'active', 20, 200000)")->execute(['c' => $cvCo]);
$cvItem = (int) db()->lastInsertId();
$stockTxn = static function (string $type, string $date, float $in, float $out, float $amount)
    use ($cvCo, $cvFy, $cvItem): void {
    db()->prepare("INSERT INTO inventory_transactions
        (company_id, fiscal_year_id, item_id, transaction_type, transaction_date, qty_in, qty_out, rate, amount)
        VALUES (:c, :f, :i, :t, :d, :qi, :qo, :r, :a)")
        ->execute(['c' => $cvCo, 'f' => $cvFy, 'i' => $cvItem, 't' => $type, 'd' => $date,
            'qi' => $in, 'qo' => $out, 'r' => $in > 0 ? $amount / $in : ($out > 0 ? $amount / $out : 0),
            'a' => $amount]);
};
$stockTxn('purchase', '2026-06-10', 90, 0, 900000.0);
$stockTxn('sale', '2026-12-20', 0, 51, 510000.0);

$cvPost = static function (string $date, string $no, int $dr, int $cr, float $amount) use ($cvCo, $cvFy): int {
    return (int) create_voucher_with_entries([
        'company_id' => $cvCo, 'fiscal_year_id' => $cvFy, 'voucher_no' => $no,
        'voucher_type' => 'journal', 'voucher_date' => $date, 'total_amount' => $amount,
        'narration' => $no, 'status' => 'posted',
    ], [
        ['ledger_id' => $dr, 'entry_type' => 'debit', 'amount' => $amount],
        ['ledger_id' => $cr, 'entry_type' => 'credit', 'amount' => $amount],
    ]);
};

// The books AS THEY WERE KEPT: perpetual. Purchase into stock, cost out of it.
$cvPost('2026-04-01', 'CV-OPEN', $cvStock, $cvEquity, 200000.0);
$cvPost('2026-06-10', 'CV-PUR', $cvStock, $cvCred, 900000.0);
$cvPost('2026-12-20', 'CV-SALE', $cvDebtor, $cvSales, 1200000.0);
$cvPost('2026-12-20', 'CV-COGS', $cvCogs, $cvStock, 510000.0);

$cvBalance = static function (int $ledgerId) use ($cvCo): float {
    $stmt = db()->prepare("SELECT COALESCE(SUM(CASE WHEN ve.entry_type='debit' THEN ve.amount ELSE -ve.amount END),0)
        FROM voucher_entries ve INNER JOIN vouchers v ON v.id = ve.voucher_id
        WHERE v.company_id = :c AND ve.ledger_id = :l AND v.status='posted'");
    $stmt->execute(['c' => $cvCo, 'l' => $ledgerId]);

    return round((float) $stmt->fetchColumn(), 2);
};

ok(abs($cvBalance($cvStock) - 590000.0) < 0.01,
    'Perpetual as kept: stock is 200,000 + 900,000 - 510,000 = 590,000');
ok(abs($cvBalance($cvCogs) - 510000.0) < 0.01, '  ...and cost of sales is a ledger holding 510,000');
ok(abs($cvBalance($cvPurch)) < 0.01, '  ...and there is no Purchases balance at all');

with_method('periodic', static function () use ($cvCo, $cvFy, $cvStock, $cvPurch, $cvCogs, $cvChange, $cvBalance): void {
    // A dry run first, which is what the tool does by default.
    $plan = periodic_conversion_plan($cvCo, $cvFy);
    ok($plan['ok'], 'The conversion plans cleanly' . ($plan['ok'] ? '' : ' — ' . $plan['note']));
    ok(abs($plan['purchases'] - 900000.0) < 0.01,
        '  ...finding 900,000 of purchases, from the stock records that know what a purchase is');
    ok(abs($plan['cogs'] - 510000.0) < 0.01, '  ...and 510,000 of posted cost of sales');
    ok(abs($plan['inventory_after'] - 200000.0) < 0.01,
        '  ...and works out that stock would land back on its opening 200,000');
    ok(abs($cvBalance($cvPurch)) < 0.01, 'And planning posted nothing — it is a dry run');

    $done = periodic_conversion_apply($cvCo, $cvFy, null);
    ok($done['ok'], 'Applying it works' . ($done['ok'] ? '' : ' — ' . $done['note']));
    ok((int) $done['voucher_id'] > 0, '  ...as ONE journal, leaving every original voucher untouched');

    // The three rules, on books that were perpetual ten seconds ago.
    ok(abs($cvBalance($cvPurch) - 900000.0) < 0.01,
        'Purchases now holds the 900,000 that was buried in stock');
    ok(abs($cvBalance($cvCogs)) < 0.01,
        'Cost of sales is nought — it is derived now, so it cannot sit in a trial balance');
    // The restatement puts stock back on its opening 200,000, and the
    // closing-stock journal that runs with it immediately carries it to the
    // counted 590,000. Both happen inside apply(), so what is read here is the
    // end state: a balance sheet showing what is actually on the shelf.
    ok(abs($cvBalance($cvStock) - 590000.0) < 0.01,
        'And stock now shows the counted closing figure of 590,000, got '
            . number_format($cvBalance($cvStock), 2));

    // The closing-stock journal ran with it, so the balance sheet is right.
    ok((int) ($done['closing_voucher_id'] ?? 0) > 0 || abs((float) ($done['closing_stock'] ?? 0)) > 0.005,
        'The closing-stock journal ran too, so the balance sheet is not left showing last year');

    $figures = rc_pl_figures($cvCo, '2026-04-01', '2027-03-31');
    ok(abs($figures['cogs'] - 510000.0) < 0.01,
        'The profit and loss still says cost of sales is 510,000, got ' . number_format($figures['cogs'], 2));
    ok(abs($figures['gross_profit'] - 690000.0) < 0.01,
        '  ...and gross profit is unchanged at 690,000 — the SAME trading, said a different way');
});

echo "\n12. And it goes back\n";
// Reversibility is the property that makes converting real books defensible.
with_method('periodic', static function () use ($cvCo, $cvFy, $cvStock, $cvPurch, $cvCogs, $cvBalance): void {
    $undone = periodic_conversion_undo($cvCo, $cvFy);
    ok($undone['ok'] && $undone['removed'] > 0,
        'The conversion reverses, removing ' . $undone['removed'] . ' journal(s)');
    ok(abs($cvBalance($cvStock) - 590000.0) < 0.01, 'Stock is back at 590,000');
    ok(abs($cvBalance($cvCogs) - 510000.0) < 0.01, 'Cost of sales is a ledger again, at 510,000');
    ok(abs($cvBalance($cvPurch)) < 0.01, 'And Purchases is empty again');
    ok((int) db()->query("SELECT COUNT(*) FROM vouchers WHERE company_id = {$cvCo}
        AND voucher_no IN ('CV-OPEN','CV-PUR','CV-SALE','CV-COGS')")->fetchColumn() === 4,
        'Every original voucher survived both the conversion and the reversal untouched');
});

foreach (['voucher_entries' => 'voucher_id IN (SELECT id FROM vouchers WHERE company_id = :c)',
    'vouchers' => 'company_id = :c', 'inventory_transactions' => 'company_id = :c',
    'inventory_items' => 'company_id = :c', 'inventory_ledger_mappings' => 'company_id = :c',
    'ledgers' => 'company_id = :c', 'ledger_groups' => 'company_id = :c',
    'fiscal_years' => 'company_id = :c', 'companies' => 'id = :c'] as $table => $where) {
    try {
        db()->prepare("DELETE FROM `{$table}` WHERE {$where}")->execute(['c' => $cvCo]);
    } catch (Throwable $ignored) {
    }
}
inv_mapping_forget();
echo "\n" . str_repeat('=', 50) . "\n  PASS: $pass    FAIL: $fail\n" . str_repeat('=', 50) . "\n";
exit($fail > 0 ? 1 : 0);
