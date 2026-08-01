<?php
declare(strict_types=1);

/**
 * The grid's own behaviour, per row.
 *
 * Every row is independent: one may be a ring off order JO-3, the next a chain
 * off JO-9, and picking an order on one must not disturb the other. So the
 * handlers are bound to the GRID and find their row from the event, rather than
 * to elements that only exist when the page loads — rows are added after it.
 *
 * The row machinery itself (clone, renumber, remove, minimum rows) is the
 * shared one every grid in this app uses.
 */
?>
<?php include __DIR__ . '/../vouchers/grid_script.php'; ?>
<script>
(function () {
    'use strict';
    var form = document.getElementById('jw-assign-form');
    if (!form) { return; }

    var isCustomer = <?= $isCustomer ? 'true' : 'false' ?>;
    var orders = <?= json_encode($orderPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    var byId = {};
    orders.forEach(function (order) { byId[String(order.id)] = order; });

    function w(value) { return (Math.round((Number(value) || 0) * 10000) / 10000).toFixed(4); }
    function cell(row, selector) { return row ? row.querySelector(selector) : null; }

    /** Net is never typed on either flow: it is always the other two. */
    function recalcNet(row) {
        var gross = cell(row, '.jw-gross');
        var stone = cell(row, '.jw-stone');
        var net = cell(row, '.jw-net');
        if (!gross || !net) { return; }
        var g = Number(gross.value) || 0;
        var s = stone ? (Number(stone.value) || 0) : 0;
        net.value = g > 0 ? w(g - s) : '';
    }

    /** An order picked on THIS row fills THIS row and no other. */
    function applyOrder(row) {
        var orderSelect = cell(row, '.jw-order');
        var lineSelect = cell(row, '.jw-line');
        if (!orderSelect || !lineSelect) { return; }
        var order = byId[orderSelect.value];

        lineSelect.innerHTML = '';
        var customer = cell(row, '.jw-customer');
        var assigned = cell(row, '.jw-assigned');
        var delivery = cell(row, '.jw-delivery');

        if (!order) {
            lineSelect.appendChild(new Option('Pick the order first', ''));
            if (customer) { customer.value = ''; }
            if (assigned) { assigned.removeAttribute('min'); }
            if (delivery) { delivery.removeAttribute('max'); }
            applyLine(row);
            return;
        }
        if (customer) { customer.value = order.customer_name || ''; }
        // Fenced by the order's own dates: not before it was taken, not after
        // the customer was promised.
        if (assigned && order.order_date) { assigned.setAttribute('min', order.order_date); }
        if (delivery && order.delivery_date) { delivery.setAttribute('max', order.delivery_date); }

        lineSelect.appendChild(new Option('Select the item being made', ''));
        order.lines.forEach(function (line) {
            var option = new Option(
                line.item_name + ' (' + line.item_code + ') — ' + Number(line.gross_weight).toFixed(3) + ' ' + line.unit_code,
                line.id
            );
            option.dataset.line = JSON.stringify(line);
            lineSelect.appendChild(option);
        });
        if (order.lines.length === 1) { lineSelect.selectedIndex = 1; }
        applyLine(row);
    }

    /** The ornament chosen fills the weights, purity and size it already has. */
    function applyLine(row) {
        var lineSelect = cell(row, '.jw-line');
        if (!lineSelect) { return; }
        var option = lineSelect.options[lineSelect.selectedIndex];
        var line = option && option.dataset.line ? JSON.parse(option.dataset.line) : null;
        var gross = cell(row, '.jw-gross');
        var stone = cell(row, '.jw-stone');
        var size = cell(row, '.jw-size');
        var purity = cell(row, '.jw-purity');
        var purityLabel = cell(row, '.jw-purity-label');
        var delivery = cell(row, '.jw-delivery');

        if (!line) {
            [gross, stone, size, purity, purityLabel].forEach(function (f) { if (f) { f.value = ''; } });
            recalcNet(row);
            countFilled();
            return;
        }
        if (gross) { gross.value = w(line.gross_weight); }
        if (stone) { stone.value = w(line.stone_weight); }
        if (size) { size.value = line.size || ''; }
        if (purity) { purity.value = line.purity_id; }
        if (purityLabel) { purityLabel.value = line.purity_code || ''; }
        if (delivery && line.delivery_date && !delivery.value) { delivery.value = line.delivery_date; }
        recalcNet(row);
        countFilled();
    }

    /** The showroom flow names its own piece, so the name follows the item. */
    function applyItem(row) {
        var item = cell(row, '.jw-item');
        var ornament = cell(row, '.jw-ornament');
        if (!item || !ornament) { return; }
        var option = item.options[item.selectedIndex];
        ornament.value = (option && option.getAttribute('data-name')) || '';
    }

    /** How many rows are actually filled in, so the button means something. */
    function countFilled() {
        var filled = 0;
        form.querySelectorAll('[data-rows] tr').forEach(function (row) {
            var karigar = cell(row, 'select[name="karigar_id[]"]');
            var what = cell(row, '.jw-line') || cell(row, '.jw-item');
            if (karigar && karigar.value && what && what.value) { filled++; }
        });
        var pill = document.getElementById('jw-assign-count');
        if (!pill) { return; }
        pill.textContent = filled === 0 ? 'No rows filled'
            : (filled === 1 ? '1 row ready to save' : filled + ' rows ready to save');
        pill.className = 'mbw-pill ' + (filled === 0 ? 'tone-gray' : 'tone-green');
    }

    form.addEventListener('change', function (event) {
        var row = event.target.closest ? event.target.closest('tr') : null;
        if (!row) { return; }
        if (event.target.classList.contains('jw-order')) { applyOrder(row); }
        else if (event.target.classList.contains('jw-line')) { applyLine(row); }
        else if (event.target.classList.contains('jw-item')) { applyItem(row); }
        countFilled();
    });
    form.addEventListener('input', function (event) {
        var row = event.target.closest ? event.target.closest('tr') : null;
        if (row) { recalcNet(row); }
    });
    /**
     * Give every row that has none an ornament list.
     *
     * Two cases need it and neither is a change event. A row added after load
     * starts with an empty select; and row one may open with an order ALREADY
     * picked, because somebody pressed Assign on that order — its list has to
     * be built without anybody touching it.
     */
    function fillEmptyLists() {
        if (!isCustomer) { return; }
        form.querySelectorAll('[data-rows] tr').forEach(function (row) {
            var lineSelect = cell(row, '.jw-line');
            if (lineSelect && lineSelect.options.length === 0) { applyOrder(row); }
        });
    }

    form.addEventListener('vch:change', function () {
        fillEmptyLists();
        countFilled();
    });
    // The grid builds its rows as the page parses, which is before this file
    // runs — so the first pass is made here rather than waited for.
    fillEmptyLists();
    countFilled();
})();
</script>
