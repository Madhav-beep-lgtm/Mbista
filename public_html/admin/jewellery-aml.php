<?php
declare(strict_types=1);
require_once __DIR__.'/../../app/bootstrap.php';
require_once __DIR__.'/../../app/accounting_module_repair.php';
require_once __DIR__.'/../../app/jewellery_engine.php';
require_once __DIR__.'/../../app/jewellery_aml.php';
require_once __DIR__.'/../../app/export_engine.php';
accounting_module_repair_database(); require_jewellery(); require_permission('jewellery','post');
$company=current_company(); $fy=current_fiscal_year();
if(!$company||!$fy){flash('error','Company and fiscal year context required.');redirect('admin/accounting-dashboard.php');}
$companyId=(int)$company['id']; $userId=(int)(current_user()['id']??0); $canReview=true;
$from=(string)($_GET['from']??$fy['start_date']); $to=(string)($_GET['to']??min((string)$fy['end_date'],date('Y-m-d')));
$status=(string)($_GET['status']??''); if(!in_array($status,['','candidate','under_review','approved','dismissed','filed'],true))$status='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf(); require_permission('jewellery','post'); $action=(string)($_POST['action']??'');
 if($action==='scan'){ $r=jw_aml_scan($companyId,$from,$to,$userId); flash('success','Scanned '.$r['transactions'].' transactions; refreshed '.$r['created_or_refreshed'].' AML candidates.'); }
 elseif($action==='manual_sar'){ $id=jw_aml_create_manual_sar($companyId,(int)($_POST['party_id']??0),trim((string)($_POST['activity_date']??date('Y-m-d'))),trim((string)($_POST['reason']??'')),trim((string)($_POST['narrative']??'')),$userId); flash($id?'success':'error',$id?'Manual SAR candidate created for compliance review.':'A reason is required.'); }
 elseif($action==='save_settings'){ $threshold=max(1,(float)($_POST['ttr_threshold']??1000000)); $due=max(1,(int)($_POST['ttr_due_days']??15)); $near=max(1,min(100,(float)($_POST['near_threshold_percent']??90))); $structuring=max(2,(int)($_POST['structuring_min_count']??3)); $missing=max(1,(float)($_POST['missing_kyc_threshold']??500000)); db()->prepare('INSERT INTO jewellery_aml_settings (company_id,ttr_threshold,ttr_due_days,near_threshold_percent,structuring_min_count,missing_kyc_threshold,updated_by) VALUES (:cid,:threshold,:due,:near,:cnt,:missing,:uid) ON DUPLICATE KEY UPDATE ttr_threshold=VALUES(ttr_threshold),ttr_due_days=VALUES(ttr_due_days),near_threshold_percent=VALUES(near_threshold_percent),structuring_min_count=VALUES(structuring_min_count),missing_kyc_threshold=VALUES(missing_kyc_threshold),updated_by=VALUES(updated_by)')->execute(['cid'=>$companyId,'threshold'=>$threshold,'due'=>$due,'near'=>$near,'cnt'=>$structuring,'missing'=>$missing,'uid'=>$userId]); log_activity('jewellery_aml',$companyId,'rules_updated','AML detection settings updated.',$userId); flash('success','AML detection settings updated.'); }
 else { $ok=jw_aml_update_case($companyId,(int)($_POST['case_id']??0),$action,$_POST,$userId); flash($ok?'success':'error',$ok?'AML review updated.':'Could not update AML review; check required fields.'); }
 redirect('admin/jewellery-aml.php?from='.urlencode($from).'&to='.urlencode($to).'&status='.urlencode($status));
}
if(isset($_GET['export'])){
 require_permission('jewellery','export'); $data=[['Case','Type','Date','Due','Customer','PAN','Amount','Transactions','Risk','Rule','Status','goAML reference']];
 foreach(jw_aml_cases($companyId,$from,$to,$status) as $c)$data[]=[(int)$c['id'],$c['case_type'],$c['case_date'],$c['due_on'],$c['party_name'],$c['pan_no'],$c['aggregate_amount'],$c['transaction_count'],$c['risk_score'],$c['rule_code'],$c['status'],$c['goaml_reference']];
 export_dispatch('csv','jewellery-goaml-register-'.$from.'-'.$to,$data,'Jewellery AML / goAML Register',['Rule'=>'FIU-Nepal July 2025']);
}
$amlLoadError = '';
if(jw_aml_ready()) {
 try { jw_aml_scan($companyId,$from,$to,0); }
 catch(Throwable $e) {
  $amlLoadError = $e->getMessage();
  error_log('Jewellery AML automatic scan failed for company '.$companyId.': '.$e->getMessage());
 }
}
$amlSettings=jw_aml_settings($companyId);
$cases=[];
if($amlLoadError==='') {
 try { $cases=jw_aml_cases($companyId,$from,$to,$status); }
 catch(Throwable $e) {
  $amlLoadError=$e->getMessage();
  error_log('Jewellery AML register load failed for company '.$companyId.': '.$e->getMessage());
 }
}
$selectedId=(int)($_GET['case_id']??0); $selected=null; foreach($cases as $c)if((int)$c['id']===$selectedId)$selected=$c;
$transactions=$selected?jw_aml_case_transactions($companyId,$selectedId):[];
$pageTitle='Jewellery AML / goAML'; $pageSubtitle='FIU-Nepal candidate detection, compliance review and filing register'; $bodyClass='admin-layout';
include __DIR__.'/../../app/views/partials/admin_header.php';
?>
<section class="mbw-card"><div class="mbw-card-head"><div><h1>AML / goAML Reporting</h1><p>TTR candidates are deterministic. STR/SAR candidates are internal red flags and require compliance-officer examination before filing.</p></div></div>
<?php if(!jw_aml_ready()): ?><div class="alert error">Migration 114 is required before AML reporting can run.</div><?php else: ?>
<?php if($amlLoadError!==''): ?><div class="alert error"><strong>AML data scan could not run.</strong> The page remains available, but the production database must be upgraded or repaired. Administrator detail: <?=e($amlLoadError)?></div><?php endif;?>
<form method="get" class="jw-aml-filters"><label>From <input type="date" name="from" value="<?=e($from)?>"></label><label>To <input type="date" name="to" value="<?=e($to)?>"></label><label>Status <select name="status"><option value="">All</option><?php foreach(['candidate','under_review','approved','dismissed','filed'] as $s):?><option value="<?=e($s)?>" <?=$status===$s?'selected':''?>><?=e(ucwords(str_replace('_',' ',$s)))?></option><?php endforeach;?></select></label><div class="jw-aml-filter-actions"><button class="button" type="submit">Filter</button><a class="button secondary" href="<?=e(url('admin/jewellery-aml.php?from='.$from.'&to='.$to.'&status='.$status.'&export=csv'))?>">Export register</a></div></form>
<?php if($canReview):?><form method="post" style="margin-top:12px"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="scan"><button class="button" type="submit">Scan posted transactions</button></form><?php endif;?>
<?php if($canReview):?><details style="margin-top:12px"><summary>Create SAR candidate for attempted transaction or suspicious activity</summary><form method="post" class="stack" style="margin-top:10px"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="manual_sar"><label>Activity date<input type="date" name="activity_date" value="<?=e(date('Y-m-d'))?>" required></label><label>Party ID (optional)<input type="number" min="1" name="party_id"></label><label>Reason / red flags<textarea name="reason" rows="3" required></textarea></label><label>Initial narrative<textarea name="narrative" rows="5"></textarea></label><button class="button" type="submit">Create review candidate</button></form></details><?php endif;?>
<?php if($canReview):?><details style="margin-top:12px"><summary>Detection settings (current rule: <?=e($amlSettings['rule_version'])?>)</summary><form method="post" class="stack" style="margin-top:10px"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="save_settings"><label>TTR threshold (NPR)<input type="number" min="1" step="0.01" name="ttr_threshold" value="<?=e((string)$amlSettings['ttr_threshold'])?>" required></label><label>Filing due days<input type="number" min="1" name="ttr_due_days" value="<?=e((string)$amlSettings['ttr_due_days'])?>" required></label><label>Near-threshold warning %<input type="number" min="1" max="100" step="0.01" name="near_threshold_percent" value="<?=e((string)$amlSettings['near_threshold_percent'])?>"></label><label>Structuring minimum transaction count<input type="number" min="2" name="structuring_min_count" value="<?=e((string)$amlSettings['structuring_min_count'])?>"></label><label>Missing-KYC warning threshold<input type="number" min="1" step="0.01" name="missing_kyc_threshold" value="<?=e((string)$amlSettings['missing_kyc_threshold'])?>"></label><button class="button" type="submit">Save detection settings</button></form></details><?php endif;?>
<div style="overflow-x:auto;margin-top:14px"><table><thead><tr><th>Case</th><th>Type</th><th>Date / due</th><th>Customer</th><th>Amount</th><th>Risk</th><th>Reason</th><th>Status</th></tr></thead><tbody>
<?php if(!$cases):?><tr><td colspan="8">No AML candidates in this period. Run the scanner after posting transactions.</td></tr><?php endif; foreach($cases as $c):?><tr><td><a href="<?=e(url('admin/jewellery-aml.php?from='.$from.'&to='.$to.'&status='.$status.'&case_id='.(int)$c['id']))?>">#<?=e((string)$c['id'])?></a></td><td><strong><?=e($c['case_type'])?></strong><br><small><?=e($c['candidate_kind'])?></small></td><td><?=e(app_date($c['case_date']))?><br><small><?=e($c['due_on']?('Due '.app_date($c['due_on'])):'Review promptly')?></small></td><td><?=e($c['party_name'])?><br><small>PAN: <?=e($c['pan_no']?:'missing')?></small></td><td><?=e(site_currency_symbol().number_format((float)$c['aggregate_amount'],2))?><br><small><?=e((string)$c['transaction_count'])?> transaction(s)</small></td><td><?=e((string)$c['risk_score'])?></td><td><?=e($c['reason'])?></td><td><?=e(ucwords(str_replace('_',' ',$c['status'])))?></td></tr><?php endforeach;?></tbody></table></div>
<?php endif;?></section>
<?php if($selected):?><section class="mbw-card" style="margin-top:14px"><h2>Case #<?=e((string)$selected['id'])?> review</h2><div style="overflow-x:auto"><table><thead><tr><th>Date</th><th>Document</th><th>Direction</th><th>Mode</th><th>Amount</th></tr></thead><tbody><?php foreach($transactions as $t):?><tr><td><?=e(app_date($t['transaction_date']))?></td><td><?=e($t['document_no'])?></td><td><?=e($t['direction'])?></td><td><?=e($t['payment_mode'])?></td><td><?=e(number_format((float)$t['amount'],2))?></td></tr><?php endforeach;?></tbody></table></div>
<?php if($canReview):?><form method="post" class="stack" style="margin-top:14px"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="case_id" value="<?=e((string)$selected['id'])?>"><label>Source of funds / declaration<input name="source_of_funds" value="<?=e($selected['source_of_funds']??'')?>"></label><label>Compliance analysis and goAML narrative<textarea name="narrative" rows="7"><?=e($selected['narrative']??'')?></textarea></label><label>Dismissal reason (required to dismiss)<textarea name="dismissal_reason" rows="2"></textarea></label><label>goAML acknowledgement/reference (required to mark filed)<input name="goaml_reference" value="<?=e($selected['goaml_reference']??'')?>"></label><div class="actions"><button class="button secondary" name="action" value="start_review">Start review</button><button class="button" name="action" value="approve">Approve for filing</button><button class="button secondary" name="action" value="dismiss">Dismiss with reason</button><button class="button" name="action" value="mark_filed">Mark filed</button></div></form><?php endif;?></section><?php endif;?>
<?php include __DIR__.'/../../app/views/partials/admin_footer.php'; ?>
