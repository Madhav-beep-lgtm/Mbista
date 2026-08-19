<?php
declare(strict_types=1);

/**
 * Each voucher type posts what that type means.
 *
 * The composer is pure — it takes a tagged ledger directory and the fields one
 * screen submits, and returns debits and credits. So the whole of the accounting
 * behaviour of the eight specialised screens can be asserted here without a
 * database, a session, or a browser:
 *
 *   php database/test_voucher_types.php
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only.');
}

require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/voucher_types.php';

$pass = 0;
$fail = 0;
function ok(bool $condition, string $label): void
{
    global $pass, $fail;
    if ($condition) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label\n"; }
}
function near(float $a, float $b): bool { return abs($a - $b) < 0.005; }

/** The debit or credit posted to one ledger, 0.0 when it was not touched. */
function side_of(array $entries, int $ledgerId, string $side): float
{
    $total = 0.0;
    foreach ($entries as $entry) {
        if ((int) $entry['ledger_id'] === $ledgerId && (string) $entry['entry_type'] === $side) {
            $total += (float) $entry['amount'];
        }
    }

    return round($total, 2);
}

function balanced(array $entries): bool
{
    $net = 0.0;
    foreach ($entries as $entry) {
        $net += (string) $entry['entry_type'] === 'debit' ? (float) $entry['amount'] : -(float) $entry['amount'];
    }

    return abs(round($net, 2)) < 0.005;
}

// ---------------------------------------------------------------------------
// A small chart of accounts, tagged the way voucher_ledger_directory tags one.
// ---------------------------------------------------------------------------
$rawLedgers = [
    1 => ['name' => 'Cash in hand', 'code' => 'CASH', 'group_id' => 10, 'group_code' => 'CASH_GRP', 'group_name' => 'Cash in Hand', 'master_key' => 'current_asset', 'is_cash_or_bank' => 1],
    2 => ['name' => 'Nabil Bank', 'code' => 'BANK1', 'group_id' => 11, 'group_code' => 'BANK', 'group_name' => 'Bank', 'master_key' => 'current_asset', 'is_cash_or_bank' => 1],
    3 => ['name' => 'Altiora Pvt Ltd', 'code' => 'AR-001', 'group_id' => 12, 'group_code' => 'RECEIVABLE', 'group_name' => 'Trade Receivables', 'master_key' => 'current_asset', 'is_cash_or_bank' => 0],
    4 => ['name' => 'Nepal Traders', 'code' => 'AP-001', 'group_id' => 13, 'group_code' => 'PAYABLE', 'group_name' => 'Trade Payables', 'master_key' => 'current_liability', 'is_cash_or_bank' => 0],
    5 => ['name' => 'Consultancy income', 'code' => 'INC-1', 'group_id' => 14, 'group_code' => 'DIRECT_INCOME_GRP', 'group_name' => 'Sales / Service Income', 'master_key' => 'direct_income', 'is_cash_or_bank' => 0],
    6 => ['name' => 'Office rent', 'code' => 'EXP-1', 'group_id' => 15, 'group_code' => 'ADMIN_EXP', 'group_name' => 'Administrative Expenses', 'master_key' => 'indirect_expense', 'is_cash_or_bank' => 0],
    7 => ['name' => 'VAT payable', 'code' => 'VAT', 'group_id' => 16, 'group_code' => 'DUTIES_TAXES', 'group_name' => 'Duties and Taxes', 'master_key' => 'current_liability', 'is_cash_or_bank' => 0],
    8 => ['name' => 'Depreciation', 'code' => 'EXP-2', 'group_id' => 15, 'group_code' => 'ADMIN_EXP', 'group_name' => 'Administrative Expenses', 'master_key' => 'indirect_expense', 'is_cash_or_bank' => 0],
    9 => ['name' => 'Accumulated depreciation', 'code' => 'AD', 'group_id' => 17, 'group_code' => 'FIXED', 'group_name' => 'Fixed Assets', 'master_key' => 'non_current_asset', 'is_cash_or_bank' => 0],
];
$ledgers = [];
foreach ($rawLedgers as $id => $row) {
    $row['id'] = $id;
    $row['roles'] = voucher_ledger_roles($row, 12, 13);
    $ledgers[$id] = $row;
}

