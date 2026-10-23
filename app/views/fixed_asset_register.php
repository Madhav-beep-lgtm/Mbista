<?php
declare(strict_types=1);
require_permission('accounting','view');
if(!company_module_enabled('fixed_assets',$companyId)) deny_access('Fixed Assets is not active for this company.');
$search=trim((string)($_GET['q'] ?? ''));
$params=[$companyId];$where='company_id=?';
if($search!==''){$where.=' AND (name LIKE ? OR asset_code LIKE ?)';$params[]='%'.$search.'%';$params[]='%'.$search.'%';}
$q=db()->prepare('SELECT COUNT(*) FROM fixed_assets WHERE '.$where);$q->execute($params);$total=(int)$q->fetchColumn();
$page=max(1,min((int)($_GET['page'] ?? 1),max(1,(int)ceil($total/50))));$offset=($page-1)*50;
$q=db()->prepare('SELECT * FROM fixed_assets WHERE '.$where.' ORDER BY asset_code,id LIMIT 50 OFFSET '.$offset);$q->execute($params);$assets=$q->fetchAll(PDO::FETCH_ASSOC);
$selected=null;$events=[];$schedule=[];
if(!empty($_GET['asset_id'])) {
    $q=db()->prepare('SELECT * FROM fixed_assets WHERE company_id=? AND id=?');$q->execute([$companyId,(int)$_GET['asset_id']]);$selected=$q->fetch(PDO::FETCH_ASSOC);
    if(!$selected) deny_access('Asset not found.');
    $q=db()->prepare("SELECT v.id,v.voucher_no,v.voucher_date,v.status,j.payload FROM fixed_asset_journals j JOIN vouchers v ON v.id=j.voucher_id WHERE j.company_id=? AND JSON_UNQUOTE(JSON_EXTRACT(j.payload,'$.asset_id'))=? ORDER BY v.voucher_date DESC,v.id DESC LIMIT 100");$q->execute([$companyId,(string)$selected['id']]);$events=$q->fetchAll(PDO::FETCH_ASSOC);
    $q=db()->prepare('SELECT * FROM asset_depreciation_schedule WHERE company_id=? AND asset_id=? ORDER BY period_date DESC LIMIT 100');$q->execute([$companyId,$selected['id']]);$schedule=$q->fetchAll(PDO::FETCH_ASSOC);
}
$pageTitle='Fixed Assets';$pageHero=false;$bodyClass='admin-layout accounting-module-page';
include __DIR__.'/partials/admin_header.php';
?>
<section class="mbw-card">
    <div class="mbw-card-head"><h2>Fixed Assets</h2><div class="mbw-card-tools">
        <a class="button secondary" href="<?= e(url('admin/accounting.php?type=journal')) ?>">Journal register</a>
        <?php if(user_can_do('accounting','create')): ?><a class="button" href="<?= e(url('admin/voucher-form.php?type=journal&asset_new=1')) ?>">Add through journal</a><?php endif; ?>
        <a class="button secondary" href="<?= e(url('admin/fixed-assets.php?legacy=1')) ?>">Existing schedules &amp; advanced workflows</a>
    </div></div>
    <form method="get" class="asset-register-search"><label>Search<input type="search" name="q" value="<?= e($search) ?>" placeholder="Asset code or name"></label><button class="button secondary">Search</button></form>
    <div class="mbw-tablewrap"><table><thead><tr><th>Code</th><th>Item</th><th>Acquired</th><th>Cost</th><th>Accumulated depreciation</th><th>Carrying amount</th><th>Basis</th><th>Status</th><th>Action</th></tr></thead><tbody>
    <?php foreach($assets as $asset): ?><tr>
        <td><?= e($asset['asset_code']) ?></td><td><?= e($asset['name']) ?></td><td><?= e(app_date((string)$asset['purchase_date'])) ?></td>
        <td class="is-numeric"><?= e(number_format((float)$asset['cost']+(float)$asset['directly_attributable_cost']+(float)$asset['restoration_provision'],2)) ?></td>
        <td class="is-numeric"><?= e(number_format((float)$asset['accumulated_depreciation'],2)) ?></td><td class="is-numeric"><?= e(number_format((float)$asset['carrying_amount'],2)) ?></td>
        <td><?= e(ucwords(str_replace('_',' ',$asset['depreciation_method']))) ?></td><td><?= e(ucwords(str_replace('_',' ',$asset['status']))) ?></td>
        <td><a class="button secondary" href="?asset_id=<?= (int)$asset['id'] ?>">View</a> <?php if(user_can_do('accounting','create') && in_array($asset['status'],['active','fully_depreciated'],true)): ?><a class="button secondary" href="<?= e(url('admin/voucher-form.php?type=journal&asset_id='.(int)$asset['id'])) ?>">Journal</a><?php endif; ?></td>
    </tr><?php endforeach; ?>
    <?php if(!$assets): ?><tr><td colspan="9">No assets found.</td></tr><?php endif; ?>
    </tbody></table></div>
    <div class="asset-register-search"><span><?= $total ?> assets</span><?php if($page>1): ?><a class="button secondary" href="?<?= e(http_build_query(['q'=>$search,'page'=>$page-1])) ?>">Previous</a><?php endif; ?><?php if($offset+50<$total): ?><a class="button secondary" href="?<?= e(http_build_query(['q'=>$search,'page'=>$page+1])) ?>">Next</a><?php endif; ?></div>
