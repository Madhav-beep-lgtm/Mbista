<?php
declare(strict_types=1);

/**
 * The voucher-type bar — Tally's F-key row, made clickable.
 *
 * The Tally key is printed on each chip because that is what people who have
 * used Tally reach for; the browser shortcut that actually works is beside it,
 * since F5 reloads a web page and no amount of wanting will change that.
 *
 * Expects: $voucherTypes (catalog), $type (current key), $editVoucher (or null).
 */
?>
<nav class="vch-typebar" aria-label="Voucher type" id="vch-typebar">
    <?php foreach ($voucherTypes as $typeKey => $typeSpec): ?>
        <?php
        $isCurrent = $typeKey === $type;
        $hotkey = (string) $typeSpec['hotkey'];
        $hotkeyLabel = str_contains($hotkey, 'shift+') ? 'Alt+Shift+' . substr($hotkey, 6) : 'Alt+' . $hotkey;
        ?>
        <a class="vch-type<?= $isCurrent ? ' is-active' : '' ?> tone-<?= e((string) $typeSpec['tone']) ?>"
           href="<?= e(url(voucher_type_url($typeKey))) ?>"
           data-hotkey="<?= e($hotkey) ?>"
           title="<?= e((string) $typeSpec['label']) ?> — <?= e((string) $typeSpec['blurb']) ?> (Tally <?= e((string) $typeSpec['tally_key']) ?>, here <?= e($hotkeyLabel) ?>)"
           <?= $isCurrent ? 'aria-current="page"' : '' ?>>
            <span class="vch-type-icon"><?= icon((string) $typeSpec['icon']) ?></span>
            <span class="vch-type-name"><?= e((string) $typeSpec['short']) ?></span>
            <span class="vch-type-key"><?= e((string) $typeSpec['tally_key']) ?></span>
        </a>
    <?php endforeach; ?>
</nav>
<?php if ($editVoucher === null): ?>
<script>
// Alt+4…Alt+9 and Alt+Shift+8/9 switch voucher type, the way the F-keys do in
// Tally. Only on a fresh voucher: silently navigating away from half-typed
// lines would lose them.
document.addEventListener('keydown', function (event) {
    if (!event.altKey || event.ctrlKey || event.metaKey) { return; }
    // event.code, not event.key: with Shift held, "8" arrives as "*".
    var match = /^Digit([4-9])$/.exec(String(event.code || ''));
    if (!match) { return; }
    var wanted = (event.shiftKey ? 'shift+' : '') + match[1];
    var target = document.querySelector('#vch-typebar [data-hotkey="' + wanted + '"]');
    if (target) {
        event.preventDefault();
        window.location.href = target.getAttribute('href');
    }
});
</script>
<?php endif; ?>
