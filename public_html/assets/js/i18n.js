/**
 * Chrome translator: swaps known English UI strings (nav links, buttons,
 * table headers, headings, labels) for the active language using the
 * dictionary served by /i18n-dict.php. Exact-match on trimmed text nodes,
 * so data values, names and numbers are never touched; anything not in the
 * dictionary stays English.
 */
(function () {
    'use strict';
    var dict = window.MBW_I18N || {};
    if (!window.MBW_LANG || window.MBW_LANG === 'en' || Object.keys(dict).length === 0) { return; }

    var SCOPE = 'a, button, th, summary, h1, h2, h3, label, .mbw-pill, .mbw-view-all, option';

    function translateElement(el) {
        for (var i = 0; i < el.childNodes.length; i++) {
            var node = el.childNodes[i];
            if (node.nodeType !== 3) { continue; }
            var raw = node.nodeValue;
            var trimmed = raw.trim();
            if (trimmed === '' || !Object.prototype.hasOwnProperty.call(dict, trimmed)) { continue; }
            node.nodeValue = raw.replace(trimmed, dict[trimmed]);
        }
    }

    function run(root) {
        var els = (root || document).querySelectorAll(SCOPE);
        for (var i = 0; i < els.length; i++) { translateElement(els[i]); }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { run(document); });
    } else {
        run(document);
    }
})();
