<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/coa_import.php';
if (!in_array(DB_HOST,['localhost','127.0.0.1'],true)) throw new RuntimeException('Local database only');
foreach (['ledgers'=>['pan_no',60], 'coa_import_rows'=>['raw_pan_no',120]] as $table => [$column,$length]) {
    if (!column_exists($table,$column)) db()->exec("ALTER TABLE {$table} ADD COLUMN {$column} VARCHAR({$length}) DEFAULT NULL");
}
function assert_account(bool $ok,string $why): void { if (!$ok) throw new RuntimeException($why); }
$cid = (int)db()->query('SELECT id FROM companies ORDER BY id LIMIT 1')->fetchColumn();
$uid = (int)db()->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
$rows = coa_import_template_rows();
$suffix = ' ' . bin2hex(random_bytes(4));
$names = [];
foreach (array_slice($rows,1) as $row) $names[$row[2]] = $row[2] . $suffix;
foreach ($rows as $i => &$row) if ($i) { $row[2] = $names[$row[2]]; $row[5] = $names[$row[5]] ?? $row[5]; if (in_array($row[0],['Ledger','Subledger'],true)) $row[9] = '001234567'; }
unset($row);
$input = array_map(static fn($row,$i)=>['n'=>$i+1,'cells'=>$row],$rows,array_keys($rows));
$judged = coa_import_judge($cid,$input);
assert_account(coa_import_totals($judged)['errors'] === 0, json_encode($judged));
$path = tempnam(sys_get_temp_dir(),'coa-test-');
$f = fopen($path,'w'); foreach ($rows as $row) fputcsv($f,$row,',','"','\\'); fclose($f);
db()->beginTransaction();
try {
    $id = coa_import_stage($cid,null,$uid,$path,'accounts.csv');
    $result = coa_import_commit($cid,$id,$uid);
    assert_account($result['ok'],json_encode($result));
    assert_account($result['groups'] === 3 && $result['ledgers'] === 3,'All levels must import');
    $stmt = db()->prepare('SELECT * FROM ledgers WHERE company_id = ? AND name = ?');
    $stmt->execute([$cid,$names['Example Customer Branch']]);
    $sub = $stmt->fetch();
    assert_account((int)$sub['parent_ledger_id'] > 0 && $sub['pan_no'] === '001234567','Subledger parent/PAN missing');
    assert_account(preg_match('/^1\.\d{2}\.\d{2}\.\d{3}\.01$/D',$sub['code']) === 1,'Subledger numeric code');
    $again = coa_import_judge($cid,$input);
    assert_account(coa_import_totals($again)['ready'] === 0,'Repeated template creates duplicates');
    $result = coa_import_commit($cid,$id,$uid);
    assert_account(!$result['ok'],'Committed upload must not commit twice');
    echo "Four-level template, preview, PAN leading zeros, parent links, generated codes and duplicate protection passed (rolled back).\n";
} finally { db()->rollBack(); unlink($path); }
foreach (['chart-groups.php','chart-ledgers.php'] as $file) {
    $source = file_get_contents(__DIR__.'/../public_html/admin/'.$file);
    assert_account(!str_contains($source,'INSERT INTO') && !str_contains($source,'<form'),'Legacy form/handler remains');
}
