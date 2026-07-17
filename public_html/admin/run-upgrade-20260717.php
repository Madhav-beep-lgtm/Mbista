<?php
/**
 * One-time production upgrade for the 2026-07-17 release. Safe to run more
 * than once — every step checks before it changes anything.
 *
 *  1. Applies migration 052: ledger deletion becomes RESTRICT so posted
 *     entries can never be silently destroyed again.
 *  2. Repairs any one-legged POSTED vouchers the old CASCADE rule left
 *     behind (balancing leg posted to Opening Balance Adjustments with a
 *     clear memo for reclassification).
 *  3. Retro-posts the firm-side discount voucher for discounts approved
 *     before the fix existed (Dr Discount Allowed + Dr VAT / Cr receivable).
 *  4. Prints a per-company Dr = Cr tie-out so you can see the books balance.
 *
 * Visit this page in the browser while logged in as an administrator.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_admin();

$lines = [];
$note = static function (string $text) use (&$lines): void {
    $lines[] = $text;
};

$dbName = (string) db()->query('SELECT DATABASE()')->fetchColumn();

// ---------------------------------------------------------------------------
// 1. Migration 052 — RESTRICT ledger deletion.
// ---------------------------------------------------------------------------
$ruleStmt = db()->prepare('
    SELECT rc.DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS rc
    WHERE rc.CONSTRAINT_SCHEMA = :db AND rc.CONSTRAINT_NAME = "fk_voucher_entries_ledger"
');
$ruleStmt->execute(['db' => $dbName]);
$deleteRule = (string) ($ruleStmt->fetchColumn() ?: '');
if ($deleteRule === 'CASCADE') {
    db()->exec('ALTER TABLE voucher_entries DROP FOREIGN KEY fk_voucher_entries_ledger');
    db()->exec('ALTER TABLE voucher_entries ADD CONSTRAINT fk_voucher_entries_ledger FOREIGN KEY (ledger_id) REFERENCES ledgers (id) ON DELETE RESTRICT');
    $note('Migration 052 applied: deleting a ledger can no longer destroy posted entries.');
} elseif ($deleteRule === 'RESTRICT') {
    $note('Migration 052 already applied — nothing to do.');
} else {
    $note('Ledger foreign key not found (rule: "' . $deleteRule . '") — skipped; contact support if vouchers misbehave.');
}

// ---------------------------------------------------------------------------
// 2. Repair one-legged posted vouchers (damage from the old CASCADE rule).
// ---------------------------------------------------------------------------
$unbalanced = db()->query('
    SELECT v.id, v.company_id, v.voucher_no,
           ROUND(SUM(CASE WHEN ve.entry_type = "debit" THEN ve.amount ELSE -ve.amount END), 2) AS gap
    FROM vouchers v
    JOIN voucher_entries ve ON ve.voucher_id = v.id
    WHERE v.status = "posted"
    GROUP BY v.id, v.company_id, v.voucher_no
    HAVING ABS(gap) > 0.005
')->fetchAll();
if ($unbalanced === []) {
    $note('No one-legged posted vouchers found.');
}
foreach ($unbalanced as $broken) {
    $adjustLedgerId = opening_balance_ledger_id((int) $broken['company_id']);
    if ($adjustLedgerId <= 0) {
        $note('Voucher ' . $broken['voucher_no'] . ' is unbalanced by ' . $broken['gap'] . ' but no adjustments ledger exists — skipped.');
        continue;
    }
    $gap = (float) $broken['gap'];
    db()->prepare('INSERT INTO voucher_entries (voucher_id, ledger_id, entry_type, amount, memo) VALUES (?,?,?,?,?)')
        ->execute([
            (int) $broken['id'],
            $adjustLedgerId,
            $gap > 0 ? 'credit' : 'debit',
            abs($gap),
            'Repair: balancing leg restored (a ledger deletion removed the original) - reclassify as needed',
        ]);
    $note('Voucher ' . $broken['voucher_no'] . ': balancing leg of ' . number_format(abs($gap), 2) . ' restored to Opening Balance Adjustments.');
}

// ---------------------------------------------------------------------------
// 3. Retro-post firm-side discount vouchers for pre-fix approvals.
// ---------------------------------------------------------------------------
$discountInvoices = db()->query('
    SELECT ti.*, v.total_amount AS sv_total
    FROM task_invoices ti
    JOIN vouchers v ON v.source_type = "task_invoice" AND v.source_id = ti.id
    LEFT JOIN vouchers dv ON dv.source_type = "invoice_discount" AND dv.source_id IN (ti.id)
    WHERE ti.discount_amount > 0 AND ti.status IN ("issued", "paid") AND dv.id IS NULL
')->fetchAll();
$repairedDiscounts = 0;
foreach ($discountInvoices as $invoice) {
    $totalDelta = round((float) $invoice['sv_total'] - (float) $invoice['total_amount'], 2);
    if ($totalDelta <= 0.004) {
        continue; // Sales voucher already reflects the discounted total.
    }
    $discount = round((float) $invoice['discount_amount'], 2);
    $vatDelta = max(0.0, round($totalDelta - $discount, 2));
    auto_post_invoice_discount_voucher(0, (int) $invoice['id'], $discount, $vatDelta, $totalDelta, (int) (current_user()['id'] ?? 0) ?: null);
    $posted = db()->prepare('SELECT voucher_no FROM vouchers WHERE source_type = "invoice_discount" AND source_id = ?');
    $posted->execute([(int) $invoice['id']]);
    $voucherNo = $posted->fetchColumn();
    if ($voucherNo) {
        $repairedDiscounts++;
        $note('Invoice ' . $invoice['invoice_no'] . ': discount voucher ' . $voucherNo . ' posted for ' . number_format($totalDelta, 2) . ' (receivable cleared).');
    } else {
        $note('Invoice ' . $invoice['invoice_no'] . ': discount voucher could NOT post — check ledger mappings and an open fiscal year.');
    }
}
if ($repairedDiscounts === 0 && $discountInvoices === []) {
    $note('No approved discounts were missing their voucher.');
}

// ---------------------------------------------------------------------------
// 4. Tie-out.
// ---------------------------------------------------------------------------
$tieOut = db()->query('
    SELECT v.company_id, c.name,
           ROUND(SUM(CASE WHEN ve.entry_type = "debit" THEN ve.amount ELSE -ve.amount END), 2) AS diff
    FROM vouchers v
    JOIN voucher_entries ve ON ve.voucher_id = v.id
    LEFT JOIN companies c ON c.id = v.company_id
    WHERE v.status = "posted"
    GROUP BY v.company_id, c.name
')->fetchAll();

$pageTitle = 'System Upgrade — 17 Jul 2026';
$bodyClass = 'admin-layout';
include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
<section class="mbw-card">
    <div class="mbw-card-head"><h2>Upgrade complete</h2></div>
    <ul style="margin:0;padding-left:20px;display:grid;gap:6px">
        <?php foreach ($lines as $line): ?>
            <li><?= e($line) ?></li>
        <?php endforeach; ?>
    </ul>
</section>
<section class="mbw-card">
    <div class="mbw-card-head"><h2>Books tie-out (every figure below should be 0.00)</h2></div>
    <div style="overflow-x:auto">
    <table>
        <thead><tr><th>Company</th><th class="is-numeric">Posted debits − credits</th></tr></thead>
        <tbody>
            <?php foreach ($tieOut as $row): ?>
                <tr>
                    <td><?= e((string) ($row['name'] ?? ('Company #' . $row['company_id']))) ?></td>
                    <td class="is-numeric"><span class="mbw-pill <?= abs((float) $row['diff']) < 0.005 ? 'tone-green' : 'tone-red' ?>"><?= e(number_format((float) $row['diff'], 2)) ?></span></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <p class="muted" style="margin:12px 0 0">You can leave this page installed — every step checks before changing anything, so re-running it is harmless.</p>
</section>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
