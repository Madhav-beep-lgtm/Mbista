<?php
declare(strict_types=1);

function jw_aml_ready(): bool
{
    return table_exists('jewellery_aml_cases') && table_exists('jewellery_aml_case_transactions');
}

function jw_aml_settings(int $companyId): array
{
    $defaults = ['ttr_threshold' => 1000000.0, 'ttr_due_days' => 15, 'near_threshold_percent' => 90.0,
        'structuring_min_count' => 3, 'missing_kyc_threshold' => 500000.0, 'rule_version' => 'FIU-NP-TTR-2025-07'];
    if (!table_exists('jewellery_aml_settings')) {
        return $defaults;
    }
    db()->prepare('INSERT IGNORE INTO jewellery_aml_settings (company_id) VALUES (:cid)')->execute(['cid' => $companyId]);
    $stmt = db()->prepare('SELECT * FROM jewellery_aml_settings WHERE company_id = :cid');
    $stmt->execute(['cid' => $companyId]);
    return array_merge($defaults, $stmt->fetch() ?: []);
}

/** Posted commercial/payment events used by deterministic AML rules. */
function jw_aml_transactions(int $companyId, string $from, string $to): array
{
    $sql = "SELECT 'sale' source_type, s.id source_id, s.sale_date transaction_date, s.sale_no document_no,
                   s.party_id, COALESCE(p.name, NULLIF(s.customer_name,''), 'Walk-in / unidentified') party_name,
                   p.pan_no, p.phone, 'customer_purchase' direction, s.settle_mode payment_mode, s.total_amount amount
            FROM jewellery_sales s LEFT JOIN accounting_parties p ON p.id=s.party_id
            WHERE s.company_id=? AND s.status='posted' AND s.sale_date BETWEEN ? AND ?
            UNION ALL
            SELECT 'purchase', pch.id, pch.purchase_date, pch.purchase_no, pch.party_id,
                   COALESCE(ap.name, 'Walk-in / unidentified'), ap.pan_no, ap.phone,
                   IF(pch.source='customer_old_gold','old_gold_purchase','supplier_purchase'), pch.settle_mode, pch.total_amount
            FROM jewellery_purchases pch LEFT JOIN accounting_parties ap ON ap.id=pch.party_id
            WHERE pch.company_id=? AND pch.status='posted' AND pch.purchase_date BETWEEN ? AND ?
            UNION ALL
            SELECT 'settlement', st.id, st.settlement_date, st.settlement_no, st.party_id,
                   COALESCE(ap2.name, 'Unidentified'), ap2.pan_no, ap2.phone,
                   st.direction, st.mode, st.amount
            FROM jewellery_settlements st LEFT JOIN accounting_parties ap2 ON ap2.id=st.party_id
            WHERE st.company_id=? AND st.status='posted' AND st.settlement_date BETWEEN ? AND ?
              AND NOT EXISTS (SELECT 1 FROM jewellery_settlement_allocations a WHERE a.settlement_id=st.id)";
    $stmt = db()->prepare($sql);
    $stmt->execute([$companyId,$from,$to,$companyId,$from,$to,$companyId,$from,$to]);
    return $stmt->fetchAll();
}

