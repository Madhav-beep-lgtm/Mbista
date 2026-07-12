<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

// Employee self-service: any logged-in staff/client sees ONLY their own
// finalized payslips, across every company that pays them.
$currentUser = current_user();
if (!$currentUser) {
    redirect('login.php');
}
$userId = (int) $currentUser['id'];

$lines = [];
if (function_exists('table_exists') && table_exists('payroll_run_lines')) {
    $stmt = db()->prepare("SELECT l.id, l.gross, l.tax_month, l.net_pay, r.period_label, r.pay_date, r.status AS run_status, c.name AS company_name
        FROM payroll_run_lines l
        INNER JOIN payroll_runs r ON r.id = l.run_id
        INNER JOIN payroll_employees pe ON pe.id = l.payroll_employee_id
        INNER JOIN companies c ON c.id = r.company_id
        WHERE pe.user_id = :uid AND r.status IN ('approved', 'posted', 'paid')
        ORDER BY r.pay_date DESC, l.id DESC LIMIT 60");
    $stmt->execute(['uid' => $userId]);
    $lines = $stmt->fetchAll();
}
$sym = site_currency_symbol();

$pageTitle = 'My Payslips';
$role = (string) ($currentUser['role'] ?? '');
if ($role === 'staff' || $role === 'admin') {
    include __DIR__ . '/../app/views/partials/staff_header.php';
} else {
    include __DIR__ . '/../app/views/partials/client_header.php';
}
?>
<section class="mbw-card" aria-label="My payslips" style="margin:18px auto;max-width:900px">
    <div class="mbw-card-head"><h2>My Payslips</h2></div>
    <div style="overflow-x:auto">
    <table>
        <thead><tr><th>Period</th><th>Company</th><th>Pay date</th><th class="is-numeric">Gross (<?= e($sym) ?>)</th><th class="is-numeric">Tax (<?= e($sym) ?>)</th><th class="is-numeric">Net (<?= e($sym) ?>)</th><th>Status</th><th></th></tr></thead>
        <tbody>
            <?php if ($lines === []): ?><tr><td colspan="8">No finalized payslips yet.</td></tr><?php endif; ?>
            <?php foreach ($lines as $line): ?>
                <tr>
                    <td><?= e($line['period_label']) ?></td>
                    <td><?= e($line['company_name']) ?></td>
                    <td><?= $line['pay_date'] ? e(app_date((string) $line['pay_date'])) : '-' ?></td>
                    <td class="is-numeric"><?= e(number_format((float) $line['gross'], 2)) ?></td>
                    <td class="is-numeric"><?= e(number_format((float) $line['tax_month'], 2)) ?></td>
                    <td class="is-numeric"><strong><?= e(number_format((float) $line['net_pay'], 2)) ?></strong></td>
                    <td><span class="mbw-pill <?= $line['run_status'] === 'paid' ? 'tone-green' : 'tone-blue' ?>"><?= e(ucfirst((string) $line['run_status'])) ?></span></td>
                    <td><a class="button secondary" target="_blank" href="<?= e(url('admin/payroll-payslip.php?line=' . (int) $line['id'])) ?>">Open</a></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>
<?php
if ($role === 'staff' || $role === 'admin') {
    include __DIR__ . '/../app/views/partials/staff_footer.php';
} else {
    include __DIR__ . '/../app/views/partials/client_footer.php';
}
