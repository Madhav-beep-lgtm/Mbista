<?php
$pageTitle = $pageTitle ?? 'Admin';
$pageSubtitle = $pageSubtitle ?? '';
$bodyClass = $bodyClass ?? 'admin-layout';
// Legacy rc-*/blueprint styles resolve their tokens from this body class;
// keep it so pages that still use them don't lose their variables.
if (str_contains($bodyClass, 'admin-layout') && !str_contains($bodyClass, 'accounting-reference-layout')) {
    $bodyClass .= ' accounting-reference-layout';
}
$currentUser = current_user();
$headerCompany = current_company();
$headerFiscalYear = current_fiscal_year();
$headerScript = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$headerTab = (string) ($_GET['tab'] ?? '');
$headerView = (string) ($_GET['view'] ?? '');
$headerShowFiscalYear = in_array($headerScript, ['accounting.php', 'ledgers.php', 'day-book.php', 'accounting-parties.php', 'accounting-dashboard.php', 'accounting-inventory.php', 'export-ledger.php', 'banking.php', 'reconciliation.php', 'chart-of-accounts.php', 'chart-groups.php', 'chart-ledgers.php', 'chart-posting-accounts.php', 'fixed-assets.php', 'opening-balances.php'], true);
$headerCompanyId = (int) ($headerCompany['id'] ?? 0);
$headerCompanyCode = (string) ($headerCompany['code'] ?? '');
$headerBusinessType = company_accounting_business_type($headerCompanyId);
$headerBusinessProfile = accounting_business_profile($headerBusinessType);
$headerAltioraCompany = $headerCompanyCode === 'MBAACA' ? company_by_code('AGHPL') : null;
$headerSubsidiaryCompanies = $headerCompanyCode === 'AGHPL' && $headerCompanyId > 0 ? child_companies_for_company($headerCompanyId) : [];
$headerPortalLabel = match (true) {
    $headerCompanyCode === 'MBAACA' => 'M.Bista superadmin portal',
    $headerCompanyCode === 'AGHPL' => 'Altiora parent admin portal',
    (int) ($headerCompany['is_client_company'] ?? 0) === 1 => 'Client accounting books',
    default => $headerCompany ? 'Subsidiary company portal' : 'Admin portal',
};

// Accounting workspace submenu: [label, url, icon, active?]
$headerChartPages = ['chart-of-accounts.php', 'chart-groups.php', 'chart-ledgers.php', 'chart-posting-accounts.php'];
$headerAccountingChildren = [];
if ($headerCompanyCode !== 'MBAACA') {
    $headerAccountingChildren[] = ['Overview', 'admin/accounting-dashboard.php', 'dashboard', $headerScript === 'accounting-dashboard.php'];
}
$headerAccountingChildren = array_merge($headerAccountingChildren, [
    ['Chart of Accounts', 'admin/chart-of-accounts.php', 'tree', in_array($headerScript, $headerChartPages, true)],
    ['Vouchers', 'admin/accounting.php', 'journal', in_array($headerScript, ['accounting.php', 'voucher-form.php'], true)],
    ['Ledgers', 'admin/ledgers.php', 'contracts', $headerScript === 'ledgers.php'],
    ['Opening Balances', 'admin/opening-balances.php', 'reconcile', $headerScript === 'opening-balances.php'],
    ['Day Book', 'admin/day-book.php', 'calendar', $headerScript === 'day-book.php'],
    ['Voucher Import (Excel)', 'admin/voucher-import.php', 'upload', $headerScript === 'voucher-import.php'],
    ['Unified Transaction and Party Master', 'admin/accounting-parties.php', 'users', $headerScript === 'accounting-parties.php'],
    ['Banking', 'admin/banking.php', 'bank', $headerScript === 'banking.php'],
    ['Reconciliation', 'admin/reconciliation.php', 'reconcile', $headerScript === 'reconciliation.php'],
    ['Budgets', 'admin/budgets.php', 'pie', $headerScript === 'budgets.php'],
    // These shared accounting links are identical for admin, staff and client books.
    ['Inventory & Manufacturing', 'admin/accounting-inventory.php', 'box', $headerScript === 'accounting-inventory.php'],
    ['Stock Summary', 'admin/stock-summary-report.php', 'reports', in_array($headerScript, ['stock-summary-report.php', 'stock-ledger.php'], true)],
    // Models / revaluation / categories live on the Fixed Assets page's own
    // tab bar — the sidebar keeps only the two entry points.
    ['Fixed Asset Register', 'admin/fixed-assets.php', 'tag',
        $headerScript === 'fixed-assets.php' && $headerView !== 'leases'],
    ['Lease', 'admin/fixed-assets.php?view=leases', 'key',
        $headerScript === 'fixed-assets.php' && $headerView === 'leases'],
]);
$headerAccountingActive = false;
foreach ($headerAccountingChildren as $headerChild) {
    if ($headerChild[3]) {
        $headerAccountingActive = true;
        break;
    }
}