const CASH = 1;
const BANK = 2;
const CUSTOMER = 3;
const SUPPLIER = 4;
const INCOME = 5;
const RENT = 6;
const VAT = 7;
const DEPRECIATION = 8;
const ACC_DEPRECIATION = 9;

// ---------------------------------------------------------------------------
echo "1. The catalogue covers every type the vouchers table can hold\n";
// ---------------------------------------------------------------------------
$catalog = voucher_type_catalog();
ok(count($catalog) === 8, 'Eight voucher types are defined');
foreach (['payment', 'receipt', 'journal', 'sales', 'purchase', 'contra', 'debit_note', 'credit_note'] as $enumValue) {
    ok(isset($catalog[$enumValue]), "The vouchers ENUM value '$enumValue' has a screen");
}
$prefixes = array_column($catalog, 'prefix');
ok(count(array_unique($prefixes)) === 8, 'Every type numbers into its own series');
$hotkeys = array_column($catalog, 'hotkey');
ok(count(array_unique($hotkeys)) === 8, 'No two types answer the same shortcut');
ok(voucher_type_spec('nonsense')['key'] === 'journal', 'An unknown type falls back to the journal screen');
ok(voucher_type_spec('sales')['value_side'] === 'credit' && voucher_type_spec('sales')['tax_side'] === 'credit',
    'On a sale the value and the tax both sit opposite the customer');
ok(voucher_type_spec('purchase')['value_side'] === 'debit', 'On a purchase the value sits opposite the supplier');

// ---------------------------------------------------------------------------
echo "\n2. Ledgers are offered only where they belong\n";
// ---------------------------------------------------------------------------
ok($ledgers[CASH]['roles']['cash_bank'] && $ledgers[BANK]['roles']['cash_bank'], 'Cash and bank carry the cash/bank role');
ok(!$ledgers[INCOME]['roles']['cash_bank'], 'An income ledger does not');
ok($ledgers[CUSTOMER]['roles']['receivable'] && $ledgers[SUPPLIER]['roles']['payable'], 'Party ledgers are tagged by their group');
ok($ledgers[VAT]['roles']['tax'], 'Duties and Taxes is recognised as the tax group');
ok(count(voucher_ledgers_for_role($ledgers, 'cash_bank')) === 2, 'The cash/bank slot offers exactly the two bank accounts');
ok(count(voucher_ledgers_for_role($ledgers, 'income')) === 1, 'A sales voucher offers only income ledgers');
$expenseSlot = array_column(voucher_ledgers_for_role($ledgers, 'expense'), 'id');
ok(in_array(RENT, $expenseSlot, true) && in_array(ACC_DEPRECIATION, $expenseSlot, true) && !in_array(CASH, $expenseSlot, true),
    'A purchase offers expenses and assets, but never the bank it is paid from');

// ---------------------------------------------------------------------------
echo "\n3. Contra moves money between our own accounts, and nothing else\n";
// ---------------------------------------------------------------------------
$result = voucher_compose('contra', [
    'contra_from_ledger' => CASH,
    'contra_to_ledger' => BANK,
    'contra_amount' => '25000',
    'instrument_type' => 'cash',
    'instrument_no' => 'DEP-91',
], $ledgers);
ok($result['errors'] === [], 'Cash deposited into the bank composes cleanly');
ok(near(side_of($result['entries'], BANK, 'debit'), 25000.00), 'The receiving bank is debited');
ok(near(side_of($result['entries'], CASH, 'credit'), 25000.00), 'The cash it came from is credited');
ok(balanced($result['entries']), 'And the two sides balance');
ok($result['header']['instrument_no'] === 'DEP-91', 'The deposit slip number is kept on the header');

$result = voucher_compose('contra', ['contra_from_ledger' => CASH, 'contra_to_ledger' => INCOME, 'contra_amount' => '500'], $ledgers);
ok($result['errors'] !== [] && $result['entries'] === [], 'Income cannot be the far side of a contra');

$result = voucher_compose('contra', ['contra_from_ledger' => BANK, 'contra_to_ledger' => BANK, 'contra_amount' => '500'], $ledgers);
ok($result['errors'] !== [], 'Money cannot move to the account it is already in');

