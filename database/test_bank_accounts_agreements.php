<?php
declare(strict_types=1);

/**
 * Per-company bank accounts on invoices + bilingual service agreements:
 * repair-created tables, helper filtering/ordering, tenant isolation,
 * multi-account invoice rendering, legacy single-account fallback,
 * agreement defaults, JSON round-trip and FK cascade cleanup.
 *   php database/test_bank_accounts_agreements.php
 */
if (PHP_SAPI !== 'cli') { exit('CLI only.'); }
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/accounting_module_repair.php';
accounting_module_repair_database();

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }

function bank_cleanup(): void
{
    foreach (db()->query("SELECT id FROM companies WHERE code IN ('BANKTA','BANKTB')")->fetchAll(PDO::FETCH_COLUMN) as $s) {
        $s = (int) $s;
        db()->exec("DELETE FROM company_bank_accounts WHERE company_id=$s");
        db()->exec("DELETE FROM service_agreements WHERE company_id=$s");
        db()->exec("DELETE FROM fiscal_years WHERE company_id=$s");
        db()->exec("DELETE FROM companies WHERE id=$s");
    }
}
bank_cleanup();

echo "== Schema ==\n";
ok(table_exists('company_bank_accounts'), 'repair created company_bank_accounts');
ok(table_exists('service_agreements'), 'repair created service_agreements');

db()->exec("INSERT INTO companies (name, code, is_active) VALUES ('Bank Test A', 'BANKTA', 1)");
$coA = (int) db()->lastInsertId();
db()->exec("INSERT INTO companies (name, code, is_active) VALUES ('Bank Test B', 'BANKTB', 1)");
$coB = (int) db()->lastInsertId();

echo "== Helper filtering and ordering ==\n";
ok(company_bank_accounts($coA) === [], 'no accounts configured -> empty list');

