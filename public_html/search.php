<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

$currentUser = current_user();
$role = (string) ($currentUser['role'] ?? '');
$query = trim((string) ($_GET['q'] ?? ''));
$normalizedQuery = mb_strtolower($query);

$catalog = [
    'admin' => [
        ['label' => 'Admin Dashboard', 'url' => 'admin/accounting-dashboard.php', 'terms' => 'dashboard reports inventory manufacturing accounting'],
        ['label' => 'Chart of Accounts', 'url' => 'admin/chart-of-accounts.php', 'terms' => 'chart accounts ledger group masters posting'],
        ['label' => 'Vouchers', 'url' => 'admin/accounting.php', 'terms' => 'voucher journal payment receipt sales purchase'],
        ['label' => 'Parties', 'url' => 'admin/accounting-parties.php', 'terms' => 'clients suppliers collections payments parties'],
        ['label' => 'Inventory', 'url' => 'admin/accounting-inventory.php', 'terms' => 'inventory stock goods raw material manufacturing'],
        ['label' => 'Reports Center', 'url' => 'admin/reports-center.php', 'terms' => 'reports trial balance profit loss balance sheet cash flow'],
        ['label' => 'Invoices', 'url' => 'admin/invoice.php', 'terms' => 'invoice sales billing service trading manufacturing'],
        ['label' => 'Documents', 'url' => 'admin/documents.php?view=requests', 'terms' => 'documents files requests approvals'],
        ['label' => 'Compliance', 'url' => 'admin/compliance.php?view=deadlines', 'terms' => 'compliance deadlines calendar filing'],
        ['label' => 'Audit Trail', 'url' => 'admin/audit-trail.php', 'terms' => 'audit logs approvals history'],
    ],
    'staff' => [
        ['label' => 'Staff Dashboard', 'url' => 'staff/index.php?view=home', 'terms' => 'dashboard clients tasks workload'],
        ['label' => 'My Clients', 'url' => 'staff/index.php?view=clients', 'terms' => 'clients profiles company'],
        ['label' => 'Client Tasks', 'url' => 'staff/index.php?view=tasks', 'terms' => 'tasks stages progress'],
        ['label' => 'Documents', 'url' => 'admin/documents.php?view=requests', 'terms' => 'documents requests files'],
        ['label' => 'Compliance', 'url' => 'admin/compliance.php?view=deadlines', 'terms' => 'deadlines filings'],
        ['label' => 'Messages', 'url' => 'admin/messages.php', 'terms' => 'messages inbox thread'],
        ['label' => 'Tickets', 'url' => 'admin/tickets.php', 'terms' => 'tickets support help'],
    ],
    'customer' => [
        ['label' => 'Client Dashboard', 'url' => 'dashboard.php', 'terms' => 'dashboard invoices reports documents'],
        ['label' => 'Invoices', 'url' => 'dashboard.php#invoices', 'terms' => 'invoice billing payment'],
        ['label' => 'Documents', 'url' => 'dashboard.php#documents', 'terms' => 'documents files downloads'],
        ['label' => 'Messages', 'url' => 'dashboard.php#messages', 'terms' => 'messages support'],
    ],
    'guest' => [
        ['label' => 'Home', 'url' => 'index.php', 'terms' => 'home services about company'],
        ['label' => 'Services', 'url' => 'services/index.php', 'terms' => 'services accounting audit tax'],
        ['label' => 'Team', 'url' => 'team/index.php', 'terms' => 'team people leadership'],
        ['label' => 'Contact', 'url' => 'contact/index.php', 'terms' => 'contact enquiry consultation'],
    ],
];

$scope = 'guest';
if ($currentUser) {
    $scope = ($role === 'admin') ? 'admin' : (($role === 'staff') ? 'staff' : 'customer');
}

$items = $catalog[$scope] ?? $catalog['guest'];
$results = [];
if ($query !== '') {
    foreach ($items as $item) {
        $haystack = mb_strtolower($item['label'] . ' ' . $item['terms']);
        if (str_contains($haystack, $normalizedQuery)) {
            $results[] = $item;
        }
    }
}

