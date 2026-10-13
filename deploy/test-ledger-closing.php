<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
if (!in_array(DB_HOST, ['localhost', '127.0.0.1'], true)) throw new RuntimeException('Local DB only');
$fy = db()->query('SELECT * FROM fiscal_years ORDER BY id DESC LIMIT 1')->fetch();
if (!$fy) throw new RuntimeException('Local fiscal year required');
$cid = (int) $fy['company_id'];
$fid = (int) $fy['id'];
db()->beginTransaction();
try {
    $ids = [];
    foreach (['asset', 'revenue'] as $type) {
        db()->prepare("INSERT INTO ledgers (company_id, code, name, type, status, opening_balance, opening_side) VALUES (?, ?, ?, ?, 'active', ?, 'debit')")
            ->execute([$cid, 'TEST' . uniqid(), 'Closing fixture ' . $type, $type, $type === 'asset' ? 100 : 0]);
        $ids[] = (int) db()->lastInsertId();
    }
    $add = static function (string $date, string $status, float $amount, ?string $source = null) use ($cid, $fid, $ids): void {
        db()->prepare("INSERT INTO vouchers (company_id,fiscal_year_id,voucher_no,voucher_date,status,source_type) VALUES (?,?,?,?,?,?)")
            ->execute([$cid, $fid, 'TEST' . uniqid(), $date, $status, $source]);
        $vid = (int) db()->lastInsertId();
        foreach (['debit', 'credit'] as $i => $side) {
            db()->prepare('INSERT INTO voucher_entries (voucher_id,ledger_id,entry_type,amount) VALUES (?,?,?,?)')
                ->execute([$vid, $ids[$i], $side, $amount]);
        }
    };
    $add(date('Y-m-d', strtotime($fy['start_date'] . ' -1 day')), 'posted', 50);
    $add($fy['start_date'], 'posted', 20, 'ledger_opening');
    $add($fy['start_date'], 'posted', 30);
    $add($fy['start_date'], 'draft', 900);
    $add(date('Y-m-d', strtotime($fy['end_date'] . ' +1 day')), 'posted', 800);
    $rows = array_column(ledger_report_balances($cid, $fid), null, 'id');
    if (round((float) $rows[$ids[0]]['closing_net'], 2) !== 200.0) throw new RuntimeException('Asset closing must include direct opening, prior activity and current activity once');
    if ((float) $rows[$ids[0]]['debit_total'] !== 30.0) throw new RuntimeException('FY movement includes opening/draft/future or excludes normal entry');
    if ((float) $rows[$ids[1]]['closing_net'] !== -50.0) throw new RuntimeException('Prior revenue was not reset');
    if (ledger_report_balances(-1, $fid) !== []) throw new RuntimeException('Company scope failure');
    $html = export_ledger_html($cid, $fid);
    if (!str_contains($html, 'Closing Balance') || !str_contains($html, '200.00 Dr')) throw new RuntimeException('Print closing missing');
    $csv = export_ledger_to_excel($cid, $fid);
    if (!str_contains(json_encode($csv), '200.00 Dr')) throw new RuntimeException('Export closing missing');
    if (str_ends_with(ledger_closing_balance_text(0), 'Dr')) throw new RuntimeException('Zero incorrectly marked debit');
    db()->prepare("UPDATE vouchers v INNER JOIN voucher_entries e ON e.voucher_id = v.id SET v.location = 'Head Office', v.department = 'Operations', v.cost_centre = 'General' WHERE e.ledger_id = ?")->execute([$ids[0]]);
    db()->prepare("UPDATE voucher_entries SET cost_centre = 'Training' WHERE ledger_id = ?")->execute([$ids[0]]);
    $dims = ['location' => 'Head Office', 'department' => 'Operations', 'cost_centre' => 'Training'];
    $filtered = rc_ledger_balances($cid, $fy['start_date'], $fy['end_date'], '', 0, $ids[0], $dims, $fy['start_date']);
    if ((float) $filtered[0]['closing_net'] !== 100.0 || (float) $filtered[0]['tx_dr'] !== 30.0) throw new RuntimeException('Dimension selection or unallocated opening failed');
    $dims['cost_centre'] = 'General';
    $filtered = rc_ledger_balances($cid, $fy['start_date'], $fy['end_date'], '', 0, $ids[0], $dims, $fy['start_date']);
    if ((float) $filtered[0]['closing_net'] !== 0.0) throw new RuntimeException('Header centre overrode explicit line centre');
    $dims['cost_centre'] = 'Training';
    $lines = rc_entry_lines($cid, [$ids[0]], $fy['start_date'], $fy['end_date'], $dims);
    if (count($lines) !== 1 || (float) $lines[0]['amount'] !== 30.0) throw new RuntimeException('Filtered ledger entries or opening exclusion failed');
    echo "Closing, carry-forward, fiscal reset, status/date scope and export checks passed (rolled back).\n";
} finally {
    db()->rollBack();
}
