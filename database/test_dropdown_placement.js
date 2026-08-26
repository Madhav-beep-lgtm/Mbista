/**
 * Where a searchable dropdown's list actually lands.
 *
 * The list is position:fixed and placed from the field's viewport rect. That
 * is right only while position:fixed really does resolve against the viewport,
 * and it does not: any ancestor carrying a transform, a filter, a
 * backdrop-filter, a perspective, will-change or contain becomes the
 * containing block for everything fixed inside it, and the list then opens
 * offset by that ancestor's own position — halfway across the page, nowhere
 * near the field, which is what it was reported as on the Reports Center.
 *
 * Static source checks cannot see that; it is arithmetic, so it is run.
 * The DOM below is a stub with one honest part: an element's rect is derived
 * from the styles set on it PLUS the offset of whatever containing block it
 * is in. Set that offset to zero and the page is well behaved; set it to
 * something and the page has a transformed ancestor.
 *
 *   node database/test_dropdown_placement.js
 */
'use strict';

var pass = 0;
var fail = 0;
function ok(cond, label) {
    if (cond) { pass++; console.log('  PASS  ' + label); }
    else { fail++; console.log('  FAIL  ' + label); }
}
function near(a, b, tol) { return Math.abs(a - b) <= (tol === undefined ? 0.51 : tol); }