// ---------------------------------------------------------------------------
// Record search: match real invoices, parties, ledgers, vouchers, clients,
// and tickets — not just page names. Company-scoped; every block is guarded
// so a missing table simply contributes nothing.
// ---------------------------------------------------------------------------
$recordResults = [];
$like = '%' . $query . '%';
if ($query !== '' && mb_strlen($query) >= 2) {
    try {
        if ($scope === 'admin' || $scope === 'staff') {
            $companyId = current_company_id();
            if ($companyId > 0) {
                if (table_exists('task_invoices')) {
                    $stmt = db()->prepare('SELECT id, invoice_no, total_amount, status FROM task_invoices WHERE company_id = :cid AND invoice_no LIKE :q ORDER BY id DESC LIMIT 5');
                    $stmt->execute(['cid' => $companyId, 'q' => $like]);
                    foreach ($stmt->fetchAll() as $row) {
                        $recordResults[] = ['kind' => 'Invoice', 'label' => $row['invoice_no'] . ' — ' . site_currency_symbol() . number_format((float) $row['total_amount'], 2) . ' (' . $row['status'] . ')', 'url' => 'admin/invoice.php?invoice_id=' . (int) $row['id']];
                    }
                }
                if (table_exists('accounting_parties')) {
                    $stmt = db()->prepare('SELECT id, code, name, party_type FROM accounting_parties WHERE company_id = :cid AND (name LIKE :q OR code LIKE :q2) ORDER BY name ASC LIMIT 5');
                    $stmt->execute(['cid' => $companyId, 'q' => $like, 'q2' => $like]);
                    foreach ($stmt->fetchAll() as $row) {
                        $recordResults[] = ['kind' => 'Party', 'label' => $row['code'] . ' — ' . $row['name'] . ' (' . $row['party_type'] . ')', 'url' => 'admin/accounting-parties.php?party_id=' . (int) $row['id']];
                    }
                }
                if (table_exists('ledgers')) {
                    $stmt = db()->prepare("SELECT id, code, name FROM ledgers WHERE company_id = :cid AND status = 'active' AND (name LIKE :q OR code LIKE :q2) ORDER BY code ASC LIMIT 5");
                    $stmt->execute(['cid' => $companyId, 'q' => $like, 'q2' => $like]);
                    foreach ($stmt->fetchAll() as $row) {
                        $recordResults[] = ['kind' => 'Ledger', 'label' => $row['code'] . ' — ' . $row['name'], 'url' => 'admin/reports-center.php?report=ledger-report&ledger_id=' . (int) $row['id']];
                    }
                }
                if (table_exists('vouchers')) {
                    $stmt = db()->prepare('SELECT id, voucher_no, voucher_type, total_amount FROM vouchers WHERE company_id = :cid AND voucher_no LIKE :q ORDER BY id DESC LIMIT 5');
                    $stmt->execute(['cid' => $companyId, 'q' => $like]);
                    foreach ($stmt->fetchAll() as $row) {
                        $recordResults[] = ['kind' => 'Voucher', 'label' => $row['voucher_no'] . ' — ' . $row['voucher_type'] . ' ' . site_currency_symbol() . number_format((float) $row['total_amount'], 2), 'url' => 'admin/accounting.php'];
                    }
                }
                if (table_exists('support_tickets')) {
                    $stmt = db()->prepare('SELECT id, ticket_no, subject FROM support_tickets WHERE company_id = :cid AND (ticket_no LIKE :q OR subject LIKE :q2) ORDER BY id DESC LIMIT 5');
                    $stmt->execute(['cid' => $companyId, 'q' => $like, 'q2' => $like]);
                    foreach ($stmt->fetchAll() as $row) {
                        $recordResults[] = ['kind' => 'Ticket', 'label' => $row['ticket_no'] . ' — ' . $row['subject'], 'url' => 'admin/tickets.php?ticket_id=' . (int) $row['id']];
                    }
                }
            }
            if (table_exists('client_profiles')) {
                // Scope client search to clients served by a company the user
                // may access; staff are further narrowed to their own
                // assignments. Previously this ran unscoped and leaked every
                // tenant's client names/codes to any admin or staff member.
                $authCompanyIds = authorized_company_ids($currentUser);
                if ($authCompanyIds !== []) {
                    $ph = implode(',', array_fill(0, count($authCompanyIds), '?'));
                    $sql = 'SELECT id, organization_name, client_code FROM client_profiles
                            WHERE (organization_name LIKE ? OR client_code LIKE ?)
                              AND company_id IN (' . $ph . ')';
                    $params = array_merge([$like, $like], array_values($authCompanyIds));
                    if ($scope === 'staff') {
                        $sql .= ' AND assigned_staff_user_id = ?';
                        $params[] = (int) ($currentUser['id'] ?? 0);
                    }
                    $sql .= ' ORDER BY organization_name ASC LIMIT 5';
                    $stmt = db()->prepare($sql);
                    $stmt->execute($params);
                    foreach ($stmt->fetchAll() as $row) {
                        $recordResults[] = ['kind' => 'Client', 'label' => $row['organization_name'] . ($row['client_code'] ? ' (' . $row['client_code'] . ')' : ''), 'url' => 'admin/manage-clients.php'];
                    }
                }
            }
        } elseif ($scope === 'customer') {
            $profileStmt = db()->prepare('SELECT id FROM client_profiles WHERE user_id = :uid LIMIT 1');
            $profileStmt->execute(['uid' => (int) ($currentUser['id'] ?? 0)]);
            $clientId = (int) ($profileStmt->fetchColumn() ?: 0);
            if ($clientId > 0) {
                if (table_exists('task_invoices')) {
                    $stmt = db()->prepare('SELECT ti.invoice_no, ti.total_amount, ti.status FROM task_invoices ti INNER JOIN client_tasks ct ON ct.id = ti.task_id WHERE ct.client_id = :client_id AND ti.invoice_no LIKE :q ORDER BY ti.id DESC LIMIT 5');
                    $stmt->execute(['client_id' => $clientId, 'q' => $like]);
                    foreach ($stmt->fetchAll() as $row) {
                        $recordResults[] = ['kind' => 'Invoice', 'label' => $row['invoice_no'] . ' — ' . site_currency_symbol() . number_format((float) $row['total_amount'], 2) . ' (' . $row['status'] . ')', 'url' => 'dashboard.php?view=invoices'];
                    }
                }
                if (table_exists('documents')) {
                    $stmt = db()->prepare("SELECT id, title FROM documents WHERE client_id = :client_id AND visibility = 'client' AND title LIKE :q ORDER BY id DESC LIMIT 5");
                    $stmt->execute(['client_id' => $clientId, 'q' => $like]);
                    foreach ($stmt->fetchAll() as $row) {
                        $recordResults[] = ['kind' => 'Document', 'label' => $row['title'], 'url' => 'dashboard.php?view=documents'];
                    }
                }
                if (table_exists('support_tickets')) {
                    $stmt = db()->prepare('SELECT id, ticket_no, subject FROM support_tickets WHERE client_id = :client_id AND (ticket_no LIKE :q OR subject LIKE :q2) ORDER BY id DESC LIMIT 5');
                    $stmt->execute(['client_id' => $clientId, 'q' => $like, 'q2' => $like]);
                    foreach ($stmt->fetchAll() as $row) {
                        $recordResults[] = ['kind' => 'Ticket', 'label' => $row['ticket_no'] . ' — ' . $row['subject'], 'url' => 'dashboard.php?view=tickets&ticket_id=' . (int) $row['id']];
                    }
                }
            }
        }
    } catch (Throwable $exception) {
        $recordResults = [];
    }
}

