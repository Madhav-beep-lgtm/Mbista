<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/reports_engine.php';
$balances = [
    ['id' => 1, 'master_key' => 'asset', 'group_name' => 'Cash', 'values' => [0, 0, 0, 0]],
    ['id' => 2, 'master_key' => 'asset', 'group_name' => 'Cash', 'values' => [10, 10, 0, 0]],
    ['id' => 3, 'master_key' => 'asset', 'group_name' => 'Bank', 'values' => [0, 0, 5, 0]],
];
$value = static fn (array $row): array => $row['values'];
$shown = rc_group_balances($balances, ['asset'], $value, false);
$hidden = rc_group_balances($balances, ['asset'], $value, true);
if (count($shown['groups']['Cash']['ledgers']) !== 2) throw new RuntimeException('Zero account missing by default');
if (count($hidden['groups']['Cash']['ledgers']) !== 1) throw new RuntimeException('Hide zero removed movement or retained empty account');
if ($shown['sum'] !== $hidden['sum']) throw new RuntimeException('Hide zero changed totals');
$zero = rc_group_balances([$balances[0]], ['asset'], $value, false);
if ($zero['groups'] === []) throw new RuntimeException('All-zero report became empty');
echo "Zero balances visibility, period activity and unchanged totals passed.\n";
function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
$report = [
    'title' => 'Trial Balance',
    'columns' => [['Code', 'left', ''], ['Particulars', 'left', '']],
    'rows' => [rc_row(['111', 'Cash & bank'], '', ['level' => 2, 'ledger_id' => 42, 'label_cell' => 1])],
    'totals' => null,
];
ob_start();
rc_render_table($report, false, '/admin/ledgers.php?ledger_id=');
$html = ob_get_clean();
if (!str_contains($html, 'href="/admin/ledgers.php?ledger_id=42"') || !str_contains($html, 'Cash &amp; bank')) throw new RuntimeException('Accessible ledger link missing');
ob_start();
rc_render_table($report, false);
$print = ob_get_clean();
if (str_contains($print, 'rc-ledger-link')) throw new RuntimeException('Screen links leaked into print');
echo "Ledger links and print separation passed.\n";
