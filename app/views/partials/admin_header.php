<?php
$pageTitle = $pageTitle ?? 'Admin';
$bodyClass = $bodyClass ?? 'admin-layout';
// Every admin shell page uses the reference design language (sidebar theme,
// bold components, stat cards) introduced on the accounting module.
if (str_contains($bodyClass, 'admin-layout') && !str_contains($bodyClass, 'accounting-reference-layout')) {
    $bodyClass .= ' accounting-reference-layout';
}
$currentUser = current_user();
$headerCompany = current_company();
$headerFiscalYear = current_fiscal_year();
$headerScript = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$headerReportId = (string) ($_GET['report'] ?? '');
$headerShowFiscalYear = in_array($headerScript, ['accounting.php', 'accounting-parties.php', 'accounting-dashboard.php', 'accounting-inventory.php', 'export-ledger.php'], true);
$headerCompanyId = (int) ($headerCompany['id'] ?? 0);
$headerCompanyCode = (string) ($headerCompany['code'] ?? '');
$headerBusinessType = company_accounting_business_type($headerCompanyId);
$headerBusinessProfile = accounting_business_profile($headerBusinessType);
$headerAltioraCompany = $headerCompanyCode === 'MBAACA' ? company_by_code('AGHPL') : null;
$headerMbistaCompany = $headerCompanyCode !== 'MBAACA' ? company_by_code('MBAACA') : null;
$headerSubsidiaryCompanies = $headerCompanyCode === 'AGHPL' && $headerCompanyId > 0 ? child_companies_for_company($headerCompanyId) : [];
$headerPortalLabel = match ($headerCompanyCode) {
    'MBAACA' => 'M.Bista superadmin portal',
    'AGHPL' => 'Altiora parent admin portal',
    default => $headerCompany ? 'Subsidiary company portal' : 'Admin portal',
};
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> | <?= e(app_name()) ?></title>
    <link rel="stylesheet" href="/assets/css/style.css?v=20260704-reference-appwide">
