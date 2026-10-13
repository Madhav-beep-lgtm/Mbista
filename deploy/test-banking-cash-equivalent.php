<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/voucher_types.php';
if (!in_array(DB_HOST, ['localhost', '127.0.0.1'], true)) throw new RuntimeException('Local DB only');
$fiscalYear = db()->query('SELECT * FROM fiscal_years ORDER BY id DESC LIMIT 1')->fetch();
if (!$fiscalYear) throw new RuntimeException('Local fiscal-year fixture required');
$companyId = (int) $fiscalYear['company_id'];
$fiscalYearId = (int) $fiscalYear['id'];
$hasBankMeta = column_exists('ledgers', 'bank_account_no');
$cashAccountSql = '(COALESCE(g.is_cash_or_bank, 0) = 1 OR COALESCE(l.is_cash_equivalent, 0) = 1 OR COALESCE(g.is_cash_equivalent, 0) = 1)';
$source = file_get_contents(__DIR__ . '/../public_html/admin/banking.php');
$from = strpos($source, '$inventoryLedgerGuard =');
$to = strpos($source, '$fmtMoney =', $from);
db()->beginTransaction();
try {
    $stmt = db()->prepare('SELECT id FROM ledger_groups WHERE company_id = ? AND COALESCE(is_cash_or_bank,0) = 0 LIMIT 1');
    $stmt->execute([$companyId]);
    $groupId = (int) $stmt->fetchColumn();
    if (!$groupId) throw new RuntimeException('Local non-bank group fixture required');
    $stmt = db()->prepare("INSERT INTO ledgers (company_id,group_id,code,name,type,status,is_cash_equivalent,opening_balance,opening_side) VALUES (?, ?, ?, 'Cash equivalent test', 'asset', 'active', 1, 123, 'debit')");
    $stmt->execute([$companyId, $groupId, 'TEST' . uniqid()]);
    $id = (int) db()->lastInsertId();
    eval(substr($source, $from, $to - $from));
    $matching = array_values(array_filter($accounts, static fn ($row) => (int) $row['id'] === $id));
    if (count($matching) !== 1 || (float) $matching[0]['balance'] !== 123.0) throw new RuntimeException('Missing flagged account/opening');
    $directory = voucher_ledger_directory($companyId);
    if (empty($directory[$id]['roles']['cash_bank'])) throw new RuntimeException('Missing voucher cash role');
    echo "Banking queries, cash flag, opening balance and voucher role passed (rolled back).\n";
} finally {
    db()->rollBack();
}