function jw_aml_upsert_case(int $companyId, ?int $partyId, string $type, string $kind, string $date,
    float $amount, array $transactions, int $risk, string $rule, string $version, string $reason, ?string $dueOn): int
{
    $sourceKeys = array_map(static fn(array $t): string => $t['source_type'] . ':' . $t['source_id'], $transactions);
    sort($sourceKeys);
    $fingerprint = hash('sha256', implode('|', [$type,$kind,$date,$partyId ?: 0,implode(',',$sourceKeys),$rule,$version]));
    $stmt = db()->prepare("INSERT INTO jewellery_aml_cases
        (company_id,party_id,case_type,candidate_kind,case_date,period_from,period_to,aggregate_amount,
         transaction_count,risk_score,rule_code,rule_version,reason,due_on,fingerprint)
        VALUES (:cid,:pid,:type,:kind,:dt,:dt2,:dt3,:amt,:cnt,:risk,:rule,:ver,:reason,:due,:fp)
        ON DUPLICATE KEY UPDATE aggregate_amount=VALUES(aggregate_amount), transaction_count=VALUES(transaction_count),
          risk_score=GREATEST(risk_score,VALUES(risk_score)), reason=VALUES(reason), due_on=VALUES(due_on), id=LAST_INSERT_ID(id)");
    $stmt->execute(['cid'=>$companyId,'pid'=>$partyId ?: null,'type'=>$type,'kind'=>$kind,'dt'=>$date,'dt2'=>$date,'dt3'=>$date,
        'amt'=>$amount,'cnt'=>count($transactions),'risk'=>$risk,'rule'=>$rule,'ver'=>$version,'reason'=>$reason,'due'=>$dueOn,'fp'=>$fingerprint]);
    $caseId = (int) db()->lastInsertId();
    $link = db()->prepare('INSERT IGNORE INTO jewellery_aml_case_transactions
        (case_id,company_id,source_type,source_id,transaction_date,document_no,direction,payment_mode,amount,details_json)
        VALUES (:case_id,:cid,:stype,:sid,:dt,:doc,:direction,:mode,:amount,:details)');
    foreach ($transactions as $t) {
        $link->execute(['case_id'=>$caseId,'cid'=>$companyId,'stype'=>$t['source_type'],'sid'=>$t['source_id'],
            'dt'=>$t['transaction_date'],'doc'=>$t['document_no'],'direction'=>$t['direction'],'mode'=>$t['payment_mode'],
            'amount'=>$t['amount'],'details'=>json_encode(['party_name'=>$t['party_name'],'pan_no'=>$t['pan_no'],'phone'=>$t['phone']], JSON_UNESCAPED_UNICODE)]);
    }
    return $caseId;
}

/** Generate review candidates; never files or labels a customer suspicious automatically. */
function jw_aml_scan(int $companyId, string $from, string $to, int $actorId = 0): array
{
    if (!jw_aml_ready()) return ['created_or_refreshed'=>0,'transactions'=>0];
    $settings = jw_aml_settings($companyId);
    $threshold = (float) $settings['ttr_threshold'];
    $near = $threshold * ((float) $settings['near_threshold_percent'] / 100);
    $rows = jw_aml_transactions($companyId,$from,$to);
    $groups = [];
    foreach ($rows as $row) {
        // Never aggregate unrelated walk-in customers together merely because
        // their identity is missing. An anonymous document is its own subject.
        $subject = $row['party_id'] ?: ('anonymous:' . $row['source_type'] . ':' . $row['source_id']);
        $key = $subject . '|' . $row['transaction_date'];
        $groups[$key][] = $row;
    }
    $count = 0;
    foreach ($groups as $txns) {
        $first = $txns[0]; $sum = array_sum(array_map(static fn($t)=>(float)$t['amount'],$txns));
        $partyId = (int) ($first['party_id'] ?? 0); $date = (string) $first['transaction_date'];
        if ($sum >= $threshold) {
            jw_aml_upsert_case($companyId,$partyId,'TTR','daily_threshold',$date,$sum,$txns,70,'DPMS_DAILY_1M',
                (string)$settings['rule_version'],'Single or linked customer transactions reached NPR '.number_format($sum,2).' in one day.',
                date('Y-m-d',strtotime($date.' +'.(int)$settings['ttr_due_days'].' days'))); $count++;
        }
        if ($sum >= $near && $sum < $threshold && count($txns) >= (int)$settings['structuring_min_count']) {
            jw_aml_upsert_case($companyId,$partyId,'SAR','possible_structuring',$date,$sum,$txns,75,'INTERNAL_STRUCTURING',
                (string)$settings['rule_version'],'Internal red flag: multiple same-day transactions close to the TTR threshold; compliance review required.',null); $count++;
        }
        $missingKyc = $partyId <= 0 || ((string)($first['pan_no'] ?? '') === '' && (string)($first['phone'] ?? '') === '');
        if ($missingKyc && $sum >= (float)$settings['missing_kyc_threshold']) {
            jw_aml_upsert_case($companyId,$partyId,'SAR','missing_kyc',$date,$sum,$txns,80,'INTERNAL_MISSING_KYC',
                (string)$settings['rule_version'],'Internal red flag: high-value activity has incomplete customer identification; obtain/update CDD and review.',null); $count++;
        }
    }
    if ($actorId > 0 && $count > 0) log_activity('jewellery_aml', $companyId, 'candidate_scan', "AML scan refreshed {$count} candidates for {$from} to {$to}.", $actorId);
    return ['created_or_refreshed'=>$count,'transactions'=>count($rows)];
}

function jw_aml_cases(int $companyId, string $from, string $to, string $status = ''): array
{
    if (!jw_aml_ready()) return [];
    $sql = 'SELECT c.*, COALESCE(p.name,\'Walk-in / unidentified\') party_name, p.pan_no, p.phone,
            u.name assigned_name FROM jewellery_aml_cases c
            LEFT JOIN accounting_parties p ON p.id=c.party_id LEFT JOIN users u ON u.id=c.assigned_to
            WHERE c.company_id=:cid AND c.case_date BETWEEN :from AND :to';
    $params=['cid'=>$companyId,'from'=>$from,'to'=>$to];
    if ($status !== '') { $sql .= ' AND c.status=:status'; $params['status']=$status; }
    $sql .= ' ORDER BY CASE WHEN c.status=\'candidate\' THEN 0 WHEN c.status=\'under_review\' THEN 1 ELSE 2 END, c.due_on ASC, c.case_date DESC';
    $stmt=db()->prepare($sql); $stmt->execute($params); return $stmt->fetchAll();
}

function jw_aml_case_transactions(int $companyId, int $caseId): array
{
    $stmt=db()->prepare('SELECT * FROM jewellery_aml_case_transactions WHERE company_id=:cid AND case_id=:id ORDER BY transaction_date, id');
    $stmt->execute(['cid'=>$companyId,'id'=>$caseId]); return $stmt->fetchAll();
}

function jw_aml_update_case(int $companyId, int $caseId, string $action, array $input, int $userId): bool
{
    $stmt=db()->prepare('SELECT * FROM jewellery_aml_cases WHERE company_id=:cid AND id=:id');
    $stmt->execute(['cid'=>$companyId,'id'=>$caseId]); $case=$stmt->fetch(); if (!$case) return false;
    $from=(string)$case['status']; $to=$from; $fields=[]; $params=['cid'=>$companyId,'id'=>$caseId];
    if ($action==='start_review') { $to='under_review'; $fields[]='assigned_to=:uid'; $params['uid']=$userId; }
    elseif ($action==='approve') { $to='approved'; $fields[]='reviewed_by=:uid'; $fields[]='reviewed_at=NOW()'; $params['uid']=$userId; }
    elseif ($action==='dismiss') { $to='dismissed'; $fields[]='reviewed_by=:uid'; $fields[]='reviewed_at=NOW()'; $fields[]='dismissal_reason=:dismissal'; $params['uid']=$userId; $params['dismissal']=trim((string)($input['dismissal_reason']??'')); if ($params['dismissal']==='') return false; }
    elseif ($action==='mark_filed') { $to='filed'; $fields[]='filed_by=:uid'; $fields[]='filed_at=NOW()'; $fields[]='goaml_reference=:ref'; $params['uid']=$userId; $params['ref']=trim((string)($input['goaml_reference']??'')); if ($params['ref']==='') return false; }
    else return false;
    $fields[]='status=:status'; $fields[]='narrative=:narrative'; $fields[]='source_of_funds=:sof';
    $params['status']=$to; $params['narrative']=trim((string)($input['narrative']??$case['narrative']??''));
    $params['sof']=trim((string)($input['source_of_funds']??$case['source_of_funds']??''));
    db()->prepare('UPDATE jewellery_aml_cases SET '.implode(',',$fields).' WHERE company_id=:cid AND id=:id')->execute($params);
    db()->prepare('INSERT INTO jewellery_aml_case_events (case_id,company_id,event_type,from_status,to_status,note,created_by)
        VALUES (:id,:cid,:event,:froms,:tos,:note,:uid)')->execute(['id'=>$caseId,'cid'=>$companyId,'event'=>$action,'froms'=>$from,'tos'=>$to,'note'=>trim((string)($input['note']??'')),'uid'=>$userId]);
    log_activity('jewellery_aml',$caseId,$action,'AML case changed from '.$from.' to '.$to.'.',$userId); return true;
}

function jw_aml_create_manual_sar(int $companyId, ?int $partyId, string $date, string $reason, string $narrative, int $userId): int
{
    $reason=trim($reason); if($reason==='') return 0;
    $fingerprint=hash('sha256',implode('|',['SAR','manual_activity',$companyId,$partyId?:0,$date,$reason]));
    $stmt=db()->prepare("INSERT INTO jewellery_aml_cases
        (company_id,party_id,case_type,candidate_kind,case_date,period_from,period_to,risk_score,rule_code,rule_version,reason,narrative,status,assigned_to,fingerprint)
        VALUES (:cid,:pid,'SAR','manual_activity',:dt,:dt2,:dt3,80,'MANUAL_ACTIVITY','FIU-NP-STR-2025-07',:reason,:narrative,'under_review',:uid,:fp)
        ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
    $stmt->execute(['cid'=>$companyId,'pid'=>$partyId?:null,'dt'=>$date,'dt2'=>$date,'dt3'=>$date,'reason'=>$reason,'narrative'=>trim($narrative),'uid'=>$userId,'fp'=>$fingerprint]);
    $id=(int)db()->lastInsertId();
    db()->prepare("INSERT INTO jewellery_aml_case_events (case_id,company_id,event_type,to_status,note,created_by) VALUES (:id,:cid,'manual_candidate','under_review',:note,:uid)")
      ->execute(['id'=>$id,'cid'=>$companyId,'note'=>'Attempted transaction or suspicious activity entered manually.','uid'=>$userId]);
    return $id;
}