</section>
<?php if($selected): ?>
<section class="mbw-card"><div class="mbw-card-head"><h2><?= e($selected['asset_code'].' — '.$selected['name']) ?></h2></div>
    <div class="asset-register-search"><span>Residual: <?= e(number_format((float)$selected['residual_value'],2)) ?></span><span>Useful life: <?= (int)$selected['useful_life_months'] ?> months</span><span>Depreciation starts: <?= e(app_date((string)$selected['depreciation_start_date'])) ?></span></div>
    <h3>Asset journals</h3><div class="mbw-tablewrap"><table><thead><tr><th>Date</th><th>Voucher</th><th>Transaction</th><th>Value</th><th>Status</th></tr></thead><tbody>
    <?php foreach($events as $event): $eventData=json_decode($event['payload'],true); ?><tr><td><?= e(app_date($event['voucher_date'])) ?></td><td><a href="<?= e(url('admin/voucher-view.php?id='.(int)$event['id'])) ?>"><?= e($event['voucher_no']) ?></a></td><td><?= e(ucfirst($eventData['operation'])) ?></td><td><?= e(number_format((float)$eventData['amount'],2)) ?></td><td><?= e(ucfirst($event['status'])) ?></td></tr><?php endforeach; ?>
    <?php if(!$events): ?><tr><td colspan="5">No linked asset journals.</td></tr><?php endif; ?></tbody></table></div>
    <h3>Depreciation history</h3><div class="mbw-tablewrap"><table><thead><tr><th>Date</th><th>Depreciation</th><th>Accumulated</th><th>Carrying amount</th><th>Status</th></tr></thead><tbody>
    <?php foreach($schedule as $line): ?><tr><td><?= e(app_date($line['period_date'])) ?></td><td><?= e(number_format((float)$line['depreciation'],2)) ?></td><td><?= e(number_format((float)$line['accumulated'],2)) ?></td><td><?= e(number_format((float)$line['carrying'],2)) ?></td><td><?= $line['posted']?'Posted':'Planned' ?></td></tr><?php endforeach; ?>
    <?php if(!$schedule): ?><tr><td colspan="5">No depreciation entries.</td></tr><?php endif; ?></tbody></table></div>
</section>
<?php endif; ?>
<?php include __DIR__.'/partials/admin_footer.php'; ?>
