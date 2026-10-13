<?php
declare(strict_types=1);

/** Adapt all four account levels to the existing preview/duplicate validator. */
function coa_import_judge(int $companyId, array $rows): array
{
    $groups = [];
    $ledgers = [];
    $index = static function (array &$map, array $entry): void {
        foreach (['code','name'] as $key) if (trim((string)$entry[$key]) !== '') $map[mb_strtolower(trim((string)$entry[$key]))] ??= $entry;
    };
    $stmt = db()->prepare('SELECT * FROM ledger_groups WHERE company_id = ?');
    $stmt->execute([$companyId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $g) $index($groups, ['code'=>$g['code'],'name'=>$g['name'],'master'=>$g['master_key'],'level'=>empty($g['parent_group_id'])?'group':'subgroup','parent'=>'']);
    $stmt = db()->prepare('SELECT l.*, g.code AS group_code FROM ledgers l LEFT JOIN ledger_groups g ON g.id = l.group_id AND g.company_id = l.company_id WHERE l.company_id = ?');
    $stmt->execute([$companyId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $l) $index($ledgers, ['code'=>$l['code'],'name'=>$l['name'],'type'=>$l['type'],'level'=>empty($l['parent_ledger_id'])?'ledger':'subledger','parent'=>$l['group_code'] ?? '']);
    foreach ($rows as $row) {
        $c = array_pad($row['cells'],10,'');
        $level = strtolower(trim((string)$c[0]));
        $entry = ['code'=>trim((string)$c[1]),'name'=>trim((string)$c[2]),'master'=>trim((string)$c[3]),'type'=>trim((string)$c[4]),'parent'=>trim((string)$c[5]),'level'=>$level];
        if (in_array($level,['group','subgroup'],true)) $index($groups,$entry);
        if (in_array($level,['ledger','subledger'],true)) $index($ledgers,$entry);
    }
    $transformed = [];
    $originals = [];
    $errors = [];
    foreach ($rows as $row) {
        $c = array_pad($row['cells'],10,'');
        $n = (int)$row['n'];
        $originals[$n] = $c;
        $level = strtolower(trim((string)$c[0]));
        $parent = mb_strtolower(trim((string)$c[5]));
        if ($level === 'subgroup') {
            $p = $groups[$parent] ?? null;
            if (!$p || $p['level'] !== 'group') $errors[$n] = 'A subgroup requires a root Group parent (code or exact name).';
            $c[0] = 'Group';
            $c[3] = $p['master'] ?? '';
        } elseif ($level === 'subledger') {
            $p = $ledgers[$parent] ?? null;
            if (!$p || $p['level'] !== 'ledger') $errors[$n] = 'A subledger requires a Ledger parent (code or exact name), not another subledger.';
            if ($p && trim((string)$c[4]) !== '' && strtolower(trim((string)$c[4])) !== strtolower($p['type'])) $errors[$n] = 'Subledger nature must match its parent ledger.';
            $c[0] = 'Ledger';
            $c[4] = $p['type'] ?? '';
            $c[5] = $p['parent'] ?? '';
        }
        $pan = trim((string)$c[9]);
        if (mb_strlen($pan) > 60) $errors[$n] = 'PAN Number must be at most 60 characters.';
        if (in_array($level,['group','subgroup'],true) && $pan !== '') $errors[$n] = 'PAN Number applies to ledgers and subledgers only.';
        $row['cells'] = $c;
        $transformed[] = $row;
    }
    $judged = coa_import_judge_flat($companyId,$transformed);
    foreach ($judged as &$entry) {
        $c = $originals[$entry['n']];
        $entry['raw_level'] = trim((string)$c[0]);
        $entry['raw_group_code'] = trim((string)$c[5]);
        $entry['raw_pan_no'] = trim((string)$c[9]);
        $entry['parent_code'] = trim((string)$c[5]);
        if (isset($errors[$entry['n']])) { $entry['status']='error'; $entry['error']=$errors[$entry['n']]; }
    }
    unset($entry);
    return $judged;
}
