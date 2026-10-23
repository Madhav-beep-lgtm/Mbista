<?php
declare(strict_types=1);
$assetPayload=(string)($retry['input']['asset_payload'] ?? ($editVoucher ? (faj_link((int)$editVoucher['id'])['payload'] ?? '') : ''));
$assetFields=(array)($retry['input']['asset_fields'] ?? json_decode($assetPayload,true) ?? []);
$aq=db()->prepare("SELECT * FROM fixed_assets WHERE company_id=? AND status IN ('active','fully_depreciated') ORDER BY name");$aq->execute([$companyId]);$assetChoices=$aq->fetchAll(PDO::FETCH_ASSOC);
foreach($assetChoices as &$choice) {
    foreach(['cost_ledger'=>'ppe_cost','accumulated_ledger'=>'accumulated_depreciation','expense_ledger'=>'depreciation_expense'] as $key=>$purpose) $choice[$key]=(int)(fa_resolve_mapping($companyId,$purpose,(int)$choice['id'])['id'] ?? 0);
} unset($choice);
$flagQuery=db()->prepare("SELECT l.id FROM ledgers l LEFT JOIN ledger_groups g ON g.id=l.group_id WHERE l.company_id=? AND l.status='active' AND (l.is_fixed_asset=1 OR g.is_fixed_asset=1)");$flagQuery->execute([$companyId]);$assetLedgerIds=array_map('intval',$flagQuery->fetchAll(PDO::FETCH_COLUMN));
?>
<div class="purchase-items-toolbar"><h2>Journal entries</h2><button type="button" class="button secondary" id="asset-open">Asset details</button></div>
<input type="hidden" name="asset_payload" value="<?= e($assetPayload) ?>">
<dialog id="asset-dialog" class="asset-journal-dialog" aria-labelledby="asset-dialog-title">
    <div class="asset-dialog-head"><h2 id="asset-dialog-title">Fixed asset journal</h2><button type="button" class="button secondary" id="asset-close" aria-label="Close asset details">Close</button></div>
    <div class="asset-dialog-fields">
        <label>Transaction<select name="asset_fields[operation]" id="asset-operation">
            <?php foreach(['acquisition'=>'Add asset','addition'=>'Additional cost','depreciation'=>'Depreciation','revaluation'=>'Revaluation','disposal'=>'Disposal'] as $key=>$label): ?><option value="<?= e($key) ?>" <?= ($assetFields['operation'] ?? '')===$key?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?>
        </select></label>
        <label data-asset-existing>Asset<select name="asset_fields[asset_id]" id="asset-existing"><option value="">Select asset</option><?php foreach($assetChoices as $choice): ?><option value="<?= (int)$choice['id'] ?>" <?= (int)($assetFields['asset_id'] ?? $_GET['asset_id'] ?? 0)===(int)$choice['id']?'selected':'' ?>><?= e($choice['asset_code'].' — '.$choice['name']) ?></option><?php endforeach; ?></select></label>
        <label data-asset-new>Asset code<input name="asset_fields[code]" maxlength="80" value="<?= e((string)($assetFields['code'] ?? '')) ?>"></label>
        <label data-asset-new>Item name<input name="asset_fields[name]" maxlength="190" value="<?= e((string)($assetFields['name'] ?? '')) ?>"></label>
        <label><span id="asset-amount-label">Asset value</span><input type="number" name="asset_fields[amount]" min="0" step="0.01" value="<?= e((string)($assetFields['amount'] ?? '')) ?>"></label>
        <label>Cost centre<select name="asset_fields[cost_centre]"><option value="">Select cost centre</option><?php foreach($costCentres as $centre): ?><option <?= ($assetFields['cost_centre'] ?? '')===$centre?'selected':'' ?>><?= e($centre) ?></option><?php endforeach; ?></select></label>
        <label data-asset-new>Depreciation basis<select name="asset_fields[method]"><option value="straight_line">Straight-line / original cost</option><option value="diminishing_balance" <?= ($assetFields['method'] ?? '')==='diminishing_balance'?'selected':'' ?>>Diminishing / carrying value</option></select></label>
        <label data-asset-new>Annual depreciation rate (%)<input type="number" name="asset_fields[rate]" min="0.001" max="100" step="0.001" value="<?= e((string)($assetFields['rate'] ?? 20)) ?>"></label>
        <label data-asset-new>Useful life (months)<input type="number" name="asset_fields[life_months]" min="1" max="1200" value="<?= e((string)($assetFields['life_months'] ?? 60)) ?>"></label>
        <label data-asset-new>Residual value<input type="number" name="asset_fields[residual]" min="0" step="0.01" value="<?= e((string)($assetFields['residual'] ?? 0)) ?>"></label>
        <label data-asset-new>Depreciation start<input type="date" name="asset_fields[start_date]" value="<?= e((string)($assetFields['start_date'] ?? $prefill['voucher_date'] ?? $defaultEntryDate)) ?>"></label>
        <?php foreach(['cost_ledger'=>'Asset ledger','accumulated_ledger'=>'Accumulated depreciation ledger','expense_ledger'=>'Depreciation expense ledger','counter_ledger'=>'Payment / supplier / proceeds ledger','reserve_ledger'=>'Revaluation reserve ledger','loss_ledger'=>'Revaluation / disposal loss ledger','gain_ledger'=>'Disposal gain ledger','impairment_ledger'=>'Accumulated impairment ledger'] as $key=>$label): ?>
        <label data-asset-ledger="<?= e($key) ?>"><?= e($label) ?><select name="asset_fields[<?= e($key) ?>]"><option value="">Select ledger</option><?= $renderLedgerOptions($optionsAll,(int)($assetFields[$key] ?? 0)) ?></select></label>
        <?php endforeach; ?>
        <label data-asset-revaluation>Valuation reference / evidence<input name="asset_fields[evidence]" maxlength="255" value="<?= e((string)($assetFields['evidence'] ?? '')) ?>"></label>
    </div>
    <div class="asset-dialog-foot"><small>Replaces the journal lines for review. Posting updates the asset register.</small><button type="submit" class="button" name="grid_action" value="prepare_asset_journal" formnovalidate>Prepare journal</button></div>