// ---------------------------------------------------------------------------
echo "\n4. Payment: one bank line, or several when the settlement was mixed\n";
// ---------------------------------------------------------------------------
$result = voucher_compose('payment', [
    'tender_ledger' => [BANK],
    'tender_amount' => ['45000'],
    'tender_mode' => ['cheque'],
    'tender_instrument_no' => ['004312'],
    'line_ledger' => [RENT],
    'line_amount' => ['45000'],
    'line_memo' => ['Shrawan rent'],
], $ledgers);
ok($result['errors'] === [], 'A single-bank payment composes cleanly');
ok(near(side_of($result['entries'], RENT, 'debit'), 45000.00), 'What the money paid for is debited');
ok(near(side_of($result['entries'], BANK, 'credit'), 45000.00), 'The bank it left is credited');
ok(balanced($result['entries']), 'And it balances');

// One payment settled three ways at once — the case a single "mode" field
// cannot express, and the one a jeweller's counter meets every day.
$result = voucher_compose('payment', [
    'tender_ledger' => [CASH, BANK],
    'tender_amount' => ['15000', '30000'],
    'tender_mode' => ['cash', 'bank_transfer'],
    'tender_instrument_no' => ['', 'TXN-7781'],
    'line_ledger' => [RENT, SUPPLIER],
    'line_amount' => ['20000', '25000'],
], $ledgers);
ok($result['errors'] === [], 'A payment settled part cash, part transfer composes cleanly');
ok(near(side_of($result['entries'], CASH, 'credit'), 15000.00) && near(side_of($result['entries'], BANK, 'credit'), 30000.00),
    'Each tender line credits its own account for its own amount');
ok(near(side_of($result['entries'], RENT, 'debit') + side_of($result['entries'], SUPPLIER, 'debit'), 45000.00),
    'And the whole 45,000 lands on what it was for');
ok(balanced($result['entries']), 'A mixed-tender payment still balances');
ok($result['header']['instrument_type'] === 'cash', 'The header keeps the first mode of a mixed payment');

$result = voucher_compose('payment', [
    'tender_ledger' => [RENT], 'tender_amount' => ['1000'],
    'line_ledger' => [SUPPLIER], 'line_amount' => ['1000'],
], $ledgers);
ok($result['errors'] !== [], 'Money cannot be paid out of an expense ledger');

$result = voucher_compose('payment', [
    'tender_ledger' => [BANK], 'tender_amount' => ['1000'],
    'line_ledger' => [RENT], 'line_amount' => ['900'],
], $ledgers);
ok($result['errors'] !== [], 'A payment whose two sides disagree is refused');

// ---------------------------------------------------------------------------
echo "\n5. Receipt is the mirror of payment\n";
// ---------------------------------------------------------------------------
$result = voucher_compose('receipt', [
    'tender_ledger' => [BANK],
    'tender_amount' => ['118000'],
    'tender_mode' => ['bank_transfer'],
    'line_ledger' => [CUSTOMER],
    'line_amount' => ['118000'],
    'line_reference' => ['INV-0142'],
], $ledgers);
ok($result['errors'] === [], 'A collection against an invoice composes cleanly');
ok(near(side_of($result['entries'], BANK, 'debit'), 118000.00), 'The bank that took the money in is debited');
ok(near(side_of($result['entries'], CUSTOMER, 'credit'), 118000.00), 'The customer who owed it is credited');
$customerLine = null;
foreach ($result['entries'] as $entry) {
    if ((int) $entry['ledger_id'] === CUSTOMER) { $customerLine = $entry; }
}
ok(($customerLine['line_reference'] ?? '') === 'INV-0142', 'The invoice it settles is kept on the line');

// ---------------------------------------------------------------------------
echo "\n6. Sales: the value is typed, the tax and the party line are worked out\n";
// ---------------------------------------------------------------------------
$result = voucher_compose('sales', [
    'settlement_mode' => 'party',
    'settlement_ledger_id' => CUSTOMER,
    'value_ledger' => [INCOME],
    'value_amount' => ['100000'],
    'value_description' => ['Consultancy'],
    'tax_mode' => 'exclusive',
    'tax_rate' => '13',
    'tax_ledger_id' => VAT,
    'reference_no' => 'INV-0143',
    'reference_date' => '2026-07-20',
], $ledgers);
ok($result['errors'] === [], 'A credit sale composes cleanly');
ok(near(side_of($result['entries'], INCOME, 'credit'), 100000.00), 'Income is credited with the taxable value only');
ok(near(side_of($result['entries'], VAT, 'credit'), 13000.00), 'VAT at 13% is credited as its own line');
ok(near(side_of($result['entries'], CUSTOMER, 'debit'), 113000.00), 'The customer is debited with value plus tax');
ok(balanced($result['entries']), 'And the sale balances');
ok($result['header']['reference_date'] === '2026-07-20', 'The invoice date is kept');

