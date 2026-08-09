<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/accounting_module_repair.php';

require_staff_admin_or_client_books();
require_company_context();

$repairErrors = accounting_module_repair_database();

$pageTitle = 'Chart Ledgers';
$pageSubtitle = 'Manage ledger accounts, edit rows, and remove unused ledgers.';
$bodyClass = 'admin-layout accounting-module-page chart-accounts-page';
$company = current_company();
$companyId = (int) ($company['id'] ?? 0);
$masters = ledger_masters();
$partyLedgerSync = sync_company_party_ledgers_from_chart($companyId, (int) (current_user()['id'] ?? 0) ?: null);
$groupsStmt = db()->prepare('SELECT * FROM ledger_groups WHERE company_id = :company_id AND is_active = 1 ORDER BY master_key ASC, name ASC');
$groupsStmt->execute(['company_id' => $companyId]);
$groups = $groupsStmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'save_ledger') {
        require_permission('accounting', 'edit');
        $ledgerId = (int) ($_POST['ledger_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $groupId = (int) ($_POST['group_id'] ?? 0);
        $groupStmt = db()->prepare('SELECT id, master_key, party_role FROM ledger_groups WHERE id = :id AND company_id = :company_id AND is_active = 1 LIMIT 1');
        $groupStmt->execute(['id' => $groupId, 'company_id' => $companyId]);
        $group = $groupStmt->fetch();
        $ledgerType = $group ? ledger_master_nature((string) $group['master_key']) : null;
        if ($name === '' || !$group || $ledgerType === null) {
            flash('error', 'Name and group are required.');
            redirect('admin/chart-ledgers.php');
        }
        if ($ledgerId > 0) {
            $partyLinkStmt = db()->prepare("SELECT
                    CASE
                        WHEN ledger_id = :id1 THEN 'customer_receivable'
                        WHEN payable_ledger_id = :id2 THEN 'supplier_payable'
                        WHEN advance_ledger_id = :id3 THEN 'customer_advance'
                        WHEN supplier_advance_ledger_id = :id4 THEN 'supplier_advance'
                    END AS linked_role
                FROM accounting_parties
                WHERE company_id = :cid
                  AND (ledger_id = :id5 OR payable_ledger_id = :id6 OR advance_ledger_id = :id7 OR supplier_advance_ledger_id = :id8)
                LIMIT 1");
            $partyLinkStmt->execute([
                'id1' => $ledgerId, 'id2' => $ledgerId, 'id3' => $ledgerId, 'id4' => $ledgerId,
                'id5' => $ledgerId, 'id6' => $ledgerId, 'id7' => $ledgerId, 'id8' => $ledgerId,
                'cid' => $companyId,
            ]);
            $linkedRole = $partyLinkStmt->fetchColumn() ?: null;
            if ($linkedRole !== null && (string) ($group['party_role'] ?? '') !== (string) $linkedRole) {
                flash('error', 'A Party Master ledger cannot be moved outside its canonical group. Change the party relationship instead.');
                redirect('admin/chart-ledgers.php?edit_id=' . $ledgerId);
            }
        }
        // Codes follow the structure rules and are never entered manually.
        if ($ledgerId > 0) {
            $codeStmt = db()->prepare('SELECT code FROM ledgers WHERE id = :id AND company_id = :cid LIMIT 1');
            $codeStmt->execute(['id' => $ledgerId, 'cid' => $companyId]);
            $code = (string) ($codeStmt->fetchColumn() ?: coa_next_ledger_code($companyId, $groupId));
        } else {
            $code = coa_next_ledger_code($companyId, $groupId);
        }
        if ($ledgerId > 0) {
            db()->prepare("UPDATE ledgers SET code = :code, name = :name, group_id = :group_id, type = :type WHERE id = :id AND company_id = :company_id")
                ->execute(['id' => $ledgerId, 'company_id' => $companyId, 'code' => $code, 'name' => $name, 'group_id' => $groupId, 'type' => $ledgerType]);
        } else {
            db()->prepare("INSERT INTO ledgers (company_id, group_id, code, name, type, is_system, status) VALUES (:company_id, :group_id, :code, :name, :type, 0, 'active')")
                ->execute(['company_id' => $companyId, 'group_id' => $groupId, 'code' => $code, 'name' => $name, 'type' => $ledgerType]);
            $ledgerId = (int) db()->lastInsertId();
        }

        $syncedPartyId = $ledgerId > 0
            ? sync_party_from_chart_ledger($companyId, $ledgerId, (int) (current_user()['id'] ?? 0) ?: null)
            : 0;
        flash('success', $syncedPartyId > 0
            ? 'Ledger saved and synchronized with Party Master.'
            : 'Ledger saved.');

        // Balance-sheet ledgers (asset/liability/equity) can carry an opening
        // balance, journalled once against Opening Balance Adjustments and
        // replaced whenever the figure here changes. The field arrives empty
        // when untouched — only act when the user typed something.
        $openingRaw = trim((string) ($_POST['opening_balance'] ?? ''));
        if ($openingRaw !== '' && $ledgerId > 0 && in_array($ledgerType, ['asset', 'liability', 'equity'], true)) {
            $openingError = post_ledger_opening_balance(
                $companyId,
                $ledgerId,
                (float) $openingRaw,
                (string) ($_POST['opening_side'] ?? 'debit'),
                (int) (current_user()['id'] ?? 0) ?: null
            );
            if ($openingError !== null) {
                flash('error', 'Opening balance not posted: ' . $openingError);
            } elseif ((float) $openingRaw > 0) {
                flash('success', 'Opening balance journalled against Opening Balance Adjustments.');
            }
        }
        redirect('admin/chart-ledgers.php');
    }
    if ($action === 'delete_ledger') {
        require_permission('accounting', 'edit');
        $ledgerId = (int) ($_POST['ledger_id'] ?? 0);
        if ($ledgerId > 0) {
            $linkedCheck = db()->prepare('SELECT COUNT(*) FROM accounting_parties
                WHERE company_id = :company_id
                  AND (ledger_id = :id1 OR payable_ledger_id = :id2 OR advance_ledger_id = :id3 OR supplier_advance_ledger_id = :id4)');
            $linkedCheck->execute([
                'company_id' => $companyId,
                'id1' => $ledgerId,
                'id2' => $ledgerId,
                'id3' => $ledgerId,
                'id4' => $ledgerId,
            ]);
            if ((int) $linkedCheck->fetchColumn() > 0) {
                flash('error', 'This ledger is linked to Party Master and cannot be deleted. Change the party link first so transaction history stays intact.');
                redirect('admin/chart-ledgers.php');
            }
            // Never delete a ledger that carries accounting entries — the
            // entries (and the vouchers' balance) must survive. Offer suspend.
            $entryCount = db()->prepare('SELECT COUNT(*) FROM voucher_entries ve JOIN ledgers l ON l.id = ve.ledger_id WHERE ve.ledger_id = :id AND l.company_id = :company_id');
            $entryCount->execute(['id' => $ledgerId, 'company_id' => $companyId]);
            if ((int) $entryCount->fetchColumn() > 0) {
                flash('error', 'This ledger has posted entries and cannot be deleted — suspend it instead (Edit → status Suspended) to hide it from new postings.');
                redirect('admin/chart-ledgers.php');
            }
            try {
                db()->prepare('DELETE FROM ledgers WHERE id = :id AND company_id = :company_id')->execute(['id' => $ledgerId, 'company_id' => $companyId]);
                security_event('ledger_deleted', 'success', 'Ledger #' . $ledgerId . ' deleted.', $companyId, (int) (current_user()['id'] ?? 0) ?: null);
                flash('success', 'Ledger deleted.');
            } catch (Throwable $e) {
                flash('error', 'This ledger cannot be deleted while transactions still reference it.');
            }
        }
        redirect('admin/chart-ledgers.php');
    }
}

$ledgerStmt = db()->prepare('SELECT l.*, g.name AS group_name, g.master_key, g.party_role,
        ap.id AS party_id, ap.name AS party_name
    FROM ledgers l
    LEFT JOIN ledger_groups g ON g.id = l.group_id AND g.company_id = l.company_id
    LEFT JOIN accounting_parties ap ON ap.company_id = l.company_id
       AND (ap.ledger_id = l.id OR ap.payable_ledger_id = l.id
            OR ap.advance_ledger_id = l.id OR ap.supplier_advance_ledger_id = l.id)
    WHERE l.company_id = :company_id
    ORDER BY l.code ASC');
$ledgerStmt->execute(['company_id' => $companyId]);
$ledgers = $ledgerStmt->fetchAll();
$editId = (int) ($_GET['edit_id'] ?? 0);
$editLedger = null;
if ($editId > 0) {
    $editStmt = db()->prepare('SELECT * FROM ledgers WHERE id = :id AND company_id = :company_id LIMIT 1');
    $editStmt->execute(['id' => $editId, 'company_id' => $companyId]);
    $editLedger = $editStmt->fetch() ?: null;
}

include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
<?php if ($repairErrors !== []): ?>
    <div class="notice error">Accounting repair warnings: <?= e(implode(' | ', $repairErrors)) ?></div>
<?php endif; ?>
<section class="mbw-card">
    <div class="mbw-card-head">
        <h2><?= $editLedger ? 'Edit Ledger' : 'Create Ledger' ?></h2>
        <div class="mbw-card-tools">
            <a class="mbw-view-all" href="<?= e(url('admin/chart-of-accounts.php')) ?>">Overview</a>
            <a class="mbw-view-all" href="<?= e(url('admin/chart-groups.php')) ?>">Groups</a>
        </div>
    </div>
    <p style="margin:0 0 12px;color:var(--mbw-muted)">One place for the ledger record and its actions.</p>
    <form method="post" class="workspace-form-grid">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_ledger">
            <input type="hidden" name="ledger_id" value="<?= e((int) ($editLedger['id'] ?? 0)) ?>">
            <label>Code<input type="text" value="<?= e($editLedger['code'] ?? 'Auto — group code + sequence') ?>" disabled title="System-generated per the code structure rules"></label>
            <label>Name<input type="text" name="name" value="<?= e($editLedger['name'] ?? '') ?>" required></label>
            <label>Group
                <select name="group_id" required>
                    <option value="">Select group</option>
                    <?php foreach ($groups as $group): ?>
                        <option value="<?= e((int) $group['id']) ?>" <?= (int) ($editLedger['group_id'] ?? 0) === (int) $group['id'] ? 'selected' : '' ?>><?= e($masters[$group['master_key']]['label'] ?? $group['master_key']) ?> / <?= e($group['name']) ?><?= !empty($group['party_role']) ? ' · Party Master' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php
            // Current opening balance (from the ledger's OB voucher) prefills the
            // edit form; leaving the field untouched (blank) changes nothing.
            $existingOpening = null;
            if ($editLedger) {
                $obStmt = db()->prepare("
                    SELECT ve.entry_type, ve.amount FROM vouchers v
                    JOIN voucher_entries ve ON ve.voucher_id = v.id AND ve.ledger_id = :lid
                    WHERE v.source_type = 'ledger_opening' AND v.source_id = :lid2 AND v.company_id = :cid LIMIT 1
                ");
                $obStmt->execute(['lid' => (int) $editLedger['id'], 'lid2' => (int) $editLedger['id'], 'cid' => $companyId]);
                $existingOpening = $obStmt->fetch() ?: null;
            }
            ?>
            <label>Opening balance
                <input type="number" step="0.01" min="0" name="opening_balance"
                       value="<?= e($existingOpening !== null ? number_format((float) $existingOpening['amount'], 2, '.', '') : '') ?>"
                       placeholder="Assets, liabilities & equity only — 0 clears it"
                       title="Journalled against Opening Balance Adjustments at the fiscal year start. Income and expense ledgers always open at zero.">
            </label>
            <label>Opening side
                <select name="opening_side">
                    <option value="debit" <?= ($existingOpening['entry_type'] ?? 'debit') === 'debit' ? 'selected' : '' ?>>Debit (Dr)</option>
                    <option value="credit" <?= ($existingOpening['entry_type'] ?? '') === 'credit' ? 'selected' : '' ?>>Credit (Cr)</option>
                </select>
            </label>
            <div class="workspace-span-2">
                <button type="submit"><?= icon('documents') ?>Save ledger</button>
                <?php if ($editLedger): ?><a class="button secondary" href="<?= e(url('admin/chart-ledgers.php')) ?>">Cancel</a><?php endif; ?>
            </div>
    </form>
</section>

<section class="mbw-card">
    <div class="mbw-card-head">
        <h2>Ledger table</h2>
    </div>
    <div style="overflow-x:auto">
    <table>
        <thead><tr><th>Code</th><th>Name</th><th>Group</th><th>Party Master</th><th>Type</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
            <?php if ($ledgers === []): ?><tr><td colspan="7">No ledgers yet.</td></tr><?php endif; ?>
            <?php foreach ($ledgers as $ledger): ?>
                <tr>
                    <td><?= e($ledger['code']) ?></td>
                    <td><?= e($ledger['name']) ?></td>
                    <td><?= e($ledger['group_name'] ?? '-') ?></td>
                    <td>
                        <?php if ((int) ($ledger['party_id'] ?? 0) > 0): ?>
                            <a class="reference-link" href="<?= e(url('admin/accounting-parties.php?party_id=' . (int) $ledger['party_id'] . '&ptab=ledger')) ?>"><?= e($ledger['party_name']) ?></a>
                        <?php elseif (!empty($ledger['party_role']) && (int) ($ledger['is_system'] ?? 0) === 0): ?>
                            <span class="mbw-pill tone-amber">Review link</span>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td><?= e($ledger['type']) ?></td>
                    <td><span class="mbw-pill <?= ($ledger['status'] ?? '') === 'active' ? 'tone-green' : 'tone-red' ?>"><?= e($ledger['status']) ?></span></td>
                    <td>
                        <a class="button secondary" href="<?= e(url('admin/chart-ledgers.php?edit_id=' . (int) $ledger['id'])) ?>">Edit</a>
                        <form method="post" style="display:inline">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="delete_ledger">
                            <input type="hidden" name="ledger_id" value="<?= e((int) $ledger['id']) ?>">
                            <button type="submit" class="button secondary" onclick="return confirm('Delete this ledger?')">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
