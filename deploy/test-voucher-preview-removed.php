<?php
declare(strict_types=1);
$source = file_get_contents(__DIR__ . '/../app/views/vouchers/layout_trade.php');
foreach (['What will be posted', 'vch-summary-card', 'vch-sum-', 'vch-stock-note'] as $retired) {
    if (str_contains($source, $retired)) throw new RuntimeException('Retired posting preview remains: ' . $retired);
}
foreach (["form.setAttribute('data-balanced'", 'taxLedger.required = tax > 0', "getElementById('vch-value-total')", "getElementById('vch-settle-note')"] as $required) {
    if (!str_contains($source, $required)) throw new RuntimeException('Voucher calculation or validation removed: ' . $required);
}
echo "Posting preview removed; calculation and validation hooks retained.\n";
