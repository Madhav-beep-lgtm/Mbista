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
$headerShowFiscalYear = in_array($headerScript, ['accounting.php', 'ledgers.php', 'day-book.php', 'accounting-parties.php', 'accounting-dashboard.php', 'accounting-inventory.php', 'export-ledger.php', 'banking.php', 'reconciliation.php', 'chart-of-accounts.php', 'chart-groups.php', 'chart-ledgers.php', 'chart-posting-accounts.php', 'fixed-assets.php'], true);
$headerCompanyId = (int) ($headerCompany['id'] ?? 0);
$headerCompanyCode = (string) ($headerCompany['code'] ?? '');
$headerBusinessType = company_accounting_business_type($headerCompanyId);
$headerBusinessProfile = accounting_business_profile($headerBusinessType);
$headerAltioraCompany = $headerCompanyCode === 'MBAACA' ? company_by_code('AGHPL') : null;
$headerMbistaCompany = $headerCompanyCode !== 'MBAACA' ? company_by_code('MBAACA') : null;
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
    ['Day Book', 'admin/day-book.php', 'calendar', $headerScript === 'day-book.php'],
    ['Voucher Import (Excel)', 'admin/voucher-import.php', 'upload', $headerScript === 'voucher-import.php'],
    ['Sales & Invoices', 'admin/accounting-parties.php?tab=sales', 'receipt-voucher', $headerScript === 'accounting-parties.php' && in_array($headerTab, ['', 'sales'], true)],
    ['Purchases', 'admin/accounting-parties.php?tab=purchases', 'cart', $headerScript === 'accounting-parties.php' && $headerTab === 'purchases'],
    ['Banking', 'admin/banking.php', 'bank', $headerScript === 'banking.php'],
    ['Reconciliation', 'admin/reconciliation.php', 'reconcile', $headerScript === 'reconciliation.php'],
    ['Budgets', 'admin/budgets.php', 'pie', $headerScript === 'budgets.php'],
    // These shared accounting links are identical for admin, staff and client books.
    ['Inventory & Manufacturing', 'admin/accounting-inventory.php', 'box', $headerScript === 'accounting-inventory.php'],
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
    'ledgers.php' => 'contracts', 'day-book.php' => 'calendar',
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
    'insights.php' => 'insights',
];
$headerPayrollScripts = ['payroll.php', 'payroll-employees.php', 'payroll-settings.php'];
$headerPayrollActive = in_array($headerScript, $headerPayrollScripts, true);
if ($pageBreadcrumb === null) {
    $headerTrailHome = [['Home', 'admin/index.php']];
    $headerTrailReports = [['Home', 'admin/index.php'], ['Reports', 'admin/reports-center.php']];
    $headerTrailAccounting = [['Home', 'admin/index.php'], ['Accounting', 'admin/accounting-dashboard.php']];
    $headerAccountingScripts = ['accounting.php', 'ledgers.php', 'day-book.php', 'voucher-form.php', 'voucher-import.php', 'accounting-parties.php', 'accounting-inventory.php', 'fixed-assets.php', 'banking.php', 'reconciliation.php', 'chart-of-accounts.php', 'chart-groups.php', 'chart-ledgers.php', 'chart-posting-accounts.php', 'chart-masters.php', 'invoice.php', 'budgets.php'];
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
// A customer login inside its own books gets a reduced, books-only nav.
$headerIsCustomer = (string) ($currentUser['role'] ?? '') === 'customer';
$headerClientBooksOptions = [];
if (($currentUser['role'] ?? '') === 'admin' && table_exists('client_profiles') && column_exists('client_profiles', 'books_company_id')) {
    $headerClientsStmt = db()->query('SELECT * FROM client_profiles WHERE is_active = 1 AND books_company_id IS NOT NULL ORDER BY organization_name ASC');
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
    <meta name="theme-color" content="#3b2418">
    <link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
    <link rel="apple-touch-icon" href="/assets/img/favicon.svg">
    <link rel="mask-icon" href="/assets/img/favicon.svg" color="#3b2418">
    <link rel="manifest" href="/site.webmanifest">
    <link rel="stylesheet" href="/assets/css/style.css?v=20260713g">
    <link rel="stylesheet" href="/assets/css/portal.css?v=20260714m1">
    <link rel="stylesheet" href="/assets/css/theme-brown.css?v=20260717b">
</head>
<body class="<?= e($bodyClass) ?>" data-date-mode="<?= e(date_mode()) ?>">
<div class="admin-shell">
    <aside class="admin-sidebar">
        <a class="brand brand-admin" href="<?= e(url('admin/accounting-dashboard.php')) ?>">
            <?= brand_logo('light', 'mbw-logo mbw-logo-sidebar') ?>
            <span class="brand-admin-sub"><?= e(match ((string) ($currentUser['role'] ?? '')) {
                'customer' => 'Client Accounting Portal',
                'staff' => 'Staff Accounting Portal',
                default => 'Admin Portal',
            }) ?></span>
        </a>
        <?php if ($headerCompany): ?>
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
            <?php if ($headerIsCustomer): ?>
            <?php // Client login: books-only navigation — no admin surfaces. ?>
            <span class="admin-nav-group">My Portal</span>
            <a href="<?= e(url('dashboard.php')) ?>"><?= icon('home') ?>Client Portal</a>
            <a href="<?= e(url('my-profile.php')) ?>"><?= icon('profile') ?>My Profile &amp; Users</a>
            <span class="admin-nav-group">Accounting Workspace</span>
            <div class="mbw-nav-parent is-open" data-nav-parent="accounting">
                <a href="#" data-nav-toggle aria-expanded="true" class="is-active">
                    <?= icon('accounting') ?>Accounting
                    <span class="mbw-nav-caret"><?= icon('chevron') ?></span>
                </a>
                <div class="mbw-subnav">
                    <?php foreach ($headerAccountingChildren as [$headerChildLabel, $headerChildUrl, $headerChildIcon, $headerChildActive]): ?>
                        <?php if (str_starts_with($headerChildUrl, 'admin/budgets.php')) { continue; } // budgets stay firm-side ?>
                        <a class="<?= $headerChildActive ? 'is-active' : '' ?>" href="<?= e(url($headerChildUrl)) ?>"><?= icon($headerChildIcon) ?><?= e($headerChildLabel) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
            <span class="admin-nav-group">Reports</span>
            <a class="<?= $headerScript === 'reports-center.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/reports-center.php')) ?>"><?= icon('reports') ?>Reports Center</a>
            <span class="admin-nav-group">System</span>
            <a href="<?= e(url('logout.php')) ?>"><?= icon('logout') ?>Logout</a>
            <?php else: ?>
            <span class="admin-nav-group">Core Admin</span>
            <?php if ($headerCompanyCode === 'MBAACA'): ?>
                <a class="<?= $headerScript === 'accounting-dashboard.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/accounting-dashboard.php')) ?>"><?= icon('dashboard') ?>Dashboard</a>
                <?php if (($currentUser['role'] ?? '') === 'admin'): ?>
                    <a class="<?= $headerScript === 'consolidated-report.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/consolidated-report.php')) ?>"><?= icon('layers') ?>Consolidated Report</a>
                <?php endif; ?>
            <?php endif; ?>
            <a class="<?= $headerScript === 'index.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/index.php')) ?>"><?= icon('home') ?>Admin Overview</a>

            <span class="admin-nav-group">Accounting Workspace</span>
            <?php if ($headerIsClientBooks): ?>
                <?php $headerHomeCompany = company_by_code('MBAACA'); ?>
                <?php if ($headerHomeCompany): ?>
                    <form method="post" action="<?= e(url('admin/switch-company.php')) ?>" style="margin:0">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="company_id" value="<?= (int) $headerHomeCompany['id'] ?>">
                        <button type="submit" style="all:unset;box-sizing:border-box;display:flex;align-items:center;gap:10px;min-height:38px;padding:8px 12px;border-radius:8px;color:var(--mbw-sidebar-text);font-size:13.5px;font-weight:500;cursor:pointer;width:100%"><?= icon('login') ?>My Company Accounting</button>
                    </form>
                <?php endif; ?>
            <?php else: ?>
            <div class="mbw-nav-parent<?= $headerAccountingActive ? ' is-open' : '' ?>" data-nav-parent="accounting">
                <a href="#" data-nav-toggle aria-expanded="<?= $headerAccountingActive ? 'true' : 'false' ?>" class="<?= $headerAccountingActive ? 'is-active' : '' ?>">
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

            <span class="admin-nav-group">Manage Clients</span>
            <a class="<?= in_array($headerScript, ['manage-clients.php', 'client-books.php'], true) ? 'is-active' : '' ?>" href="<?= e(url('admin/manage-clients.php')) ?>"><?= icon('handshake') ?>Client Directory</a>
            <?php if ($headerClientBooksOptions !== []): ?>
                <form method="post" action="<?= e(url('admin/switch-company.php')) ?>" style="margin:2px 12px 4px; display:flex; gap:6px">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <label class="sr-only" for="sidebar-client-books">Open client accounting</label>
                    <select id="sidebar-client-books" name="company_id" style="flex:1;min-height:32px;font-size:12px;border-radius:8px;border:1px solid var(--mbw-sidebar-line);background:rgba(186,230,253,0.10);color:#dbeffd;padding:4px 8px" onchange="if(this.value){this.form.submit();}">
                        <option value="">Select client...</option>
                        <?php foreach ($headerClientBooksOptions as $headerClientOption): ?>
                            <option value="<?= (int) $headerClientOption['books_company_id'] ?>" <?= $headerIsClientBooks && (int) $headerClientOption['books_company_id'] === $headerCompanyId ? 'selected' : '' ?>><?= e($headerClientOption['organization_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            <?php endif; ?>
            <?php if ($headerIsClientBooks): ?>
                <div class="mbw-nav-parent is-open" data-nav-parent="client-accounting">
                    <a href="#" data-nav-toggle aria-expanded="true" class="is-active">
                        <?= icon('accounting') ?>Client Accounting
                        <span class="mbw-nav-caret"><?= icon('chevron') ?></span>
                    </a>
                    <div class="mbw-subnav">
                        <?php foreach ($headerAccountingChildren as [$headerChildLabel, $headerChildUrl, $headerChildIcon, $headerChildActive]): ?>
                            <a class="<?= $headerChildActive ? 'is-active' : '' ?>" href="<?= e(url($headerChildUrl)) ?>"><?= icon($headerChildIcon) ?><?= e($headerChildLabel) ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <span class="admin-nav-group">Reports &amp; Controls</span>
            <a class="<?= in_array($headerScript, ['reports-center.php', 'report-schedules.php'], true) ? 'is-active' : '' ?>" href="<?= e(url('admin/reports-center.php')) ?>"><?= icon('reports') ?>Reports Center</a>
            <a class="<?= $headerScript === 'documents.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/documents.php?view=requests')) ?>"><?= icon('documents') ?>Documents</a>
            <a class="<?= $headerScript === 'compliance.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/compliance.php?view=deadlines')) ?>"><?= icon('compliance') ?>Compliance</a>
            <a class="<?= $headerScript === 'audit-trail.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/audit-trail.php')) ?>"><?= icon('admin') ?>Audit Trail &amp; Approvals</a>

            <span class="admin-nav-group">Operations</span>
            <a class="<?= $headerScript === 'workspace.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/workspace.php?view=home')) ?>"><?= icon('portal') ?>Work Portal</a>
            <a class="<?= $headerScript === 'companies.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/companies.php')) ?>"><?= icon('companies') ?>Companies</a>
            <a class="<?= $headerScript === 'messages.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/messages.php')) ?>"><?= icon('messages') ?>Messages</a>
            <a class="<?= $headerScript === 'tickets.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/tickets.php')) ?>"><?= icon('tickets') ?>Tickets</a>
            <a class="<?= $headerScript === 'hr.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/hr.php?view=attendance')) ?>"><?= icon('attendance') ?>HR &amp; Attendance</a>
            <div class="mbw-nav-parent<?= $headerPayrollActive ? ' is-open' : '' ?>" data-nav-parent="payroll">
                <a href="#" data-nav-toggle aria-expanded="<?= $headerPayrollActive ? 'true' : 'false' ?>" class="<?= $headerPayrollActive ? 'is-active' : '' ?>">
                    <?= icon('wallet') ?>Payroll
                    <span class="mbw-nav-caret"><?= icon('chevron') ?></span>
                </a>
                <div class="mbw-subnav">
                    <a class="<?= $headerScript === 'payroll.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/payroll.php')) ?>"><?= icon('card') ?>Payroll Processing</a>
                    <a class="<?= $headerScript === 'payroll-employees.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/payroll-employees.php')) ?>"><?= icon('teams') ?>Employees &amp; Advances</a>
                    <a class="<?= $headerScript === 'payroll-settings.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/payroll-settings.php')) ?>"><?= icon('sliders') ?>Payroll Settings</a>
                    <a href="<?= e(url('admin/reports-center.php?report=salary-sheet')) ?>"><?= icon('analytics') ?>Salary Sheet Report</a>
                </div>
            </div>

            <span class="admin-nav-group">Users &amp; System</span>
            <a class="<?= $headerScript === 'users.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/users.php')) ?>"><?= icon('users') ?>Users</a>
            <?php if ($headerCompanyCode === 'MBAACA'): ?>
                <a class="<?= $headerScript === 'insights.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/insights.php')) ?>"><?= icon('insights') ?>Website Insights</a>
            <?php endif; ?>
            <a class="<?= $headerScript === 'settings.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/settings.php')) ?>"><?= icon('settings') ?>Settings</a>
            <a href="<?= e(url('admin/logout.php')) ?>"><?= icon('logout') ?>Logout</a>
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
            <?php if ($pageHero !== false): ?>
                <nav class="admin-topbar-crumbs" aria-label="Breadcrumb"><?= $headerBreadcrumbHtml ?></nav>
            <?php else: ?>
                <div class="admin-topbar-title">
                    <h1><?= e($pageTitle) ?></h1>
                    <p><?= $pageSubtitle !== '' ? e($pageSubtitle) : 'Signed in as ' . e($currentUser['name'] ?? 'Admin') ?></p>
                </div>
            <?php endif; ?>
            <form method="get" action="<?= e(url('search.php')) ?>" class="admin-topbar-search" role="search">
                <span class="mbw-search-glyph"><?= icon('search') ?></span>
                <label class="sr-only" for="admin-global-search">Search</label>
                <input id="admin-global-search" type="search" name="q" placeholder="Search transactions, reports, ledgers..." value="<?= e((string) ($_GET['q'] ?? '')) ?>">
                <button type="submit" aria-label="Search" title="Search"><?= icon('search') ?></button>
            </form>
            <a class="admin-icon-button" href="<?= e(url('admin/reports-center.php')) ?>" aria-label="Open Reports Center" title="Reports Center"><?= icon('analytics') ?></a>
            <?php if ($headerCompany): ?>
                <div class="admin-context-chip" aria-label="Current admin portal" title="<?= e($headerPortalLabel) ?>">
                    <span class="admin-context-icon"><?= icon($headerCompanyCode === 'MBAACA' ? 'admin' : 'companies') ?></span>
                    <span>
                        <strong><?= e($headerCompany['name'] ?? 'Company') ?></strong>
                        <small><?= $headerShowFiscalYear ? e($headerFiscalYear['label'] ?? 'No fiscal year') : e($headerPortalLabel) ?></small>
                    </span>
                </div>
            <?php endif; ?>
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
            <?php if (date_mode() !== 'ad'): ?><span class="mbw-pill tone-amber" title="Bikram Sambat (today)" style="align-self:center"><?= e(bs_format(date('Y-m-d'))) ?> BS</span><?php endif; ?>
            <div class="admin-topbar-actions">
                <form method="post" action="<?= e(url('set-date-mode.php')) ?>" style="align-self:center;margin:0">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="return" value="<?= e($_SERVER['REQUEST_URI'] ?? '') ?>">
                    <select name="date_mode" onchange="this.form.submit()" title="Date display: English / Nepali" style="cursor:pointer">
                        <option value="ad" <?= date_mode() === 'ad' ? 'selected' : '' ?>>AD</option>
                        <option value="bs" <?= date_mode() === 'bs' ? 'selected' : '' ?>>BS</option>
                        <option value="both" <?= date_mode() === 'both' ? 'selected' : '' ?>>AD+BS</option>
                    </select>
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
                <?php if ($headerMbistaCompany): ?>
                    <form method="post" action="<?= e(url('admin/switch-company.php')) ?>" class="topbar-icon-form">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="company_id" value="<?= e((int) $headerMbistaCompany['id']) ?>">
                        <button class="admin-icon-button" type="submit" aria-label="Open M.Bista superadmin" title="Open M.Bista superadmin">
                            <?= icon('admin') ?>
                            <span class="sr-only">Open M.Bista superadmin</span>
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
                <?php if (!$headerIsClientBooks): ?>
                    <a class="admin-icon-button" href="<?= e(url('admin/compliance.php?view=deadlines')) ?>" aria-label="Compliance calendar" title="Compliance calendar"><?= icon('calendar') ?></a>
                <?php endif; ?>
                <?php endif; ?>
                <?php include __DIR__ . '/attention_bell.php'; ?>
                <button type="button" class="theme-toggle-link admin-icon-button" data-theme-toggle aria-label="Switch to dark mode" title="Switch to dark mode">
                    <?= icon('theme') ?>
                    <span class="sr-only" data-theme-toggle-label>Dark mode</span>
                </button>
            </div>
        </header>
        <div class="admin-content">
            <?php if ($pageHero === false): ?>
                <nav class="admin-breadcrumb" aria-label="Breadcrumb"><?= $headerBreadcrumbHtml ?></nav>
            <?php endif; ?>
            <?php if ($pageHero !== false): ?>
                <div class="cr-head">
                    <span class="cr-head-icon"><?= icon((string) (is_array($pageHero) && !empty($pageHero['icon']) ? $pageHero['icon'] : ($headerPageIcons[$headerScript] ?? 'dashboard'))) ?></span>
                    <div>
                        <h2><?= e(is_array($pageHero) && !empty($pageHero['title']) ? $pageHero['title'] : $pageTitle) ?></h2>
                        <?php $heroSub = is_array($pageHero) && !empty($pageHero['sub']) ? $pageHero['sub'] : $pageSubtitle; ?>
                        <?php if ($heroSub !== ''): ?><p><?= e($heroSub) ?></p><?php endif; ?>
                    </div>
                </div>
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
