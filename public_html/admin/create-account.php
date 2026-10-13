<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/accounting_module_repair.php';
require_staff_admin_or_client_books();
require_company_context();
$editId = max(0, (int) ($_GET['edit_id'] ?? $_POST['edit_id'] ?? 0));
$editKind = (string) ($_GET['edit_kind'] ?? $_POST['edit_kind'] ?? 'ledger');
if (!in_array($editKind, ['ledger', 'group'], true)) { http_response_code(400); exit('Invalid account kind.'); }
$editTable = $editKind === 'group' ? 'ledger_groups' : 'ledgers';
require_permission('accounting', $editId > 0 ? 'edit' : 'create');
$repairErrors = accounting_module_repair_database();
if (!column_exists('ledgers', 'pan_no') || !column_exists('ledgers', 'parent_ledger_id') || !column_exists('ledger_groups', 'use_cost_centre') || !column_exists('ledgers', 'is_cash_equivalent') || !column_exists('ledger_groups', 'is_cash_equivalent')) {
    $pageTitle = $editId > 0 ? 'Edit account' : 'Create account';
    $pageHero = false;
    include __DIR__ . '/../../app/views/partials/admin_header.php';
    echo '<div class="notice error">Account creation requires the latest database upgrade. Run the deployment schema repair before continuing.</div>';
    include __DIR__ . '/../../app/views/partials/admin_footer.php';
    exit;
}
$companyId = (int) current_company()['id'];
$editAccount = null;
if ($editId > 0) {
    $editStmt = db()->prepare("SELECT * FROM {$editTable} WHERE id = ? AND company_id = ?");
    $editStmt->execute([$editId, $companyId]);
    $editAccount = $editStmt->fetch(PDO::FETCH_ASSOC);
    if (!$editAccount) { http_response_code(404); exit('Account not found.'); }
}
$editDefaults = $editAccount ? array_merge($editAccount, $editKind === 'group' ? [
    'level' => empty($editAccount['parent_group_id']) ? 'group' : 'subgroup',
    'nature' => ledger_master_nature($editAccount['master_key']),
    'parent' => empty($editAccount['parent_group_id']) ? 'master:' . $editAccount['master_key'] : 'group:' . $editAccount['parent_group_id'],
] : [
    'level' => empty($editAccount['parent_ledger_id']) ? 'ledger' : 'subledger',
    'nature' => $editAccount['type'],
    'parent' => empty($editAccount['parent_ledger_id']) ? 'group:' . $editAccount['group_id'] : 'ledger:' . $editAccount['parent_ledger_id'],
]) : null;
$masters = ledger_masters();
$natureLabels = ['asset' => 'Assets', 'liability' => 'Liabilities', 'equity' => 'Equity', 'expense' => 'Expenses', 'revenue' => 'Income'];
$stmt = db()->prepare('SELECT * FROM ledger_groups WHERE company_id = :cid AND is_active = 1 ORDER BY name');
$stmt->execute(['cid' => $companyId]);
$groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt = db()->prepare('SELECT id, code, name, group_id, type FROM ledgers WHERE company_id = :cid AND status = \'active\' AND parent_ledger_id IS NULL ORDER BY name');
$stmt->execute(['cid' => $companyId]);
$parents = $stmt->fetchAll(PDO::FETCH_ASSOC);
$values = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : ($editDefaults ?? ['level' => ($_GET['level'] ?? '') === 'group' ? 'group' : 'ledger', 'nature' => 'asset']);
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        if ($repairErrors !== []) throw new InvalidArgumentException('Account setup needs repair before saving.');
        $level = (string) ($values['level'] ?? '');
        $nature = (string) ($values['nature'] ?? '');
        if ($editAccount && ($level !== $editDefaults['level'] || $nature !== $editDefaults['nature'] || ($values['parent'] ?? '') !== $editDefaults['parent'])) {
            throw new InvalidArgumentException('Account level, nature and parent cannot be changed while editing.');
        }
        $name = trim((string) ($values['name'] ?? ''));
        if (!in_array($level, ['group', 'subgroup', 'ledger', 'subledger'], true)) throw new InvalidArgumentException('Select a valid level. Masters are system-defined.');
        if ($name === '' || mb_strlen($name) > 150 || !isset($natureLabels[$nature])) throw new InvalidArgumentException('Enter an account name (up to 150 characters) and select its nature.');
        if (in_array($nature, ['revenue', 'expense'], true)) {
            foreach (['email', 'contact_no', 'address', 'bank_name', 'bank_account_no', 'bank_branch'] as $field) $values[$field] = '';
        }
        $email = trim((string) ($values['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Enter a valid email address.');
        $opening = trim((string) ($values['opening_balance'] ?? ''));
        if ($opening !== '' && (!is_numeric($opening) || !is_finite((float) $opening) || (float) $opening < 0)) throw new InvalidArgumentException('Opening balance must be a non-negative amount.');
        $side = (string) ($values['opening_side'] ?? 'debit');
        if (!in_array($side, ['debit', 'credit'], true)) throw new InvalidArgumentException('Select Debit or Credit.');
        $isGroup = in_array($level, ['group', 'subgroup'], true);
        $panNo = trim((string) ($values['pan_no'] ?? ''));
        if (!$isGroup && mb_strlen($panNo) > 60) throw new InvalidArgumentException('PAN Number must be at most 60 characters.');
        if ((float) $opening > 0 && ($isGroup || in_array($nature, ['revenue', 'expense'], true))) throw new InvalidArgumentException('Opening balances apply only to asset, liability and equity ledgers or subledgers.');
        if ((!empty($values['is_fixed_asset']) || !empty($values['is_cash_or_bank'])) && $nature !== 'asset') throw new InvalidArgumentException('Fixed Asset and Cash / bank are available for asset accounts only.');
        $metadata = [];
        foreach (['alias_name' => 160, 'email' => 190, 'contact_no' => 40, 'address' => 2000, 'bank_name' => 120, 'bank_account_no' => 40, 'bank_branch' => 120] as $field => $limit) {
            $metadata[$field] = trim((string) ($values[$field] ?? ''));
            if (mb_strlen($metadata[$field]) > $limit) throw new InvalidArgumentException(ucwords(str_replace('_', ' ', $field)) . ' is too long.');
        }
        if (!empty($values['is_cash_equivalent']) && $nature !== 'asset') throw new InvalidArgumentException('Cash or cash equivalent is available for asset accounts only.');
        foreach (['is_fixed_asset', 'maintain_bill_by_bill', 'use_cost_centre', 'is_cash_equivalent'] as $field) $metadata[$field] = empty($values[$field]) ? 0 : 1;
        if (!$isGroup) $metadata['pan_no'] = $panNo ?: null;
        db()->beginTransaction();
        // Serialize code allocation for this company's unified creation flow.
        $lock = db()->prepare('SELECT id FROM companies WHERE id = :cid FOR UPDATE');
        $lock->execute(['cid' => $companyId]);
        $parent = (string) ($values['parent'] ?? '');
        $masterKey = '';
        $groupId = null;
        $ledgerParent = null;
        if ($isGroup && str_starts_with($parent, 'master:') && $level === 'group') {
            $masterKey = substr($parent, 7);
            if (!isset($masters[$masterKey])) throw new InvalidArgumentException('Select a valid master.');
        } elseif (($level === 'subgroup' || $level === 'ledger') && str_starts_with($parent, 'group:')) {
            $groupId = (int) substr($parent, 6);
            $find = db()->prepare('SELECT master_key FROM ledger_groups WHERE id = :id AND company_id = :cid AND is_active = 1');
            $find->execute(['id' => $groupId, 'cid' => $companyId]);
            $masterKey = (string) ($find->fetchColumn() ?: '');
        } elseif ($level === 'subledger' && str_starts_with($parent, 'ledger:')) {
            $ledgerParent = (int) substr($parent, 7);
            $find = db()->prepare('SELECT l.group_id, g.master_key, l.type FROM ledgers l JOIN ledger_groups g ON g.id = l.group_id AND g.company_id = l.company_id WHERE l.id = :id AND l.company_id = :cid AND l.status = \'active\' AND l.parent_ledger_id IS NULL');
            $find->execute(['id' => $ledgerParent, 'cid' => $companyId]);
            $parentRow = $find->fetch(PDO::FETCH_ASSOC);
            if (!$parentRow || $parentRow['type'] !== $nature) throw new InvalidArgumentException('Select an active parent ledger of the same nature.');
            $groupId = (int) $parentRow['group_id'];
            $masterKey = (string) $parentRow['master_key'];
        }
        if ($masterKey === '' || ledger_master_nature($masterKey) !== $nature) throw new InvalidArgumentException('Select a parent of the same nature and correct level.');
        $table = $isGroup ? 'ledger_groups' : 'ledgers';
        $duplicate = db()->prepare("SELECT id FROM $table WHERE company_id = :cid AND LOWER(TRIM(name)) = LOWER(:name) AND id <> :edit_id LIMIT 1");
        $duplicate->execute(['cid' => $companyId, 'name' => $name, 'edit_id' => $editId]);
        if ($duplicate->fetchColumn()) throw new InvalidArgumentException('An account with this name already exists at this level.');
        $code = $editAccount ? (string) $editAccount['code'] : ($isGroup ? coa_next_group_code($companyId, $masterKey, $groupId) : coa_next_ledger_code($companyId, (int) $groupId, $ledgerParent, $nature));
        $data = ['company_id' => $companyId, 'code' => $code, 'name' => $name, 'is_system' => 0] + $metadata;
        if ($isGroup) $data += ['master_key' => $masterKey, 'parent_group_id' => $groupId, 'is_active' => 1, 'is_cash_or_bank' => empty($values['is_cash_or_bank']) ? 0 : 1];
        else $data += ['group_id' => $groupId, 'parent_ledger_id' => $ledgerParent, 'type' => $nature, 'status' => 'active'];
        if ($editAccount) {
            $locked = db()->prepare("SELECT * FROM {$table} WHERE id = ? AND company_id = ? FOR UPDATE");
            $locked->execute([$editId, $companyId]);
            $current = $locked->fetch(PDO::FETCH_ASSOC);
            $structureChanged = $isGroup
                ? (!$current || $current['master_key'] !== $masterKey || (int) $current['parent_group_id'] !== (int) $groupId)
                : (!$current || $current['type'] !== $nature || (int) $current['group_id'] !== (int) $groupId || (int) $current['parent_ledger_id'] !== (int) $ledgerParent);
            if ($structureChanged) {
                throw new InvalidArgumentException('Account structure changed. Reload before saving.');
            }
            // Preserve code, status, hierarchy and system ownership.
            $updates = ['name' => $name] + $metadata;
            if ($isGroup) $updates['is_cash_or_bank'] = empty($values['is_cash_or_bank']) ? 0 : 1;
            $sets = array_map(static fn (string $key): string => "`$key` = :$key", array_keys($updates));
            db()->prepare('UPDATE ' . $table . ' SET ' . implode(', ', $sets) . ' WHERE id = :edit_id AND company_id = :cid')
                ->execute($updates + ['edit_id' => $editId, 'cid' => $companyId]);
            $id = $editId;
        } else {
        $columns = array_keys($data);
        db()->prepare('INSERT INTO ' . $table . ' (`' . implode('`,`', $columns) . '`) VALUES (:' . implode(',:', $columns) . ')')->execute($data);
        $id = (int) db()->lastInsertId();
        }
        if (!$isGroup) {
            $partyId = sync_party_from_chart_ledger($companyId, $id, (int) current_user()['id']);
            if ($partyId > 0) db()->prepare('UPDATE accounting_parties SET pan_no = ? WHERE id = ? AND company_id = ?')->execute([$panNo ?: null, $partyId, $companyId]);
            if ($opening !== '' && in_array($nature, ['asset', 'liability', 'equity'], true)) {
                $openingError = post_ledger_opening_balance($companyId, $id, (float) $opening, $side, (int) current_user()['id']);
                if ($openingError !== null) throw new InvalidArgumentException($openingError);
            }
        }
        log_activity($isGroup ? 'ledger_group' : 'ledger', $id, $editAccount ? 'updated' : 'created', ($editAccount ? 'Updated ' : 'Created ') . $level . ' ' . $code . ': ' . $name, (int) current_user()['id']);
        db()->commit();
        flash('success', 'Account ' . $code . ($editAccount ? ' updated.' : ' created.'));
        redirect($editAccount ? 'admin/create-account.php?edit_kind=' . $editKind . '&edit_id=' . $editId : 'admin/create-account.php');
    } catch (Throwable $exception) {
        if (db()->inTransaction()) db()->rollBack();
        $error = $exception instanceof InvalidArgumentException ? $exception->getMessage() : 'Account could not be saved. Please retry or check the accounting setup.';
    }
}
$pageTitle = $editAccount ? 'Edit account' : 'Create account';
$pageHero = false;
$bodyClass = 'admin-layout accounting-module-page chart-accounts-page';
include __DIR__ . '/../../app/views/partials/admin_header.php';
$field = static fn (string $key): string => e((string) ($values[$key] ?? ''));
?>
<?php if ($error): ?><div class="notice error" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if ($repairErrors): ?><div class="notice error">Account setup needs repair before saving.</div><?php endif; ?>
<section class="mbw-card">
    <div class="mbw-card-head"><h2><?= e($pageTitle) ?></h2></div>
    <form method="post" id="account-create-form">
        <?php if ($editAccount): ?>
        <input type="hidden" name="edit_id" value="<?= $editId ?>">
        <input type="hidden" name="edit_kind" value="<?= e($editKind) ?>">
        <?php foreach (['level', 'nature', 'parent'] as $fixedField): ?><input type="hidden" name="<?= $fixedField ?>" value="<?= e((string) $editDefaults[$fixedField]) ?>"><?php endforeach; ?>
        <?php endif; ?>
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <div class="workspace-form-grid">
            <label>Account code<input value="<?= e((string) ($editAccount['code'] ?? 'Auto-generated on save')) ?>" readonly></label>
            <label>Account name<input name="name" maxlength="150" value="<?= $field('name') ?>" required></label>
            <label>Alias name<input name="alias_name" maxlength="160" value="<?= $field('alias_name') ?>"></label>
            <label id="account-pan">PAN Number<input name="pan_no" maxlength="60" value="<?= $field('pan_no') ?>"></label>
            <label>Level<select name="level" id="account-level" <?= $editAccount ? 'disabled' : '' ?>><option disabled>Master (system-defined)</option><?php foreach (['group'=>'Group','subgroup'=>'Subgroup','ledger'=>'Ledger','subledger'=>'Subledger'] as $key=>$label): ?><option value="<?= $key ?>" <?= ($values['level'] ?? 'ledger') === $key ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
            <label>Nature<select name="nature" id="account-nature" <?= $editAccount ? 'disabled' : '' ?>><?php foreach ($natureLabels as $key=>$label): ?><option value="<?= $key ?>" <?= ($values['nature'] ?? 'asset') === $key ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
            <label>Parent<select name="parent" id="account-parent" <?= $editAccount ? 'disabled' : '' ?> class="js-searchable" required></select></label>
        </div>
        <fieldset id="account-contact"><legend>Contact details</legend>
        <div class="workspace-form-grid">
            <label>Email<input type="email" name="email" maxlength="190" value="<?= $field('email') ?>"></label>
            <label>Contact No<input type="tel" name="contact_no" maxlength="40" value="<?= $field('contact_no') ?>"></label>
            <label>Address<input name="address" maxlength="2000" value="<?= $field('address') ?>"></label>
        </div>
        </fieldset>
        <fieldset id="account-bank"><legend>Bank details</legend>
        <div class="workspace-form-grid">
            <label>Bank name<input name="bank_name" maxlength="120" value="<?= $field('bank_name') ?>"></label>
            <label>Account number<input name="bank_account_no" maxlength="40" value="<?= $field('bank_account_no') ?>"></label>
            <label>Branch<input name="bank_branch" maxlength="120" value="<?= $field('bank_branch') ?>"></label>
        </div>
        </fieldset>
        <fieldset id="account-opening"><legend>Opening Balances</legend><div class="workspace-form-grid">
            <label>Amount<input type="number" name="opening_balance" min="0" step="0.01" value="<?= $field('opening_balance') ?>"></label>
            <label>Side<select name="opening_side"><option value="debit">Debit (Dr)</option><option value="credit" <?= ($values['opening_side'] ?? '') === 'credit' ? 'selected' : '' ?>>Credit (Cr)</option></select></label>
        </div></fieldset>
        <fieldset><legend>Type</legend><div class="actions">
            <?php foreach (['is_fixed_asset'=>'Fixed Asset','maintain_bill_by_bill'=>'Maintain bill by bill','use_cost_centre'=>'Cost centre','is_cash_equivalent'=>'Cash or cash equivalent','is_cash_or_bank'=>'Cash / bank group'] as $key=>$label): ?>
                <label class="checkbox-line" <?= $key === 'is_cash_or_bank' ? 'id="account-bank-group"' : '' ?>><input type="checkbox" name="<?= $key ?>" value="1" <?= !empty($values[$key]) ? 'checked' : '' ?>><?= $label ?></label>
            <?php endforeach; ?>
        </div></fieldset>
        <div class="actions" style="margin-top:16px"><button class="button" type="submit" <?= $repairErrors ? 'disabled' : '' ?>><?= $editAccount ? 'Save changes' : 'Create account' ?></button><a class="button secondary" href="<?= e(url('admin/chart-of-accounts.php')) ?>">Cancel</a></div>
    </form>