// ---------------------------------------------------------------------------
// A DOM, only as far as this script actually reaches into one.
// ---------------------------------------------------------------------------
function makeDom(options) {
    var cbOffset = options.containingBlockOffset || { x: 0, y: 0 };
    var fieldRect = options.fieldRect;
    var itemWidth = options.itemWidth || 0;
    var listeners = { window: {} };

    function Style() { this.display = ''; }

    function El(tag) {
        this.tagName = tag.toUpperCase();
        this.style = new Style();
        this.children = [];
        this.parentNode = null;
        this.className = '';
        this.dataset = {};
        this.attributes = {};
        this.handlers = {};
        this.textContent = '';
        var self = this;
        this.classList = {
            contains: function (c) { return (' ' + self.className + ' ').indexOf(' ' + c + ' ') !== -1; },
            add: function (c) { if (!this.contains(c)) { self.className = (self.className + ' ' + c).trim(); } },
            remove: function (c) { self.className = (' ' + self.className + ' ').split(' ' + c + ' ').join(' ').trim(); },
            toggle: function (c, on) { if (on) { this.add(c); } else { this.remove(c); } }
        };
    }
    El.prototype.appendChild = function (child) {
        if (child.parentNode) { child.parentNode.removeChild(child); }
        child.parentNode = this;
        this.children.push(child);
        return child;
    };
    El.prototype.removeChild = function (child) {
        var i = this.children.indexOf(child);
        if (i >= 0) { this.children.splice(i, 1); }
        child.parentNode = null;
        return child;
    };
    El.prototype.insertBefore = function (node, ref) {
        if (node.parentNode) { node.parentNode.removeChild(node); }
        var i = this.children.indexOf(ref);
        this.children.splice(i < 0 ? this.children.length : i, 0, node);
        node.parentNode = this;
        return node;
    };
    El.prototype.setAttribute = function (k, v) { this.attributes[k] = v; };
    El.prototype.getAttribute = function (k) { return this.attributes[k]; };
    El.prototype.addEventListener = function (type, fn) {
        (this.handlers[type] = this.handlers[type] || []).push(fn);
    };
    El.prototype.dispatchEvent = function (ev) {
        (this.handlers[ev.type] || []).forEach(function (fn) { fn(ev); });
    };
    El.prototype.fire = function (type, ev) { this.dispatchEvent(Object.assign({ type: type }, ev || {})); };
    El.prototype.closest = function (sel) {
        var node = this;
        while (node) {
            if (sel === 'dialog' && node.tagName === 'DIALOG') { return node; }
            node = node.parentNode;
        }
        return null;
    };
    El.prototype.select = function () {};        // the text box's own selectAll
    El.prototype.scrollIntoView = function () {};
    El.prototype.matches = function (sel) { return sel === this.tagName.toLowerCase(); };
    El.prototype.contains = function (node) {
        if (node === this) { return true; }
        return this.children.some(function (c) { return c.contains && c.contains(node); });
    };
    El.prototype.querySelectorAll = function (sel) {
        var out = [];
        (function walk(el) {
            el.children.forEach(function (c) {
                if (sel === 'select' && c.tagName === 'SELECT') { out.push(c); }
                if (sel.charAt(0) === '.' && c.classList.contains(sel.slice(1))) { out.push(c); }
                walk(c);
            });
        })(this);
        return out;
    };
    Object.defineProperty(El.prototype, 'innerHTML', {
        get: function () { return ''; },
        set: function (v) { if (v === '') { this.children.forEach(function (c) { c.parentNode = null; }); this.children = []; } }
    });

    // THE ONE HONEST PART. A fixed element's rect is its own left/top plus the
    // origin of its containing block — the viewport (0,0) when nothing above it
    // creates one, and the offending ancestor's corner when something does.
    // <body> is never inside it, which is exactly why moving the list there is
    // half the fix.
    El.prototype.getBoundingClientRect = function () {
        if (this === input) {
            return {
                left: fieldRect.left, top: fieldRect.top,
                right: fieldRect.left + fieldRect.width, bottom: fieldRect.top + fieldRect.height,
                width: fieldRect.width, height: fieldRect.height
            };
        }
        var inBody = this.parentNode === body;
        var ox = inBody ? 0 : cbOffset.x;
        var oy = inBody ? 0 : cbOffset.y;
        if (dialog && this.parentNode === dialog) {
            // A dialog is a containing block for fixed children, exactly like
            // a transformed ancestor. Hosting the list there is only safe
            // because the placement is measured and corrected afterwards.
            ox = cbOffset.x;
            oy = cbOffset.y;
        }
        var left = parseFloat(this.style.left || '0') + ox;
        var top = parseFloat(this.style.top || '0') + oy;
        var w = this.style.width === 'auto' || !this.style.width ? this.scrollWidth : parseFloat(this.style.width);
        var h = this.offsetHeight;
        return { left: left, top: top, right: left + w, bottom: top + h, width: w, height: h };
    };
    Object.defineProperty(El.prototype, 'scrollWidth', {
        get: function () { return this.classList.contains('ss-list') ? itemWidth : 0; }
    });
    Object.defineProperty(El.prototype, 'offsetHeight', {
        get: function () {
            if (!this.classList.contains('ss-list')) { return 0; }
            var natural = this.children.length * 33;
            var cap = parseFloat(this.style.maxHeight || '260');
            return Math.min(natural, cap);
        }
    });

    function Option(text, value) {
        El.call(this, 'option');
        this.text = text;
        this.value = value;
    }
    Option.prototype = Object.create(El.prototype);

    var body = new El('body');
    var head = new El('head');
    var dialog = null;
    var host = new El('label');          // the field's own box
    if (options.inDialog) {
        // A modal dialog paints in the browser's top layer, above everything
        // in <body> whatever its z-index. Anything hosted in <body> while a
        // dialog is open is therefore invisible, which is the whole reason
        // the list has to move into the dialog instead.
        dialog = new El('dialog');
        body.appendChild(dialog);
        dialog.appendChild(host);
    } else {
        body.appendChild(host);
    }

    var sel = new El('select');
    sel.multiple = false;
    sel.options = [];
    for (var i = 0; i < (options.optionCount || 37); i++) {
        var o = new Option(options.optionText ? options.optionText(i) : ('Report ' + i), 'r' + i);
        o.parentNode = sel;
        sel.options.push(o);
    }
    sel.options.length = sel.options.length; // array already
    sel.selectedIndex = 0;
    sel.value = 'r0';
    host.appendChild(sel);

    var input = null;   // filled in after the script wires the field up

    var document = {
        head: head,
        body: body,
        readyState: 'complete',
        getElementById: function (id) { return head.children.filter(function (c) { return c.id === id; })[0] || null; },
        createElement: function (tag) { return new El(tag); },
        querySelectorAll: function (sel2) { return body.querySelectorAll(sel2); },
        addEventListener: function () {}
    };
    var windowObj = {
        innerWidth: options.viewport.width,
        innerHeight: options.viewport.height,
        addEventListener: function (type, fn) { (listeners.window[type] = listeners.window[type] || []).push(fn); },
        // Run straight away: the blur handler defers its close by 120ms and
        // the test wants to see what the close leaves behind.
        setTimeout: function (fn) { fn(); return 0; }
    };

    return {
        document: document, window: windowObj, body: body, host: host, select: sel, dialog: dialog,
        listeners: listeners,
        setInput: function (el) { input = el; },
        getInput: function () { return input; }
    };
}

// ---------------------------------------------------------------------------
// Run the real file against it.
// ---------------------------------------------------------------------------
var fs = require('fs');
var path = require('path');
var vm = require('vm');
var source = fs.readFileSync(path.join(__dirname, '..', 'public_html', 'assets', 'js', 'searchable-select.js'), 'utf8');

function run(options) {
    var dom = makeDom(options);
    var sandbox = {
        document: dom.document,
        window: dom.window,
        MutationObserver: function () { this.observe = function () {}; },
        Event: function (type) { this.type = type; },
        WeakSet: WeakSet,
        Math: Math,
        Array: Array,
        Object: Object,
        console: console,
        setTimeout: function (fn) { fn(); return 0; }
    };
    sandbox.window.document = dom.document;
    vm.createContext(sandbox);
    vm.runInContext(source, sandbox);

    var wrap = dom.host.children.filter(function (c) { return c.classList.contains('ss-wrap'); })[0];
    if (!wrap) { return { dom: dom, wrap: null }; }
    var input = wrap.children.filter(function (c) { return c.classList.contains('ss-input'); })[0];
    var list = wrap.children.filter(function (c) { return c.classList.contains('ss-list'); })[0];
    dom.setInput(input);
    input.fire('focus');
    return { dom: dom, wrap: wrap, input: input, list: list };
}

