const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../public_html/admin/hospitality.php'), 'utf8');
const dialog = source.match(/<dialog id="sales-upload-instructions"[\s\S]*?<\/dialog>/);
assert.ok(dialog, 'Instructions are inside a native dialog');
assert.ok(!/<dialog[^>]*\sopen(?:\s|>)/.test(dialog[0]), 'Dialog is closed initially');
for (const label of ['Date (AD or BS)', 'Category', 'Item', 'Qty', 'Total Sales Amount', 'Discount', 'VAT']) {
    assert.ok(dialog[0].includes(`<td>${label}</td>`), `${label} retained in popup`);
}
assert.equal(source.split('<th>What it takes</th>').length - 1, 1, 'No duplicated inline guide');
assert.ok(source.includes('value="sales_upload_file"'), 'Upload action retained');
assert.ok(source.includes('template=xlsx'), 'Template download retained');
const script = [...source.matchAll(/<script>([\s\S]*?)<\/script>/g)]
    .find(match => match[1].includes("const dialog = document.getElementById('sales-upload-instructions')"))[1];
const elements = {};
for (const suffix of ['', '-open', '-close']) {
    elements['sales-upload-instructions' + suffix] = {
        events: {}, open: false, focused: false,
        addEventListener(event, handler) { this.events[event] = handler; },
        showModal() { this.open = true; },
        close() { this.open = false; this.events.close(); },
        focus() { this.focused = true; },
    };
}
vm.runInNewContext(script, {document: {getElementById: id => elements[id]}});
elements['sales-upload-instructions-open'].events.click();
assert.equal(elements['sales-upload-instructions'].open, true);
elements['sales-upload-instructions-close'].events.click();
assert.equal(elements['sales-upload-instructions'].open, false);
assert.equal(elements['sales-upload-instructions-open'].focused, true);
console.log('Upload instructions: popup containment, seven columns, open/close, focus return and upload controls passed.');
