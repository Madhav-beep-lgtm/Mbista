<?php
declare(strict_types=1);

/**
 * Clickable voucher-type selector.
 *
 * Browser and operating-system shortcuts are intentionally not advertised or
 * intercepted here: several F-keys already have browser meanings and behaved
 * inconsistently across platforms.
 *
 * Expects: $voucherTypes (catalog), $type (current key).
 */
?>
<label class="vch-type-picker"><span>Voucher type <em>*</em></span>
    <select id="vch-type-picker" aria-label="Voucher type">
        <?php foreach (voucher_entry_type_catalog() as $pickerKey => $pickerSpec): ?>
            <option value="<?= e(url(voucher_type_url($pickerKey))) ?>" <?= $pickerKey === $type ? 'selected' : '' ?>><?= e((string) $pickerSpec['short']) ?></option>
        <?php endforeach; ?>
    </select>
</label>
<script>
document.getElementById('vch-type-picker').addEventListener('change', function () {
    if (this.value) { window.location.assign(this.value); }
});
</script>