var viewport = { width: 1440, height: 900 };
// The Reports Center pill: a narrow green box at the top left of the toolbar.
var pill = { left: 110, top: 128, width: 190, height: 34 };

console.log('\nA. A page with nothing above the field');
var clean = run({ viewport: viewport, fieldRect: pill, containingBlockOffset: { x: 0, y: 0 }, itemWidth: 240 });
ok(clean.wrap !== null, 'A 37-option select is upgraded to a searchable field');
var r = clean.list.getBoundingClientRect();
ok(near(r.left, pill.left), 'The list opens at the field\'s left edge (' + r.left + ' vs ' + pill.left + ')');
ok(near(r.top, pill.top + pill.height + 4), 'And directly under it (' + r.top + ' vs ' + (pill.top + pill.height + 4) + ')');
ok(r.width >= 240, 'Wide enough for the longest report name, not squeezed to the pill (' + r.width + ')');

console.log('\nB. A page with a transformed ancestor over the field');
// This is the reported fault: the list opened well right of and below the
// pill, over the filter form. An ancestor containing block is the only thing
// that does that to a fixed element placed from viewport coordinates.
var hostile = run({ viewport: viewport, fieldRect: pill, containingBlockOffset: { x: 267, y: 82 }, itemWidth: 240 });
var h = hostile.list.getBoundingClientRect();
ok(near(h.left, pill.left), 'The list STILL opens at the field\'s left edge (' + h.left + ' vs ' + pill.left + ')');
ok(near(h.top, pill.top + pill.height + 4), 'And still directly under it (' + h.top + ' vs ' + (pill.top + pill.height + 4) + ')');
ok(hostile.list.parentNode === hostile.dom.body,
    'Because an open list is moved to <body>, where no ancestor can claim it');

console.log('\nC. A field low on the screen opens upwards');
var low = { left: 110, top: 800, width: 190, height: 34 };
var up = run({ viewport: viewport, fieldRect: low, containingBlockOffset: { x: 267, y: 82 }, itemWidth: 240 });
var u = up.list.getBoundingClientRect();
ok(u.bottom <= low.top, 'The list sits above the field, not off the foot of the screen');
ok(u.top >= 8, 'And not off the top of it either');

console.log('\nD. A field at the right-hand edge stays on screen');
var edge = { left: 1330, top: 128, width: 100, height: 34 };
var clamped = run({ viewport: viewport, fieldRect: edge, containingBlockOffset: { x: 0, y: 0 }, itemWidth: 300 });
var c = clamped.list.getBoundingClientRect();
ok(c.right <= viewport.width - 8 + 0.51, 'Its right edge stays inside the viewport (' + c.right + ')');
ok(c.left >= 8, 'And its left edge does too (' + c.left + ')');

console.log('\nF. A field inside a modal dialog still searches');
// This is where it went wrong once already. Moving the open list to <body> is
// what stops an ordinary page clipping or displacing it -- but a dialog paints
// in the top layer, so a list in <body> is BEHIND it however high its z-index.
// The answer at the time was to switch the search box off inside dialogs
// entirely, which is how the purchase bill's item picker became a plain select
// with no way to search a few hundred items.
var dlg = run({ viewport: viewport, fieldRect: pill, containingBlockOffset: { x: 267, y: 82 },
    itemWidth: 240, inDialog: true });
ok(dlg.wrap !== null, 'A long select inside a dialog is still upgraded to a searchable field');
ok(dlg.list.parentNode === dlg.dom.dialog,
    '  ...and its list is hosted in the DIALOG, which shares the top layer with it');
ok(dlg.list.parentNode !== dlg.dom.body,
    '  ...not in <body>, where the dialog would paint straight over it');
var d = dlg.list.getBoundingClientRect();
ok(near(d.left, pill.left), "It still opens at the field's left edge (" + d.left + ' vs ' + pill.left + ')');
ok(near(d.top, pill.top + pill.height + 4),
    '  ...and directly under it, the dialog being a containing block notwithstanding');

console.log('\nE. A closed list is handed back to its field');
// A list parked in <body> outlives the row it belongs to, and the item grids
// add and remove rows all day.
clean.input.fire('blur');
var closedInBody = clean.dom.body.children.filter(function (el) { return el.classList.contains('ss-list'); });
ok(clean.list.parentNode === clean.wrap || closedInBody.length === 0,
    'Nothing is left behind in <body> once the list closes');

console.log('\n' + '='.repeat(50));
console.log('  PASS: ' + pass + '    FAIL: ' + fail);
console.log('='.repeat(50));
process.exit(fail > 0 ? 1 : 0);
