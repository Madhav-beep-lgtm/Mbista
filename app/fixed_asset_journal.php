<?php
declare(strict_types=1);
require_once __DIR__ . '/fixed_asset_revaluation.php';

function faj_schema(): void
{
    if (table_exists('fixed_asset_journals') && column_exists('fixed_assets','disposal_gain_loss') && column_exists('fixed_assets','depreciation_base')) return;
    fa_revaluation_repair_database();
    foreach(['disposal_proceeds','disposal_gain_loss'] as $column) if(!column_exists('fixed_assets',$column)) db()->exec("ALTER TABLE fixed_assets ADD `$column` DECIMAL(18,2) NULL");
    db()->exec("CREATE TABLE IF NOT EXISTS fixed_asset_journals (
        voucher_id INT UNSIGNED NOT NULL PRIMARY KEY,
        company_id INT UNSIGNED NOT NULL,
        payload MEDIUMTEXT NOT NULL,
        applied TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_faj_company (company_id, applied),
        FOREIGN KEY (voucher_id) REFERENCES vouchers(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function faj_link(int $voucherId): ?array
{
    if (!table_exists('fixed_asset_journals')) return null;
    $q=db()->prepare('SELECT * FROM fixed_asset_journals WHERE voucher_id=?');
    $q->execute([$voucherId]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: null;
}

function faj_blocker(int $voucherId): ?string
{
    return !empty(faj_link($voucherId)['applied'])
        ? 'This journal has updated the asset register. Use a new asset adjustment journal; the posted original cannot be edited or deleted.' : null;
}

function faj_managed_asset(int $cid,int $assetId): bool
{
    if (!table_exists('fixed_asset_journals')) return false;
    $q=db()->prepare("SELECT 1 FROM fixed_asset_journals WHERE company_id=? AND applied=1 AND ".faj_asset_filter('payload')." LIMIT 1");$q->execute([$cid,(string)$assetId,$assetId]);
    return (bool)$q->fetchColumn();
}

/** Backward-compatible single-event payloads and multi-asset journals. */
function faj_events(array $payload): array
{
    $events=$payload['events'] ?? [$payload];
    if (!is_array($events) || !array_is_list($events) || !$events || count($events)>50) throw new RuntimeException('A journal must contain between 1 and 50 asset transactions.');
    foreach($events as &$event) {
        if (!is_array($event) || isset($event['events'])) throw new RuntimeException('Invalid asset transaction.');
        $event['asset_id']=(int)($event['asset_id'] ?? 0);
    } unset($event);
    return $events;
}

function faj_pack(array $events): array
{
    return count($events)===1 ? $events[0] : ['events'=>array_values($events)];
}

/** Asset acquisition metadata follows the user's journal, never writes its lines. */
function faj_from_journal_line(array $data,array $input): array
{
    if (!in_array($data['operation'] ?? '',['acquisition','addition'],true)) return $data;
    $line=filter_var($data['journal_line'] ?? null,FILTER_VALIDATE_INT);
    $credit=filter_var($data['credit_line'] ?? null,FILTER_VALIDATE_INT);
    if ($line===false || $credit===false || $line<0 || $credit<0 || ($input['entry_type'][$line] ?? '')!=='debit' || ($input['entry_type'][$credit] ?? '')!=='credit') throw new RuntimeException('Enter the asset debit and funding credit in the journal, then select those entries in Asset details.');
    $data['cost_ledger']=(int)($input['ledger_id'][$line] ?? 0);
    $data['counter_ledger']=(int)($input['ledger_id'][$credit] ?? 0);
    $data['amount']=round((float)($input['amount'][$line] ?? 0),2);
    if ($data['amount']<=0 || (float)($input['amount'][$credit] ?? 0)<=0) throw new RuntimeException('Enter positive debit and credit amounts in the journal first.');
    return $data;
}

// Column names are supplied by application code, never by a request.
function faj_asset_filter(string $column): string
{
    return "(JSON_UNQUOTE(JSON_EXTRACT($column,'$.asset_id'))=? OR JSON_CONTAINS(JSON_EXTRACT($column,'$.events'),JSON_OBJECT('asset_id',CAST(? AS UNSIGNED))))";
}

function faj_bundle_plan(int $cid,array $payload,string $date): array
{
    $events=faj_events($payload);$seen=[];$plans=[];$deltas=[];
    foreach($events as $event) {
        $key=($event['operation'] ?? '')==='acquisition'?'code:'.mb_strtolower(trim((string)($event['code'] ?? ''))):'id:'.$event['asset_id'];
        if(isset($seen[$key])) throw new RuntimeException('Include each asset only once in a journal. Use separate journals for successive events.');
        $seen[$key]=true;
        $plan=faj_validate($cid,$event,$date);$plans[]=$plan;
        foreach($plan['deltas'] as $lid=>$amount) $deltas[$lid]=round(($deltas[$lid] ?? 0)+$amount,2);
    }
    return ['events'=>$events,'plans'=>$plans,'deltas'=>$deltas];
}

/** Exact posted ledger deltas required by an asset event. No second voucher. */
function faj_plan(array $data, ?array $asset): array
{
    $op=(string)($data['operation'] ?? 'acquisition');
    if (!in_array($op,['acquisition','addition','depreciation','revaluation','disposal','impairment','impairment_reversal'],true)) throw new RuntimeException('Select a supported asset transaction.');
    $amount=round((float)($data['amount'] ?? 0),2);
    if (!is_finite($amount) || $amount<0 || $amount>999999999999) throw new RuntimeException('Invalid asset amount.');
    if ($op!=='acquisition' && !$asset) throw new RuntimeException('Select an existing asset.');
    $cost=(float)($asset['cost'] ?? 0)+(float)($asset['directly_attributable_cost'] ?? 0)+(float)($asset['restoration_provision'] ?? 0);
    $acc=(float)($asset['accumulated_depreciation'] ?? 0);
    $imp=(float)($asset['accumulated_impairment'] ?? 0);
    $carrying=round($cost-$acc-$imp,2);
    if ($asset && isset($asset['carrying_amount']) && abs((float)$asset['carrying_amount']-$carrying)>0.01) throw new RuntimeException('This historical asset has unreconciled cost and carrying values. Reconcile its existing valuation before preparing a journal.');
    $deltas=[]; $changes=[];
    $add=static function(string $key,float $value) use (&$deltas,$data): void {
        if (abs($value)<0.005) return;
        $id=(int)($data[$key] ?? 0);
        if ($id<=0) throw new RuntimeException('Select the '.str_replace('_',' ',$key).'.');
        $deltas[$id]=round(($deltas[$id] ?? 0)+$value,2);
    };
    if ($op==='acquisition') {
        if ($amount<=0 || trim((string)($data['name'] ?? ''))==='' || trim((string)($data['code'] ?? ''))==='') throw new RuntimeException('Asset code, name and a positive value are required.');
        if (mb_strlen((string)$data['name'])>190 || mb_strlen((string)$data['code'])>80) throw new RuntimeException('Asset code or name is too long.');
        $method=(string)($data['method'] ?? '');
        $rate=(float)($data['rate'] ?? 0); $life=(int)($data['life_months'] ?? 0); $residual=(float)($data['residual'] ?? 0);
        if (!in_array($method,['straight_line','diminishing_balance'],true) || !is_finite($rate) || $rate<=0 || $rate>100 || $life<1 || $life>1200 || $residual<0 || $residual>$amount) throw new RuntimeException('Check depreciation method, rate, useful life and residual value.');
        if ($method==='straight_line' && abs($rate-1200/$life)>0.051) throw new RuntimeException('Straight-line rate must agree with useful life (annual rate = 1200 / life in months).');
        $add('cost_ledger',$amount); $add('counter_ledger',-$amount);
    } elseif ($op==='addition') {
        if ($amount<=0) throw new RuntimeException('Enter a positive addition.');
        $add('cost_ledger',$amount); $add('counter_ledger',-$amount);
        $changes=['cost'=>(float)$asset['cost']+$amount,'carrying_amount'=>$carrying+$amount];
    } elseif ($op==='depreciation') {
        if ($amount<=0 || $amount>round($carrying-(float)$asset['residual_value'],2)) throw new RuntimeException('Depreciation must be positive and cannot reduce the asset below residual value.');
        $add('expense_ledger',$amount); $add('accumulated_ledger',-$amount);
        $changes=['accumulated_depreciation'=>$acc+$amount,'carrying_amount'=>$carrying-$amount];
    } elseif (in_array($op,['impairment','impairment_reversal'],true)) {
        if (trim((string)($data['evidence'] ?? ''))==='') throw new RuntimeException('Enter the impairment assessment reference.');
        if ((float)($asset['revaluation_reserve'] ?? 0)>0 || (float)($asset['revaluation_loss_balance'] ?? 0)>0) throw new RuntimeException('Use Revaluation for an asset with a revaluation balance so reserve and profit-or-loss allocations are preserved.');
        $reversal=$op==='impairment_reversal';
        $movement=$reversal ? round(min($amount-$carrying,$imp),2) : round($carrying-$amount,2);
        if ($movement<=0) throw new RuntimeException($reversal?'No recognised impairment is available to reverse at this recoverable amount.':'Recoverable amount must be below carrying amount.');
        $add('impairment_ledger',$reversal?$movement:-$movement);
        $add($reversal?'reversal_ledger':'loss_ledger',$reversal?-$movement:$movement);
        $changes=['accumulated_impairment'=>$imp+($reversal?-$movement:$movement),'carrying_amount'=>$carrying+($reversal?$movement:-$movement)];
    } elseif ($op==='revaluation') {
        if (trim((string)($data['evidence'] ?? ''))==='') throw new RuntimeException('Enter the valuation reference or evidence.');
        $delta=round($amount-$carrying,2);
        if (abs($delta)<0.005) throw new RuntimeException('Revalued amount is unchanged.');
        $allocation=fa_revaluation_allocation($delta,(float)$asset['revaluation_reserve'],(float)($asset['revaluation_loss_balance'] ?? 0));
        // Eliminate accumulated depreciation/impairment against gross cost.
        $add('cost_ledger',$amount-$cost); $add('accumulated_ledger',$acc); $add('impairment_ledger',$imp);
        $add('reserve_ledger',$allocation['oci_decrease']-$allocation['oci_increase']);
        $add('loss_ledger',$allocation['pnl_loss']-$allocation['pnl_reversal']);
        $changes=['cost'=>$amount,'directly_attributable_cost'=>0,'restoration_provision'=>0,'accumulated_depreciation'=>0,'accumulated_impairment'=>0,'carrying_amount'=>$amount,
            'revaluation_reserve'=>(float)$asset['revaluation_reserve']+$allocation['oci_increase']-$allocation['oci_decrease'],
            'revaluation_loss_balance'=>(float)($asset['revaluation_loss_balance'] ?? 0)+$allocation['pnl_loss']-$allocation['pnl_reversal'],
            'depreciation_base'=>$amount,'depreciation_base_accumulated'=>0];
    } else {
        $add('cost_ledger',-$cost); $add('accumulated_ledger',$acc); $add('impairment_ledger',$imp);
        $add('counter_ledger',$amount);
        $gain=round($amount-$carrying,2);
        $add($gain>=0?'gain_ledger':'loss_ledger',-$gain);
        $changes=['status'=>'disposed','carrying_amount'=>0,'disposal_proceeds'=>$amount,'disposal_gain_loss'=>$gain];
    }
    if (count(array_filter($deltas,static fn($v)=>abs($v)>0.005))<2 || abs(array_sum($deltas))>0.005) throw new RuntimeException('Asset ledgers must produce a balanced journal using distinct accounts.');
    return ['deltas'=>$deltas,'changes'=>$changes];
}

function faj_validate(int $cid,array $data,string $date): array
{
    if (!db()->inTransaction()) throw new LogicException('Asset validation requires a transaction.');
    $companyLock=db()->prepare('SELECT id FROM companies WHERE id=? FOR UPDATE');$companyLock->execute([$cid]);
    if (!company_module_enabled('fixed_assets',$cid)) throw new RuntimeException('Fixed Assets is not active for this company.');
    if (!fa_valid_date($date)) throw new RuntimeException('Invalid asset journal date.');
    $asset=null; $id=(int)($data['asset_id'] ?? 0);
    if (($data['operation'] ?? '')!=='acquisition') {
        $q=db()->prepare('SELECT * FROM fixed_assets WHERE id=? AND company_id=? FOR UPDATE');$q->execute([$id,$cid]);$asset=$q->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$asset || !in_array($asset['status'],['active','fully_depreciated'],true)) throw new RuntimeException('Select an active asset belonging to this company.');
        if (!in_array($asset['asset_class'],['ppe','intangible','investment_property'],true)) throw new RuntimeException('Lease and construction assets require their specialist workflow until migrated.');
        if (($data['operation'] ?? '')==='revaluation') fa_assert_revaluation_model($cid,(string)$asset['asset_class']);
        $q=db()->prepare('SELECT MAX(period_date) FROM asset_depreciation_schedule WHERE asset_id=? AND company_id=? AND posted=1');$q->execute([$id,$cid]);$lastDep=(string)$q->fetchColumn();
        if ($lastDep>$date || (($data['operation'] ?? '')==='depreciation' && $lastDep===$date)) throw new RuntimeException('Depreciation already exists on this date or a later date.');
        if (($data['operation'] ?? '')==='depreciation' && (string)$asset['depreciation_start_date']>$date) throw new RuntimeException('Journal date precedes depreciation start.');
        $q=db()->prepare('SELECT MAX(test_date) FROM asset_impairments WHERE asset_id=? AND company_id=? AND voucher_id IS NOT NULL');$q->execute([$id,$cid]);
        if ((string)$q->fetchColumn()>$date) throw new RuntimeException('A later valuation or impairment already exists.');
        if ((string)($asset['purchase_date'] ?? '')>$date || (string)($asset['last_revaluation_date'] ?? '')>$date) throw new RuntimeException('Asset journal cannot precede acquisition or revaluation.');
        $q=db()->prepare('SELECT MAX(v.voucher_date) FROM fixed_asset_journals j JOIN vouchers v ON v.id=j.voucher_id WHERE j.company_id=? AND j.applied=1 AND '.faj_asset_filter('j.payload'));$q->execute([$cid,(string)$id,$id]);
        if ((string)$q->fetchColumn()>$date) throw new RuntimeException('A later asset journal already exists.');
        foreach (['cost_ledger'=>'ppe_cost','accumulated_ledger'=>'accumulated_depreciation','expense_ledger'=>'depreciation_expense'] as $field=>$purpose) {
            $mapped=fa_resolve_mapping($cid,$purpose,$id);
            if (!$mapped || (int)$mapped['id']!==(int)($data[$field] ?? 0)) throw new RuntimeException('Use the registered asset cost and depreciation ledgers.');
        }
    } else {
        $q=db()->prepare('SELECT id FROM fixed_assets WHERE company_id=? AND asset_code=? FOR UPDATE');$q->execute([$cid,trim((string)($data['code'] ?? ''))]);
        if ($q->fetchColumn()) throw new RuntimeException('Asset code already exists.');
        if (!fa_valid_date($data['start_date'] ?? '') || $data['start_date']<$date) throw new RuntimeException('Depreciation start date cannot precede the acquisition journal.');
    }
    $plan=faj_plan($data,$asset);
    if ($asset && (float)$asset['accumulated_impairment']>0 && in_array($data['operation'],['impairment','impairment_reversal','revaluation','disposal'],true)) {
        $mapped=fa_resolve_mapping($cid,'accumulated_impairment',$id);
        if (!$mapped || (int)$mapped['id']!==(int)($data['impairment_ledger'] ?? 0)) throw new RuntimeException('Use the registered accumulated impairment ledger.');
    }
    $types=['cost_ledger'=>'asset','accumulated_ledger'=>'asset','expense_ledger'=>'expense','reserve_ledger'=>'equity','impairment_ledger'=>'asset','loss_ledger'=>'expense','gain_ledger'=>'revenue','reversal_ledger'=>'revenue'];
    foreach (array_unique(array_merge(array_keys($plan['deltas']),[(int)($data['accumulated_ledger'] ?? 0),(int)($data['expense_ledger'] ?? 0)])) as $ledgerId) {
        $q=db()->prepare("SELECT l.*,COALESCE(g.is_fixed_asset,0) AS group_fixed FROM ledgers l LEFT JOIN ledger_groups g ON g.id=l.group_id WHERE l.id=? AND l.company_id=? AND l.status='active'");$q->execute([$ledgerId,$cid]);$ledger=$q->fetch(PDO::FETCH_ASSOC);
        if (!$ledger) throw new RuntimeException('Select active ledgers belonging to this company.');
        foreach ($types as $field=>$type) if ((int)($data[$field] ?? 0)===$ledgerId && $ledger['type']!==$type) throw new RuntimeException('Incorrect account nature for '.str_replace('_',' ',$field).'.');
        if ($ledgerId===(int)$data['cost_ledger'] && empty($ledger['is_fixed_asset']) && empty($ledger['group_fixed'])) throw new RuntimeException('Mark the asset cost ledger as Fixed Asset in account settings.');
    }
    $assetAccounts=[(int)$data['cost_ledger'],(int)$data['accumulated_ledger']];
    if (!empty($data['impairment_ledger']) && in_array($data['operation'],['impairment','impairment_reversal','revaluation','disposal'],true)) $assetAccounts[]=(int)$data['impairment_ledger'];
    if (count(array_unique($assetAccounts))!==count($assetAccounts)) throw new RuntimeException('Asset cost, accumulated depreciation and accumulated impairment need separate ledgers.');
    return $plan;
}

function faj_save(int $voucherId,int $cid,array $data): void
{
    if (!db()->inTransaction()) throw new LogicException('Asset journals require an accounting transaction.');
    if (faj_blocker($voucherId)) throw new RuntimeException(faj_blocker($voucherId));
    $q=db()->prepare('SELECT * FROM vouchers WHERE id=? AND company_id=? FOR UPDATE');$q->execute([$voucherId,$cid]);$v=$q->fetch();
    if (!$v || $v['voucher_type']!=='journal') throw new RuntimeException('Fixed assets must be entered through a journal voucher.');
    $plan=faj_bundle_plan($cid,$data,$v['voucher_date']);
    $data=faj_pack($plan['events']);
    faj_check_entries($voucherId,$plan);
    db()->prepare('INSERT INTO fixed_asset_journals(voucher_id,company_id,payload) VALUES(?,?,?) ON DUPLICATE KEY UPDATE payload=VALUES(payload)')->execute([$voucherId,$cid,json_encode($data,JSON_THROW_ON_ERROR)]);
    if ($v['status']==='posted') faj_finalize($voucherId,$cid);
}

function faj_check_entries(int $voucherId,array $plan): void
{
    $q=db()->prepare('SELECT ledger_id,entry_type,amount FROM voucher_entries WHERE voucher_id=?');$q->execute([$voucherId]);$actual=[];
    foreach ($q->fetchAll() as $line) $actual[(int)$line['ledger_id']]=round(($actual[(int)$line['ledger_id']] ?? 0)+($line['entry_type']==='debit'?1:-1)*(float)$line['amount'],2);
    foreach (array_unique(array_merge(array_keys($actual),array_keys($plan['deltas']))) as $lid) if (abs(($actual[$lid] ?? 0)-($plan['deltas'][$lid] ?? 0))>0.005) throw new RuntimeException('Journal debit and credit entries do not match the attached asset details. Review the entries and reattach any changed asset details before saving.');
}

function faj_finalize(int $voucherId,int $cid): void
{
    $link=faj_link($voucherId);
    if (!$link || $link['applied']) return;
    if (!db()->inTransaction()) throw new LogicException('Asset posting requires a transaction.');
    $lock=db()->prepare('SELECT * FROM fixed_asset_journals WHERE voucher_id=? AND company_id=? FOR UPDATE');$lock->execute([$voucherId,$cid]);$link=$lock->fetch(PDO::FETCH_ASSOC);
    if (!$link) throw new RuntimeException('Asset journal not found for this company.');
    if ($link['applied']) return;
    $q=db()->prepare("SELECT * FROM vouchers WHERE id=? AND company_id=? AND status='posted' FOR UPDATE");$q->execute([$voucherId,$cid]);$v=$q->fetch();
    if (!$v || (int)$link['company_id']!==$cid) throw new RuntimeException('Asset voucher company mismatch.');
    $data=json_decode($link['payload'],true,32,JSON_THROW_ON_ERROR);
    $bundle=faj_bundle_plan($cid,$data,$v['voucher_date']);
    faj_check_entries($voucherId,$bundle);
    $applied=[];
    foreach($bundle['events'] as $index=>$data) {
    $plan=$bundle['plans'][$index];
    $id=(int)($data['asset_id'] ?? 0);
    $before=db()->prepare('SELECT * FROM fixed_assets WHERE id=? AND company_id=?');$before->execute([$id,$cid]);$data['before']=$before->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($data['operation']==='acquisition') {
        db()->prepare("INSERT INTO fixed_assets(company_id,asset_code,name,purchase_date,available_for_use_date,depreciation_start_date,cost,carrying_amount,residual_value,useful_life_months,depreciation_method,diminishing_rate_pct,status,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,'active',?)")
            ->execute([$cid,trim($data['code']),trim($data['name']),$v['voucher_date'],$data['start_date'],$data['start_date'],$data['amount'],$data['amount'],$data['residual'],$data['life_months'],$data['method'],$data['rate'],$v['submitted_by'] ?? $v['posted_by'] ?? null]);
        $id=(int)db()->lastInsertId();$data['asset_id']=$id;
        foreach (['cost_ledger'=>'ppe_cost','accumulated_ledger'=>'accumulated_depreciation','expense_ledger'=>'depreciation_expense'] as $key=>$purpose) db()->prepare("INSERT INTO asset_ledger_mappings(company_id,scope,asset_id,purpose,ledger_id) VALUES(?,'asset',?,?,?)")->execute([$cid,$id,$purpose,(int)$data[$key]]);
    } else {
        $changes=$plan['changes'];
        if ($data['operation']==='revaluation') $changes['last_revaluation_date']=$v['voucher_date'];
        if ($data['operation']==='disposal') $changes['disposed_on']=$v['voucher_date'];
        $sets=[];$params=[];foreach($changes as $key=>$value){$sets[]="`$key`=?";$params[]=$value;}
        $params[]=$id;$params[]=$cid;db()->prepare('UPDATE fixed_assets SET '.implode(',',$sets).' WHERE id=? AND company_id=?')->execute($params);
        if ($data['operation']==='depreciation') {
            $q=db()->prepare('SELECT COALESCE(MAX(period_no),0)+1 FROM asset_depreciation_schedule WHERE asset_id=?');$q->execute([$id]);$period=(int)$q->fetchColumn();
            db()->prepare('INSERT INTO asset_depreciation_schedule(company_id,asset_id,period_no,period_date,depreciation,accumulated,carrying,voucher_id,posted) VALUES(?,?,?,?,?,?,?,?,1)')->execute([$cid,$id,$period,$v['voucher_date'],$data['amount'],$changes['accumulated_depreciation'],$changes['carrying_amount'],$voucherId]);
        }
        if (in_array($data['operation'],['impairment','impairment_reversal'],true)) {
            $reversal=$data['operation']==='impairment_reversal';
            $movement=abs((float)$changes['carrying_amount']-(float)$data['before']['carrying_amount']);
            db()->prepare('INSERT INTO asset_impairments(company_id,asset_id,test_date,kind,carrying_amount,recoverable_amount,impairment_loss,reversal,voucher_id,created_by,evidence) VALUES(?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$cid,$id,$v['voucher_date'],$reversal?'reversal':'impairment',$data['before']['carrying_amount'],$data['amount'],$reversal?0:$movement,$reversal?$movement:0,$voucherId,$v['posted_by'] ?? $v['submitted_by'] ?? null,mb_substr(trim($data['evidence']),0,255)]);
            // Freeze the contra-asset account used for future reversals/disposal.
            db()->prepare("DELETE FROM asset_ledger_mappings WHERE company_id=? AND scope='asset' AND asset_id=? AND purpose='accumulated_impairment'")->execute([$cid,$id]);
            db()->prepare("INSERT INTO asset_ledger_mappings(company_id,scope,asset_id,purpose,ledger_id) VALUES(?,'asset',?,'accumulated_impairment',?)")->execute([$cid,$id,(int)$data['impairment_ledger']]);
        }
    }
    $after=db()->prepare('SELECT * FROM fixed_assets WHERE id=? AND company_id=?');$after->execute([$id,$cid]);$data['after']=$after->fetch(PDO::FETCH_ASSOC);
    $applied[]=$data;
    log_activity('fixed_asset',$id,'journal_'.$data['operation'],'Journal #'.$voucherId,(int)($v['posted_by'] ?? $v['submitted_by'] ?? 0));
    }
    db()->prepare('UPDATE fixed_asset_journals SET applied=1,payload=? WHERE voucher_id=? AND company_id=?')->execute([json_encode(faj_pack($applied),JSON_THROW_ON_ERROR),$voucherId,$cid]);
}