// Tax already inside the figure: the same 113,000 must split the same way.
$result = voucher_compose('sales', [
    'settlement_mode' => 'party', 'settlement_ledger_id' => CUSTOMER,
    'value_ledger' => [INCOME], 'value_amount' => ['113000'],
    'tax_mode' => 'inclusive', 'tax_rate' => '13', 'tax_ledger_id' => VAT,
], $ledgers);
ok(near(side_of($result['entries'], INCOME, 'credit'), 100000.00), 'A tax-inclusive amount is peeled back to its taxable value');
ok(near(side_of($result['entries'], VAT, 'credit'), 13000.00), 'And the tax inside it is posted separately');
ok(balanced($result['entries']), 'A tax-inclusive sale balances too');

// The rounding case: three lines whose individual taxes each round, and a
// party total that must equal exactly what was posted, not what was typed.
$result = voucher_compose('sales', [
    'settlement_mode' => 'party', 'settlement_ledger_id' => CUSTOMER,
    'value_ledger' => [INCOME, INCOME, INCOME],
    'value_amount' => ['33.33', '33.33', '33.34'],
    'tax_mode' => 'exclusive', 'tax_rate' => '13', 'tax_ledger_id' => VAT,
], $ledgers);
ok(balanced($result['entries']), 'Three lines that each round still balance to the paisa');
ok(near($result['total'], side_of($result['entries'], CUSTOMER, 'debit')), 'The receivable equals what was actually posted');

// A cash sale settles into the till, not into a receivable.
$result = voucher_compose('sales', [
    'settlement_mode' => 'cash', 'settlement_ledger_id' => CASH,
    'value_ledger' => [INCOME], 'value_amount' => ['5000'],
    'tax_mode' => 'none', 'tax_rate' => '0',
], $ledgers);
ok($result['errors'] === [] && near(side_of($result['entries'], CASH, 'debit'), 5000.00), 'A cash sale debits the till');
ok(side_of($result['entries'], VAT, 'credit') === 0.0, 'With no tax, no tax line is posted');

$result = voucher_compose('sales', [
    'settlement_mode' => 'cash', 'settlement_ledger_id' => INCOME,
    'value_ledger' => [INCOME], 'value_amount' => ['5000'], 'tax_mode' => 'none',
], $ledgers);
ok($result['errors'] !== [], 'A cash sale cannot settle into an income ledger');

// ---------------------------------------------------------------------------
echo "\n6b. A sale settled several ways posts one line per way\n";
// ---------------------------------------------------------------------------
$settlementSlot = array_column(voucher_ledgers_for_role($ledgers, 'settlement'), 'id');
ok(in_array(CASH, $settlementSlot, true) && in_array(CUSTOMER, $settlementSlot, true) && !in_array(INCOME, $settlementSlot, true),
    'The settlement slot offers tills and party accounts, never an income head');
ok(isset(voucher_instrument_modes()['fonepay']) && isset(voucher_instrument_modes()['qr']),
    'Fonepay and a QR scan are modes in their own right, not "online"');
ok(voucher_instrument_key_for_label('Wallet / QR') === 'wallet',
    'A mode renamed since it was posted still reads back to its own key');
ok(voucher_instrument_key_for_label('Fonepay') === 'fonepay', 'And a current one reads back too');