</section>
<?php
$parentOptions = [];
foreach ($masters as $key => $master) $parentOptions[] = ['value'=>'master:'.$key,'label'=>$master['label'],'kind'=>'master','nature'=>$master['nature']];
foreach ($groups as $group) $parentOptions[] = ['value'=>'group:'.$group['id'],'label'=>$group['code'].' — '.$group['name'],'kind'=>empty($group['parent_group_id']) ? 'group' : 'subgroup','nature'=>ledger_master_nature($group['master_key'])];
foreach ($parents as $parent) $parentOptions[] = ['value'=>'ledger:'.$parent['id'],'label'=>$parent['code'].' — '.$parent['name'],'kind'=>'ledger','nature'=>$parent['type']];
?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const options = <?= json_encode($parentOptions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const level = document.getElementById('account-level'), nature = document.getElementById('account-nature');
    const parent = document.getElementById('account-parent');
    let selected = <?= json_encode((string) ($values['parent'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    function update() {
        const group = ['group','subgroup'].includes(level.value);
        document.getElementById('account-pan').hidden = group;
        document.querySelector('#account-pan input').disabled = group;
        const allowed = level.value === 'group' ? ['master'] : level.value === 'subledger' ? ['ledger'] : level.value === 'subgroup' ? ['group'] : ['group','subgroup'];
        parent.replaceChildren(new Option('Select parent', ''));
        options.filter(o => allowed.includes(o.kind) && o.nature === nature.value).forEach(o => parent.add(new Option(o.label, o.value)));
        parent.value = Array.from(parent.options).some(o => o.value === selected) ? selected : '';
        parent.dispatchEvent(new Event('change', {bubbles:true}));
        const opening = document.getElementById('account-opening');
        opening.disabled = group || ['revenue','expense'].includes(nature.value);
        opening.hidden = opening.disabled;
        ['account-contact', 'account-bank'].forEach(function (id) {
            const section = document.getElementById(id);
            section.disabled = ['revenue','expense'].includes(nature.value);
            section.hidden = section.disabled;
        });
        const bank = document.getElementById('account-bank-group');
        bank.hidden = !group;
        bank.querySelector('input').disabled = !group;
    }
    parent.addEventListener('change', () => {selected = parent.value;});
    level.addEventListener('change', update); nature.addEventListener('change', update); update();
});
</script>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
