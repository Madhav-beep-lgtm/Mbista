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
