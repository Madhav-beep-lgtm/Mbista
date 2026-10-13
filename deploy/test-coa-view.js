const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public_html/admin/chart-of-accounts.php', 'utf8');
assert.ok(!source.includes('coa-tgl'), 'Collapse arrows must be absent');
assert.ok(!/<td style="padding-left:\d+px">.*\['code'\]/.test(source), 'Code indentation must be absent');
const row = (type, name, branch, parents = '') => ({
  cells: [null,null,{textContent:type}], style: {},
  getAttribute: key => ({'data-name':name,'data-branch':branch,'data-parent-of':parents}[key] || null),
});
const rows = [row('Master','assets 1.00.00.000.00','m1'),row('Group','bank 1.01.00.000.00','g1','m1'),row('Ledger','cash 1.01.00.001.00',null,'m1 g1')];
const field = value => ({value,addEventListener(){}});
const nodes = {'coa-section':field('hierarchy'),'coa-search':field('')};
for (const id of ['coa-tree-panel','coa-posting-panel','coa-audit-panel','coa-no-matches']) nodes[id] = {hidden:false,querySelectorAll:()=>[],querySelector:()=>null};
const context = {document:{getElementById:id=>nodes[id],querySelectorAll:()=>rows}};
vm.createContext(context);
const start = source.indexOf("    var view = document.getElementById('coa-section');");
const end = source.indexOf('\n});',start);
vm.runInContext(source.slice(start,end),context);
assert.ok(rows.every(r=>r.style.display === ''), 'All hierarchy rows visible');
nodes['coa-search'].value = 'bank';
context.updateView();
assert.ok(rows.every(r=>r.style.display === ''), 'Group search preserves ancestors and ledgers');
nodes['coa-search'].value = '';
nodes['coa-section'].value = 'ledgers';
context.updateView();
assert.deepEqual(rows.map(r=>r.style.display),['none','none','']);
console.log('Arrow removal, aligned code markup and hierarchy search/view regression passed.');