// The counter case this whole mode exists for: one day's takings, arriving
// three ways, none of which can be made to stand for the others.
$result = voucher_compose('sales', [
    'settlement_mode' => 'split',
    'settlement_party_ledger_id' => CUSTOMER,
    'settle_ledger' => [CASH, BANK, 'party'],
    'settle_mode' => ['cash', 'fonepay', 'credit'],
    'settle_instrument_no' => ['', 'FP-99120', ''],
    'settle_amount' => ['3000', '5000', '3300'],
    'value_ledger' => [INCOME],
    'value_amount' => ['10000'],
    'tax_mode' => 'exclusive', 'tax_rate' => '13', 'tax_ledger_id' => VAT,
    'reference_no' => 'INV-0144',
], $ledgers);
ok($result['errors'] === [], 'A sale taken three ways composes cleanly');
ok(near(side_of($result['entries'], INCOME, 'credit'), 10000.00), 'Income is still credited once, with the taxable value');
ok(near(side_of($result['entries'], VAT, 'credit'), 1300.00), 'And the VAT is still one line');
ok(near(side_of($result['entries'], CASH, 'debit'), 3000.00), 'The till takes only what went into the till');
ok(near(side_of($result['entries'], BANK, 'debit'), 5000.00), 'The bank takes only the Fonepay part');
ok(near(side_of($result['entries'], CUSTOMER, 'debit'), 3300.00), 'And only the rest is left owing');
ok(balanced($result['entries']), 'A split sale balances');
ok(near($result['total'], 11300.00), 'Its total is still the invoice total, not one of the parts');
ok(($result['header']['instrument_type'] ?? '') === 'cash', 'The register keeps the first mode that actually moved money');

$fonepayMemo = '';
foreach ($result['entries'] as $entry) {
    if ((int) $entry['ledger_id'] === BANK) { $fonepayMemo = (string) $entry['memo']; }
}
ok(strpos($fonepayMemo, 'Fonepay') === 0 && strpos($fonepayMemo, 'FP-99120') !== false,
    'How the money came in is written onto its own line, for reconciling later');

// The rule the split cannot bend.
$result = voucher_compose('sales', [
    'settlement_mode' => 'split', 'settlement_party_ledger_id' => CUSTOMER,
    'settle_ledger' => [CASH], 'settle_mode' => ['cash'], 'settle_amount' => ['3000'],
    'value_ledger' => [INCOME], 'value_amount' => ['10000'], 'tax_mode' => 'none',
], $ledgers);
ok($result['errors'] !== [] && $result['entries'] === [], 'A split that leaves part of the bill unaccounted for is refused');

$result = voucher_compose('sales', [
    'settlement_mode' => 'split', 'settlement_party_ledger_id' => CUSTOMER,
    'settle_ledger' => [CASH], 'settle_mode' => ['cash'], 'settle_amount' => ['12000'],
    'value_ledger' => [INCOME], 'value_amount' => ['10000'], 'tax_mode' => 'none',
], $ledgers);
ok($result['errors'] !== [], 'And so is one that takes more than was billed');

$result = voucher_compose('sales', [
    'settlement_mode' => 'split',
    'settle_ledger' => [INCOME], 'settle_mode' => ['cash'], 'settle_amount' => ['10000'],
    'value_ledger' => [INCOME], 'value_amount' => ['10000'], 'tax_mode' => 'none',
], $ledgers);
ok($result['errors'] !== [], 'Money cannot be settled into an income head');

$result = voucher_compose('sales', [
    'settlement_mode' => 'split', 'settlement_party_ledger_id' => 0,
    'settle_ledger' => ['party'], 'settle_mode' => ['credit'], 'settle_amount' => ['10000'],
    'value_ledger' => [INCOME], 'value_amount' => ['10000'], 'tax_mode' => 'none',
], $ledgers);
ok($result['errors'] !== [], 'A line left on credit has to name who owes it');

// Money going the other way splits the same way.
$result = voucher_compose('purchase', [
    'settlement_mode' => 'split', 'settlement_party_ledger_id' => SUPPLIER,
    'settle_ledger' => [BANK, 'party'], 'settle_mode' => ['bank_transfer', 'credit'],
    'settle_amount' => ['1200', '800'],
    'value_ledger' => [RENT], 'value_amount' => ['2000'], 'tax_mode' => 'none',
], $ledgers);
ok($result['errors'] === [] && near(side_of($result['entries'], BANK, 'credit'), 1200.00)
    && near(side_of($result['entries'], SUPPLIER, 'credit'), 800.00),
    'A part-paid purchase credits the bank and the supplier for their own shares');
ok(balanced($result['entries']), 'And a part-paid purchase balances');

