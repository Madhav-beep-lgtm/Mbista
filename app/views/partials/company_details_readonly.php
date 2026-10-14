<section class="mbw-card company-details-card">
 <div class="mbw-card-head"><h2>Company details</h2><div class="actions"><a class="button secondary" href="<?= e(url($settingsRoute)) ?>">Fiscal years</a><?php if ($platformShell): ?><a class="button secondary" href="<?= e(url('platform/index.php')) ?>">Back to companies</a><?php endif; ?></div></div>
 <dl class="company-detail-grid">
 <?php $detailFields = ['Company name'=>$companyProfile['name'],'Company code'=>$companyProfile['code'],'PAN'=>setting('company_pan','',false,$settingsCompanyId),'Email'=>setting('support_email','',false,$settingsCompanyId),'Phone'=>setting('support_phone','',false,$settingsCompanyId),'Address'=>setting('office_address','',false,$settingsCompanyId),'Registered address'=>setting('registered_address','',false,$settingsCompanyId)]; ?>
 <?php foreach ($detailFields as $label=>$value): ?><div><dt><?= e($label) ?></dt><dd><?= e($value !== '' ? $value : '—') ?></dd></div><?php endforeach; ?>
 <?php if ($detailOwner): ?><div><dt>Company owner</dt><dd><?= e($detailOwner['name']) ?></dd></div><div><dt>Login email</dt><dd><?= e($detailOwner['email']) ?></dd></div><?php endif; ?>
 <?php if (!empty($companyProfile['logo_path'])): ?><div><dt>Logo</dt><dd><img class="company-logo-preview" src="<?= e(url($companyProfile['logo_path'])) ?>" alt="Company logo" width="96" height="64"></dd></div><?php endif; ?>
 </dl>
 <h3>Bank accounts</h3>
 <div class="table-responsive"><table><thead><tr><th>Bank</th><th>Account name</th><th>Account number</th><th>Branch</th></tr></thead><tbody>
 <?php foreach ($detailBanks as $bank): ?><tr><?php foreach (['bank_name','account_name','account_number','branch'] as $field): ?><td><?= e($bank[$field] ?? '') ?></td><?php endforeach; ?></tr><?php endforeach; ?>
 <?php if (!$detailBanks): ?><tr><td colspan="4">No bank accounts recorded.</td></tr><?php endif; ?>
 </tbody></table></div>
</section>