// Page hero + breadcrumb trails, applied automatically to every admin page.
// A page can override with $pageHero = ['icon' => ...] / $pageBreadcrumb, or
// opt out entirely with $pageHero = false.
$pageHero = $pageHero ?? [];
$pageBreadcrumb = $pageBreadcrumb ?? null;
$headerPageIcons = [
    'index.php' => 'home', 'accounting-dashboard.php' => 'dashboard', 'consolidated-report.php' => 'layers',
    'accounting.php' => 'journal', 'voucher-form.php' => 'journal', 'voucher-import.php' => 'upload',
    'ledgers.php' => 'contracts', 'day-book.php' => 'calendar', 'opening-balances.php' => 'reconcile',
    'accounting-parties.php' => 'receipt-voucher', 'accounting-inventory.php' => 'box', 'fixed-assets.php' => 'tag', 'banking.php' => 'bank',
    'reconciliation.php' => 'reconcile', 'chart-of-accounts.php' => 'tree', 'chart-groups.php' => 'tree',
    'chart-ledgers.php' => 'tree', 'chart-posting-accounts.php' => 'tree', 'chart-masters.php' => 'tree',
    'invoice.php' => 'invoices', 'reports-center.php' => 'reports', 'report-schedules.php' => 'calendar',
    'documents.php' => 'documents', 'compliance.php' => 'compliance', 'audit-trail.php' => 'admin',
    'workspace.php' => 'portal', 'companies.php' => 'companies', 'users.php' => 'users',
    'settings.php' => 'settings', 'tickets.php' => 'tickets', 'messages.php' => 'messages',
    'hr.php' => 'attendance', 'manage-clients.php' => 'handshake', 'client-books.php' => 'accounting',
    'search.php' => 'search', 'export-ledger.php' => 'reports', 'budgets.php' => 'pie',
    'payroll.php' => 'card', 'payroll-employees.php' => 'teams', 'payroll-settings.php' => 'sliders',
    'payroll-overtime.php' => 'attendance', 'payroll-service-charge.php' => 'handshake',
    'payment-gateways.php' => 'card',
    'insights.php' => 'insights',
    'jewellery.php' => 'coins', 'jewellery-trade.php' => 'coins',
    'jewellery-workshop.php' => 'handshake', 'jewellery-assign.php' => 'handshake', 'jewellery-receive.php' => 'box', 'jewellery-reports.php' => 'reports', 'jewellery-aml.php' => 'aml',
    'jewellery-trace.php' => 'search',
    'jewellery-tags.php' => 'documents',
];
$headerPayrollScripts = ['payroll.php', 'payroll-employees.php', 'payroll-settings.php', 'payroll-overtime.php', 'payroll-service-charge.php'];
$headerPayrollActive = in_array($headerScript, $headerPayrollScripts, true);
if ($pageBreadcrumb === null) {
    $headerTrailHome = [['Home', 'admin/index.php']];
    $headerTrailReports = [['Home', 'admin/index.php'], ['Reports', 'admin/reports-center.php']];
    $headerTrailAccounting = [['Home', 'admin/index.php'], ['Accounting', 'admin/accounting-dashboard.php']];
    $headerAccountingScripts = ['accounting.php', 'ledgers.php', 'day-book.php', 'opening-balances.php', 'voucher-form.php', 'voucher-import.php', 'accounting-parties.php', 'payment-gateways.php', 'accounting-inventory.php', 'fixed-assets.php', 'banking.php', 'reconciliation.php', 'chart-of-accounts.php', 'chart-groups.php', 'chart-ledgers.php', 'chart-posting-accounts.php', 'chart-masters.php', 'invoice.php', 'budgets.php'];
    if (in_array($headerScript, ['report-schedules.php', 'consolidated-report.php'], true)) {
        $pageBreadcrumb = $headerTrailReports;
    } elseif (in_array($headerScript, $headerPayrollScripts, true)) {
        $pageBreadcrumb = [['Home', 'admin/index.php'], ['Payroll', 'admin/payroll.php']];
    } elseif (in_array($headerScript, $headerAccountingScripts, true)) {
        $pageBreadcrumb = $headerTrailAccounting;
    } elseif ($headerScript !== 'index.php') {
        $pageBreadcrumb = $headerTrailHome;
    }
}

