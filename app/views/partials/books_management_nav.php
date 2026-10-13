<?php
// Shared company-books navigation for admin, staff and client users.
$inventoryNavActive = in_array($headerScript, ['item-profit.php', 'items.php', 'accounting-inventory.php', 'stock-summary-report.php', 'stock-ledger.php'], true);
?>
<span class="admin-nav-group">Management</span>
<div class="mbw-nav-parent<?= $inventoryNavActive ? ' is-open' : '' ?>" data-nav-parent="inventory-management">
    <a href="#" data-nav-toggle aria-expanded="<?= $inventoryNavActive ? 'true' : 'false' ?>">
        <?= icon('box') ?>Inventory Management
        <span class="mbw-nav-caret"><?= icon('chevron') ?></span>
    </a>
    <div class="mbw-subnav">
        <a class="<?= $headerScript === 'items.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/items.php')) ?>"><?= icon('box') ?>Items</a>
        <a class="<?= in_array($headerScript, ['stock-summary-report.php', 'stock-ledger.php'], true) ? 'is-active' : '' ?>" href="<?= e(url('admin/stock-summary-report.php')) ?>"><?= icon('reports') ?>Inventory Summary</a>
        <a class="<?= $headerScript === 'item-profit.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/item-profit.php')) ?>"><?= icon('reports') ?>Item Profitability</a>
    </div>
</div>
<a class="<?= $headerScript === 'fixed-assets.php' && $headerView !== 'leases' ? 'is-active' : '' ?>" href="<?= e(url('admin/fixed-assets.php')) ?>"><?= icon('tag') ?>Fixed Asset Management</a>
<span class="admin-nav-group">NFRS Compliance</span>
<a class="<?= $headerScript === 'fixed-assets.php' && $headerView === 'leases' ? 'is-active' : '' ?>" href="<?= e(url('admin/fixed-assets.php?view=leases')) ?>"><?= icon('key') ?>Lease</a>
