<?php
declare(strict_types=1);

/**
 * A sales or purchase voucher moves the goods, not just the money.
 *
 * The whole point of naming an item on a voucher line is that the shelf and
 * the ledger stop being two separate stories somebody reconciles by hand at
 * the year end. So this suite follows real goods through a real company:
 * bought in, sold out, returned, corrected, deleted — checking on-hand, cost
 * layers, and what landed in the books each time.
 *
 * The division of labour it exists to protect: a purchase voucher already
 * carries both its legs, so it must NOT post a second voucher; a sale carries
 * only the revenue, so its cost MUST post as its own journal.
 *
 *   php database/test_voucher_stock.php
 */
if (PHP_SAPI !== 'cli') { exit('CLI only.'); }
$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';

// These assertions are about the PERPETUAL postings, so the suite says so
// rather than reading whatever the database happens to be set to -- and
// hands the setting back untouched when it finishes.
require_once __DIR__ . '/test_support_method.php';
test_pin_inventory_method('perpetual');
require_once $root . '/app/accounting_module_repair.php';
accounting_module_repair_database();

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }
function near(float $a, float $b, float $tolerance = 0.005): bool { return abs($a - $b) < $tolerance; }

function vst_cleanup(): void
{
    foreach (db()->query("SELECT id FROM companies WHERE code = 'VCHSTK'")->fetchAll(PDO::FETCH_COLUMN) as $companyId) {
        $companyId = (int) $companyId;
        db()->exec("DELETE FROM inventory_cost_layers WHERE company_id=$companyId");
        db()->exec("DELETE FROM inventory_transactions WHERE company_id=$companyId");
        db()->exec("DELETE FROM inventory_ledger_mappings WHERE company_id=$companyId");
        db()->exec("DELETE FROM inventory_items WHERE company_id=$companyId");
        db()->exec("DELETE FROM voucher_entries WHERE voucher_id IN (SELECT id FROM vouchers WHERE company_id=$companyId)");
        db()->exec("DELETE FROM vouchers WHERE company_id=$companyId");
        db()->exec("DELETE FROM accounting_parties WHERE company_id=$companyId");
        db()->exec("DELETE FROM ledgers WHERE company_id=$companyId");
        db()->exec("DELETE FROM ledger_groups WHERE company_id=$companyId");
        db()->exec("DELETE FROM fiscal_years WHERE company_id=$companyId");
        db()->exec("DELETE FROM companies WHERE id=$companyId");
    }
}
vst_cleanup();

// ---------------------------------------------------------------------------
// A trading company: a chart of accounts, a supplier, a customer, one item
// ---------------------------------------------------------------------------
db()->prepare('INSERT INTO companies (name, code, is_active) VALUES (:n, :c, 1)')
    ->execute(['n' => 'Voucher Stock Test Co', 'c' => 'VCHSTK']);
$cid = (int) db()->lastInsertId();
$fy = create_fiscal_year($cid, 'VST 2026-27', '2026-07-16', '2027-07-15', true);
$fyId = (int) $fy['id'];
db()->prepare("UPDATE fiscal_years SET status='open' WHERE id=?")->execute([$fyId]);

$groupInsert = db()->prepare('INSERT INTO ledger_groups (company_id, code, name, master_key, is_cash_or_bank, is_system) VALUES (:cid,:code,:name,:mk,:cb,1)');
$groups = [];
foreach ([
    ['BANK', 'Bank', 'current_asset', 1],
    ['RECEIVABLE', 'Trade Receivables', 'current_asset', 0],
    ['PAYABLE', 'Trade Payables', 'current_liability', 0],
    ['DUTIES_TAXES', 'Duties and Taxes', 'current_liability', 0],
    ['DIRECT_INCOME_GRP', 'Sales / Service Income', 'direct_income', 0],
    ['DIRECT_EXP_GRP', 'Direct Expenses', 'direct_expense', 0],
    ['STOCK_GRP', 'Stock in Hand', 'current_asset', 0],
] as [$code, $name, $master, $cashBank]) {
    $groupInsert->execute(['cid' => $cid, 'code' => $code, 'name' => $name, 'mk' => $master, 'cb' => $cashBank]);
    $groups[$code] = (int) db()->lastInsertId();
}

