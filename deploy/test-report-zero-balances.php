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
