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

<div class="table-card">
    <h2>Results</h2>
    <table>
        <thead><tr><th>Item</th><th>Open</th></tr></thead>
        <tbody>
            <?php if ($query === ''): ?>
                <tr><td colspan="2">Start typing to search the app.</td></tr>
            <?php elseif ($results === []): ?>
                <tr><td colspan="2">No matching items found.</td></tr>
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
