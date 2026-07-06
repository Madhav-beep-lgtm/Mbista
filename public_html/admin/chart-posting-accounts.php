<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/accounting_module_repair.php';

require_staff_or_admin();
require_company_context();
accounting_module_repair_database();

$pageTitle = 'Posting Accounts';
$bodyClass = 'admin-layout accounting-module-page chart-accounts-page';
$company = current_company();
$companyId = (int) ($company['id'] ?? 0);
$currentAdmin = current_user();
$adminId = (int) ($currentAdmin['id'] ?? 0);

$mappingRoles = [
    'default_cash_bank' => ['label' => 'Cash / bank account', 'nature' => 'asset', 'cash_only' => true],
    'default_accounts_receivable' => ['label' => 'Accounts receivable', 'nature' => 'asset'],
    'default_accounts_payable' => ['label' => 'Accounts payable', 'nature' => 'liability'],
    'default_tax_payable' => ['label' => 'Tax payable', 'nature' => 'liability'],
    'default_excise_payable' => ['label' => 'Excise payable', 'nature' => 'liability'],
    'default_service_revenue' => ['label' => 'Service revenue', 'nature' => 'revenue'],
    'default_hosting_revenue' => ['label' => 'Hosting revenue', 'nature' => 'revenue'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!table_exists('company_ledger_mappings')) {
        flash('error', 'Run the ledger-mapping migration before configuring automated posting accounts.');
        redirect('admin/chart-posting-accounts.php');
    }

    $ledgerStmt = db()->prepare('SELECT l.id, l.type, COALESCE(g.is_cash_or_bank, 0) AS is_cash_or_bank FROM ledgers l LEFT JOIN ledger_groups g ON g.id = l.group_id WHERE l.company_id = :company_id');
    $ledgerStmt->execute(['company_id' => $companyId]);
    $companyLedgers = [];
    foreach ($ledgerStmt->fetchAll() as $ledger) {
        $companyLedgers[(int) $ledger['id']] = $ledger;
    }

    $requestedMappings = [];
    foreach ($mappingRoles as $mapKey => $role) {
        $ledgerId = (int) ($_POST['mapping'][$mapKey] ?? 0);
        if ($ledgerId === 0) {
            $requestedMappings[$mapKey] = null;
            continue;
        }
        $ledger = $companyLedgers[$ledgerId] ?? null;
        $validNature = $ledger && (string) $ledger['type'] === (string) $role['nature'];
        $validCashAccount = empty($role['cash_only']) || (int) ($ledger['is_cash_or_bank'] ?? 0) === 1;
        if (!$validNature || !$validCashAccount) {
            flash('error', 'One or more automated posting accounts are not valid for their assigned role.');
            redirect('admin/chart-posting-accounts.php');
        }
        $requestedMappings[$mapKey] = $ledgerId;
    }

    db()->beginTransaction();
    try {
        $deleteStmt = db()->prepare('DELETE FROM company_ledger_mappings WHERE company_id = :company_id AND map_key = :map_key');
        $upsertStmt = db()->prepare('INSERT INTO company_ledger_mappings (company_id, map_key, ledger_id) VALUES (:company_id, :map_key, :ledger_id) ON DUPLICATE KEY UPDATE ledger_id = VALUES(ledger_id)');
        foreach ($requestedMappings as $mapKey => $ledgerId) {
            if ($ledgerId === null) {
                $deleteStmt->execute(['company_id' => $companyId, 'map_key' => $mapKey]);
            } else {
                $upsertStmt->execute(['company_id' => $companyId, 'map_key' => $mapKey, 'ledger_id' => $ledgerId]);
            }
        }
        db()->commit();
    } catch (Throwable $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        flash('error', 'Could not save the automated posting accounts.');
        redirect('admin/chart-posting-accounts.php');
    }

    log_activity('company', $companyId, 'ledger_mappings_updated', 'Automated posting ledger mappings updated.', $adminId);
    flash('success', 'Automated posting accounts saved.');
    redirect('admin/chart-posting-accounts.php');
}

$flatLedgers = [];
$ledgerStmt = db()->prepare('SELECT l.*, COALESCE(g.is_cash_or_bank, 0) AS is_cash_or_bank FROM ledgers l LEFT JOIN ledger_groups g ON g.id = l.group_id WHERE l.company_id = :company_id AND l.status = \'active\' ORDER BY l.code ASC');
$ledgerStmt->execute(['company_id' => $companyId]);
$flatLedgers = $ledgerStmt->fetchAll();

$currentMappings = [];
if (table_exists('company_ledger_mappings')) {
    $mappingStmt = db()->prepare('SELECT map_key, ledger_id FROM company_ledger_mappings WHERE company_id = :company_id');
    $mappingStmt->execute(['company_id' => $companyId]);
    foreach ($mappingStmt->fetchAll() as $mapping) {
        $currentMappings[(string) $mapping['map_key']] = (int) $mapping['ledger_id'];
    }
}

include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
<div class="accounting-page-head">
    <div>
        <h2>Automated Posting Accounts</h2>
        <p>Map company ledgers to the roles used by invoices, receipts, taxes, and revenue posting.</p>
    </div>
    <div class="accounting-actions">
        <a class="button secondary" href="<?= e(url('admin/chart-of-accounts.php')) ?>"><?= icon('accounting') ?>Overview</a>
        <a class="button secondary" href="<?= e(url('admin/chart-groups.php')) ?>"><?= icon('teams') ?>Groups</a>
        <a class="button secondary" href="<?= e(url('admin/chart-ledgers.php')) ?>"><?= icon('documents') ?>Ledgers</a>
    </div>
</div>

<?php if (!table_exists('company_ledger_mappings')): ?>
    <div class="notice error">Run the ledger-mapping migration before configuring automated posting accounts.</div>
<?php else: ?>
    <div class="table-card">
        <h2>Posting map</h2>
        <form method="post" class="workspace-form-grid">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <?php foreach ($mappingRoles as $mapKey => $role): ?>
                <label><?= e($role['label']) ?>
                    <select name="mapping[<?= e($mapKey) ?>]">
                        <option value="0">Use legacy default</option>
                        <?php foreach ($flatLedgers as $ledger): ?>
                            <?php
                            $matchesNature = (string) $ledger['type'] === (string) $role['nature'];
                            $matchesCashRole = empty($role['cash_only']) || (int) $ledger['is_cash_or_bank'] === 1;
                            if (!$matchesNature || !$matchesCashRole) {
                                continue;
                            }
                            ?>
                            <option value="<?= (int) $ledger['id'] ?>" <?= ($currentMappings[$mapKey] ?? 0) === (int) $ledger['id'] ? 'selected' : '' ?>>
                                <?= e($ledger['code']) ?> - <?= e($ledger['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endforeach; ?>
            <div class="workspace-span-2">
                <button type="submit"><?= icon('settings') ?>Save Posting Accounts</button>
            </div>
        </form>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