// It has to reopen as what it was, or editing it would flatten the split.
$composed = voucher_compose('sales', [
    'settlement_mode' => 'split', 'settlement_party_ledger_id' => CUSTOMER,
    'settle_ledger' => [CASH, 'party'], 'settle_mode' => ['cash', 'credit'],
    'settle_amount' => ['4000', '6000'],
    'value_ledger' => [INCOME], 'value_amount' => ['10000'], 'tax_mode' => 'none',
], $ledgers);
$back = voucher_decompose('sales', [], $composed['entries'], $ledgers);
ok($back['ok'] === true && $back['settlement_mode'] === 'split', 'A split sale reopens as a split');
ok(count($back['settlements']) === 2, 'With both of its settlement lines');
ok($back['settlements'][0]['mode'] === 'cash' && $back['settlements'][1]['mode'] === 'credit',
    'Each remembering how that part of the money came in');
ok(near((float) $back['settlements'][1]['amount'], 6000.00), 'And how much of it did');
ok(count($back['values']) === 1, 'The value line is not mistaken for a settlement');

$prefill = voucher_prefill_from_input('sales', [
    'settlement_mode' => 'split',
    'settle_ledger' => [CASH, 'party'], 'settle_mode' => ['cash', 'credit'],
    'settle_instrument_no' => ['', ''], 'settle_amount' => ['4000', '6000'],
]);
ok($prefill['settlement_mode'] === 'split' && count($prefill['settlements']) === 2,
    'A rejected split comes back with its lines still typed in');

// ---------------------------------------------------------------------------
echo "\n7. Purchase is the sale seen from the other side of the counter\n";
// ---------------------------------------------------------------------------
$result = voucher_compose('purchase', [
    'settlement_mode' => 'party', 'settlement_ledger_id' => SUPPLIER,
    'value_ledger' => [RENT], 'value_amount' => ['2000'], 'value_qty' => ['4'], 'value_rate' => ['500'],
    'tax_mode' => 'exclusive', 'tax_rate' => '13', 'tax_ledger_id' => VAT,
    'reference_no' => 'NT/2082/0091', 'reference_date' => '2026-07-18',
], $ledgers);
ok($result['errors'] === [], "A supplier's bill composes cleanly");
ok(near(side_of($result['entries'], RENT, 'debit'), 2000.00), 'What was bought is debited');
ok(near(side_of($result['entries'], VAT, 'debit'), 260.00), 'The VAT on it is debited, not credited');
ok(near(side_of($result['entries'], SUPPLIER, 'credit'), 2260.00), 'The supplier is credited with the whole bill');
ok(balanced($result['entries']), 'And the purchase balances');
$boughtLine = null;
foreach ($result['entries'] as $entry) {
    if ((int) $entry['ledger_id'] === RENT) { $boughtLine = $entry; }
}
ok(str_contains((string) ($boughtLine['memo'] ?? ''), '4 × 500.00'), 'Quantity and rate are written onto the line');

// ---------------------------------------------------------------------------
echo "\n8. Debit and credit notes reverse the document they answer\n";
// ---------------------------------------------------------------------------
$result = voucher_compose('debit_note', [
    'settlement_mode' => 'party', 'settlement_ledger_id' => SUPPLIER,
    'value_ledger' => [RENT], 'value_amount' => ['1000'],
    'tax_mode' => 'exclusive', 'tax_rate' => '13', 'tax_ledger_id' => VAT,
    'return_reason' => 'Damaged in transit',
    'reference_no' => 'NT/2082/0091',
], $ledgers);
ok($result['errors'] === [], 'A purchase return composes cleanly');
ok(near(side_of($result['entries'], SUPPLIER, 'debit'), 1130.00), 'A debit note debits the supplier — we owe them less');
ok(near(side_of($result['entries'], RENT, 'credit'), 1000.00), 'The purchase it reverses is credited back');
ok(near(side_of($result['entries'], VAT, 'credit'), 130.00), 'And the VAT claimed on it is given back');
ok($result['header']['return_reason'] === 'Damaged in transit', 'The reason is kept on the voucher');

$result = voucher_compose('debit_note', [
    'settlement_mode' => 'party', 'settlement_ledger_id' => SUPPLIER,
    'value_ledger' => [RENT], 'value_amount' => ['1000'], 'tax_mode' => 'none',
], $ledgers);
ok($result['errors'] !== [], 'A note without a reason is refused');

