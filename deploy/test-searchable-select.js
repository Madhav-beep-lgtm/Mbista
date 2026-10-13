const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public_html/assets/js/searchable-select.js', 'utf8');
const start = source.indexOf('        function isOpen()');
const end = source.indexOf('        function choose(', start);
assert.ok(start > 0 && end > start);
const listeners = new Map();
const frames = new Map();
let nextFrame = 0, placements = 0;
const wrap = {classList: {remove() {}}, appendChild(node) {node.parentNode = this;}};
const list = {style: {display: 'block'}, contains: () => false, parentNode: wrap};
const sel = {isConnected: true};
const openWidgets = new Map([[sel, () => {}]]);
const context = vm.createContext({
  document: {}, list, sel, wrap, openWidgets,
  input: {setAttribute() {}}, activeIndex: 0,
  placeList() {placements++;},
  window: {
    removeEventListener(type) {listeners.delete(type);},
    requestAnimationFrame(fn) {frames.set(++nextFrame, fn); return nextFrame;},
    cancelAnimationFrame(id) {frames.delete(id);},
  },
});
vm.runInContext(source.slice(start, end), context);
for (let i = 0; i < 100; i++) context.reposition();
assert.equal(frames.size, 1, 'Burst of scroll events schedules only one layout');
let callback = frames.values().next().value; frames.clear(); callback();
assert.equal(placements, 1);
context.reposition();
listeners.set('scroll', true); listeners.set('resize', true);
context.close();
assert.equal(frames.size, 0, 'Close cancels queued positioning');
assert.equal(listeners.size, 0, 'Close removes global listeners');
assert.equal(openWidgets.size, 0, 'Closed field is no longer retained');
list.style.display = 'block'; sel.isConnected = false;
context.reposition(); callback = frames.values().next().value; frames.clear(); callback();
assert.equal(list.style.display, 'none', 'Detached field closes its floating list');
assert.equal(placements, 1, 'Detached fields do not trigger layout');
let changes = 0;
context.visible = [{value: 'blocked', disabled: true, parentNode: {}}];
context.sel.disabled = false;
context.sel.dispatchEvent = () => {changes++;};
context.syncFromSelect = () => {};
context.Event = function () {};
vm.runInContext(source.slice(end, source.indexOf('        function render(', end)), context);
context.choose(0);
assert.equal(changes, 0, 'Disabled options cannot change the selection');
context.visible[0].disabled = false;
context.visible[0].parentNode.disabled = true;
context.choose(0);
assert.equal(changes, 0, 'Disabled groups cannot change the selection');
context.visible[0].parentNode.disabled = false;
context.sel.disabled = true;
context.choose(0);
assert.equal(changes, 0, 'Disabled selects cannot change the selection');
context.sel.disabled = false;
context.choose(0);
assert.equal(changes, 1, 'Enabled choices still dispatch the native change event');
assert.match(source, /if \(sel\.disabled \|\| !opt \|\| opt\.disabled/);
assert.match(source, /input\.disabled = sel\.disabled/);
assert.match(source, /attributeFilter: \['disabled', 'required'\]/);
console.log('Searchable selects: frame batching, listener cleanup, removed fields and disabled-state guards passed.');
