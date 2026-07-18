<?php
declare(strict_types=1);

/**
 * Payment-gateway acceptance suite (CLI, self-contained).
 * Exercises: self-repair, config CRUD, intent lifecycle, eSewa/Fonepay signature
 * round-trips, and the money-critical atomic settle (verified payment -> posted
 * Dr Bank / Cr Receivable receipt, invoice paid, idempotent). Tears down after.
 *
 *   php database/test_payment_gateways.php
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only.');
}

require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/accounting_module_repair.php';
require_once __DIR__ . '/../app/payment_gateway_engine.php';

accounting_module_repair_database();

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }
function near(float $a, float $b): bool { return abs($a - $b) < 0.01; }

echo "Schema\n";
ok(table_exists('payment_gateways'), 'payment_gateways table exists (self-repair)');
ok(table_exists('payment_intents'), 'payment_intents table exists (self-repair)');

// ---------------------------------------------------------------------------
// Fixture
// ---------------------------------------------------------------------------
function pgt_cleanup(): void
{
    foreach (db()->query("SELECT id FROM companies WHERE code = 'PGTESTA'")->fetchAll(PDO::FETCH_COLUMN) as $stale) {
        $stale = (int) $stale;
        db()->exec("DELETE FROM payment_intents WHERE company_id = $stale");
        db()->exec("DELETE FROM payment_gateways WHERE company_id = $stale");
        db()->exec("DELETE FROM invoice_payment_receipts WHERE company_id = $stale");
        db()->exec("DELETE FROM invoice_payment_requests WHERE company_id = $stale");
        db()->exec("DELETE ve FROM voucher_entries ve INNER JOIN vouchers v ON v.id = ve.voucher_id WHERE v.company_id = $stale");
        db()->exec("DELETE FROM vouchers WHERE company_id = $stale");
        db()->exec("DELETE FROM task_invoices WHERE company_id = $stale");
        db()->exec("DELETE FROM company_ledger_mappings WHERE company_id = $stale");
        db()->exec("DELETE FROM ledgers WHERE company_id = $stale");
        db()->exec("DELETE FROM ledger_groups WHERE company_id = $stale");
        db()->exec("DELETE FROM fiscal_years WHERE company_id = $stale");
        db()->exec("DELETE FROM companies WHERE id = $stale");
    }
}
pgt_cleanup();

echo "\nSetting up fixture...\n";
db()->prepare("INSERT INTO companies (name, code, is_active) VALUES ('Payment Gateway Test Co', 'PGTESTA', 1)")->execute();
$cid = (int) db()->lastInsertId();
$_SESSION['company_id'] = $cid;

$gCash = (function () use ($cid) { db()->prepare('INSERT INTO ledger_groups (company_id, master_key, code, name, is_cash_or_bank) VALUES (?,?,?,?,1)')->execute([$cid, 'current_asset', 'PG-CASH', 'Cash and Bank']); return (int) db()->lastInsertId(); })();
$gAR = (function () use ($cid) { db()->prepare('INSERT INTO ledger_groups (company_id, master_key, code, name, is_cash_or_bank) VALUES (?,?,?,?,0)')->execute([$cid, 'current_asset', 'PG-AR', 'Sundry Debtors']); return (int) db()->lastInsertId(); })();
$gInc = (function () use ($cid) { db()->prepare('INSERT INTO ledger_groups (company_id, master_key, code, name, is_cash_or_bank) VALUES (?,?,?,?,0)')->execute([$cid, 'direct_income', 'PG-INC', 'Sales']); return (int) db()->lastInsertId(); })();
$mkLedger = static function (int $gid, string $code, string $name, string $type) use ($cid): int {
    db()->prepare("INSERT INTO ledgers (company_id, group_id, code, name, type, status) VALUES (?,?,?,?,?, 'active')")->execute([$cid, $gid, $code, $name, $type]);
    return (int) db()->lastInsertId();
};
$lCash = $mkLedger($gCash, 'CASH', 'Bank', 'asset');
$lAR = $mkLedger($gAR, 'PG-110', 'Accounts Receivable', 'asset');
$lSales = $mkLedger($gInc, 'PG-400', 'Sales Revenue', 'revenue');

// Map default_cash_bank -> our bank ledger so receipts can post.
db()->prepare('INSERT INTO company_ledger_mappings (company_id, map_key, ledger_id) VALUES (?, ?, ?)')->execute([$cid, 'default_cash_bank', $lCash]);

$fy = create_fiscal_year($cid, 'PG 2026/27', '2026-07-16', '2027-07-15', true);
db()->prepare("UPDATE fiscal_years SET status='open' WHERE id=?")->execute([$fy['id']]);
$fyId = (int) $fy['id'];
$_SESSION['fiscal_year_id'] = $fyId;

$clientUser = (int) (db()->query('SELECT id FROM users ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 0);

// Helper: create an issued invoice + its posted sales voucher (Dr AR / Cr Sales).
$mkInvoice = static function (float $total, string $no) use ($cid, $fyId, $lAR, $lSales): int {
    db()->prepare("INSERT INTO task_invoices (company_id, invoice_no, invoice_type, invoice_category, amount, taxable_amount, total_amount, status, issued_on)
        VALUES (:cid, :no, 'other', 'tax', :amt, :amt2, :tot, 'issued', '2026-07-17')")
        ->execute(['cid' => $cid, 'no' => $no, 'amt' => $total, 'amt2' => $total, 'tot' => $total]);
    $iid = (int) db()->lastInsertId();
    create_voucher_with_entries([
        'company_id' => $cid, 'fiscal_year_id' => $fyId, 'voucher_no' => 'SV-' . $no,
        'voucher_type' => 'sales', 'voucher_date' => '2026-07-17',
        'source_type' => 'task_invoice', 'source_id' => $iid, 'total_amount' => $total,
        'narration' => 'Test sale', 'status' => 'posted',
    ], [
        ['ledger_id' => $lAR, 'entry_type' => 'debit', 'amount' => $total, 'memo' => 'AR'],
        ['ledger_id' => $lSales, 'entry_type' => 'credit', 'amount' => $total, 'memo' => 'Sales'],
    ]);
    return $iid;
};

// ---------------------------------------------------------------------------
// Config CRUD
// ---------------------------------------------------------------------------
echo "\nConfig\n";
pg_save_config($cid, 'esewa', ['mode' => 'test', 'enabled' => true, 'merchant_code' => 'EPAYTEST', 'secret_key' => '8gBm/:&EnhH.1/q']);
$esewaCfg = pg_config($cid, 'esewa');
ok($esewaCfg && (int) $esewaCfg['enabled'] === 1 && pg_config_is_ready((array) $esewaCfg), 'eSewa config saved, enabled and ready');
pg_save_config($cid, 'fonepay', ['mode' => 'test', 'enabled' => true, 'merchant_code' => 'PID123', 'secret_key' => 'fonesecret']);
pg_save_config($cid, 'khalti', ['mode' => 'test', 'enabled' => true, 'secret_key' => '']); // missing secret -> not ready
$enabled = pg_enabled_configs($cid);
ok(isset($enabled['esewa']) && isset($enabled['fonepay']) && !isset($enabled['khalti']), 'Enabled list includes ready gateways only (khalti excluded, no secret)');
// Update existing (no duplicate row).
pg_save_config($cid, 'esewa', ['mode' => 'live', 'enabled' => true, 'merchant_code' => 'EPAYTEST', 'secret_key' => '8gBm/:&EnhH.1/q']);
$count = (int) db()->query("SELECT COUNT(*) FROM payment_gateways WHERE company_id = $cid AND provider = 'esewa'")->fetchColumn();
ok($count === 1 && (string) pg_config($cid, 'esewa')['mode'] === 'live', 'Re-save updates in place (no duplicate, mode -> live)');
pg_save_config($cid, 'esewa', ['mode' => 'test', 'enabled' => true, 'merchant_code' => 'EPAYTEST', 'secret_key' => '8gBm/:&EnhH.1/q']);

// ---------------------------------------------------------------------------
// Intent + eSewa initiate signature
// ---------------------------------------------------------------------------
echo "\nIntent + eSewa signature\n";
$iid1 = $mkInvoice(5000.0, 'PG-INV-1');
$intent = pg_create_intent($cid, $iid1, 'esewa', 5000.0, 'test', 'NPR', $clientUser);
ok($intent && (string) $intent['status'] === 'pending' && strlen((string) $intent['token']) === 32, 'Intent created (pending, 32-char token)');
ok(pg_intent_by_token((string) $intent['token'])['id'] === $intent['id'], 'Intent lookup by token works');

$esewaCfg = pg_config($cid, 'esewa');
$init = pg_initiate((array) $esewaCfg, $intent, ['id' => $iid1, 'invoice_no' => 'PG-INV-1'], 'https://x/cb', 'https://x/cancel');
$expectSig = base64_encode(hash_hmac('sha256', 'total_amount=5000.00,transaction_uuid=' . $intent['token'] . ',product_code=EPAYTEST', (string) $esewaCfg['secret_key'], true));
ok($init['type'] === 'form_post' && $init['fields']['signature'] === $expectSig, 'eSewa form signature matches HMAC-SHA256 of signed fields');

// eSewa verify round-trip (valid + tampered).
$mkEsewaData = static function (array $cfg, string $uuid, string $status): string {
    $fields = ['transaction_code' => 'TC1', 'status' => $status, 'total_amount' => '5000.00', 'transaction_uuid' => $uuid, 'product_code' => 'EPAYTEST', 'signed_field_names' => 'transaction_code,status,total_amount,transaction_uuid,product_code'];
    $parts = [];
    foreach (explode(',', $fields['signed_field_names']) as $n) { $parts[] = $n . '=' . $fields[$n]; }
    $fields['signature'] = base64_encode(hash_hmac('sha256', implode(',', $parts), (string) $cfg['secret_key'], true));
    return base64_encode(json_encode($fields));
};
$goodData = $mkEsewaData((array) $esewaCfg, (string) $intent['token'], 'COMPLETE');
$v = pg_verify((array) $esewaCfg, $intent, ['data' => $goodData]);
ok($v['paid'] === true && near($v['amount'], 5000.0), 'eSewa verify accepts a correctly-signed COMPLETE payload');
$tampered = base64_encode(str_replace('5000.00', '1.00', (string) base64_decode($goodData)));
$vt = pg_verify((array) $esewaCfg, $intent, ['data' => $tampered]);
ok($vt['paid'] === false, 'eSewa verify rejects a tampered payload (signature mismatch)');

// Fonepay verify round-trip.
echo "\nFonepay signature\n";
$foneCfg = pg_config($cid, 'fonepay');
$foneParams = ['PRN' => (string) $intent['token'], 'PID' => 'PID123', 'PS' => 'true', 'RC' => 'successful', 'UID' => 'U1', 'BC' => 'BANK', 'INI' => 'INI', 'P_AMT' => '5000.00', 'R_AMT' => '5000.00'];
$foneParams['DV'] = hash_hmac('sha512', implode(',', [$foneParams['PRN'], $foneParams['PID'], $foneParams['PS'], $foneParams['RC'], $foneParams['UID'], $foneParams['BC'], $foneParams['INI'], $foneParams['P_AMT'], $foneParams['R_AMT']]), (string) $foneCfg['secret_key']);
$vf = pg_verify((array) $foneCfg, $intent, $foneParams);
ok($vf['paid'] === true && near($vf['amount'], 5000.0), 'Fonepay verify accepts a correctly-hashed success');
$foneParams['DV'] = 'deadbeef';
ok(pg_verify((array) $foneCfg, $intent, $foneParams)['paid'] === false, 'Fonepay verify rejects a bad DV hash');

// ---------------------------------------------------------------------------
// Settle: verified payment -> posted receipt (atomic, idempotent)
// ---------------------------------------------------------------------------
echo "\nSettle (money-critical)\n";
$settle = pg_settle_intent((int) $intent['id'], 'ESEWA-REF-1');
ok(!empty($settle['ok']) && (int) $settle['payment_request_id'] > 0, 'Full payment settled');
$prId = (int) $settle['payment_request_id'];
$rv = db()->query("SELECT v.id, v.status,
        (SELECT COALESCE(SUM(CASE WHEN ve.entry_type='debit' THEN ve.amount ELSE 0 END),0) FROM voucher_entries ve WHERE ve.voucher_id=v.id) AS dr,
        (SELECT COALESCE(SUM(CASE WHEN ve.entry_type='credit' THEN ve.amount ELSE 0 END),0) FROM voucher_entries ve WHERE ve.voucher_id=v.id) AS cr
    FROM vouchers v WHERE v.source_type='invoice_payment_request' AND v.source_id=$prId LIMIT 1")->fetch();
ok($rv && near((float) $rv['dr'], 5000.0) && near((float) $rv['cr'], 5000.0), 'Receipt voucher posted and balances (Dr 5000 / Cr 5000)');
$cashDr = (float) db()->query("SELECT COALESCE(SUM(CASE WHEN entry_type='debit' THEN amount ELSE 0 END),0) FROM voucher_entries WHERE voucher_id={$rv['id']} AND ledger_id=$lCash")->fetchColumn();
$arCr = (float) db()->query("SELECT COALESCE(SUM(CASE WHEN entry_type='credit' THEN amount ELSE 0 END),0) FROM voucher_entries WHERE voucher_id={$rv['id']} AND ledger_id=$lAR")->fetchColumn();
ok(near($cashDr, 5000.0) && near($arCr, 5000.0), 'Receipt debits Bank and credits the invoice receivable ledger');
ok((string) db()->query("SELECT status FROM task_invoices WHERE id=$iid1")->fetchColumn() === 'paid', 'Invoice marked paid');
$paidIntent = pg_intent((int) $intent['id']);
ok((string) $paidIntent['status'] === 'paid' && (int) $paidIntent['payment_request_id'] === $prId, 'Intent marked paid and linked to the request');

// Idempotency: a repeat callback must not double-post.
$again = pg_settle_intent((int) $intent['id'], 'ESEWA-REF-1');
$rvCount = (int) db()->query("SELECT COUNT(*) FROM vouchers WHERE source_type='invoice_payment_request' AND source_id=$prId")->fetchColumn();
ok(!empty($again['already']) && $rvCount === 1, 'Repeat settle is idempotent (no second voucher)');

// Partial settle.
echo "\nPartial settle\n";
$iid2 = $mkInvoice(5000.0, 'PG-INV-2');
$intent2 = pg_create_intent($cid, $iid2, 'esewa', 2000.0, 'test', 'NPR', $clientUser);
$s2 = pg_settle_intent((int) $intent2['id'], 'ESEWA-REF-2');
$pr2 = (int) $s2['payment_request_id'];
$dr2 = (float) db()->query("SELECT COALESCE(SUM(CASE WHEN entry_type='debit' THEN amount ELSE 0 END),0) FROM voucher_entries ve INNER JOIN vouchers v ON v.id=ve.voucher_id WHERE v.source_type='invoice_payment_request' AND v.source_id=$pr2")->fetchColumn();
ok(!empty($s2['ok']) && near($dr2, 2000.0), 'Partial payment books 2,000');
ok((string) db()->query("SELECT status FROM task_invoices WHERE id=$iid2")->fetchColumn() === 'issued', 'Partly-paid invoice stays issued (not marked paid)');

// ---------------------------------------------------------------------------
// Security fixes: cross-intent replay binding, comma amounts, Stripe FX, dedup
// ---------------------------------------------------------------------------
echo "\nReplay binding + amount parsing (security)\n";
$esewaCfg = pg_config($cid, 'esewa');
$foneCfg = pg_config($cid, 'fonepay');
$iid3 = $mkInvoice(5000.0, 'PG-INV-3');

// eSewa: a validly-signed payload for intent A must NOT settle a different intent B.
$intentA = pg_create_intent($cid, $iid3, 'esewa', 5000.0, 'test', 'NPR', $clientUser);
$intentB = pg_create_intent($cid, $iid3, 'esewa', 5000.0, 'test', 'NPR', $clientUser);
$blobA = $mkEsewaData((array) $esewaCfg, (string) $intentA['token'], 'COMPLETE');
ok(pg_verify((array) $esewaCfg, $intentB, ['data' => $blobA])['paid'] === false, 'eSewa: valid payload for intent A is REJECTED against intent B (transaction_uuid binding)');
ok(pg_verify((array) $esewaCfg, $intentA, ['data' => $blobA])['paid'] === true, 'eSewa: the same payload still settles its OWN intent A');

// eSewa: comma-grouped total_amount must parse correctly (not truncate to 5).
$intentC = pg_create_intent($cid, $iid3, 'esewa', 5000.0, 'test', 'NPR', $clientUser);
$commaFields = ['transaction_code' => 'TCc', 'status' => 'COMPLETE', 'total_amount' => '5,000.00', 'transaction_uuid' => (string) $intentC['token'], 'product_code' => 'EPAYTEST', 'signed_field_names' => 'transaction_code,status,total_amount,transaction_uuid,product_code'];
$cparts = [];
foreach (explode(',', $commaFields['signed_field_names']) as $n) { $cparts[] = $n . '=' . $commaFields[$n]; }
$commaFields['signature'] = base64_encode(hash_hmac('sha256', implode(',', $cparts), (string) $esewaCfg['secret_key'], true));
$vc = pg_verify((array) $esewaCfg, $intentC, ['data' => base64_encode(json_encode($commaFields))]);
ok($vc['paid'] === true && near($vc['amount'], 5000.0), 'eSewa: comma-grouped total_amount "5,000.00" parses to 5000 (not 5)');

// Fonepay: a valid return for intent A must NOT settle intent B (PRN binding).
$fA = pg_create_intent($cid, $iid3, 'fonepay', 5000.0, 'test', 'NPR', $clientUser);
$fB = pg_create_intent($cid, $iid3, 'fonepay', 5000.0, 'test', 'NPR', $clientUser);
$fp = ['PRN' => (string) $fA['token'], 'PID' => 'PID123', 'PS' => 'true', 'RC' => 'successful', 'UID' => 'U9', 'BC' => 'B', 'INI' => 'I', 'P_AMT' => '5000.00', 'R_AMT' => '5000.00'];
$fp['DV'] = hash_hmac('sha512', implode(',', [$fp['PRN'], $fp['PID'], $fp['PS'], $fp['RC'], $fp['UID'], $fp['BC'], $fp['INI'], $fp['P_AMT'], $fp['R_AMT']]), (string) $foneCfg['secret_key']);
ok(pg_verify((array) $foneCfg, $fB, $fp)['paid'] === false, 'Fonepay: valid return for intent A is REJECTED against intent B (PRN binding)');

// Stripe readiness now requires an FX rate.
echo "\nStripe FX gating\n";
pg_save_config($cid, 'stripe', ['mode' => 'test', 'enabled' => true, 'secret_key' => 'sk_test_x']);
ok(pg_config_is_ready((array) pg_config($cid, 'stripe')) === false, 'Stripe NOT ready without an FX rate (no NPR-as-USD overcharge possible)');
pg_save_config($cid, 'stripe', ['mode' => 'test', 'enabled' => true, 'secret_key' => 'sk_test_x', 'extra_config' => ['stripe_currency' => 'usd', 'fx_rate' => 133]]);
ok(pg_config_is_ready((array) pg_config($cid, 'stripe')) === true, 'Stripe ready once an FX rate is set');

// Single-use dedup: one provider reference settles at most one intent.
echo "\nSingle-use dedup\n";
$iid4 = $mkInvoice(1500.0, 'PG-INV-4');
$iid5 = $mkInvoice(1500.0, 'PG-INV-5');
$dupA = pg_create_intent($cid, $iid4, 'esewa', 1500.0, 'test', 'NPR', $clientUser);
$dupB = pg_create_intent($cid, $iid5, 'esewa', 1500.0, 'test', 'NPR', $clientUser);
ok(!empty(pg_settle_intent((int) $dupA['id'], 'SHARED-REF-1')['ok']), 'First settle with reference SHARED-REF-1 succeeds');
$sB = pg_settle_intent((int) $dupB['id'], 'SHARED-REF-1');
ok(empty($sB['ok']) && str_contains((string) ($sB['error'] ?? ''), 'already applied'), 'Second settle reusing the same reference is REJECTED (single-use dedup)');

pgt_cleanup();
echo "\n----------------------------------------\n";
echo "PASS: $pass   FAIL: $fail\n";
exit($fail === 0 ? 0 : 1);
