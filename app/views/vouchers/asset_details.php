<?php
declare(strict_types=1);
$assetPayload=(string)($retry['input']['asset_payload'] ?? ($editVoucher ? (faj_link((int)$editVoucher['id'])['payload'] ?? '') : ''));
$assetEvents=[];
try {if($assetPayload!=='') $assetEvents=faj_events(json_decode($assetPayload,true,32,JSON_THROW_ON_ERROR));} catch(Throwable $error) {$assetPayload='';}
$assetEventIndex=(int)($retry['input']['asset_event_index'] ?? ($assetEvents?0:-1));
$assetFields=(array)($retry['input']['asset_fields'] ?? $assetEvents[$assetEventIndex] ?? []);
$aq=db()->prepare("SELECT * FROM fixed_assets WHERE company_id=? AND status IN ('active','fully_depreciated') ORDER BY name");$aq->execute([$companyId]);$assetChoices=$aq->fetchAll(PDO::FETCH_ASSOC);
$mappingQuery=db()->prepare('SELECT m.* FROM asset_ledger_mappings m JOIN ledgers l ON l.id=m.ledger_id AND l.company_id=m.company_id WHERE m.company_id=?');$mappingQuery->execute([$companyId]);
$assetMappingIndex=[];
foreach($mappingQuery->fetchAll(PDO::FETCH_ASSOC) as $mapping) {
    $scopeId=$mapping['scope']==='asset'?(int)$mapping['asset_id']:($mapping['scope']==='category'?(int)$mapping['category_id']:0);
    $assetMappingIndex[$mapping['scope']][$scopeId][$mapping['purpose']] ??= (int)$mapping['ledger_id'];
}
foreach($assetChoices as &$choice) {
    foreach(['cost_ledger'=>'ppe_cost','accumulated_ledger'=>'accumulated_depreciation','expense_ledger'=>'depreciation_expense','impairment_ledger'=>'accumulated_impairment','reversal_ledger'=>'impairment_reversal_income'] as $key=>$purpose) $choice[$key]=$assetMappingIndex['asset'][(int)$choice['id']][$purpose] ?? $assetMappingIndex['category'][(int)($choice['category_id'] ?? 0)][$purpose] ?? $assetMappingIndex['global'][0][$purpose] ?? 0;
} unset($choice);
$flagQuery=db()->prepare("SELECT l.id FROM ledgers l LEFT JOIN ledger_groups g ON g.id=l.group_id WHERE l.company_id=? AND l.status='active' AND (l.is_fixed_asset=1 OR g.is_fixed_asset=1)");$flagQuery->execute([$companyId]);$assetLedgerIds=array_map('intval',$flagQuery->fetchAll(PDO::FETCH_COLUMN));
?>
<input type="hidden" name="asset_payload" value="<?= e($assetPayload) ?>">
<input type="hidden" name="asset_event_index" value="<?= $assetEventIndex ?>">
<details id="asset-details" class="asset-journal-details" <?= $assetEvents?'open':'' ?>>
    <summary>Asset details<?= $assetEvents?' ('.count($assetEvents).')':'' ?></summary>
    <div class="asset-dialog-fields">
        <?php if($assetEvents): ?><label>Journal asset<select id="asset-event-picker"><option value="-1">New asset transaction</option><?php foreach($assetEvents as $eventIndex=>$assetEvent): ?><option value="<?= $eventIndex ?>" <?= $eventIndex===$assetEventIndex?'selected':'' ?>><?= e(($assetEvent['name'] ?? $assetEvent['code'] ?? 'Asset').' / '.ucwords(str_replace('_',' ',$assetEvent['operation']))) ?></option><?php endforeach; ?></select></label><div><button type="button" class="button secondary" id="asset-add-another">Add another asset</button></div><?php endif; ?>
        <label>Transaction<select name="asset_fields[operation]" id="asset-operation">
            <?php foreach(['acquisition'=>'Add asset','addition'=>'Additional cost','depreciation'=>'Depreciation','revaluation'=>'Revaluation','impairment'=>'Impairment','impairment_reversal'=>'Impairment reversal','disposal'=>'Disposal'] as $key=>$label): ?><option value="<?= e($key) ?>" <?= ($assetFields['operation'] ?? '')===$key?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?>
        </select></label>
        <label data-asset-existing>Asset<select name="asset_fields[asset_id]" id="asset-existing"><option value="">Select asset</option><?php foreach($assetChoices as $choice): ?><option value="<?= (int)$choice['id'] ?>" <?= (int)($assetFields['asset_id'] ?? $_GET['asset_id'] ?? 0)===(int)$choice['id']?'selected':'' ?>><?= e($choice['asset_code'].' — '.$choice['name']) ?></option><?php endforeach; ?></select></label>
        <label data-asset-new>Asset code<input name="asset_fields[code]" maxlength="80" value="<?= e((string)($assetFields['code'] ?? '')) ?>"></label>
        <label data-asset-new>Item name<input name="asset_fields[name]" maxlength="190" value="<?= e((string)($assetFields['name'] ?? '')) ?>"></label>
        <label data-asset-acquisition>Asset debit entry<select name="asset_fields[journal_line]" id="asset-debit-entry" data-selected="<?= e((string)($assetFields['journal_line'] ?? '')) ?>"><option value="">Select journal debit</option></select></label>
        <label data-asset-acquisition>Funding credit entry<select name="asset_fields[credit_line]" id="asset-credit-entry" data-selected="<?= e((string)($assetFields['credit_line'] ?? '')) ?>"><option value="">Select journal credit</option></select></label>
        <label id="asset-value-field"><span id="asset-amount-label">Asset value</span><input type="number" name="asset_fields[amount]" min="0" step="0.01" value="<?= e((string)($assetFields['amount'] ?? '')) ?>"></label>
        <label data-asset-new>Depreciation basis<select name="asset_fields[method]"><option value="straight_line">Straight-line / original cost</option><option value="diminishing_balance" <?= ($assetFields['method'] ?? '')==='diminishing_balance'?'selected':'' ?>>Diminishing / carrying value</option></select></label>
        <label data-asset-new>Annual depreciation rate (%)<input type="number" name="asset_fields[rate]" min="0.001" max="100" step="0.001" value="<?= e((string)($assetFields['rate'] ?? 20)) ?>"></label>
        <label data-asset-new>Useful life (months)<input type="number" name="asset_fields[life_months]" min="1" max="1200" value="<?= e((string)($assetFields['life_months'] ?? 60)) ?>"></label>
        <label data-asset-new>Residual value<input type="number" name="asset_fields[residual]" min="0" step="0.01" value="<?= e((string)($assetFields['residual'] ?? 0)) ?>"></label>
        <label data-asset-new>Depreciation start<input type="date" name="asset_fields[start_date]" value="<?= e((string)($assetFields['start_date'] ?? $prefill['voucher_date'] ?? $defaultEntryDate)) ?>"></label>
        <?php foreach(['cost_ledger'=>'Asset ledger','accumulated_ledger'=>'Accumulated depreciation ledger','expense_ledger'=>'Depreciation expense ledger','counter_ledger'=>'Payment / supplier / proceeds ledger','reserve_ledger'=>'Revaluation reserve ledger','loss_ledger'=>'Valuation / disposal loss ledger','gain_ledger'=>'Disposal gain ledger','impairment_ledger'=>'Accumulated impairment ledger','reversal_ledger'=>'Impairment reversal income ledger'] as $key=>$label): ?>
        <label data-asset-ledger="<?= e($key) ?>"><?= e($label) ?><select name="asset_fields[<?= e($key) ?>]"><option value="">Select ledger</option><?= $renderLedgerOptions($optionsAll,(int)($assetFields[$key] ?? 0)) ?></select></label>
        <?php endforeach; ?>
        <label data-asset-revaluation>Valuation reference / evidence<input name="asset_fields[evidence]" maxlength="255" value="<?= e((string)($assetFields['evidence'] ?? '')) ?>"></label>
    </div>
    <div class="asset-dialog-foot"><?php if(count($assetEvents)>1): ?><button type="submit" class="button secondary" name="grid_action" value="remove_asset_journal" id="asset-remove" formnovalidate>Remove selected asset details</button><?php endif; ?><button type="submit" class="button secondary" name="grid_action" value="prepare_asset_journal" formnovalidate>Attach asset details</button></div>
