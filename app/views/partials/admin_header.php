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
$headerShowFiscalYear = in_array($headerScript, ['accounting.php', 'accounting-parties.php', 'accounting-dashboard.php', 'accounting-inventory.php', 'export-ledger.php', 'banking.php', 'reconciliation.php', 'chart-of-accounts.php', 'chart-groups.php', 'chart-ledgers.php', 'chart-posting-accounts.php'], true);
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
    ['Sales & Invoices', 'admin/accounting-parties.php?tab=sales', 'invoices', $headerScript === 'accounting-parties.php' && in_array($headerTab, ['', 'sales'], true)],
    ['Purchases', 'admin/accounting-parties.php?tab=purchases', 'cart', $headerScript === 'accounting-parties.php' && $headerTab === 'purchases'],
    ['Banking', 'admin/banking.php', 'bank', $headerScript === 'banking.php'],
    ['Reconciliation', 'admin/reconciliation.php', 'reconcile', $headerScript === 'reconciliation.php'],
]);
$headerAccountingActive = false;
foreach ($headerAccountingChildren as $headerChild) {
    if ($headerChild[3]) {
        $headerAccountingActive = true;
        break;
    }
}

// Client-books awareness: when the portal IS a client books space, the
// Accounting Workspace points back home and the client submenu takes over.
$headerIsClientBooks = (int) ($headerCompany['is_client_company'] ?? 0) === 1;
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
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='7' fill='%230e2240'/%3E%3Ctext x='16' y='21.5' font-family='Georgia,serif' font-size='13' font-weight='700' fill='%23e3a13c' text-anchor='middle'%3EMB%3C/text%3E%3C/svg%3E">
    <link rel="stylesheet" href="/assets/css/style.css?v=20260706-portal">
    <link rel="stylesheet" href="/assets/css/portal.css?v=20260707c">
</head>
<body class="<?= e($bodyClass) ?>">
<div class="admin-shell">
    <aside class="admin-sidebar">
        <a class="brand brand-admin" href="<?= e(url('admin/accounting-dashboard.php')) ?>">
            <span class="brand-mark">MB</span>
            <span>
                <strong>MB World</strong>
                <small>Admin Portal</small>
            </span>
        </a>
        <?php if ($headerCompany): ?>
            <div class="admin-sidebar-context">
                <span>Current portal</span>
                <strong><?= e($headerCompany['name'] ?? 'Company') ?></strong>
                <?php if ($headerShowFiscalYear): ?>
                    <small><?= e($headerFiscalYear['label'] ?? 'No fiscal year') ?></small>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <nav class="admin-nav">
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
                        <button type="submit" style="all:unset;box-sizing:border-box;display:flex;align-items:center;gap:10px;min-height:38px;padding:8px 12px;border-radius:8px;color:var(--mbw-sidebar-text);font-size:13.5px;font-weight:500;cursor:pointer;width:100%"><?= icon('accounting') ?>My Company Accounting</button>
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
            <?php if (($headerBusinessProfile['show_inventory'] ?? false) || ($headerBusinessProfile['show_manufacturing'] ?? false)): ?>
                <?php if ($headerBusinessProfile['show_inventory'] ?? false): ?>
                    <a class="<?= $headerScript === 'accounting-inventory.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/accounting-inventory.php')) ?>"><?= icon('layers') ?>Inventory &amp; Manufacturing</a>
                <?php elseif ($headerBusinessProfile['show_manufacturing'] ?? false): ?>
                    <a class="<?= $headerScript === 'accounting-inventory.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/accounting-inventory.php')) ?>"><?= icon('layers') ?>Manufacturing</a>
                <?php endif; ?>
            <?php endif; ?>
            <?php endif; ?>

            <span class="admin-nav-group">Manage Clients</span>
            <a class="<?= in_array($headerScript, ['manage-clients.php', 'client-books.php'], true) ? 'is-active' : '' ?>" href="<?= e(url('admin/manage-clients.php')) ?>"><?= icon('clients') ?>Client Directory</a>
            <?php if ($headerClientBooksOptions !== []): ?>
                <form method="post" action="<?= e(url('admin/switch-company.php')) ?>" style="margin:2px 12px 4px; display:flex; gap:6px">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <label class="sr-only" for="sidebar-client-books">Open client accounting</label>
                    <select id="sidebar-client-books" name="company_id" style="flex:1;min-height:32px;font-size:12px;border-radius:8px;border:1px solid var(--mbw-sidebar-line);background:rgba(148,178,215,0.08);color:#dbe6f5;padding:4px 8px" onchange="if(this.value){this.form.submit();}">
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

            <span class="admin-nav-group">Users &amp; System</span>
            <a class="<?= $headerScript === 'users.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/users.php')) ?>"><?= icon('users') ?>Users</a>
            <a class="<?= $headerScript === 'settings.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/settings.php')) ?>"><?= icon('settings') ?>Settings</a>
            <a href="<?= e(url('admin/logout.php')) ?>"><?= icon('logout') ?>Logout</a>
        </nav>
    </aside>
    <section class="admin-main">
        <header class="admin-topbar">
            <div class="admin-topbar-title">
                <h1><?= e($pageTitle) ?></h1>
                <p><?= $pageSubtitle !== '' ? e($pageSubtitle) : 'Signed in as ' . e($currentUser['name'] ?? 'Admin') ?></p>
            </div>
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
            <div class="admin-topbar-actions">
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
                <button type="button" class="theme-toggle-link admin-icon-button" data-theme-toggle aria-label="Switch to dark mode" title="Switch to dark mode">
                    <?= icon('theme') ?>
                    <span class="sr-only" data-theme-toggle-label>Dark mode</span>
                </button>
            </div>
        </header>
        <div class="admin-content">
            <nav class="admin-breadcrumb" aria-label="Breadcrumb">
                <a href="<?= e(url('admin/index.php')) ?>">Home</a> / <strong><?= e($pageTitle) ?></strong>
            </nav>
            <?php if ($message = flash('success')): ?>
                <div class="notice success"><?= e($message) ?></div>
            <?php endif; ?>
            <?php if ($message = flash('error')): ?>
                <div class="notice error"><?= e($message) ?></div>
            <?php endif; ?>
