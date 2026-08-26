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
        // Fixed to the viewport, placed against the box it belongs to. An
        // absolutely positioned list is clipped by any scrolling ancestor, and
        // the old answer to that was to force overflow:visible onto every one
        // of them while the list was open -- which on a grid that scrolls
        // sideways let the whole 1700px table burst out of its card and lie
        // across the page. Taking the list out of the flow entirely means no
        // ancestor has to be touched at all.
        '.ss-list{position:fixed;box-sizing:border-box;z-index:1200;max-height:260px;overflow:auto;' +
        'background:var(--mbw-card,#fff);color:var(--mbw-ink,#12261f);border:1px solid var(--mbw-line,rgba(0,0,0,.16));' +
        'border-radius:10px;box-shadow:0 14px 34px rgba(0,0,0,.22)}' +
        '.ss-item{padding:8px 12px;cursor:pointer;font-size:13px}' +
        '.ss-group{position:sticky;top:0;padding:6px 12px;font-size:11px;font-weight:600;letter-spacing:.04em;' +
        'text-transform:uppercase;color:var(--mbw-muted,#5b6b64);background:var(--mbw-soft,#eef5f0)}' +
        '.ss-item.is-active,.ss-item:hover{background:var(--mbw-soft,#eef5f0)}' +
        '.ss-empty{padding:10px 12px;font-size:12px;color:var(--mbw-muted,#5b6b64)}';

    function injectStyle() {
        if (document.getElementById('ss-style')) { return; }
        var s = document.createElement('style');
        s.id = 'ss-style';
        s.textContent = STYLE;
        document.head.appendChild(s);
    }


    // Which selects this script has actually wired up, as objects rather than
    // as a data- attribute. The difference is the whole point: cloneNode COPIES
    // an attribute but cannot copy an event listener, so a cloned row arrives
    // carrying data-ss-ready="1" and a dead search box. Membership of this set
    // is the only honest answer to "is this one working".
    var wired = new WeakSet();

    function enhance(sel) {
        if (sel.dataset.ssReady || sel.multiple) { return; }
        // Dialogs are in the browser's top layer. A searchable list moved to
        // <body> cannot appear above it, so use the native picker there.
        if (sel.closest('dialog')) { return; }
        var auto = sel.options.length >= 12;
        if (!auto && !sel.classList.contains('js-searchable')) { return; }
        if (sel.closest('.no-search')) { return; }
        sel.dataset.ssReady = '1';
        wired.add(sel);

        var wrap = document.createElement('span');
        wrap.className = 'ss-wrap';
        // Preserve sizing chosen by the form. The native select may carry an
        // inline min-width (purchase suppliers and VAT/TDS ledgers use 160px),
        // but once it is hidden the table sizes itself from this wrapper
        // instead. Without copying those constraints, companies with 12+
        // options get a narrow searchable field while smaller local datasets
        // keep the correctly sized native select.
        if (sel.style.width) { wrap.style.width = sel.style.width; }
        if (sel.style.minWidth) { wrap.style.minWidth = sel.style.minWidth; }
        if (sel.style.maxWidth) { wrap.style.maxWidth = sel.style.maxWidth; }
        // A select the page has deliberately hidden must not reappear as a
        // visible search box. The wrapper inherits the select's own display
        // state, because from here on the wrapper IS the field as far as the
        // page is concerned — the select itself is always display:none.
        // Without this, the jewellery order form's stock picker — hidden on
        // rows that are not off-the-shelf — came back as a search box on every
        // row, but only for companies holding twelve or more pieces, which is
        // where this enhancer switches itself on.
        if (sel.style.display === 'none') { wrap.style.display = 'none'; }
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

        // Against the box, in viewport coordinates: below it when there is
        // room and above it when there is not, so a row near the foot of the
        // screen does not open a list nobody can see.
        //
        // POSITION:FIXED IS NOT ALWAYS FIXED TO THE VIEWPORT. Any ancestor
        // carrying a transform, a filter, a backdrop-filter, a perspective,
        // will-change or contain quietly becomes the containing block for
        // everything fixed inside it, and viewport coordinates handed to a
        // list inside one land offset by that ancestor's own position. What
        // that looks like from the outside is a dropdown opening halfway
        // across the page, nowhere near the field it belongs to — reported on
        // the Reports Center as a report list sitting over the filter form.
        //
        // Two things stop it, and both are needed. The list is moved to
        // <body> while it is open, which leaves no ancestor between it and
        // the viewport that could ever do this. And the placement is then
        // CHECKED rather than assumed: whatever the containing block turns
        // out to be — including one added to the page next year, or by a
        // browser extension — the difference between where the list was asked
        // to go and where it actually went corrects it in a single step.
        function placeList() {
            var box = input.getBoundingClientRect();
            var gap = 4;
            var edge = 8;
            var below = window.innerHeight - box.bottom - edge;
            var above = box.top - edge;
            var openDown = below > 160 || below >= above;
            var room = Math.max(120, Math.min(260, openDown ? below : above));

            if (list.parentNode !== document.body) { document.body.appendChild(list); }

            // Wide enough to read. Sized to the field alone, a list of report
            // names is a column of truncated words in a 190px pill; sized to
            // its content, it says what each row is.
            list.style.position = 'fixed';
            list.style.bottom = 'auto';
            list.style.maxHeight = room + 'px';
            list.style.width = 'auto';
            var width = Math.min(
                Math.max(box.width, list.scrollWidth + 2),
                window.innerWidth - (edge * 2)
            );
            list.style.width = width + 'px';

            // Clamped, so a field near the right-hand edge does not open a
            // list running off the screen.
            var wantLeft = Math.max(edge, Math.min(box.left, window.innerWidth - width - edge));
            var wantTop = openDown
                ? box.bottom + gap
                : Math.max(edge, box.top - gap - list.offsetHeight);
            list.style.left = wantLeft + 'px';
            list.style.top = wantTop + 'px';

            var got = list.getBoundingClientRect();
            var dx = wantLeft - got.left;
            var dy = wantTop - got.top;
            if (Math.abs(dx) > 0.5 || Math.abs(dy) > 0.5) {
                list.style.left = (wantLeft + dx) + 'px';
                list.style.top = (wantTop + dy) + 'px';
            }
        }
        function isOpen() { return list.style.display === 'block'; }
        function reposition(ev) {
            // A scroll INSIDE the list is the user reading it, and re-placing
            // the list under them measures its own content mid-scroll. Only
            // the world moving around the field is worth following.
            if (ev && ev.target && ev.target !== document && list.contains(ev.target)) { return; }
            if (isOpen()) { placeList(); }
        }
        // The page, or the grid the box sits in, can scroll under an open
        // list. Capture, so a scroll inside the table is heard as well.
        window.addEventListener('scroll', reposition, true);
        window.addEventListener('resize', reposition);

        function close() {
            list.style.display = 'none';
            input.setAttribute('aria-expanded', 'false');
            activeIndex = -1;
            wrap.classList.remove('ss-open');
            // Handed back to the field it belongs to. A closed list parked in
            // <body> outlives its own row — the item grids add and remove rows
            // all day — and would be left behind every time one went.
            if (list.parentNode !== wrap) { wrap.appendChild(list); }
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
            var lastGroup = null;
            Array.prototype.forEach.call(sel.options, function (opt) {
                if (q !== '' && opt.text.toLowerCase().indexOf(q) === -1) { return; }
                // The browser draws <optgroup> headings itself, but this list is
                // built from the options alone, so a grouped select would come
                // back flat. The heading is re-emitted when the group changes,
                // and only ahead of an option that survived the filter — a search
                // that removes a whole group must not leave its heading standing
                // over the next group's names. Headings are .ss-group, never
                // .ss-item, so keyboard navigation still walks options only.
                var group = opt.parentNode && opt.parentNode.tagName === 'OPTGROUP'
                    ? (opt.parentNode.label || '')
                    : null;
                if (group !== lastGroup) {
                    lastGroup = group;
                    if (group) {
                        var head = document.createElement('div');
                        head.className = 'ss-group';
                        head.textContent = group;
                        list.appendChild(head);
                    }
                }
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
            wrap.classList.add('ss-open');
            placeList();
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

    /**
     * Strip the dead widget off a CLONED select and hand the select back.
     *
     * A row added by "Add item" is a clone of the row above it, and what it
     * clones is not a plain <select> any more -- it is this script's wrapper,
     * with a text box and a dropdown list that no longer listen to anything.
     * The select inside it is display:none and flagged ready, so the box on
     * screen filters nothing, chooses nothing, and submits nothing. Every row
     * added after page load was a dead field, which reads from the counter as
     * "it will not let me add more items".
     */
    function unwrapClone(sel) {
        var wrap = sel.parentElement;
        delete sel.dataset.ssReady;
        if (!wrap || !wrap.classList || !wrap.classList.contains('ss-wrap')) {
            sel.style.display = '';
            return;
        }
        // A select the PAGE hid stays hidden; the wrapper carried that fact.
        var deliberatelyHidden = wrap.style.display === 'none';
        wrap.parentNode.insertBefore(sel, wrap);
        wrap.parentNode.removeChild(wrap);
        sel.style.display = deliberatelyHidden ? 'none' : '';
    }

    /** Enhance every select in a subtree that has just been added to the page. */
    function adopt(node) {
        if (!node || node.nodeType !== 1) { return; }
        var found = [];
        if (node.matches && node.matches('select')) { found.push(node); }
        if (node.querySelectorAll) {
            Array.prototype.push.apply(found, node.querySelectorAll('select'));
        }
        found.forEach(function (sel) {
            if (wired.has(sel)) { return; }   // one we built, being moved about
            if (sel.dataset.ssReady) { unwrapClone(sel); }
            enhance(sel);
        });
    }

    function watch() {
        if (typeof MutationObserver !== 'function' || !document.body) { return; }
        // Grids that add rows are everywhere -- purchase bills, invoice lines,
        // jewellery items -- and each used to have to remember to re-run this.
        // None of them did, and the fault only showed on shops with twelve or
        // more of whatever the dropdown lists, which is where this enhancer
        // switches itself on. Watching the document fixes all of them at once
        // and leaves nothing for the next grid to remember.
        new MutationObserver(function (records) {
            records.forEach(function (record) {
                Array.prototype.forEach.call(record.addedNodes, adopt);
            });
        }).observe(document.body, { childList: true, subtree: true });
    }

    function boot() {
        injectStyle();
        Array.prototype.forEach.call(document.querySelectorAll('select'), enhance);
        watch();
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