$result = voucher_compose('credit_note', [
    'settlement_mode' => 'party', 'settlement_ledger_id' => CUSTOMER,
    'value_ledger' => [INCOME], 'value_amount' => ['1000'],
    'tax_mode' => 'exclusive', 'tax_rate' => '13', 'tax_ledger_id' => VAT,
    'return_reason' => 'Sales return against INV-0142',
], $ledgers);
ok(near(side_of($result['entries'], CUSTOMER, 'credit'), 1130.00), 'A credit note credits the customer — they owe us less');
ok(near(side_of($result['entries'], INCOME, 'debit'), 1000.00), 'The income it reverses is debited back');
ok(balanced($result['entries']), 'And the credit note balances');

// ---------------------------------------------------------------------------
echo "\n9. Journal keeps both sides in the person's hands\n";
// ---------------------------------------------------------------------------
$result = voucher_compose('journal', [
    'ledger_id' => [DEPRECIATION, ACC_DEPRECIATION],
    'entry_type' => ['debit', 'credit'],
    'amount' => ['12500', '12500'],
    'memo' => ['Quarterly depreciation', ''],
], $ledgers);
ok($result['errors'] === [] && balanced($result['entries']), 'A balanced depreciation journal composes cleanly');
ok($result['warnings'] === [], 'And says nothing, because nothing is unusual about it');

$result = voucher_compose('journal', [
    'ledger_id' => [DEPRECIATION, ACC_DEPRECIATION],
    'entry_type' => ['debit', 'credit'],
    'amount' => ['12500', '12000'],
], $ledgers);
ok($result['errors'] !== [], 'An unbalanced journal is refused');

$result = voucher_compose('journal', [
    'ledger_id' => [BANK, INCOME],
    'entry_type' => ['debit', 'credit'],
    'amount' => ['1000', '1000'],
], $ledgers);
ok($result['errors'] === [], 'A journal touching the bank still posts');
ok($result['warnings'] !== [], 'But it says a receipt voucher would have been the better home');

// ---------------------------------------------------------------------------
echo "\n10. A draft keeps half-typed work; a posting never does\n";
// ---------------------------------------------------------------------------
$halfTyped = [
    'tender_ledger' => [BANK], 'tender_amount' => ['45000'],
    'line_ledger' => [RENT], 'line_amount' => ['20000'],
];
$posted = voucher_compose('payment', $halfTyped, $ledgers, false);
$draft = voucher_compose('payment', $halfTyped, $ledgers, true);
ok($posted['errors'] !== [], 'Posting a payment whose sides disagree is refused');
ok($draft['errors'] === [] && count($draft['entries']) === 2, 'Saving the same thing as a draft keeps both lines');

$draft = voucher_compose('sales', [
    'settlement_mode' => 'party', 'settlement_ledger_id' => 0,
    'value_ledger' => [INCOME], 'value_amount' => ['1000'], 'tax_mode' => 'none',
], $ledgers, true);
ok($draft['errors'] === [] && count($draft['entries']) === 1, 'A sale drafted before the customer is chosen keeps its value line');

$draft = voucher_compose('contra', ['contra_from_ledger' => CASH, 'contra_to_ledger' => INCOME, 'contra_amount' => '500'], $ledgers, true);
ok($draft['errors'] === [], 'A contra draft does not complain');
ok(side_of($draft['entries'], INCOME, 'debit') === 0.0, 'But it still refuses to write income onto a contra');

// ---------------------------------------------------------------------------
echo "\n11. What was posted can be read back into the screen it came from\n";
// ---------------------------------------------------------------------------
$composed = voucher_compose('payment', [
    'tender_ledger' => [CASH, BANK], 'tender_amount' => ['15000', '30000'],
    'tender_mode' => ['cash', 'bank_transfer'], 'tender_instrument_no' => ['', 'TXN-7781'],
    'line_ledger' => [RENT, SUPPLIER], 'line_amount' => ['20000', '25000'],
], $ledgers);
$back = voucher_decompose('payment', ['instrument_type' => 'cash'], $composed['entries'], $ledgers);
ok($back['ok'] === true, 'A mixed payment reopens on the payment screen');
ok(count($back['tender']) === 2 && count($back['lines']) === 2, 'With both bank rows and both particulars where they were');
ok((int) $back['tender'][1]['ledger_id'] === BANK && near((float) $back['tender'][1]['amount'], 30000.0), 'And the amounts intact');
ok($back['tender'][0]['mode'] === 'cash' && $back['tender'][1]['mode'] === 'bank_transfer',
    'And each row reopens as the way it was actually settled, not reset to cash');

