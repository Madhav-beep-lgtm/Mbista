<?php
/**
 * Reopen-for-correction control for a posted/paid payroll run.
 * Expects $run (the current payroll run row) in scope. Reuses the .pr-adjust
 * popover styling defined on the payroll worksheet card.
 */
$reopenBlurb = 'Reopen ' . ($run['period_label'] ?? 'this run') . ' for correction? This reverses its '
    . ((string) ($run['status'] ?? '') === 'paid' ? 'payment and accrual vouchers' : 'accrual voucher')
    . ', restores any advance recoveries, and returns the run to Calculated so you can edit the salary sheet and post again.';
?>
<details class="pr-adjust pr-reopen">
    <summary class="button secondary"><?= icon('reconcile') ?>Reopen for correction</summary>
    <form method="post" class="pr-adjust-form" data-confirm="<?= e($reopenBlurb) ?>">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="reopen_run">
        <input type="hidden" name="run_id" value="<?= e((int) $run['id']) ?>">
        <label>Correction reason
            <textarea name="reopen_reason" rows="3" minlength="10" maxlength="255" required
                placeholder="e.g. Overtime for E003 was missed — correcting before final filing."></textarea>
        </label>
        <button type="submit"><?= icon('reconcile') ?>Reverse vouchers &amp; reopen</button>
        <small>Reverses this run’s postings and restores advance recoveries. Blocked if a bank entry is reconciled, the period is locked, or the year is closed. Fully audited.</small>
    </form>
</details>
