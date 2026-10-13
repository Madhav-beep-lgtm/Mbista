<?php
declare(strict_types=1);
// Reuse the item save transaction and company-scoped mappings, not a second writer.
define('ITEMS_WORKSPACE', true);
require __DIR__ . '/accounting-inventory.php';