$composed = voucher_compose('sales', [
    'settlement_mode' => 'party', 'settlement_ledger_id' => CUSTOMER,
    'value_ledger' => [INCOME], 'value_amount' => ['100000'],
    'tax_mode' => 'exclusive', 'tax_rate' => '13', 'tax_ledger_id' => VAT,
], $ledgers);
$back = voucher_decompose('sales', [], $composed['entries'], $ledgers);
ok($back['ok'] === true && (int) $back['settlement_ledger_id'] === CUSTOMER, 'A sale reopens against its customer');
ok(near((float) $back['tax_rate'], 13.0), 'And the tax rate is recovered from what was posted');
ok((int) $back['tax_ledger_id'] === VAT, 'Along with the ledger it went to');

$composed = voucher_compose('contra', ['contra_from_ledger' => CASH, 'contra_to_ledger' => BANK, 'contra_amount' => '25000'], $ledgers);
$back = voucher_decompose('contra', [], $composed['entries'], $ledgers);
ok((int) $back['contra_from_ledger'] === CASH && (int) $back['contra_to_ledger'] === BANK, 'A contra reopens pointing the same way');

// A voucher posted by another module, whose shape does not fit its type's
// screen, must say so rather than open half-populated.
$foreign = [
    ['ledger_id' => INCOME, 'entry_type' => 'credit', 'amount' => 100.0, 'memo' => '', 'cost_centre' => '', 'line_reference' => ''],
    ['ledger_id' => RENT, 'entry_type' => 'debit', 'amount' => 100.0, 'memo' => '', 'cost_centre' => '', 'line_reference' => ''],
];
$back = voucher_decompose('payment', [], $foreign, $ledgers);
ok($back['ok'] === false, 'Lines that are not a payment do not pretend to be one');

// ---------------------------------------------------------------------------
echo "\n12. A rejected submission comes back typed in\n";
// ---------------------------------------------------------------------------
$submitted = [
    'tender_ledger' => [BANK], 'tender_amount' => ['45000'], 'tender_mode' => ['cheque'], 'tender_instrument_no' => ['004312'],
    'line_ledger' => [RENT, SUPPLIER], 'line_amount' => ['20000', '25000'],
    'line_memo' => ['Shrawan rent', ''], 'title' => 'Office rent',
];
$prefill = voucher_prefill_from_input('payment', $submitted);
ok(count($prefill['tender']) === 1 && count($prefill['lines']) === 2, 'Every row that was typed is handed back');
ok($prefill['tender'][0]['instrument_no'] === '004312', 'Including the cheque number');
ok($prefill['lines'][0]['memo'] === 'Shrawan rent', 'And the descriptions');
ok($prefill['title'] === 'Office rent', 'And the title above them');

$prefill = voucher_prefill_from_input('journal', [
    'ledger_id' => [DEPRECIATION, ACC_DEPRECIATION],
    'entry_type' => ['debit', 'credit'],
    'amount' => ['12500', '12500'],
]);
ok($prefill['journal'][0]['debit'] === '12500' && $prefill['journal'][0]['credit'] === '',
    'A journal row comes back in the column it was typed into');
ok($prefill['journal'][1]['credit'] === '12500' && $prefill['journal'][1]['debit'] === '',
    'And so does its opposite');

// ---------------------------------------------------------------------------
echo "\n13. Numbering reads as a series a person can follow\n";
// ---------------------------------------------------------------------------
ok(voucher_series_code(['label' => '2082/83']) === '2082-83', 'A fiscal year label becomes a safe series code');
ok(voucher_series_code(['label' => '', 'start_date' => '2026-07-17']) === '2026', 'A year with no label falls back to its start year');
ok(voucher_series_code(null) === date('Y'), 'And a missing year falls back to today');

// ---------------------------------------------------------------------------
echo "\n" . str_repeat('-', 60) . "\n";
echo "  $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
