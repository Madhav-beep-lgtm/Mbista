<?php
require_once __DIR__ . '/../../inventory_pages.php';
// Shared company-books navigation for admin, staff and client users.
$inventoryNavActive = in_array($headerScript, ['item-profit.php', 'items.php', 'accounting-inventory.php', 'stock-summary-report.php', 'stock-ledger.php'], true);
$inventoryNavView = (string)($_GET['view'] ?? 'inventory');
$inventoryNavTask = (string)($_GET['task'] ?? ($inventoryNavView === 'manufacturing' ? 'manufacturing' : 'item'));
$manufacturingNavActive = $headerScript === 'accounting-inventory.php' && $inventoryNavView === 'manufacturing';
$inventoryNavActive = $inventoryNavActive && !$manufacturingNavActive;
$inventoryNavProfile = accounting_business_profile(company_accounting_business_type((int)current_company_id()));
?>
<span class="admin-nav-group">Management</span>
<?php if (company_can_manage_settings()): ?>
<a href="<?= e(url('admin/company-settings.php')) ?>"><?= icon('settings') ?>Company Settings</a>
<?php endif; ?>
<?php if (company_module_enabled('inventory')): ?>
<div class="mbw-nav-parent<?= $inventoryNavActive ? ' is-open' : '' ?>" data-nav-parent="inventory-management">
    <a href="#" data-nav-toggle aria-expanded="<?= $inventoryNavActive ? 'true' : 'false' ?>">
        <?= icon('box') ?>Inventory Management
        <span class="mbw-nav-caret"><?= icon('chevron') ?></span>
    </a>
    <div class="mbw-subnav">
        <a class="<?= $headerScript === 'items.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/items.php')) ?>"><?= icon('box') ?>Items</a>
        <a class="<?= in_array($headerScript, ['stock-summary-report.php', 'stock-ledger.php'], true) ? 'is-active' : '' ?>" href="<?= e(url('admin/stock-summary-report.php')) ?>"><?= icon('reports') ?>Inventory Summary</a>
        <a class="<?= $headerScript === 'item-profit.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/item-profit.php')) ?>"><?= icon('reports') ?>Item Profitability</a>
        <?php foreach (['warehouses'=>'Warehouses / Locations', 'opening'=>'Opening Stock', 'purchase'=>'Purchase History', 'sale'=>'Sales & Returns', 'adjust'=>'Stock Adjustments', 'transfer'=>'Stock Transfers'] as $task => $label): ?>
        <a class="<?= $headerScript === 'accounting-inventory.php' && $inventoryNavView === 'inventory' && $inventoryNavTask === $task ? 'is-active' : '' ?>" href="<?= e(url('admin/' . inventory_pages()[$task]['file'])) ?>"><?= icon('box') ?><?= e($label) ?></a>
        <?php endforeach; ?>
        <a class="<?= $headerScript === 'accounting-inventory.php' && $inventoryNavView === 'valuation' ? 'is-active' : '' ?>" href="<?= e(url('admin/inventory-valuation.php')) ?>"><?= icon('reports') ?>Valuation &amp; NRV</a>
    </div>
</div>
<?php if ($inventoryNavProfile['show_manufacturing'] ?? false): ?>
<div class="mbw-nav-parent<?= $manufacturingNavActive ? ' is-open' : '' ?>" data-nav-parent="manufacturing">
    <a href="#" data-nav-toggle aria-expanded="<?= $manufacturingNavActive ? 'true' : 'false' ?>"><?= icon('layers') ?>Manufacturing<span class="mbw-nav-caret"><?= icon('chevron') ?></span></a>
    <div class="mbw-subnav">
        <a class="<?= $manufacturingNavActive && $inventoryNavTask === 'manufacturing' ? 'is-active' : '' ?>" href="<?= e(url('admin/manufacturing-orders.php')) ?>"><?= icon('settings') ?>Production Orders</a>
        <a class="<?= $manufacturingNavActive && $inventoryNavTask === 'bom' ? 'is-active' : '' ?>" href="<?= e(url('admin/manufacturing-bom.php')) ?>"><?= icon('documents') ?>Bill of Materials</a>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>
<?php if (company_module_enabled('fixed_assets')): ?>
<a class="<?= $headerScript === 'fixed-assets.php' && $headerView !== 'leases' ? 'is-active' : '' ?>" href="<?= e(url('admin/fixed-assets.php')) ?>"><?= icon('tag') ?>Fixed Asset Management</a>
<?php endif; ?>
<?php if (company_module_enabled('nfrs')): ?>
<span class="admin-nav-group">NFRS Compliance</span>
<a class="<?= $headerScript === 'fixed-assets.php' && $headerView === 'leases' ? 'is-active' : '' ?>" href="<?= e(url('admin/fixed-assets.php?view=leases')) ?>"><?= icon('key') ?>Lease</a>
<?php endif; ?>
