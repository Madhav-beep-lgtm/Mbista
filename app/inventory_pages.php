<?php
declare(strict_types=1);

function inventory_pages(): array
{
    return [
        'warehouses' => ['file'=>'inventory-warehouses.php', 'view'=>'inventory', 'title'=>'Warehouses / Locations'],
        'opening' => ['file'=>'inventory-opening.php', 'view'=>'inventory', 'title'=>'Opening Stock'],
        'purchase' => ['file'=>'inventory-purchases.php', 'view'=>'inventory', 'title'=>'Purchase History'],
        'sale' => ['file'=>'inventory-sales.php', 'view'=>'inventory', 'title'=>'Sales & Returns'],
        'adjust' => ['file'=>'inventory-adjustments.php', 'view'=>'inventory', 'title'=>'Stock Adjustments'],
        'transfer' => ['file'=>'inventory-transfers.php', 'view'=>'inventory', 'title'=>'Stock Transfers'],
        'valuation' => ['file'=>'inventory-valuation.php', 'view'=>'valuation', 'title'=>'Valuation & NRV'],
        'manufacturing' => ['file'=>'manufacturing-orders.php', 'view'=>'manufacturing', 'title'=>'Production Orders'],
        'bom' => ['file'=>'manufacturing-bom.php', 'view'=>'manufacturing', 'title'=>'Bill of Materials'],
    ];
}

/** Keep bookmarked task URLs working without recreating the old combined screen. */
function inventory_legacy_page(array $query): ?string
{
    if (isset($query['export']) || isset($query['opening_template'])) return null;
    $task = (string) ($query['task'] ?? '');
    $view = (string) ($query['view'] ?? 'inventory');
    if ($view === 'valuation') $task = 'valuation';
    if ($view === 'manufacturing' && $task === '') $task = 'manufacturing';
    $page = inventory_pages()[$task]['file'] ?? null;
    if ($task === 'item' || isset($query['edit_id'])) $page = 'items.php';
    if ($page === null) return null;
    unset($query['task'], $query['view']);
    return 'admin/' . $page . ($query === [] ? '' : '?' . http_build_query($query));
}