$ledgerInsert = db()->prepare("INSERT INTO ledgers (company_id, group_id, code, name, type, is_system, status) VALUES (:cid,:gid,:code,:name,:type,0,'active')");
$ledgers = [];
foreach ([
    ['BANK1', 'Nabil Bank', 'asset', 'BANK'],
    ['STOCK', 'Stock in hand', 'asset', 'STOCK_GRP'],
    ['VAT', 'VAT payable', 'liability', 'DUTIES_TAXES'],
    ['SALESINC', 'Sales', 'revenue', 'DIRECT_INCOME_GRP'],
    ['COGS', 'Cost of goods sold', 'expense', 'DIRECT_EXP_GRP'],
] as [$code, $name, $type, $group]) {
    $ledgerInsert->execute(['cid' => $cid, 'gid' => $groups[$group], 'code' => $code, 'name' => $name, 'type' => $type]);
    $ledgers[$code] = (int) db()->lastInsertId();
}

// The two mappings that let a sale post what its goods cost.
$mapInsert = db()->prepare("INSERT INTO inventory_ledger_mappings (company_id, scope, purpose, ledger_id) VALUES (:cid,'global',:p,:lid)");
$mapInsert->execute(['cid' => $cid, 'p' => 'inventory_asset', 'lid' => $ledgers['STOCK']]);
$mapInsert->execute(['cid' => $cid, 'p' => 'cogs', 'lid' => $ledgers['COGS']]);

$partyInsert = db()->prepare("INSERT INTO accounting_parties (company_id, code, name, party_type, status) VALUES (:cid,:code,:name,:type,'active')");
$partyInsert->execute(['cid' => $cid, 'code' => 'S-001', 'name' => 'Nepal Traders', 'type' => 'supplier']);
$supplierId = (int) db()->lastInsertId();
$partyInsert->execute(['cid' => $cid, 'code' => 'C-001', 'name' => 'Altiora Pvt Ltd', 'type' => 'customer']);
$customerId = (int) db()->lastInsertId();

