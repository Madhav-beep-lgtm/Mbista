<?php
declare(strict_types=1);

/**
 * The row machinery every voucher screen shares.
 *
 * Each screen declares its grid once, as an empty <template> row, and hands the
 * rows it wants pre-filled in as JSON. Nothing here knows what a payment or a
 * sale is — it adds rows, removes them, renumbers them, and shouts when a
 * figure changes. The screens do the arithmetic that is theirs.
 */
?>
<script>
(function () {
    'use strict';

    function money(value) {
        return (Math.round((Number(value) || 0) * 100) / 100).toFixed(2);
    }
    window.vchMoney = money;

    function fields(row) {
        var map = {};
        row.querySelectorAll('[data-field]').forEach(function (input) {
            map[input.getAttribute('data-field')] = input;
        });
        return map;
    }

    function announce(grid) {
        grid.dispatchEvent(new CustomEvent('vch:change', { bubbles: true }));
    }

    function renumber(grid) {
        var body = grid.querySelector('[data-rows]');
        var index = 0;
        body.querySelectorAll('tr').forEach(function (row) {
            index++;
            var cell = row.querySelector('[data-sn]');
            if (cell) { cell.textContent = String(index); }
        });
        // A grid never drops below its minimum: an empty payment screen with no
        // row at all gives a person nowhere to start typing.
        var minRows = parseInt(grid.getAttribute('data-min-rows') || '1', 10);
        body.querySelectorAll('[data-remove-row]').forEach(function (button) {
            button.disabled = index <= minRows;
        });
    }

    function addRow(grid, prefill) {
        var template = grid.querySelector('[data-row-template]');
        var body = grid.querySelector('[data-rows]');
        var row = template.content.firstElementChild.cloneNode(true);
        var map = fields(row);

        if (prefill) {
            Object.keys(prefill).forEach(function (key) {
                var input = map[key];
                if (!input) { return; }
                var value = prefill[key];
                if (input.type === 'checkbox') { input.checked = !!value; }
                else if (value !== null && value !== undefined && value !== '' && !(typeof value === 'number' && value === 0)) {
                    input.value = value;
                }
            });
        }

        row.querySelectorAll('[data-remove-row]').forEach(function (button) {
            button.addEventListener('click', function () {
                var minRows = parseInt(grid.getAttribute('data-min-rows') || '1', 10);
                if (body.querySelectorAll('tr').length <= minRows) { return; }
                row.remove();
                renumber(grid);
                announce(grid);
            });
        });
        row.addEventListener('input', function () { announce(grid); });
        row.addEventListener('change', function () { announce(grid); });

        body.appendChild(row);

        // The "+ Add new…" option is appended to master dropdowns by main.js
        // once, after the page loads. A row added later would be the only one
        // on screen without it, so it is copied across from a row that has it —
        // together with the behaviour, which lives on the select, not on the
        // option: half the feature would be worse than none of it.
        row.querySelectorAll('select[name]').forEach(function (select) {
            if (select.querySelector('option[data-add-new]')) { return; }
            var source = body.querySelector('select[name="' + select.name.replace(/"/g, '') + '"] option[data-add-new]');
            if (!source) { return; }
            select.setAttribute('data-last-value', select.value);
            select.appendChild(source.cloneNode(true));
            select.addEventListener('change', function () {
                if (select.value !== '__add_new__') {
                    select.setAttribute('data-last-value', select.value);
                    return;
                }
                window.open('/' + source.getAttribute('data-add-new'), '_blank', 'noopener');
                select.value = select.getAttribute('data-last-value') || '';
            });
        });

        renumber(grid);
        return row;
    }
    window.vchAddRow = addRow;

    document.querySelectorAll('[data-grid]').forEach(function (grid) {
        var prefill = [];
        try { prefill = JSON.parse(grid.getAttribute('data-prefill') || '[]'); } catch (error) { prefill = []; }
        var minRows = parseInt(grid.getAttribute('data-min-rows') || '1', 10);

        prefill.forEach(function (row) { addRow(grid, row); });
        while (grid.querySelectorAll('[data-rows] tr').length < minRows) { addRow(grid, null); }

        var addButton = grid.querySelector('[data-add-row]');
        if (addButton) {
            addButton.addEventListener('click', function () {
                var row = addRow(grid, null);
                var first = row.querySelector('select, input');
                if (first) { first.focus(); }
                announce(grid);
            });
        }
        announce(grid);
    });
})();
</script>
