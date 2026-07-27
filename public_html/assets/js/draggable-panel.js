/**
 * Draggable form panels, app-wide.
 *
 * A long entry form is often taller than the screen, and the thing you need to
 * read while filling it in — the list behind it, the balance in the corner — is
 * exactly what the form is covering. Being able to shove the panel aside with
 * the mouse is the cheapest fix for that, and it costs nothing when unused.
 *
 * Opt in by putting `data-draggable` on any element. It gets a grab handle
 * across its header (or the whole element when it has no obvious header), and
 * remembers where it was left, per element, per browser.
 *
 * Deliberately plain: no library, no dependency on the modal markup, and it
 * degrades to an ordinary static panel if JavaScript never runs.
 */
(function () {
    'use strict';

    var STORAGE_PREFIX = 'mbw.panelpos.';
    var dragging = null;

    /** A stable key for a panel, so its position survives a reload. */
    function panelKey(el) {
        var id = el.getAttribute('data-draggable-key') || el.id || '';
        if (!id) {
            // Fall back to the heading text — stable enough for one screen, and
            // a panel with neither an id nor a heading simply is not remembered.
            var heading = el.querySelector('h1, h2, h3, legend, summary');
            id = heading ? heading.textContent.trim().slice(0, 60) : '';
        }
        return id ? STORAGE_PREFIX + location.pathname + '#' + id : '';
    }

    function readPos(key) {
        if (!key) { return null; }
        try {
            var raw = window.localStorage.getItem(key);
            return raw ? JSON.parse(raw) : null;
        } catch (e) { return null; }
    }

    function writePos(key, pos) {
        if (!key) { return; }
        try { window.localStorage.setItem(key, JSON.stringify(pos)); } catch (e) { /* private mode */ }
    }

    function clearPos(key) {
        if (!key) { return; }
        try { window.localStorage.removeItem(key); } catch (e) { /* ignore */ }
    }

    function applyPos(el, pos) {
        if (!pos) { return; }
        el.style.position = 'relative';
        el.style.left = pos.x + 'px';
        el.style.top = pos.y + 'px';
        el.classList.add('is-moved');
    }

    /** The strip you grab: the panel's own header if it has one. */
    function handleFor(el) {
        return el.querySelector('.mbw-card-head, legend, summary, h2, h3') || el;
    }

    function setup(el) {
        if (el.__mbwDraggable) { return; }
        el.__mbwDraggable = true;

        var handle = handleFor(el);
        handle.classList.add('mbw-drag-handle');
        handle.setAttribute('title', 'Drag to move — double-click to put it back');

        var key = panelKey(el);
        applyPos(el, readPos(key));

        handle.addEventListener('mousedown', function (event) {
            // Never hijack a click on something interactive inside the header.
            if (event.button !== 0 || event.target.closest('a, button, input, select, textarea, label')) {
                return;
            }
            var startPos = readPos(key) || { x: 0, y: 0 };
            dragging = {
                el: el,
                key: key,
                startX: event.clientX,
                startY: event.clientY,
                originX: startPos.x,
                originY: startPos.y
            };
            el.classList.add('is-dragging');
            document.body.classList.add('mbw-dragging');
            event.preventDefault();
        });

        // Double-click the handle to snap it home — a panel dragged off-screen
        // must always be recoverable without clearing site data.
        handle.addEventListener('dblclick', function (event) {
            if (event.target.closest('a, button, input, select, textarea, label')) { return; }
            el.style.left = '';
            el.style.top = '';
            el.classList.remove('is-moved');
            clearPos(key);
        });
    }

    document.addEventListener('mousemove', function (event) {
        if (!dragging) { return; }
        var pos = {
            x: dragging.originX + (event.clientX - dragging.startX),
            y: dragging.originY + (event.clientY - dragging.startY)
        };
        dragging.el.style.position = 'relative';
        dragging.el.style.left = pos.x + 'px';
        dragging.el.style.top = pos.y + 'px';
        dragging.el.classList.add('is-moved');
        dragging.__pos = pos;
    });

    document.addEventListener('mouseup', function () {
        if (!dragging) { return; }
        if (dragging.__pos) { writePos(dragging.key, dragging.__pos); }
        dragging.el.classList.remove('is-dragging');
        document.body.classList.remove('mbw-dragging');
        dragging = null;
    });

    function scan(root) {
        (root || document).querySelectorAll('[data-draggable]').forEach(setup);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { scan(document); });
    } else {
        scan(document);
    }

    // Panels added after load (a preview table, an edit form swapped in) get
    // picked up without every caller having to remember to re-initialise.
    if (window.MutationObserver) {
        new MutationObserver(function (records) {
            records.forEach(function (record) {
                Array.prototype.forEach.call(record.addedNodes, function (node) {
                    if (node.nodeType !== 1) { return; }
                    if (node.hasAttribute && node.hasAttribute('data-draggable')) { setup(node); }
                    if (node.querySelectorAll) { scan(node); }
                });
            });
        }).observe(document.body, { childList: true, subtree: true });
    }

    window.MBWDraggable = { scan: scan };
}());