db()->prepare("INSERT INTO inventory_items (company_id, sku, name, item_type, valuation_method, unit, tax_rate, sales_rate, purchase_rate, opening_qty, opening_amount, status)
    VALUES (:cid,'SKU-1','Ceiling fan','trading_good','fifo','pcs',13,4000,2500,0,0,'active')")->execute(['cid' => $cid]);
$itemId = (int) db()->lastInsertId();

$adminId = (int) db()->query("SELECT id FROM users WHERE role IN ('admin','super_admin') ORDER BY id LIMIT 1")->fetchColumn();
if ($adminId <= 0) { echo "No admin user in this database.\n"; vst_cleanup(); exit(1); }

$supplierLedgerId = ensure_party_ledger($cid, $supplierId, 'payable');
$customerLedgerId = ensure_party_ledger($cid, $customerId, 'receivable');

// ---------------------------------------------------------------------------
// Helpers that stand in for the form: compose, post, then sync the stock.
// ---------------------------------------------------------------------------
function vst_post(int $cid, int $fyId, string $type, array $input, int $adminId, string $date = '2026-08-01'): array
{
    $directory = voucher_ledger_directory($cid);
    $composed = voucher_compose($type, $input, $directory);
    if ($composed['errors'] !== []) {
        return ['id' => 0, 'errors' => $composed['errors'], 'notes' => []];
    }
    $preflight = voucher_stock_preflight($cid, $type, $composed['entries']);
    if ($preflight !== []) {
        return ['id' => 0, 'errors' => $preflight, 'notes' => []];
    }
    $voucherId = create_voucher_with_entries([
        'company_id' => $cid, 'fiscal_year_id' => $fyId,
        'voucher_no' => voucher_next_number($cid, $fyId, $type),
        'voucher_type' => $type, 'source_type' => 'voucher_form', 'source_id' => null,
        'party_id' => ((int) ($input['party_id'] ?? 0)) ?: null,
        'voucher_date' => $date, 'narration' => 'Stock test ' . $type,
        'total_amount' => $composed['total'], 'status' => 'posted', 'approval_state' => 'approved',
        'posted_by' => $adminId, 'posted_at' => date('Y-m-d H:i:s'),
    ], $composed['entries']);

    // What the form does after create_voucher_with_entries: match the per-line
    // dimensions back on by position.
    $lineStmt = db()->prepare('SELECT id FROM voucher_entries WHERE voucher_id = :vid ORDER BY id ASC');
    $lineStmt->execute(['vid' => $voucherId]);
    $lineUpdate = db()->prepare('UPDATE voucher_entries SET item_id = :item_id, quantity = :quantity WHERE id = :id');
    foreach ($lineStmt->fetchAll() as $index => $row) {
        $entry = $composed['entries'][$index] ?? null;
        if ($entry !== null) {
            $lineUpdate->execute([
                'item_id' => ((int) ($entry['item_id'] ?? 0)) ?: null,
                'quantity' => (float) ($entry['quantity'] ?? 0),
                'id' => (int) $row['id'],
            ]);
        }
    }

    $saved = db()->prepare('SELECT * FROM vouchers WHERE id = :id LIMIT 1');
    $saved->execute(['id' => $voucherId]);
    $voucher = $saved->fetch();

    return ['id' => $voucherId, 'errors' => [], 'notes' => voucher_stock_sync($cid, $fyId, $voucher, $adminId), 'voucher' => $voucher];
}

function vst_on_hand(int $cid, int $itemId): float
{
    $stmt = db()->prepare('SELECT i.opening_qty + COALESCE((SELECT SUM(t.qty_in - t.qty_out) FROM inventory_transactions t WHERE t.item_id = i.id), 0)
        FROM inventory_items i WHERE i.id = :id AND i.company_id = :cid');
    $stmt->execute(['id' => $itemId, 'cid' => $cid]);

    return (float) $stmt->fetchColumn();
}

/** What a ledger has been debited (net of credits) across posted vouchers. */
function vst_ledger_net(int $cid, int $ledgerId): float
{
    $stmt = db()->prepare("SELECT COALESCE(SUM(CASE WHEN ve.entry_type='debit' THEN ve.amount ELSE -ve.amount END), 0)
        FROM voucher_entries ve INNER JOIN vouchers v ON v.id = ve.voucher_id
        WHERE v.company_id = :cid AND ve.ledger_id = :lid AND v.status = 'posted'");
    $stmt->execute(['cid' => $cid, 'lid' => $ledgerId]);

    return round((float) $stmt->fetchColumn(), 2);
}

function vst_cost_vouchers(int $cid): int
{
    $stmt = db()->prepare("SELECT COUNT(*) FROM vouchers WHERE company_id = :cid AND source_type = 'inventory_movement'");
    $stmt->execute(['cid' => $cid]);

    return (int) $stmt->fetchColumn();
}

// ---------------------------------------------------------------------------
echo "1. A purchase raises the stock and the payable, and nothing twice\n";
// ---------------------------------------------------------------------------
$purchase = vst_post($cid, $fyId, 'purchase', [
    'settlement_mode' => 'party', 'settlement_ledger_id' => $supplierLedgerId, 'party_id' => $supplierId,
    'value_ledger' => [$ledgers['STOCK']], 'value_item' => [$itemId],
    'value_qty' => ['10'], 'value_rate' => ['2500'], 'value_amount' => ['25000'],
    'tax_mode' => 'exclusive', 'tax_rate' => '13', 'tax_ledger_id' => $ledgers['VAT'],
    'reference_no' => 'NT/2082/0001',
], $adminId, '2026-08-01');
ok($purchase['id'] > 0, 'A purchase of 10 fans posts' . ($purchase['errors'] !== [] ? ' — ' . implode('; ', $purchase['errors']) : ''));
ok(near(vst_on_hand($cid, $itemId), 10.0), 'Ten fans are on the shelf');
ok(near(vst_ledger_net($cid, $ledgers['STOCK']), 25000.0), 'Stock in hand carries 25,000');
ok(near(vst_ledger_net($cid, $supplierLedgerId), -28250.0), 'The supplier is owed 28,250 — once, not twice');
ok(vst_cost_vouchers($cid) === 0, 'A purchase posts no second voucher: its own entry already had both legs');

$layer = db()->prepare('SELECT SUM(qty_remaining) AS qty, SUM(qty_remaining * unit_cost) AS value FROM inventory_cost_layers WHERE company_id = :cid AND item_id = :iid');
$layer->execute(['cid' => $cid, 'iid' => $itemId]);
$layerRow = $layer->fetch();
ok(near((float) $layerRow['qty'], 10.0) && near((float) $layerRow['value'], 25000.0),
    'And the cost layers hold ten fans at 2,500 — the value before tax, not after');

// ---------------------------------------------------------------------------
echo "\n2. A sale takes the stock out and posts what it cost\n";
// ---------------------------------------------------------------------------
$sale = vst_post($cid, $fyId, 'sales', [
    'settlement_mode' => 'party', 'settlement_ledger_id' => $customerLedgerId, 'party_id' => $customerId,
    'value_ledger' => [$ledgers['SALESINC']], 'value_item' => [$itemId],
    'value_qty' => ['4'], 'value_rate' => ['4000'], 'value_amount' => ['16000'],
    'tax_mode' => 'exclusive', 'tax_rate' => '13', 'tax_ledger_id' => $ledgers['VAT'],
    'reference_no' => 'INV-0001',
], $adminId, '2026-08-05');
ok($sale['id'] > 0, 'A sale of 4 fans posts' . ($sale['errors'] !== [] ? ' — ' . implode('; ', $sale['errors']) : ''));
ok(near(vst_on_hand($cid, $itemId), 6.0), 'Six fans are left');
ok(near(vst_ledger_net($cid, $ledgers['SALESINC']), -16000.0), 'Revenue is 16,000 — the price, not the cost');
ok(near(vst_ledger_net($cid, $customerLedgerId), 18080.0), 'The customer owes 18,080');
ok(vst_cost_vouchers($cid) === 1, 'The cost of the sale posts as its own journal');
ok(near(vst_ledger_net($cid, $ledgers['COGS']), 10000.0), 'Cost of goods sold is 4 × 2,500 = 10,000');
ok(near(vst_ledger_net($cid, $ledgers['STOCK']), 15000.0), 'Stock in hand drops to 15,000');

$costVoucher = db()->prepare("SELECT v.* FROM vouchers v INNER JOIN inventory_transactions t ON t.voucher_id = v.id
    WHERE t.source_voucher_id = :vid AND v.source_type = 'inventory_movement' LIMIT 1");
$costVoucher->execute(['vid' => $sale['id']]);
ok($costVoucher->fetch() !== false, 'And that journal is tied to the movement the sale caused');

// ---------------------------------------------------------------------------
echo "\n3. Stock that is not there cannot be sold\n";
// ---------------------------------------------------------------------------
$oversold = vst_post($cid, $fyId, 'sales', [
    'settlement_mode' => 'party', 'settlement_ledger_id' => $customerLedgerId, 'party_id' => $customerId,
    'value_ledger' => [$ledgers['SALESINC']], 'value_item' => [$itemId],
    'value_qty' => ['99'], 'value_rate' => ['4000'], 'value_amount' => ['396000'],
    'tax_mode' => 'none',
], $adminId, '2026-08-06');
ok($oversold['id'] === 0, 'Selling 99 fans out of 6 is refused before anything is written');
ok($oversold['errors'] !== [] && str_contains($oversold['errors'][0], 'on hand'), 'And the refusal says how many there actually are');
ok(near(vst_on_hand($cid, $itemId), 6.0), 'The shelf is untouched by the attempt');

$noQty = vst_post($cid, $fyId, 'sales', [
    'settlement_mode' => 'party', 'settlement_ledger_id' => $customerLedgerId,
    'value_ledger' => [$ledgers['SALESINC']], 'value_item' => [$itemId],
    'value_qty' => [''], 'value_amount' => ['4000'], 'tax_mode' => 'none',
], $adminId, '2026-08-06');
ok($noQty['id'] === 0, 'An item named with no quantity is refused rather than silently ignored');

// ---------------------------------------------------------------------------
echo "\n4. A credit note brings the goods back at what they cost\n";
// ---------------------------------------------------------------------------
$creditNote = vst_post($cid, $fyId, 'credit_note', [
    'settlement_mode' => 'party', 'settlement_ledger_id' => $customerLedgerId, 'party_id' => $customerId,
    'value_ledger' => [$ledgers['SALESINC']], 'value_item' => [$itemId],
    'value_qty' => ['1'], 'value_rate' => ['4000'], 'value_amount' => ['4000'],
    'tax_mode' => 'exclusive', 'tax_rate' => '13', 'tax_ledger_id' => $ledgers['VAT'],
    'return_reason' => 'Fan returned, blade bent',
], $adminId, '2026-08-08');
ok($creditNote['id'] > 0, 'A sales return posts' . ($creditNote['errors'] !== [] ? ' — ' . implode('; ', $creditNote['errors']) : ''));
ok(near(vst_on_hand($cid, $itemId), 7.0), 'The fan is back on the shelf');
ok(near(vst_ledger_net($cid, $ledgers['COGS']), 7500.0), 'Its cost comes back out of COGS at 2,500, not at the 4,000 it sold for');
ok(near(vst_ledger_net($cid, $ledgers['SALESINC']), -12000.0), 'And the revenue is reduced by the price it sold for');

// ---------------------------------------------------------------------------
echo "\n5. A debit note returns goods to the supplier\n";
// ---------------------------------------------------------------------------
$debitNote = vst_post($cid, $fyId, 'debit_note', [
    'settlement_mode' => 'party', 'settlement_ledger_id' => $supplierLedgerId, 'party_id' => $supplierId,
    'value_ledger' => [$ledgers['STOCK']], 'value_item' => [$itemId],
    'value_qty' => ['2'], 'value_rate' => ['2500'], 'value_amount' => ['5000'],
    'tax_mode' => 'exclusive', 'tax_rate' => '13', 'tax_ledger_id' => $ledgers['VAT'],
    'return_reason' => 'Two fans damaged in transit',
    'reference_no' => 'NT/2082/0001',
], $adminId, '2026-08-09');
ok($debitNote['id'] > 0, 'A purchase return posts' . ($debitNote['errors'] !== [] ? ' — ' . implode('; ', $debitNote['errors']) : ''));
ok(near(vst_on_hand($cid, $itemId), 5.0), 'Five fans are left');
ok(vst_cost_vouchers($cid) === 2, 'It posts no cost journal of its own — only the two the sales side made');

// ---------------------------------------------------------------------------
echo "\n6. Deleting the voucher gives the goods back\n";
// ---------------------------------------------------------------------------
$beforeDelete = vst_on_hand($cid, $itemId);
$costVouchersBefore = vst_cost_vouchers($cid);
$deleted = delete_voucher_with_entries($sale['id'], $cid, $adminId);
ok($deleted['ok'] === true, 'The sale can be deleted' . ($deleted['ok'] ? '' : ' — ' . (string) $deleted['error']));
ok(near(vst_on_hand($cid, $itemId), $beforeDelete + 4.0), 'The four fans it took out are back');
ok(vst_cost_vouchers($cid) === $costVouchersBefore - 1, 'And the cost journal it posted went with it');
ok(near(vst_ledger_net($cid, $ledgers['COGS']), -2500.0), 'COGS keeps only the credit note the sale no longer offsets');

$orphans = db()->prepare('SELECT COUNT(*) FROM inventory_transactions WHERE company_id = :cid AND source_voucher_id = :vid');
$orphans->execute(['cid' => $cid, 'vid' => $sale['id']]);
ok((int) $orphans->fetchColumn() === 0, 'No stock movement is left pointing at a voucher that is gone');

$layer->execute(['cid' => $cid, 'iid' => $itemId]);
$layerRow = $layer->fetch();
ok(near((float) $layerRow['qty'], vst_on_hand($cid, $itemId), 0.01),
    'The cost layers were replayed, so they agree with the shelf (' . (float) $layerRow['qty'] . ')');

// ---------------------------------------------------------------------------
echo "\n7. A draft holds no stock until it is posted\n";
// ---------------------------------------------------------------------------
$directory = voucher_ledger_directory($cid);
$draftComposed = voucher_compose('purchase', [
    'settlement_mode' => 'party', 'settlement_ledger_id' => $supplierLedgerId,
    'value_ledger' => [$ledgers['STOCK']], 'value_item' => [$itemId],
    'value_qty' => ['3'], 'value_rate' => ['2500'], 'value_amount' => ['7500'], 'tax_mode' => 'none',
], $directory, true);
$draftId = create_voucher_with_entries([
    'company_id' => $cid, 'fiscal_year_id' => $fyId,
    'voucher_no' => voucher_next_number($cid, $fyId, 'purchase'),
    'voucher_type' => 'purchase', 'source_type' => 'voucher_form', 'source_id' => null,
    'voucher_date' => '2026-08-10', 'narration' => 'Drafted purchase',
    'total_amount' => $draftComposed['total'], 'status' => 'draft', 'approval_state' => 'draft',
], $draftComposed['entries']);
$lineStmt = db()->prepare('SELECT id FROM voucher_entries WHERE voucher_id = :vid ORDER BY id ASC');
$lineStmt->execute(['vid' => $draftId]);
$lineUpdate = db()->prepare('UPDATE voucher_entries SET item_id = :item_id, quantity = :quantity WHERE id = :id');
foreach ($lineStmt->fetchAll() as $index => $row) {
    $entry = $draftComposed['entries'][$index] ?? null;
    if ($entry !== null) {
        $lineUpdate->execute([
            'item_id' => ((int) ($entry['item_id'] ?? 0)) ?: null,
            'quantity' => (float) ($entry['quantity'] ?? 0),
            'id' => (int) $row['id'],
        ]);
    }
}
$draftStmt = db()->prepare('SELECT * FROM vouchers WHERE id = :id LIMIT 1');
$draftStmt->execute(['id' => $draftId]);
$draftVoucher = $draftStmt->fetch();
$onHandBeforeDraft = vst_on_hand($cid, $itemId);
voucher_stock_sync($cid, $fyId, $draftVoucher, $adminId);
ok(near(vst_on_hand($cid, $itemId), $onHandBeforeDraft), 'A drafted purchase moves nothing — the goods are not here yet');

// Approving it is what brings them in.
db()->prepare("UPDATE vouchers SET status='posted', approval_state='approved' WHERE id = :id")->execute(['id' => $draftId]);
$draftStmt->execute(['id' => $draftId]);
voucher_stock_sync($cid, $fyId, $draftStmt->fetch(), $adminId);
ok(near(vst_on_hand($cid, $itemId), $onHandBeforeDraft + 3.0), 'Approving it brings the three fans in');

// And sending it back to draft takes them out again.
db()->prepare("UPDATE vouchers SET status='draft', approval_state='draft' WHERE id = :id")->execute(['id' => $draftId]);
$draftStmt->execute(['id' => $draftId]);
voucher_stock_sync($cid, $fyId, $draftStmt->fetch(), $adminId);
ok(near(vst_on_hand($cid, $itemId), $onHandBeforeDraft), 'Sending it back to draft takes them out again');

// ---------------------------------------------------------------------------
echo "\n8. Syncing twice is the same as syncing once\n";
// ---------------------------------------------------------------------------
$purchaseStmt = db()->prepare('SELECT * FROM vouchers WHERE id = :id LIMIT 1');
$purchaseStmt->execute(['id' => $purchase['id']]);
$purchaseVoucher = $purchaseStmt->fetch();
$onHandBefore = vst_on_hand($cid, $itemId);
$stockLedgerBefore = vst_ledger_net($cid, $ledgers['STOCK']);
voucher_stock_sync($cid, $fyId, $purchaseVoucher, $adminId);
voucher_stock_sync($cid, $fyId, $purchaseVoucher, $adminId);
ok(near(vst_on_hand($cid, $itemId), $onHandBefore), 'Re-syncing a purchase twice does not import the goods twice');
ok(near(vst_ledger_net($cid, $ledgers['STOCK']), $stockLedgerBefore), 'And the books are unchanged by it');

$movements = db()->prepare('SELECT COUNT(*) FROM inventory_transactions WHERE company_id = :cid AND source_voucher_id = :vid');
$movements->execute(['cid' => $cid, 'vid' => $purchase['id']]);
ok((int) $movements->fetchColumn() === 1, 'One voucher line still means one stock movement');

// ---------------------------------------------------------------------------
echo "\n9. A voucher with no items behaves exactly as it did before\n";
// ---------------------------------------------------------------------------
$movementsBefore = (int) db()->query("SELECT COUNT(*) FROM inventory_transactions WHERE company_id = $cid")->fetchColumn();
$serviceSale = vst_post($cid, $fyId, 'sales', [
    'settlement_mode' => 'party', 'settlement_ledger_id' => $customerLedgerId, 'party_id' => $customerId,
    'value_ledger' => [$ledgers['SALESINC']], 'value_amount' => ['9000'],
    'tax_mode' => 'exclusive', 'tax_rate' => '13', 'tax_ledger_id' => $ledgers['VAT'],
], $adminId, '2026-08-12');
ok($serviceSale['id'] > 0, 'A sale with no stock item posts');
$movementsAfter = (int) db()->query("SELECT COUNT(*) FROM inventory_transactions WHERE company_id = $cid")->fetchColumn();
ok($movementsAfter === $movementsBefore, 'And moves no stock at all');
ok($serviceSale['notes'] === [], 'With nothing to report about goods');

// ---------------------------------------------------------------------------
vst_cleanup();
echo "\n" . str_repeat('-', 60) . "\n";
echo "  $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
