<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/reports_engine.php';
function check_code(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$groups = [
    ['id'=>1,'code'=>'BANK','name'=>'Bank','master_key'=>'current_asset','parent_group_id'=>null],
    ['id'=>2,'code'=>'SUB','name'=>'Bank subgroup','master_key'=>'current_asset','parent_group_id'=>1],
    ['id'=>3,'code'=>'CAPITAL','name'=>'Capital','master_key'=>'equity','parent_group_id'=>null],
];
$ledgers = [
    ['id'=>1,'code'=>'111','name'=>'Cash','type'=>'asset','group_id'=>2,'parent_ledger_id'=>null],
    ['id'=>2,'code'=>'112','name'=>'Cash detail','type'=>'asset','group_id'=>2,'parent_ledger_id'=>1],
    ['id'=>3,'code'=>'311','name'=>'Owner','type'=>'equity','group_id'=>3,'parent_ledger_id'=>null],
    ['id'=>4,'code'=>'FREE','name'=>'Ungrouped','type'=>'liability','group_id'=>null,'parent_ledger_id'=>null],
];
$plan = coa_code_plan($groups, $ledgers);
check_code($plan['ledger_groups'][1] === '1.01.00.000.00', 'Group code');
check_code($plan['ledger_groups'][2] === '1.01.01.000.00', 'Subgroup code');
check_code($plan['ledgers'][1] === '1.01.01.001.00', 'Ledger code');
check_code($plan['ledgers'][2] === '1.01.01.001.01', 'Subledger code');
check_code($plan['ledgers'][3] === '2.01.00.001.00', 'Equity nature');
check_code($plan['ledgers'][4] === '3.00.00.001.00', 'Ungrouped liability');
check_code(coa_code_format(1,0,1,1,1) === '1.00.01.001.01', 'Missing group segment');
foreach ($groups as &$row) $row['code'] = $plan['ledger_groups'][$row['id']];
unset($row);
foreach ($ledgers as &$row) $row['code'] = $plan['ledgers'][$row['id']];
unset($row);
check_code(coa_code_plan($groups,$ledgers) === $plan, 'Planner not idempotent');
$balances = [];
foreach ($ledgers as $ledger) $balances[] = $ledger + ['op_side_dr'=>0,'op_side_cr'=>0,'tx_dr'=>10,'tx_cr'=>0,'cl_side_dr'=>10,'cl_side_cr'=>0];
$tree = coa_trial_balance_rows($balances,$groups,false);
foreach ($tree['rows'] as $row) {
    check_code(preg_match('/^[1-5]\.\d{2}\.\d{2}\.\d{3}\.\d{2}$/D', $row['cells'][0]) === 1, 'Report code mismatch');
    check_code($row['style'] === (isset($row['meta']['ledger_id']) ? '' : 'bold'), 'Hierarchy weight');
}
check_code($tree['totals'][2] === 40.0, 'Totals counted twice');
$pdf = rc_pdf_section(['columns'=>[['Code','left',''],['Particulars','left','']], 'rows'=>array_slice($tree['rows'],0,2)], 'Codes');
check_code($pdf['rows'][0]['emphasis'] === 'total', 'PDF master weight');
$zero = array_map(static fn($b) => array_replace($b,['tx_dr'=>0,'cl_side_dr'=>0]),$balances);
check_code(coa_trial_balance_rows($zero,$groups,true)['rows'] === [], 'Hide zero');
check_code(coa_trial_balance_rows($zero,$groups,false)['rows'] !== [], 'Show zero');
$invalid = $groups;
$invalid[0]['parent_group_id'] = 2;
try { coa_code_plan($invalid,$ledgers); throw new LogicException('Cycle accepted'); } catch (RuntimeException $expected) {}
try { coa_code_format(1,100); throw new LogicException('Overflow accepted'); } catch (RuntimeException $expected) {}
echo "Code hierarchy, nature mapping, zero segments, idempotency, validation and report weights passed.\n";
if (in_array('--database', $argv, true)) {
    check_code(in_array(DB_HOST,['localhost','127.0.0.1'],true), 'Local DB only');
    foreach (['ledger_groups','ledgers'] as $table) {
        if (!column_exists($table,'legacy_code')) db()->exec("ALTER TABLE {$table} ADD COLUMN legacy_code VARCHAR(80) DEFAULT NULL");
    }
    $before = coa_migrate_codes(false);
    db()->beginTransaction();
    try {
        $changed = coa_migrate_codes(true,$before);
        check_code(coa_migrate_codes(false) === [], 'Database migration not idempotent');
        foreach ($changed as $change) {
            $stmt = db()->prepare('SELECT legacy_code,code FROM ' . $change['table'] . ' WHERE id = ?');
            $stmt->execute([$change['id']]);
            $saved = $stmt->fetch();
            check_code($saved['legacy_code'] !== null && $saved['code'] === $change['new_code'], 'Legacy alias lost');
            if ($change['table'] === 'ledgers') check_code((int) (ledger_by_code($change['company_id'], $saved['legacy_code'])['id'] ?? 0) === $change['id'], 'System lookup changed ledger');
        }
        $group = db()->query('SELECT * FROM ledger_groups ORDER BY id LIMIT 1')->fetch();
        $alias = 'CODE-TEST-' . bin2hex(random_bytes(4));
        $newId = coa_create_system_ledger((int)$group['company_id'], (int)$group['id'], $alias, 'Code regression fixture', ledger_master_nature($group['master_key']));
        check_code($newId === coa_create_system_ledger((int)$group['company_id'], (int)$group['id'], $alias, 'Code regression fixture', ledger_master_nature($group['master_key'])), 'System ledger duplicated');
        check_code(preg_match('/^[1-5]\.\d{2}\.\d{2}\.\d{3}\.\d{2}$/D', ledger_by_code((int)$group['company_id'],$alias)['code']) === 1, 'System ledger code format');
        echo count($changed) . " database updates verified, then rolled back.\n";
    } finally { db()->rollBack(); }
    check_code(coa_migrate_codes(false) === $before, 'Rollback did not restore codes');
}