// Client-books awareness: when the portal IS a client books space, the
// Accounting Workspace points back home and the client submenu takes over.
$headerIsClientBooks = (int) ($headerCompany['is_client_company'] ?? 0) === 1;
// Hospitality Accounting appears ONLY inside a client's books, only when the
// Super Admin activated it for that client, and only with the view permission.
// It can never appear in the firm's own workspaces (they are not client books).
$headerHospitality = false;
if ($headerIsClientBooks) {
    require_once dirname(__DIR__, 2) . '/hospitality_engine.php';
    $headerHospitality = hospitality_enabled_for_company((int) ($headerCompany['id'] ?? 0)) && user_can_do('hospitality', 'view');
}
$headerHospitalityScripts = ['hospitality.php'];
$headerHospitalityActive = in_array($headerScript, $headerHospitalityScripts, true);
$headerHospitalityView = (string) ($_GET['view'] ?? '');
$headerHospitalityMenu = '';
if ($headerHospitality) {
    $hospLinks = [
        ['dashboard', 'Dashboard', 'dashboard'],
        ['ingredients', 'Ingredients', 'box'],
        ['menu-items', 'Menu Items', 'receipt-voucher'],
        ['recipes', 'Recipes', 'journal'],
        ['sales-upload', 'Sales Upload & Costing', 'documents'],
        ['reports', 'Reports', 'reports'],
        ['settings', 'Settings', 'sliders'],
    ];
    $headerHospitalityMenu = '<div class="mbw-nav-parent mbw-nav-sub' . ($headerHospitalityActive ? ' is-open' : '') . '" data-nav-parent="hospitality">'
        . '<a href="#" data-nav-toggle aria-expanded="' . ($headerHospitalityActive ? 'true' : 'false') . '">'
        . icon('services') . 'Hospitality Accounting<span class="mbw-nav-caret">' . icon('chevron') . '</span></a><div class="mbw-subnav">';
    foreach ($hospLinks as [$hospView, $hospLabel, $hospIcon]) {
        $isActive = $headerHospitalityActive && ($headerHospitalityView === $hospView || ($headerHospitalityView === '' && $hospView === 'dashboard'));
        $headerHospitalityMenu .= '<a class="' . ($isActive ? 'is-active' : '') . '" href="' . e(url('admin/hospitality.php?view=' . $hospView)) . '">'
            . icon($hospIcon) . e($hospLabel) . '</a>';
    }
    $headerHospitalityMenu .= '</div></div>';
}
// Jewellery Accounting follows the same rule: client books only, Super Admin
// activated, and the view permission on top.
$headerJewellery = false;
if ($headerIsClientBooks) {
    require_once dirname(__DIR__, 2) . '/jewellery_engine.php';
    $headerJewellery = jewellery_enabled_for_company((int) ($headerCompany['id'] ?? 0)) && user_can_do('jewellery', 'view');
}
// The module spans four pages, so "is this section open?" keys off the script
// rather than a single filename.
$headerJewelleryScripts = ['jewellery.php', 'jewellery-trade.php', 'jewellery-workshop.php', 'jewellery-assign.php', 'jewellery-receive.php', 'jewellery-reports.php', 'jewellery-tags.php', 'accounting-parties.php', 'jewellery-trace.php', 'jewellery-aml.php'];
$headerJewelleryActive = in_array($headerScript, $headerJewelleryScripts, true);
$headerJewelleryView = (string) ($_GET['view'] ?? '');
$headerJewelleryMenu = '';
if ($headerJewellery) {
    // TWENTY-ONE LINKS IN ONE COLUMN is a list nobody reads; it is scrolled
    // past. They are gathered here into the five things a shop actually does,
    // in the order the metal moves through it: buy and sell, hold stock, send
    // it out to be made, read what happened, and set the shop up.
    //
    // [group label, group icon, [[script, view, label, icon], ...]]
    // A group of one is not a group, so the dashboard stays a plain link above
    // them all.
    $jewDashboard = ['jewellery.php', 'dashboard', 'Dashboard', 'dashboard'];
    $jewGroups = [
        ['Trade', 'receipt-voucher', [
            ['jewellery-trade.php', 'purchases', 'Purchases', 'box'],
            ['jewellery-trade.php', 'sales', 'Sales', 'receipt-voucher'],
            ['jewellery-trade.php', 'bills', 'Bills &amp; Settlement', 'wallet'],
            ['jewellery-workshop.php', 'orders', 'Orders', 'journal'],
        ]],
        ['Stock', 'layers', [
            ['jewellery.php', 'items', 'Items', 'box'],
            ['jewellery.php', 'opening', 'Opening Stock', 'journal'],
            ['jewellery.php', 'stock', 'Stock &amp; Metal Position', 'layers'],
            // Tagging sits next to the stock it labels.
            ['jewellery-tags.php', '', 'Print Tags', 'documents'],
        ]],
        ['Workshop', 'handshake', [
            // Assigning the work comes before issuing the metal, and reads that
            // way: who is making what, then what was handed over for it.
            ['jewellery-assign.php', '', 'Kaligad Assign', 'handshake'],
            ['jewellery-receive.php', '', 'Kaligad Receive', 'box'],
            ['jewellery-workshop.php', 'assignments', 'Metal Issued', 'scale'],
            ['jewellery-workshop.php', 'delivery', 'Ready to Deliver', 'box'],
            ['jewellery-workshop.php', 'karigars', 'Kaligads', 'teams'],
            ['jewellery-workshop.php', 'refinery', 'Refinery', 'layers'],
        ]],
        ['Reports', 'reports', [
            ['jewellery-reports.php', 'summary', 'Reports', 'reports'],
            ['jewellery-aml.php', '', 'AML / goAML Reporting', 'aml'],
        ]],
        ['Masters &amp; Setup', 'sliders', [
            ['jewellery.php', 'rates', 'Daily Rates', 'pie'],
            ['accounting-parties.php', 'directory', 'Party Master', 'users'],
            ['jewellery.php', 'masters', 'Metals &amp; Units', 'scale'],
            ['jewellery.php', 'settings', 'Settings', 'sliders'],
        ]],
    ];
    // Each page's first tab is its default, so a bare URL still highlights.
    $jewDefaults = ['jewellery.php' => 'dashboard', 'jewellery-trade.php' => 'purchases',
        'jewellery-workshop.php' => 'orders', 'jewellery-reports.php' => 'summary',
        'accounting-parties.php' => 'directory'];
    // One link, and whether the page being looked at IS that link.
    $jewLink = static function (array $entry) use ($headerScript, $headerJewelleryView, $jewDefaults): array {
        [$jewScript, $jewView, $jewLabel, $jewIcon] = $entry;
        $jewQueryKey = $jewScript === 'accounting-parties.php' ? 'tab' : 'view';
        $jewCurrentValue = $jewQueryKey === 'tab'
            ? (string) ($_GET['tab'] ?? '')
            : $headerJewelleryView;

        $isPartyMaster = $jewScript === 'accounting-parties.php'
            && $headerScript === $jewScript
            && in_array($jewCurrentValue, ['', 'directory', 'customers', 'suppliers', 'unlinked'], true);

        $isActive = $isPartyMaster || (
            $headerScript === $jewScript
            && ($jewCurrentValue === $jewView
                || ($jewCurrentValue === ''
                    && ($jewDefaults[$jewScript] ?? '') === $jewView))
        );

        return [$isActive, '<a class="' . ($isActive ? 'is-active' : '')
            . '" href="' . e(url(
                'admin/' . $jewScript . '?' . $jewQueryKey . '=' . rawurlencode($jewView)
            )) . '">' . icon($jewIcon) . $jewLabel . '</a>'];
    };

    $headerJewelleryMenu = '<div class="mbw-nav-parent mbw-nav-sub' . ($headerJewelleryActive ? ' is-open' : '') . '" data-nav-parent="jewellery">'
        . '<a href="#" data-nav-toggle aria-expanded="' . ($headerJewelleryActive ? 'true' : 'false') . '">'
        . icon('coins') . 'Jewellery Accounting<span class="mbw-nav-caret">' . icon('chevron') . '</span></a><div class="mbw-subnav">';

    [, $jewDashboardHtml] = $jewLink($jewDashboard);
    $headerJewelleryMenu .= $jewDashboardHtml;

    foreach ($jewGroups as $groupIndex => [$groupLabel, $groupIcon, $groupEntries]) {
        $groupHtml = '';
        $groupHasActive = false;
        foreach ($groupEntries as $entry) {
            [$entryActive, $entryHtml] = $jewLink($entry);
            $groupHasActive = $groupHasActive || $entryActive;
            $groupHtml .= $entryHtml;
        }
        // The group holding the page you are on opens itself. Nothing else does,
        // so the column stays the length of one group rather than all five.
        $headerJewelleryMenu .= '<div class="mbw-nav-parent mbw-nav-sub' . ($groupHasActive ? ' is-open' : '')
            . '" data-nav-parent="jewellery-' . $groupIndex . '">'
            . '<a href="#" data-nav-toggle aria-expanded="' . ($groupHasActive ? 'true' : 'false') . '">'
            . icon($groupIcon) . $groupLabel . '<span class="mbw-nav-caret">' . icon('chevron') . '</span></a>'
            . '<div class="mbw-subnav">' . $groupHtml . '</div></div>';
    }
    $headerJewelleryMenu .= '</div></div>';
}
// A customer login inside its own books gets a reduced, books-only nav.
$headerIsCustomer = (string) ($currentUser['role'] ?? '') === 'customer';
$headerClientBooksOptions = [];
if (($currentUser['role'] ?? '') === 'admin' && table_exists('client_profiles') && column_exists('client_profiles', 'books_company_id')) {
    $headerClientsStmt = db()->query('SELECT id, organization_name, company_id, assigned_staff_user_id, books_company_id
        FROM client_profiles
        WHERE is_active = 1 AND books_company_id IS NOT NULL
        ORDER BY organization_name ASC');
    foreach ($headerClientsStmt->fetchAll() as $headerClientRow) {
        if (client_books_access_level($headerClientRow) === 'direct') {
            $headerClientBooksOptions[] = $headerClientRow;
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> | <?= e(app_name()) ?></title>
    <meta name="theme-color" content="#F6F9FC">
    <?php // Readable on any host without a login: view source and search for
          // mbw-build. Two hosts with the same value are running the same code. ?>
    <meta name="mbw-build" content="<?= e(app_build_stamp()) ?>">
    <?php require __DIR__ . '/pwa_head.php'; ?>
    <link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
    <link rel="apple-touch-icon" href="/assets/img/favicon.svg">
    <link rel="mask-icon" href="/assets/img/favicon.svg" color="#1676A3">
    <?php require __DIR__ . '/app_styles.php'; ?>
</head>
<body class="<?= e($bodyClass) ?>" data-date-mode="<?= e(date_mode()) ?>">
<?php require __DIR__ . '/sidebar_boot.php'; ?>
<div class="admin-shell">
    <aside class="admin-sidebar" id="adminSidebar">
        <a class="brand brand-admin" href="<?= e(url('admin/accounting-dashboard.php')) ?>">
            <?= brand_logo('dark', 'mbw-logo mbw-logo-sidebar') ?>
        </a>
        <?php // The platform is above any one company, so naming a "current
              // portal" there is wrong twice over: it is not where you are, and
              // it is whichever books happened to be open last. ?>
        <?php if ($headerCompany && empty($platformShell)): ?>
            <div class="admin-sidebar-context">
                <?php if (!empty($headerCompany['logo_path'])): ?>
                    <img class="admin-portal-logo" src="<?= e(url((string) $headerCompany['logo_path'])) ?>" alt="<?= e($headerCompany['name'] ?? 'Company') ?> logo" loading="lazy">
                <?php endif; ?>
                <span>Current portal</span>
                <strong><?= e($headerCompany['name'] ?? 'Company') ?></strong>
                <?php if ($headerShowFiscalYear): ?>
                    <small><?= e($headerFiscalYear['label'] ?? 'No fiscal year') ?></small>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <nav class="admin-nav">
            <?php if (!empty($platformShell)): ?>
            <?php
            // The Platform Admin's own navigation. It carries only screens that
            // exist: there is no package, licence or billing module in this
            // application, and a menu entry that opens nothing reads as a
            // feature that has been built.
            ?>
            <?php
            // Every one of these is a /platform/ URL. They used to point at
            // admin/*.php, which renders the COMPANY shell -- so one click left
            // the platform, and the sidebar came back as whichever client's
            // books were open last.
            ?>
            <span class="admin-nav-group">Platform</span>
            <a class="<?= $headerScript === 'index.php' ? 'is-active' : '' ?>" href="<?= e(url('platform/index.php')) ?>"><?= icon('companies') ?>Companies</a>
            <a class="<?= $headerScript === 'clients.php' ? 'is-active' : '' ?>" href="<?= e(url('platform/clients.php')) ?>"><?= icon('clients') ?>Clients</a>
            <a class="<?= $headerScript === 'staff.php' ? 'is-active' : '' ?>" href="<?= e(url('platform/staff.php')) ?>"><?= icon('users') ?>Staff</a>
            <a class="<?= $headerScript === 'work.php' ? 'is-active' : '' ?>" href="<?= e(url('platform/work.php')) ?>"><?= icon('tasks') ?>Work Portal</a>
            <?php // Report emails are not a platform matter. A schedule belongs
                  // to the company whose figures it sends, so it lives in that
                  // company's Settings — reached by opening the company, which
                  // is the only place the schedule means anything. ?>
            <span class="admin-nav-group">System</span>
            <a class="<?= $headerScript === 'settings.php' ? 'is-active' : '' ?>" href="<?= e(url('platform/settings.php')) ?>"><?= icon('settings') ?>Settings</a>
            <a href="<?= e(url('logout.php')) ?>"><?= icon('logout') ?>Sign out</a>
            <?php elseif ($headerIsCustomer): ?>
            <?php // Client login: books-only navigation — no admin surfaces. ?>
            <span class="admin-nav-group">My Portal</span>
            <a href="<?= e(url('dashboard.php')) ?>"><?= icon('home') ?>Client Portal</a>
            <a href="<?= e(url('my-profile.php')) ?>"><?= icon('profile') ?>My Profile &amp; Users</a>
            <div class="mbw-nav-parent is-open" data-nav-parent="accounting">
                <a href="#" data-nav-toggle aria-expanded="true">
                    <?= icon('accounting') ?>Accounting
                    <span class="mbw-nav-caret"><?= icon('chevron') ?></span>
                </a>
                <div class="mbw-subnav">
                    <?php foreach ($headerAccountingChildren as [$headerChildLabel, $headerChildUrl, $headerChildIcon, $headerChildActive]): ?>
                        <?php if (str_starts_with($headerChildUrl, 'admin/payment-gateways.php') && !client_portal_user_is_owner()) { continue; } ?>
                        <a class="<?= $headerChildActive ? 'is-active' : '' ?>" href="<?= e(url($headerChildUrl)) ?>"><?= icon($headerChildIcon) ?><?= e($headerChildLabel) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
            <span class="admin-nav-group">Payroll</span>
            <a class="<?= $headerScript === 'payroll.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/payroll.php')) ?>"><?= icon('card') ?>Payroll Processing</a>
            <a class="<?= $headerScript === 'payroll-employees.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/payroll-employees.php')) ?>"><?= icon('teams') ?>Employees &amp; Advances</a>
            <a class="<?= $headerScript === 'payroll-overtime.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/payroll-overtime.php')) ?>"><?= icon('attendance') ?>Overtime Review</a>
            <a class="<?= $headerScript === 'payroll-service-charge.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/payroll-service-charge.php')) ?>"><?= icon('handshake') ?>Service Charge</a>
            <?php if (client_portal_user_is_owner()): ?>
                <a class="<?= $headerScript === 'payroll-settings.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/payroll-settings.php')) ?>"><?= icon('sliders') ?>Payroll Settings</a>
            <?php endif; ?>
            <?php if ($headerHospitality): ?>
                <span class="admin-nav-group">Hospitality</span>
                <?= $headerHospitalityMenu ?>
            <?php endif; ?>
            <?php if ($headerJewellery): ?>
                <span class="admin-nav-group">Jewellery</span>
                <?= $headerJewelleryMenu ?>
            <?php endif; ?>
            <span class="admin-nav-group">Reports</span>
            <a class="<?= $headerScript === 'reports-center.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/reports-center.php')) ?>"><?= icon('reports') ?>Reports Center</a>
            <a class="<?= $headerScript === 'report-schedules.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/report-schedules.php')) ?>"><?= icon('calendar') ?>Report Schedules</a>
            <a class="<?= $headerScript === 'audit-trail.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/audit-trail.php')) ?>"><?= icon('admin') ?>Audit Trail &amp; Approvals</a>
            <span class="admin-nav-group">System</span>
            <a href="<?= e(url('logout.php')) ?>"><?= icon('logout') ?>Logout</a>
            <?php else: ?>
            <?php if (user_is_super_admin()): ?>
                <a class="admin-nav-priority" href="<?= e(url('platform/index.php')) ?>"><?= icon('companies') ?>All companies</a>
            <?php endif; ?>
            <?php if ($headerCompanyCode === 'MBAACA'): ?>
                <a class="<?= $headerScript === 'accounting-dashboard.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/accounting-dashboard.php')) ?>"><?= icon('dashboard') ?>Dashboard</a>
                <?php if (($currentUser['role'] ?? '') === 'admin'): ?>
                    <a class="<?= $headerScript === 'consolidated-report.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/consolidated-report.php')) ?>"><?= icon('layers') ?>Consolidated Report</a>
                <?php endif; ?>
            <?php endif; ?>
            <a class="<?= $headerScript === 'index.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/index.php')) ?>"><?= icon('home') ?>Admin Overview</a>

            <span class="admin-nav-group">Accounting</span>
            <?php if ($headerIsClientBooks): ?>
                <?php
                // "My Company Accounting" used to sit here, jumping out of the
                // client's books and into the firm's own. It is gone: which
                // books you are in is chosen once, on the Companies list, and a
                // button that silently changes it from inside a client's ledger
                // is how somebody posts an entry to the wrong company.
                ?>
            <?php else: ?>
            <div class="mbw-nav-parent<?= $headerAccountingActive ? ' is-open' : '' ?>" data-nav-parent="accounting">
                <a href="#" data-nav-toggle aria-expanded="<?= $headerAccountingActive ? 'true' : 'false' ?>">
                    <?= icon('accounting') ?>Accounting
                    <span class="mbw-nav-caret"><?= icon('chevron') ?></span>
                </a>
                <div class="mbw-subnav">
                    <?php foreach ($headerAccountingChildren as [$headerChildLabel, $headerChildUrl, $headerChildIcon, $headerChildActive]): ?>
                        <a class="<?= $headerChildActive ? 'is-active' : '' ?>" href="<?= e(url($headerChildUrl)) ?>"><?= icon($headerChildIcon) ?><?= e($headerChildLabel) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($headerIsClientBooks): ?>
                <?php
                // Jewellery and Hospitality ARE client accounting -- they are the
                // books of a client whose trade happens to be gold or food, and
                // they only appear at all when a client's books are open. Sitting
                // beside Client Accounting they read as separate systems, and the
                // sidebar ran to three top-level groups where there is one
                // subject. They are nested inside it instead.
                $headerClientAccountingOpen = $headerJewelleryActive || $headerHospitalityActive
                    || (bool) array_filter($headerAccountingChildren, static fn (array $c): bool => (bool) $c[3]);
                ?>
                <div class="mbw-nav-parent<?= $headerClientAccountingOpen ? ' is-open' : '' ?>" data-nav-parent="client-accounting">
                    <a href="#" data-nav-toggle aria-expanded="<?= $headerClientAccountingOpen ? 'true' : 'false' ?>">
                        <?= icon('accounting') ?>Client Accounting
                        <span class="mbw-nav-caret"><?= icon('chevron') ?></span>
                    </a>
                    <div class="mbw-subnav">
                        <?php foreach ($headerAccountingChildren as [$headerChildLabel, $headerChildUrl, $headerChildIcon, $headerChildActive]): ?>
                            <a class="<?= $headerChildActive ? 'is-active' : '' ?>" href="<?= e(url($headerChildUrl)) ?>"><?= icon($headerChildIcon) ?><?= e($headerChildLabel) ?></a>
                        <?php endforeach; ?>
                        <?= $headerHospitality ? $headerHospitalityMenu : '' ?>
                        <?= $headerJewellery ? $headerJewelleryMenu : '' ?>
                    </div>
                </div>
            <?php endif; ?>

            <a class="<?= in_array($headerScript, ['reports-center.php', 'report-schedules.php'], true) ? 'is-active' : '' ?>" href="<?= e(url('admin/reports-center.php')) ?>"><?= icon('reports') ?>Reports Center</a>
            <a class="<?= $headerScript === 'documents.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/documents.php?view=requests')) ?>"><?= icon('documents') ?>Documents</a>
            <a class="<?= $headerScript === 'compliance.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/compliance.php?view=deadlines')) ?>"><?= icon('compliance') ?>Compliance</a>
            <a class="<?= $headerScript === 'audit-trail.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/audit-trail.php')) ?>"><?= icon('admin') ?>Audit Trail &amp; Approvals</a>

            <span class="admin-nav-group">Operations</span>
            <a class="<?= in_array($headerScript, ['service-agreements.php', 'export-agreement.php'], true) ? 'is-active' : '' ?>" href="<?= e(url('admin/workspace.php?view=contracts')) ?>"><?= icon('handshake') ?>Contracts &amp; Agreements</a>
            <a class="<?= $headerScript === 'messages.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/messages.php')) ?>"><?= icon('messages') ?>Messages</a>
            <a class="<?= $headerScript === 'tickets.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/tickets.php')) ?>"><?= icon('tickets') ?>Tickets</a>
            <a class="<?= $headerScript === 'hr.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/hr.php?view=attendance')) ?>"><?= icon('attendance') ?>HR &amp; Attendance</a>
            <div class="mbw-nav-parent<?= $headerPayrollActive ? ' is-open' : '' ?>" data-nav-parent="payroll">
                <a href="#" data-nav-toggle aria-expanded="<?= $headerPayrollActive ? 'true' : 'false' ?>">
                    <?= icon('wallet') ?>Payroll
                    <span class="mbw-nav-caret"><?= icon('chevron') ?></span>
                </a>
                <div class="mbw-subnav">
                    <a class="<?= $headerScript === 'payroll.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/payroll.php')) ?>"><?= icon('card') ?>Payroll Processing</a>
                    <a class="<?= $headerScript === 'payroll-employees.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/payroll-employees.php')) ?>"><?= icon('teams') ?>Employees &amp; Pay Structure</a>
                    <a class="<?= $headerScript === 'payroll-overtime.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/payroll-overtime.php')) ?>"><?= icon('attendance') ?>Overtime Review</a>
                    <a class="<?= $headerScript === 'payroll-service-charge.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/payroll-service-charge.php')) ?>"><?= icon('handshake') ?>Service Charge</a>
                    <a class="<?= $headerScript === 'payroll-settings.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/payroll-settings.php')) ?>"><?= icon('sliders') ?>Payroll Settings</a>
                    <a href="<?= e(url('admin/payroll-settings.php#components')) ?>"><?= icon('layers') ?>Pay Components</a>
                    <a href="<?= e(url('admin/reports-center.php?report=salary-sheet')) ?>"><?= icon('analytics') ?>Payroll Reports</a>
                </div>
            </div>

            <?php if ($headerCompanyCode === 'MBAACA'): ?>
                <span class="admin-nav-group">Website</span>
                <a class="<?= $headerScript === 'insights.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/insights.php')) ?>"><?= icon('insights') ?>Website Insights</a>
            <?php endif; ?>
            <?php endif; ?>
        </nav>
    </aside>
    <section class="admin-main">
        <?php
        // Breadcrumb trail, rendered once. Pages with a hero show it in the
        // slim topbar (the hero already carries the title); pages without a
        // hero keep the title in the topbar and the trail above the content.
        ob_start();
        if (!empty($pageBreadcrumb) && is_array($pageBreadcrumb)) {
            foreach ($pageBreadcrumb as $breadcrumbStep) {
                echo '<a href="' . e(url((string) $breadcrumbStep[1])) . '">' . e((string) $breadcrumbStep[0]) . '</a> <span aria-hidden="true">&#8250;</span> ';
            }
            echo '<strong>' . e($pageTitle) . '</strong>';
        } else {
            echo '<a href="' . e(url('admin/index.php')) . '">Dashboard</a> <span aria-hidden="true">&#8250;</span> <strong>' . e($pageTitle) . '</strong>';
        }
        $headerBreadcrumbHtml = (string) ob_get_clean();
        ?>
        <header class="admin-topbar">
            <?php require __DIR__ . '/sidebar_toggle.php'; ?>
            <form method="get" action="<?= e(url('search.php')) ?>" class="admin-topbar-search" role="search">
                <span class="mbw-search-glyph"><?= icon('search') ?></span>
                <label class="sr-only" for="admin-global-search">Search</label>
                <input id="admin-global-search" type="search" name="q" value="<?= e((string) ($_GET['q'] ?? '')) ?>">
                <button type="submit" aria-label="Search" title="Search"><?= icon('search') ?></button>
            </form>
            <a class="admin-icon-button" href="<?= e(url('admin/reports-center.php')) ?>" aria-label="Open Reports Center" title="Reports Center"><?= icon('analytics') ?></a>
            <?php /* Current company is already displayed persistently in the
                      sidebar portal card, so it is not duplicated here. */ ?>
            <?php
            // Global fiscal-year switcher: the accounting context selector for
            // every module, report, export, and transaction screen. Selection
            // is per user + per company (session) and never changes the
            // company default. The endpoint re-validates ownership server-side.
            $headerFyOptions = $headerCompanyId > 0 && table_exists('fiscal_years') ? fiscal_years_for_company($headerCompanyId, true) : [];
            ?>
            <?php if ($headerFyOptions !== [] && $headerFiscalYear): ?>
                <?php
                $headerFyStatus = fiscal_year_status($headerFiscalYear);
                $headerFyStatusTone = ['upcoming' => 'blue', 'open' => 'green', 'closed' => 'amber', 'locked' => 'red'][$headerFyStatus] ?? 'gray';
                ?>
                <form method="post" action="<?= e(url('admin/switch-fiscal-year.php')) ?>" id="fy-switcher-form" style="display:flex;align-items:center;gap:6px"
                      title="Fiscal year <?= e($headerFiscalYear['label']) ?>: <?= e($headerFiscalYear['start_date']) ?> to <?= e($headerFiscalYear['end_date']) ?> (<?= e($headerFyStatus) ?><?= (int) ($headerFiscalYear['is_default'] ?? 0) === 1 ? ', company default' : '' ?>)">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="return_to" value="<?= e((string) ($_SERVER['REQUEST_URI'] ?? '')) ?>">
                    <label for="fy-switcher" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)">Fiscal year</label>
                    <select name="fiscal_year_id" id="fy-switcher" style="max-width:200px;min-height:32px;font-size:12.5px">
                        <?php foreach ($headerFyOptions as $fyOption): ?>
                            <?php $fyOptStatus = fiscal_year_status($fyOption); ?>
                            <option value="<?= (int) $fyOption['id'] ?>" <?= (int) $fyOption['id'] === (int) $headerFiscalYear['id'] ? 'selected' : '' ?>
                                title="<?= e($fyOption['start_date']) ?> to <?= e($fyOption['end_date']) ?>">
                                <?= e($fyOption['label']) ?> · <?= e(ucfirst($fyOptStatus)) ?><?= (int) ($fyOption['is_default'] ?? 0) === 1 ? ' · Default' : '' ?><?= in_array($fyOptStatus, ['closed', 'locked'], true) ? ' 🔒' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="mbw-pill tone-<?= e($headerFyStatusTone) ?>" aria-live="polite"><?= e(ucfirst($headerFyStatus)) ?></span>
                </form>
                <script>
                (function () {
                    var switcher = document.getElementById('fy-switcher');
                    if (!switcher) { return; }
                    var previousValue = switcher.value;
                    var pageDirty = false;
                    document.addEventListener('input', function (event) {
                        var form = event.target && event.target.form;
                        if (form && form.id !== 'fy-switcher-form') { pageDirty = true; }
                    }, true);
                    switcher.addEventListener('change', function () {
                        var label = switcher.options[switcher.selectedIndex] ? switcher.options[switcher.selectedIndex].text.trim() : 'the selected year';
                        if (pageDirty && !confirm('This page has unsaved changes that will be lost.\n\nSwitch fiscal year to ' + label + ' and discard them?')) {
                            switcher.value = previousValue;
                            return;
                        }
                        if (!pageDirty && !confirm('Switch fiscal year to ' + label + '? All accounting screens, reports and filters will use it.')) {
                            switcher.value = previousValue;
                            return;
                        }
                        document.getElementById('fy-switcher-form').submit();
                    });
                })();
                </script>
            <?php endif; ?>
            <?php $headerBsToday = ad_to_bs(date('Y-m-d')); ?>
            <div class="topbar-bs-calendar">
                <button type="button" class="topbar-bs-date" data-bs-calendar-toggle data-ad-today="<?= e(date('Y-m-d')) ?>" aria-expanded="false" title="Open Nepali calendar">
                    <?= icon('calendar') ?><span><?= $headerBsToday ? e(sprintf('%02d/%02d/%04d BS', $headerBsToday[2], $headerBsToday[1], $headerBsToday[0])) : '' ?></span>
                </button>
                <div class="topbar-bs-calendar-panel" data-bs-calendar-panel hidden></div>
            </div>
            <div class="admin-topbar-actions">
                <?php
                $headerLanguageCodes = array_keys(APP_LANGS);
                $headerLanguageIndex = array_search(app_lang(), $headerLanguageCodes, true);
                $headerNextLanguage = $headerLanguageCodes[((int) $headerLanguageIndex + 1) % count($headerLanguageCodes)];
                $headerLanguageQuery = $_GET;
                $headerLanguageQuery['lang'] = $headerNextLanguage;
                $headerDateModes = ['ad', 'bs', 'both'];
                $headerDateModeIndex = array_search(date_mode(), $headerDateModes, true);
                $headerNextDateMode = $headerDateModes[((int) $headerDateModeIndex + 1) % count($headerDateModes)];
                ?>
                <a class="topbar-segment" href="?<?= e(http_build_query($headerLanguageQuery)) ?>"
                   aria-label="Switch language to <?= e(APP_LANGS[$headerNextLanguage]) ?>" title="Language: <?= e(APP_LANGS[app_lang()]) ?> — switch to <?= e(APP_LANGS[$headerNextLanguage]) ?>">
                    <?= icon('globe-language') ?><span><?= e(strtoupper(app_lang())) ?></span><span class="topbar-segment-next"><?= e(strtoupper($headerNextLanguage)) ?></span>
                </a>
                <form method="post" action="<?= e(url('set-date-mode.php')) ?>" class="topbar-date-control topbar-icon-form">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="return" value="<?= e($_SERVER['REQUEST_URI'] ?? '') ?>">
                    <input type="hidden" name="date_mode" value="<?= e($headerNextDateMode) ?>">
                    <button type="submit" class="topbar-segment" aria-label="Switch date display to <?= e(strtoupper($headerNextDateMode)) ?>" title="Date display: <?= e(strtoupper(date_mode())) ?> — switch to <?= e(strtoupper($headerNextDateMode)) ?>">
                        <?= icon('calendar-range') ?><span><?= e(strtoupper(date_mode() === 'both' ? 'AD+BS' : date_mode())) ?></span><span class="topbar-segment-next"><?= e(strtoupper($headerNextDateMode === 'both' ? 'AD+BS' : $headerNextDateMode)) ?></span>
                    </button>
                </form>

                <?php if (!$headerIsCustomer): ?>
                <?php if ($headerAltioraCompany): ?>
                    <form method="post" action="<?= e(url('admin/switch-company.php')) ?>" class="topbar-icon-form">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="company_id" value="<?= e((int) $headerAltioraCompany['id']) ?>">
                        <button class="admin-icon-button" type="submit" aria-label="Open Altiora Global Holdings" title="Open Altiora Global Holdings">
                            <?= icon('companies') ?>
                            <span class="sr-only">Open Altiora Global Holdings</span>
                        </button>
                    </form>
                <?php endif; ?>
                <?php if ($headerSubsidiaryCompanies !== []): ?>
                    <form method="post" action="<?= e(url('admin/switch-company.php')) ?>" class="topbar-company-form">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <label class="sr-only" for="topbar_subsidiary_company_id">Open subsidiary company</label>
                        <select id="topbar_subsidiary_company_id" class="topbar-company-select" name="company_id" required title="Open subsidiary company">
                            <option value="">Subsidiary</option>
                            <?php foreach ($headerSubsidiaryCompanies as $headerSubsidiary): ?>
                                <option value="<?= e((int) $headerSubsidiary['id']) ?>"><?= e($headerSubsidiary['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="admin-icon-button" type="submit" aria-label="Open selected subsidiary" title="Open selected subsidiary">
                            <?= icon('chevron-right') ?>
                            <span class="sr-only">Open selected subsidiary</span>
                        </button>
                    </form>
                <?php endif; ?>
                <?php endif; ?>
                <?php include __DIR__ . '/attention_bell.php'; ?>
                <?php if (!$headerIsCustomer): ?>
                    <?php
                    $headerUserName = trim((string) ($currentUser['name'] ?? 'Super Admin')) ?: 'Super Admin';
                    $headerUserInitials = '';
                    foreach (preg_split('/\s+/', $headerUserName) ?: [] as $headerNamePart) {
                        $headerUserInitials .= mb_substr($headerNamePart, 0, 1);
                        if (mb_strlen($headerUserInitials) >= 2) { break; }
                    }
                    $headerUserInitials = mb_strtoupper($headerUserInitials ?: 'SA');
                    $headerUserRole = (string) ($currentUser['role'] ?? '') === 'admin' ? 'Super Admin' : ucfirst((string) ($currentUser['role'] ?? 'User'));
                    ?>
                    <details class="admin-profile-menu">
                        <summary aria-label="Open user menu" title="<?= e($headerUserName) ?>">
                            <span class="admin-profile-avatar"><?= e($headerUserInitials) ?></span>
                        </summary>
                        <div class="admin-profile-dropdown">
                            <div class="admin-profile-dropdown-head">
                                <span class="admin-profile-avatar is-large"><?= e($headerUserInitials) ?></span>
                                <strong><?= e($headerUserName) ?></strong>
                            </div>
                            <a href="<?= e(url('admin/profile.php')) ?>"><?= icon('profile') ?><strong>My profile</strong></a>
                            <a href="<?= e(url('admin/users.php')) ?>"><?= icon('users') ?><strong>Users</strong></a>
                            <a href="<?= e(url('admin/settings.php')) ?>"><?= icon('settings') ?><strong>Settings</strong></a>
                            <a class="is-logout" href="<?= e(url('admin/logout.php')) ?>"><?= icon('logout') ?><strong>Logout</strong></a>
                        </div>
                    </details>
                <?php endif; ?>
            </div>
        </header>
        <div class="admin-content">
            <nav class="admin-breadcrumb" aria-label="Breadcrumb"><?= $headerBreadcrumbHtml ?></nav>
            <?php if ($pageHero !== false): ?>
                <div class="cr-head page-head">
                    <div>
                        <h2><?= e(is_array($pageHero) && !empty($pageHero['title']) ? $pageHero['title'] : $pageTitle) ?></h2>
                    </div>
                    <?php // The page's own actions, to the right of its title. Set
                          // $pageHeadActions to ready-made markup before including
                          // this partial. ?>
                    <?php if (!empty($pageHeadActions)): ?>
                        <div class="page-head-actions"><?= $pageHeadActions ?></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php // A backup that silently stopped surfaces HERE, where staff look
                  // every day — not in a CLI audit nobody runs. Clients never see
                  // it; the server's backups are not their concern. ?>
            <?php if (!$headerIsCustomer && ($backupWarning = backup_health_warning()) !== null): ?>
                <div class="notice error flash-notice"><strong>Backups:</strong> <?= e($backupWarning) ?></div>
            <?php endif; ?>
            <?php if ($message = flash('success')): ?>
                <div class="notice success flash-notice"><?= e($message) ?></div>
            <?php endif; ?>
            <?php if ($message = flash('error')): ?>
                <div class="notice error flash-notice"><?= e($message) ?></div>
            <?php endif; ?>
            <?php if ($message = flash('info')): ?>
                <div class="notice flash-notice"><?= e($message) ?></div>
            <?php endif; ?>