$ins = db()->prepare('INSERT INTO company_bank_accounts
    (company_id, bank_name, account_name, account_number, branch, is_default, show_on_invoice, sort_order, active)
    VALUES (?,?,?,?,?,?,?,?,?)');
$ins->execute([$coA, 'Nabil Bank', 'M.Bista and Associates', '1111111111', 'Teku', 0, 1, 2, 1]);
$ins->execute([$coA, 'Global IME Bank', 'M.Bista and Associates', '2222222222', 'Kalimati', 1, 1, 5, 1]);
$ins->execute([$coA, 'Hidden Bank', 'M.Bista and Associates', '3333333333', 'Baneshwor', 0, 0, 1, 1]);
$ins->execute([$coA, 'Closed Bank', 'M.Bista and Associates', '4444444444', 'Patan', 0, 1, 0, 0]);
$ins->execute([$coB, 'Other Tenant Bank', 'Company B', '5555555555', 'Pokhara', 1, 1, 0, 1]);

$onInvoice = company_bank_accounts($coA);
ok(count($onInvoice) === 2, 'invoice list keeps only active + show_on_invoice rows');
ok(($onInvoice[0]['bank_name'] ?? '') === 'Global IME Bank', 'default account listed first');
ok(($onInvoice[1]['bank_name'] ?? '') === 'Nabil Bank', 'others follow by sort order');

$all = company_bank_accounts($coA, false);
ok(count($all) === 3, 'full list keeps hidden-from-invoice but still drops inactive');
ok(in_array('Hidden Bank', array_column($all, 'bank_name'), true), 'full list includes hidden account');
ok(!in_array('Closed Bank', array_column($all, 'bank_name'), true), 'inactive account never listed');
ok(!in_array('Other Tenant Bank', array_column($onInvoice, 'bank_name'), true), 'tenant isolation: other company account excluded');

echo "== Invoice rendering ==\n";
$invoice = [
    'company_id' => $coA, 'invoice_no' => 'BANKT-001', 'invoice_category' => 'tax',
    'issued_on' => date('Y-m-d'), 'status' => 'issued', 'client_name' => 'Probe Client',
    'amount' => 1000.0, 'vat_amount' => 130.0, 'total_amount' => 1130.0,
];
$html = export_invoice_html($invoice);
ok(str_contains($html, 'Global IME Bank') && str_contains($html, 'Nabil Bank'), 'invoice prints every visible bank account');
ok(str_contains($html, '1111111111') && str_contains($html, '2222222222'), 'invoice prints each account number');
ok(!str_contains($html, 'Hidden Bank') && !str_contains($html, 'Closed Bank'), 'hidden and inactive accounts stay off the invoice');
ok(!str_contains($html, 'Other Tenant Bank'), 'invoice never leaks another company\'s account');

$htmlB = export_invoice_html(['company_id' => $coB, 'invoice_no' => 'BANKT-002'] + $invoice);
ok(str_contains($htmlB, 'Other Tenant Bank'), 'company B invoice shows company B account');
ok(!str_contains($htmlB, 'Global IME Bank'), 'company B invoice omits company A accounts');

db()->exec("UPDATE company_bank_accounts SET active=0 WHERE company_id=$coB");
$legacyName = (string) setting('bank_name', '');
$htmlLegacy = export_invoice_html(['company_id' => $coB] + $invoice);
if ($legacyName !== '') {
    ok(str_contains($htmlLegacy, e($legacyName)), 'no configured accounts -> legacy settings fallback shown');
} else {
    ok(str_contains($htmlLegacy, 'Payment details available on request.'), 'no accounts and no legacy settings -> polite empty state');
}

echo "== Service agreements ==\n";
$services = [['title_np' => 'बुक किपिङ', 'title_en' => 'Bookkeeping', 'tasks_np' => 'दैनिक लेखा', 'tasks_en' => 'Daily records', 'deliverable_np' => 'मासिक लेखा', 'deliverable_en' => 'Monthly books']];
$witnesses = ['w1' => ['name' => 'Witness One', 'address' => 'KTM'], 'w2' => ['name' => '', 'address' => '']];
db()->prepare('INSERT INTO service_agreements
    (company_id, agreement_no, first_party_name_en, second_party_name_en, services_json, witnesses_json)
    VALUES (?,?,?,?,?,?)')->execute([
        $coA, 'SA-TEST-1', 'Akshara Jewellery Pvt. Ltd.', 'M.B. Training and Advisory Services Pvt. Ltd.',
        json_encode($services, JSON_UNESCAPED_UNICODE), json_encode($witnesses, JSON_UNESCAPED_UNICODE),
    ]);
$saId = (int) db()->lastInsertId();
$row = db()->query("SELECT * FROM service_agreements WHERE id=$saId")->fetch();
ok((int) $row['duration_months'] === 24 && (int) $row['trial_months'] === 1, 'defaults: 24-month term with 1-month trial');
ok((int) $row['payment_days'] === 7 && (int) $row['termination_notice_days'] === 3 && (int) $row['cure_days'] === 7, 'defaults: 7-day payment, 3-day notice, 7-day cure');
ok($row['status'] === 'draft', 'new agreement starts as draft');
ok(str_contains((string) $row['jurisdiction_np'], 'काठमाडौँ'), 'Nepali jurisdiction default preserved (utf8mb4)');
$backSvc = json_decode((string) $row['services_json'], true);
ok(($backSvc[0]['title_np'] ?? '') === 'बुक किपिङ' && ($backSvc[0]['deliverable_en'] ?? '') === 'Monthly books', 'Annex-1 service rows survive the JSON round-trip');
$backWit = json_decode((string) $row['witnesses_json'], true);
ok(($backWit['w1']['name'] ?? '') === 'Witness One', 'witness block survives the JSON round-trip');

echo "== Cascade cleanup ==\n";
db()->exec("DELETE FROM companies WHERE id=$coA");
ok((int) db()->query("SELECT COUNT(*) FROM company_bank_accounts WHERE company_id=$coA")->fetchColumn() === 0, 'deleting a company cascades its bank accounts');
ok((int) db()->query("SELECT COUNT(*) FROM service_agreements WHERE company_id=$coA")->fetchColumn() === 0, 'deleting a company cascades its agreements');

bank_cleanup();
echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
