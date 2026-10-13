<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';
require_once __DIR__.'/../app/reports_engine.php';
if (!in_array(DB_HOST,['localhost','127.0.0.1'],true)) throw new RuntimeException('Local database only');
function pl_assert(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
$fy = db()->query('SELECT * FROM fiscal_years ORDER BY id DESC LIMIT 1')->fetch();
$cid = (int)$fy['company_id'];
$suffix = bin2hex(random_bytes(4));
db()->beginTransaction();
try {
    $before = rc_generate('profit-loss',$cid,$fy['start_date'],$fy['end_date'],['biz'=>'service','currency'=>'NPR']);
    $entries = [];
    foreach ([['direct_income','revenue','credit',100],['indirect_income','revenue','credit',20],['direct_expense','expense','debit',30],['indirect_expense','expense','debit',10],['current_asset','asset','debit',80]] as [$master,$type,$side,$amount]) {
        $code = coa_next_group_code($cid,$master);
        db()->prepare('INSERT INTO ledger_groups (company_id,code,name,master_key) VALUES (?,?,?,?)')->execute([$cid,$code,'PL group '.$master.$suffix,$master]);
        $gid = (int)db()->lastInsertId();
        $code = coa_next_ledger_code($cid,$gid,null,$type);
        db()->prepare("INSERT INTO ledgers (company_id,group_id,code,name,type,status) VALUES (?,?,?,?,?,'active')")->execute([$cid,$gid,$code,'PL fixture '.$master.$suffix,$type]);
        $entries[] = [(int)db()->lastInsertId(),$side,$amount];
    }
    db()->prepare("INSERT INTO vouchers (company_id,fiscal_year_id,voucher_no,voucher_date,status) VALUES (?,?,?,?,'posted')")->execute([$cid,$fy['id'],'PL-'.$suffix,$fy['start_date']]);
    $vid = (int)db()->lastInsertId();
    foreach ($entries as [$lid,$side,$amount]) db()->prepare('INSERT INTO voucher_entries (voucher_id,ledger_id,entry_type,amount) VALUES (?,?,?,?)')->execute([$vid,$lid,$side,$amount]);
    $report = rc_generate('profit-loss',$cid,$fy['start_date'],$fy['end_date'],['biz'=>'service','currency'=>'NPR']);
    pl_assert($report['columns'][0][0] === 'Code' && $report['columns'][1][0] === 'Particulars','Code column missing');
    foreach ($report['columns'] as $col) pl_assert(!str_contains($col[0],'Variance') && $col[0] !== 'Note','Unexpected comparison column');
    foreach ($report['rows'] as $row) {
        pl_assert(count($row['cells']) === count($report['columns']),'Column count mismatch');
        if (!empty($row['meta']['ledger_id'])) pl_assert($row['cells'][0] !== '' && $row['meta']['label_cell'] === 1,'Ledger code/link missing');
    }
    $net = static function($report): float { foreach ($report['rows'] as $row) if ($row['cells'][1] === 'NET PROFIT FOR THE PERIOD') return (float)str_replace(',','',$row['cells'][2]); throw new RuntimeException('Missing net profit'); };
    pl_assert(abs($net($report)-$net($before)-80) < 0.005,'Net profit changed incorrectly');
    foreach (['trading','manufacturing'] as $biz) {
        $r = rc_generate('profit-loss',$cid,$fy['start_date'],$fy['end_date'],['biz'=>$biz,'currency'=>'NPR']);
        foreach ($r['rows'] as $row) pl_assert(count($row['cells']) === count($r['columns']),'Other organization column mismatch');
    }
    echo "Profit/loss codes, columns, linked ledgers, all organization layouts and net profit passed (rolled back).\n";
} finally { db()->rollBack(); }
