const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public_html/assets/js/main.js', 'utf8');
const start = source.indexOf('  // Select one destination;');
const end = source.indexOf('\n});', start);
assert.ok(start >= 0 && end > start);
const code = source.slice(start, end);
function check(path, specs, expected, neutral = false) {
  const current = new URL(path, 'https://example.test');
  const links = specs.map(([href, active = false, toggle = false]) => {
    const classes = new Set(active ? ['is-active'] : []);
    const attrs = { href };
    return {
      href: new URL(href, current).href, attrs,
      classList: { contains: key => classes.has(key), remove: key => classes.delete(key), add: key => classes.add(key) },
      getAttribute: key => attrs[key],
      hasAttribute: key => key === 'data-nav-toggle' && toggle,
      removeAttribute: key => delete attrs[key],
      setAttribute: (key, value) => { attrs[key] = value; },
    };
  });
  vm.runInNewContext(code, {
    URL,
    window: { location: { href: current.href } },
    document: {
      body: { dataset: { navNeutral: String(neutral) } },
      querySelectorAll: () => [{ querySelectorAll: () => links }],
    },
  });
  assert.deepEqual(links.flatMap((link, index) => link.classList.contains('is-active') ? [index] : []), expected);
  assert.deepEqual(links.flatMap((link, index) => link.attrs['aria-current'] === 'page' ? [index] : []), expected);
}
check('/admin/accounting-dashboard.php', [
  ['#', true, true], ['/admin/accounting-dashboard.php', true], ['#', true, true],
], [1]);
check('/admin/accounting-dashboard.php', [
  ['#', true, true], ['/admin/accounting-dashboard.php', true],
], [], true);
check('/admin/hospitality.php?view=recipes', [
  ['#', true, true], ['/admin/hospitality.php'], ['/admin/hospitality.php?view=recipes'],
  ['/admin/hospitality.php?view=ingredients', true],
], [2]);
check('/admin/create-account.php?edit_id=5', [
  ['#', true, true], ['/admin/chart-of-accounts.php', true],
], [1]);
check('/admin/day-book.php', [
  ['/admin/day-book.php'], ['/admin/day-book.php', true],
  ['https://other.test/admin/day-book.php'],
], [0]);
check('/admin/accounting-parties.php', [
  ['/admin/accounting-parties.php?tab=sales'], ['/admin/accounting-parties.php?tab=purchases'],
], [0]);
const masters = source.slice(source.indexOf('  const MASTERS ='), source.indexOf('  const MODULE_OF ='));
assert.ok(!/admin\/(accounting-parties|chart-ledgers|chart-groups)\.php/.test(masters));
assert.match(masters, /party_id: 'admin\/create-account.php'/);
console.log('Navigation selection and account creation targets passed.');