</dialog>
<script>
document.addEventListener('DOMContentLoaded',function(){
    const dialog=document.getElementById('asset-dialog'), form=document.getElementById('voucher-form'), opener=document.getElementById('asset-open');
    const ids=<?= json_encode($assetLedgerIds) ?>, assets=<?= json_encode($assetChoices,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
    const field=k=>form.elements.namedItem('asset_fields['+k+']');
    function refresh(){
        const op=field('operation').value, fresh=op==='acquisition';
        dialog.querySelectorAll('[data-asset-new]').forEach(el=>el.hidden=!fresh);
        dialog.querySelector('[data-asset-existing]').hidden=fresh;
        dialog.querySelector('[data-asset-revaluation]').hidden=op!=='revaluation';
        document.getElementById('asset-amount-label').textContent=({acquisition:'Asset value',addition:'Additional cost',depreciation:'Depreciation amount',revaluation:'Revalued carrying amount',disposal:'Disposal proceeds'})[op];
        dialog.querySelectorAll('[data-asset-ledger]').forEach(el=>{
            const key=el.dataset.assetLedger;
            el.hidden=(key==='counter_ledger'&&!['acquisition','addition','disposal'].includes(op)) || (['reserve_ledger','loss_ledger','gain_ledger','impairment_ledger'].includes(key)&&!['revaluation','disposal'].includes(op));
        });
        if(!fresh){const asset=assets.find(a=>Number(a.id)===Number(field('asset_id').value));if(asset){['cost_ledger','accumulated_ledger','expense_ledger'].forEach(k=>field(k).value=asset[k]);field('name').value=asset.name;}}
    }
    opener.addEventListener('click',()=>{refresh();dialog.showModal();});
    document.getElementById('asset-close').addEventListener('click',()=>dialog.close());
    dialog.addEventListener('close',()=>opener.focus());
    field('operation').addEventListener('change',refresh);field('asset_id').addEventListener('change',refresh);
    form.addEventListener('change',event=>{if(event.target.matches('.vch-journal-ledger')&&ids.includes(Number(event.target.value))&&!form.elements.asset_payload.value){field('cost_ledger').value=event.target.value;refresh();dialog.showModal();}});
    field('life_months').addEventListener('change',()=>{if(field('method').value==='straight_line'&&Number(field('life_months').value)>0)field('rate').value=(1200/Number(field('life_months').value)).toFixed(3);});
    refresh();
    <?php if(!empty($_GET['asset_id']) || !empty($_GET['asset_new'])): ?>
    <?php if(!empty($_GET['asset_id'])): ?>field('operation').value='addition';refresh();<?php endif; ?>dialog.showModal();
    <?php endif; ?>
});
</script>
