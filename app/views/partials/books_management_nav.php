<?php
// Shared company-books navigation for admin, staff and client users.
$inventoryNavActive = in_array($headerScript, ['accounting-inventory.php', 'stock-summary-report.php', 'stock-ledger.php'], true);
?>
<span class="admin-nav-group">Management</span>
<div class="mbw-nav-parent<?= $inventoryNavActive ? ' is-open' : '' ?>" data-nav-parent="inventory-management">
    <a href="#" data-nav-toggle aria-expanded="<?= $inventoryNavActive ? 'true' : 'false' ?>">
        <?= icon('box') ?>Inventory Management
        <span class="mbw-nav-caret"><?= icon('chevron') ?></span>
    </a>
    <div class="mbw-subnav">
        <a class="<?= $headerScript === 'accounting-inventory.php' ? 'is-active' : '' ?>" href="<?= e(url('admin/accounting-inventory.php')) ?>"><?= icon('box') ?>Items &amp; Manufacturing</a>
        <a class="<?= in_array($headerScript, ['stock-summary-report.php', 'stock-ledger.php'], true) ? 'is-active' : '' ?>" href="<?= e(url('admin/stock-summary-report.php')) ?>"><?= icon('reports') ?>Inventory Summary</a>
    </div>
</div>
<a class="<?= $headerScript === 'fixed-assets.php' && $headerView !== 'leases' ? 'is-active' : '' ?>" href="<?= e(url('admin/fixed-assets.php')) ?>"><?= icon('tag') ?>Fixed Asset Management</a>
<span class="admin-nav-group">NFRS Compliance</span>
<a class="<?= $headerScript === 'fixed-assets.php' && $headerView === 'leases' ? 'is-active' : '' ?>" href="<?= e(url('admin/fixed-assets.php?view=leases')) ?>"><?= icon('key') ?>Lease</a>