</details>
<script>
document.addEventListener('DOMContentLoaded',function(){
    const dialog=document.getElementById('asset-details'), form=document.getElementById('voucher-form');
    const ids=<?= json_encode($assetLedgerIds) ?>, assets=<?= json_encode($assetChoices,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
    const field=k=>form.elements.namedItem('asset_fields['+k+']');
    const events=<?= json_encode($assetEvents,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
    const picker=document.getElementById('asset-event-picker');
    function chooseEvent(index){
        form.elements.asset_event_index.value=index;
        if(picker) picker.value=index;
        const data=index>=0?events[index]:{operation:'acquisition',name:'',code:'',asset_id:'',amount:'',evidence:'',method:'straight_line',rate:20,life_months:60,residual:0};
        if(data) Object.keys(data).forEach(k=>{if(field(k))field(k).value=data[k]??'';});
        const remove=document.getElementById('asset-remove');if(remove)remove.hidden=index<0;
        refresh();
    }
    if(picker)picker.addEventListener('change',()=>chooseEvent(Number(picker.value)));
    const addAnother=document.getElementById('asset-add-another');if(addAnother)addAnother.addEventListener('click',()=>chooseEvent(-1));
    function refresh(){
        const op=field('operation').value, fresh=op==='acquisition', acquisition=['acquisition','addition'].includes(op);
        dialog.querySelectorAll('[data-asset-acquisition]').forEach(el=>el.hidden=!acquisition);
        document.getElementById('asset-value-field').hidden=acquisition;
        dialog.querySelectorAll('[data-asset-new]').forEach(el=>el.hidden=!fresh);
        dialog.querySelector('[data-asset-existing]').hidden=fresh;
        dialog.querySelector('[data-asset-revaluation]').hidden=!['revaluation','impairment','impairment_reversal'].includes(op);
        document.getElementById('asset-amount-label').textContent=({acquisition:'Asset value',addition:'Additional cost',depreciation:'Depreciation amount',revaluation:'Revalued carrying amount',impairment:'Recoverable amount',impairment_reversal:'Recoverable amount',disposal:'Disposal proceeds'})[op];
        dialog.querySelectorAll('[data-asset-ledger]').forEach(el=>{
            const key=el.dataset.assetLedger;
            const operations={counter_ledger:['acquisition','addition','disposal'],reserve_ledger:['revaluation'],loss_ledger:['revaluation','disposal','impairment'],gain_ledger:['disposal'],impairment_ledger:['revaluation','disposal','impairment','impairment_reversal'],reversal_ledger:['impairment_reversal']};
            el.hidden=(acquisition&&['cost_ledger','counter_ledger'].includes(key)) || (operations[key]&&!operations[key].includes(op));
        });
        if(!fresh){const asset=assets.find(a=>Number(a.id)===Number(field('asset_id').value));if(asset){['cost_ledger','accumulated_ledger','expense_ledger'].forEach(k=>field(k).value=asset[k]);['impairment_ledger','reversal_ledger'].forEach(k=>{if(asset[k])field(k).value=asset[k];});field('name').value=asset.name;}}
    }
    function syncEntryChoices(){
        const rows=Array.from(form.querySelectorAll('[data-rows] tr')).filter(row=>row.querySelector('.vch-journal-ledger'));
        [['journal_line','debit'],['credit_line','credit']].forEach(([key,side])=>{
            const select=field(key), chosen=select.value||select.dataset.selected||'';
            select.replaceChildren(new Option('Select journal '+side,''));
            rows.forEach((row,index)=>{
                const ledger=row.querySelector('.vch-journal-ledger'),amount=Number(row.querySelector(side==='debit'?'.vch-dr':'.vch-cr').value)||0;
                if(!ledger.value || (side==='debit'&&!ids.includes(Number(ledger.value))) || amount<=0)return;
                select.add(new Option((index+1)+'. '+ledger.options[ledger.selectedIndex].text+' / '+amount.toFixed(2),String(index)));
            });
            if(Array.from(select.options).some(option=>option.value===chosen))select.value=chosen;
            if(!select.value&&select.options.length===2)select.selectedIndex=1;
            select.dataset.selected=select.value;
        });
    }
    field('operation').addEventListener('change',refresh);field('asset_id').addEventListener('change',refresh);
    form.addEventListener('change',event=>{if(event.target.matches('.vch-journal-ledger')&&ids.includes(Number(event.target.value)))dialog.open=true;});
    form.addEventListener('vch:change',syncEntryChoices);form.addEventListener('input',event=>{if(event.target.closest('[data-rows]'))syncEntryChoices();});
    form.addEventListener('change',event=>{if(event.target.closest('[data-rows]'))syncEntryChoices();});
    field('life_months').addEventListener('change',()=>{if(field('method').value==='straight_line'&&Number(field('life_months').value)>0)field('rate').value=(1200/Number(field('life_months').value)).toFixed(3);});
    refresh();syncEntryChoices();
    <?php if(!empty($_GET['asset_id'])): ?>field('operation').value='addition';refresh();dialog.open=true;<?php endif; ?>
});
</script>
