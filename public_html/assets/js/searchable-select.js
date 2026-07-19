/**
 * Searchable <select> enhancer (progressive, dependency-free).
 *
 * Any select marked .js-searchable — or any single select with 12+ options —
 * becomes a combobox: a text box that filters the option list as you type,
 * so a long ledger/party/employee dropdown narrows to what you searched.
 * The original <select> stays in the DOM (hidden) and keeps carrying the
 * submitted value, so no form handler changes are needed; programmatic
 * changes to the select re-sync the visible box.
 */
(function () {
    'use strict';
    var STYLE = '.ss-wrap{position:relative;display:block;min-width:0}' +
        '.ss-input{width:100%;min-height:38px;padding:8px 12px;border:1px solid var(--mbw-line,rgba(0,0,0,.2));border-radius:8px;' +
        'background:var(--mbw-card,#fff);color:var(--mbw-ink,#12261f);font:inherit}' +
        '.ss-input:focus{outline:2px solid var(--mbw-accent,#2f7fb8);outline-offset:1px}' +
        '.ss-list{position:absolute;left:0;right:0;top:100%;z-index:80;max-height:260px;overflow:auto;margin-top:4px;' +
        'background:var(--mbw-card,#fff);color:var(--mbw-ink,#12261f);border:1px solid var(--mbw-line,rgba(0,0,0,.16));' +
        'border-radius:10px;box-shadow:0 14px 34px rgba(0,0,0,.22)}' +
        '.ss-item{padding:8px 12px;cursor:pointer;font-size:13px}' +
        '.ss-item.is-active,.ss-item:hover{background:var(--mbw-soft,#eef5f0)}' +
        '.ss-empty{padding:10px 12px;font-size:12px;color:var(--mbw-muted,#5b6b64)}';

    function injectStyle() {
        if (document.getElementById('ss-style')) { return; }
        var s = document.createElement('style');
        s.id = 'ss-style';
        s.textContent = STYLE;
        document.head.appendChild(s);
    }

    function enhance(sel) {
        if (sel.dataset.ssReady || sel.multiple) { return; }
        var auto = sel.options.length >= 12;
        if (!auto && !sel.classList.contains('js-searchable')) { return; }
        if (sel.closest('.no-search')) { return; }
        sel.dataset.ssReady = '1';

        var wrap = document.createElement('span');
        wrap.className = 'ss-wrap';
        sel.parentNode.insertBefore(wrap, sel);
        wrap.appendChild(sel);
        sel.style.display = 'none';

        var input = document.createElement('input');
        input.type = 'text';
        input.className = 'ss-input';
        input.autocomplete = 'off';
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-expanded', 'false');
        input.placeholder = 'Type to search…';
        wrap.appendChild(input);

        var list = document.createElement('div');
        list.className = 'ss-list';
        list.style.display = 'none';
        wrap.appendChild(list);

        var activeIndex = -1;
        var visible = [];

        function selectedText() {
            var o = sel.options[sel.selectedIndex];
            return o ? o.text : '';
        }
        function syncFromSelect() { input.value = selectedText(); }
        syncFromSelect();
        sel.addEventListener('change', syncFromSelect);

        function close() {
            list.style.display = 'none';
            input.setAttribute('aria-expanded', 'false');
            activeIndex = -1;
        }
        function choose(idx) {
            var opt = visible[idx];
            if (!opt) { return; }
            sel.value = opt.value;
            sel.dispatchEvent(new Event('change', { bubbles: true }));
            syncFromSelect();
            close();
        }
        function render(filter) {
            var q = (filter || '').toLowerCase();
            list.innerHTML = '';
            visible = [];
            Array.prototype.forEach.call(sel.options, function (opt) {
                if (q !== '' && opt.text.toLowerCase().indexOf(q) === -1) { return; }
                visible.push(opt);
                var item = document.createElement('div');
                item.className = 'ss-item' + (opt.value === sel.value ? ' is-active' : '');
                item.textContent = opt.text;
                item.addEventListener('mousedown', function (ev) {
                    ev.preventDefault(); // keep input focus until choose runs
                    choose(visible.indexOf(opt));
                });
                list.appendChild(item);
            });
            if (visible.length === 0) {
                var empty = document.createElement('div');
                empty.className = 'ss-empty';
                empty.textContent = 'No match — clear the search.';
                list.appendChild(empty);
            }
            list.style.display = 'block';
            input.setAttribute('aria-expanded', 'true');
            activeIndex = -1;
        }

        input.addEventListener('focus', function () {
            input.select();
            render('');
        });
        input.addEventListener('input', function () { render(input.value); });
        input.addEventListener('keydown', function (ev) {
            var items = list.querySelectorAll('.ss-item');
            if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
                ev.preventDefault();
                if (list.style.display === 'none') { render(input.value); return; }
                activeIndex = ev.key === 'ArrowDown'
                    ? Math.min(activeIndex + 1, items.length - 1)
                    : Math.max(activeIndex - 1, 0);
                Array.prototype.forEach.call(items, function (el, i) {
                    el.classList.toggle('is-active', i === activeIndex);
                    if (i === activeIndex) { el.scrollIntoView({ block: 'nearest' }); }
                });
            } else if (ev.key === 'Enter') {
                if (list.style.display !== 'none') {
                    ev.preventDefault();
                    choose(activeIndex >= 0 ? activeIndex : 0);
                }
            } else if (ev.key === 'Escape') {
                syncFromSelect();
                close();
            }
        });
        input.addEventListener('blur', function () {
            // A mousedown on the list prevented default, so focus stays;
            // an ordinary blur restores the selected option's label.
            window.setTimeout(function () { syncFromSelect(); close(); }, 120);
        });
    }

    function boot() {
        injectStyle();
        Array.prototype.forEach.call(document.querySelectorAll('select'), enhance);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
