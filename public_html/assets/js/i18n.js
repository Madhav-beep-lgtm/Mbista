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

    var SCOPE = 'a, button, th, td, summary, h1, h2, h3, h4, label, legend, span, strong, b, small, p, option, .mbw-pill, .mbw-view-all';

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

    function translateAttributes(root) {
        var withPlaceholder = (root || document).querySelectorAll('input[placeholder], textarea[placeholder]');
        for (var i = 0; i < withPlaceholder.length; i++) {
            var ph = withPlaceholder[i].getAttribute('placeholder').trim();
            if (Object.prototype.hasOwnProperty.call(dict, ph)) { withPlaceholder[i].setAttribute('placeholder', dict[ph]); }
        }
        var submits = (root || document).querySelectorAll('input[type="submit"], input[type="button"]');
        for (var j = 0; j < submits.length; j++) {
            var val = (submits[j].value || '').trim();
            if (Object.prototype.hasOwnProperty.call(dict, val)) { submits[j].value = dict[val]; }
        }
        var titled = (root || document).querySelectorAll('[title]');
        for (var k = 0; k < titled.length; k++) {
            var tt = titled[k].getAttribute('title').trim();
            if (Object.prototype.hasOwnProperty.call(dict, tt)) { titled[k].setAttribute('title', dict[tt]); }
        }
    }

    function run(root) {
        var els = (root || document).querySelectorAll(SCOPE);
        for (var i = 0; i < els.length; i++) { translateElement(els[i]); }
        translateAttributes(root);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { run(document); });
    } else {
        run(document);
    }
})();