$pageTitle = 'Search';
$bodyClass = 'admin-layout accounting-module-page search-page';
if ($scope === 'guest') {
    include __DIR__ . '/../app/views/partials/header.php';
} else {
    include __DIR__ . '/../app/views/partials/admin_header.php';
}
?>
<div class="accounting-page-head">
    <div>
        <h2>Search</h2>
        <p>Find the page, module, or action you need.</p>
    </div>
</div>

<form method="get" class="workspace-form-grid" style="margin-bottom: 20px;">
    <label class="workspace-span-2">Search
        <input type="search" name="q" value="<?= e($query) ?>" placeholder="Search dashboard, invoice, reports, inventory, documents...">
    </label>
    <div><button type="submit"><?= icon('reports') ?>Search</button></div>
</form>

<?php if ($recordResults !== []): ?>
    <div class="table-card">
        <h2>Matching records</h2>
        <table>
            <thead><tr><th>Type</th><th>Record</th><th>Open</th></tr></thead>
            <tbody>
                <?php foreach ($recordResults as $record): ?>
                    <tr>
                        <td><?= e($record['kind']) ?></td>
                        <td><?= e($record['label']) ?></td>
                        <td><a class="button secondary" href="<?= e(url($record['url'])) ?>">Open</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<div class="table-card">
    <h2>Pages</h2>
    <table>
        <thead><tr><th>Item</th><th>Open</th></tr></thead>
        <tbody>
            <?php if ($query === ''): ?>
                <tr><td colspan="2">Start typing to search pages, invoices, parties, ledgers, vouchers, and tickets.</td></tr>
            <?php elseif ($results === [] && $recordResults === []): ?>
                <tr><td colspan="2">No matching items found.</td></tr>
            <?php elseif ($results === []): ?>
                <tr><td colspan="2">No matching pages — see the records above.</td></tr>
            <?php else: ?>
                <?php foreach ($results as $item): ?>
                    <tr>
                        <td><?= e($item['label']) ?></td>
                        <td><a class="button secondary" href="<?= e(url($item['url'])) ?>">Open</a></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php
if ($scope === 'guest') {
    include __DIR__ . '/../app/views/partials/footer.php';
} else {
    include __DIR__ . '/../app/views/partials/admin_footer.php';
}