</head>
<body class="<?= e($bodyClass) ?>">
<div class="admin-shell">
    <aside class="admin-sidebar">
        <a class="brand brand-admin" href="<?= e(url('admin/index.php')) ?>">
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
            <a class="<?= $headerScript === 'accounting-dashboard.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/accounting-dashboard.php')) ?>"><?= icon('dashboard') ?>Dashboard</a>
            <a class="<?= $headerScript === 'index.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/index.php')) ?>"><?= icon('home') ?>Admin Overview</a>
            <span class="admin-nav-group">Accounting Workspace</span>
            <a class="<?= $headerScript === 'accounting-parties.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/accounting-parties.php')) ?>"><?= icon('accounting') ?>Accounting</a>
            <a class="<?= $headerScript === 'chart-of-accounts.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/chart-of-accounts.php')) ?>"><?= icon('accounting') ?>Chart of Accounts</a>
            <a class="<?= $headerScript === 'accounting.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/accounting.php')) ?>"><?= icon('documents') ?>Vouchers</a>
            <span class="admin-nav-group">Commercial Operations</span>
            <a class="<?= $headerScript === 'invoice.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/invoice.php')) ?>"><?= icon('invoices') ?>Sales &amp; Invoices</a>
            <a href="<?= e(url('admin/accounting-parties.php?tab=purchases')) ?>"><?= icon('services') ?>Purchases</a>
            <a class="<?= $headerScript === 'receipts.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/receipts.php')) ?>"><?= icon('documents') ?>Receipts</a>
            <?php if (($headerBusinessProfile['show_inventory'] ?? false) || ($headerBusinessProfile['show_manufacturing'] ?? false)): ?>
                <span class="admin-nav-group">Inventory &amp; Manufacturing</span>
                <?php if ($headerBusinessProfile['show_inventory'] ?? false): ?>
                    <a class="<?= $headerScript === 'accounting-inventory.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/accounting-inventory.php')) ?>"><?= icon('services') ?>Inventory</a>
                <?php endif; ?>
                <?php if ($headerBusinessProfile['show_manufacturing'] ?? false): ?>
                    <a href="<?= e(url('admin/accounting-inventory.php#manufacturing')) ?>"><?= icon('settings') ?>Manufacturing</a>
                <?php endif; ?>
            <?php endif; ?>
            <span class="admin-nav-group">Reports &amp; Controls</span>
            <a class="<?= $headerScript === 'reports-center.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/reports-center.php')) ?>"><?= icon('reports') ?>Reports Center</a>
            <a class="<?= $headerScript === 'documents.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/documents.php?view=requests')) ?>"><?= icon('documents') ?>Documents</a>
            <a class="<?= $headerScript === 'compliance.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/compliance.php?view=deadlines')) ?>"><?= icon('compliance') ?>Compliance</a>
            <a class="<?= $headerScript === 'audit-trail.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/audit-trail.php')) ?>"><?= icon('reports') ?>Audit Trail &amp; Approvals</a>
            <span class="admin-nav-group">Shared / Supporting</span>
            <a class="<?= $headerScript === 'workspace.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/workspace.php?view=home')) ?>"><?= icon('portal') ?>Work Portal</a>
            <a class="<?= $headerScript === 'messages.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/messages.php')) ?>"><?= icon('messages') ?>Messages</a>
            <a class="<?= $headerScript === 'tickets.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/tickets.php')) ?>"><?= icon('tickets') ?>Tickets</a>
            <a class="<?= $headerScript === 'users.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/users.php')) ?>"><?= icon('users') ?>Users</a>
            <a class="<?= $headerScript === 'settings.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/settings.php')) ?>"><?= icon('settings') ?>Settings</a>
            <a href="<?= e(url('admin/logout.php')) ?>"><?= icon('logout') ?>Logout</a>
        </nav>
    </aside>
    <section class="admin-main">
        <header class="admin-topbar">
            <div class="admin-topbar-title">
                <h1><?= e($pageTitle) ?></h1>
                <p>Signed in as <?= e($currentUser['name'] ?? 'Admin') ?></p>
            </div>
            <?php if ($headerCompany): ?>
                <div class="admin-context-chip" aria-label="Current admin portal">
                    <span class="admin-context-icon"><?= icon($headerCompanyCode === 'MBAACA' ? 'admin' : 'companies') ?></span>
                    <span>
                        <strong><?= e($headerPortalLabel) ?></strong>
                        <em><?= e($headerCompany['name'] ?? 'Company') ?><?= $headerCompanyCode !== '' ? ' · ' . e($headerCompanyCode) : '' ?></em>
                        <?php if ($headerShowFiscalYear): ?>
                            <small><?= e($headerFiscalYear['label'] ?? 'No fiscal year selected') ?></small>
                        <?php endif; ?>
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
                            <?= icon('portal') ?>
                            <span class="sr-only">Open selected subsidiary</span>
                        </button>
                    </form>
                <?php endif; ?>
                <button type="button" class="theme-toggle-link admin-icon-button" data-theme-toggle aria-label="Switch to dark mode" title="Switch to dark mode">
                    <?= icon('theme') ?>
                    <span class="sr-only" data-theme-toggle-label>Dark mode</span>
                </button>
            </div>
        </header>
        <?php
        $stripParentCompany = company_by_code('AGHPL');
        $stripSubsidiaries = $stripParentCompany ? child_companies_for_company((int) $stripParentCompany['id']) : [];
        $stripRoleLabel = ($currentUser['role'] ?? '') === 'admin' ? 'Super Admin' : ucfirst((string) ($currentUser['role'] ?? 'Staff'));
        ?>
        <div class="admin-hierarchy-strip" aria-label="Corporate hierarchy">
            <span><?= icon('admin') ?><strong><?= e($currentUser['name'] ?? 'Admin') ?></strong> — <?= e($stripRoleLabel) ?></span>
            <?php if ($stripParentCompany): ?>
                <span class="strip-sep">→</span>
                <span><?= icon('companies') ?><strong><?= e($stripParentCompany['name']) ?></strong> — Parent Company</span>
            <?php endif; ?>
            <?php foreach (array_slice($stripSubsidiaries, 0, 2) as $stripSubsidiary): ?>
                <span class="strip-sep">→</span>
                <span><?= icon('companies') ?><strong><?= e($stripSubsidiary['name']) ?></strong> — Subsidiary Company</span>
            <?php endforeach; ?>
        </div>
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
