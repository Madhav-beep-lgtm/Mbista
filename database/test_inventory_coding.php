<?php
declare(strict_types=1);

/**
 * How an inventory item is identified: code, name, HS code.
 *
 * The item form uppercased whatever was typed and wrote it. The database
 * refused a duplicate within the company and nothing else was checked, so a
 * code could carry a space, a slash or a comma — and an item code goes on to
 * be a barcode payload, a spreadsheet column and a URL parameter.
 */

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/accounting_module_repair.php';
require_once __DIR__ . '/../app/inventory_coding.php';

accounting_module_repair_database();

$pass = 0;
$fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }
function near(float $a, float $b, float $eps = 0.0011): bool { return abs($a - $b) < $eps; }

function invcode_cleanup(): void
{
    foreach (db()->query("SELECT id FROM companies WHERE code = 'INVCODE'")->fetchAll(PDO::FETCH_COLUMN) as $old) {
        $old = (int) $old;
        db()->exec("DELETE FROM inventory_items WHERE company_id = $old");
        foreach (['inventory_units', 'inventory_categories'] as $masterTable) {
            if (table_exists($masterTable)) { db()->exec("DELETE FROM `$masterTable` WHERE company_id = $old"); }
        }
        db()->exec("DELETE FROM fiscal_years WHERE company_id = $old");
        db()->exec("DELETE FROM companies WHERE id = $old");
    }
}
invcode_cleanup();

db()->prepare("INSERT INTO companies (name, code, is_active) VALUES ('Item Coding Co', 'INVCODE', 1)")->execute();
$cid = (int) db()->lastInsertId();

echo "\n1. What may be in an item code\n";
// The pattern, from the rule: ^[A-Za-z0-9][A-Za-z0-9.\-]*$
foreach ([
    ['I0001', true, 'the generated form'],
    ['RICE-5KG', true, 'a hyphen'],
    ['8471.30', true, 'a dot'],
    ['9', true, 'a single digit'],
    ['A', true, 'a single letter'],
] as [$code, $want, $why]) {
    ok((inv_item_code_error($code) === null) === $want, 'Accepted — ' . $why . ' (' . $code . ')');
}
foreach ([
    ['', 'nothing typed'],
    ['-LEAD', 'starting with a hyphen'],
    ['.LEAD', 'starting with a dot'],
    ['HAS SPACE', 'a space'],
    ['SLASH/X', 'a slash'],
    ['COMMA,X', 'a comma'],
    ['UNDER_SCORE', 'an underscore'],
    ['HASH#1', 'a hash'],
] as [$code, $why]) {
    ok(inv_item_code_error($code) !== null, 'Refused — ' . $why . ' (' . ($code === '' ? 'empty' : $code) . ')');
}
ok(inv_item_code_error(str_repeat('A', 40)) === null, 'Forty characters is the longest accepted');
ok(inv_item_code_error(str_repeat('A', 41)) !== null, '  ...and forty-one is refused');

// The refusal has to say WHICH rule was broken. Four of them apply here, and
// "invalid code" sends the user back to guess which.
ok(str_contains((string) inv_item_code_error('-X'), 'start'),
    'A code starting with a hyphen is told it must start with a letter or a digit');
ok(str_contains((string) inv_item_code_error('A B'), 'letters, digits'),
    'A code with a space is told which characters are allowed');
ok(str_contains((string) inv_item_code_error(str_repeat('A', 41)), '40'),
    'An over-long code is told the limit');

echo "\n2. The suggested code runs I0001, I0002, ...\n";
$mkItem = static function (string $sku) use ($cid): void {
    db()->prepare("INSERT INTO inventory_items (company_id, sku, name, item_type, valuation_method, unit, status)
        VALUES (:c, :s, :n, 'stock', 'weighted_average', 'pcs', 'active')")
        ->execute(['c' => $cid, 's' => $sku, 'n' => $sku . ' item']);
};

ok(inv_next_item_code($cid) === 'I0001', 'An empty company starts at I0001');
$mkItem('I0001');
ok(inv_next_item_code($cid) === 'I0002', 'With I0001 taken the next is I0002');

// A shop that types its own codes is not renumbered around them, and those
// codes must not push the run forward either.
$mkItem('RICE-5KG');
$mkItem('BG-100');
$mkItem('02');
ok(inv_next_item_code($cid) === 'I0002',
    'Codes typed in another style are ignored: still I0002, got ' . inv_next_item_code($cid));

// MAX() on a varchar sorts I9 above I10. The numeric part has to be compared
// as a number or the run stalls at I0009 forever.
$mkItem('I0009');
ok(inv_next_item_code($cid) === 'I0010',
    'I0009 is followed by I0010, not I00010 — the number is compared as a number, got ' . inv_next_item_code($cid));
$mkItem('I0010');
ok(inv_next_item_code($cid) === 'I0011', '  ...and the run carries on from there');

// Past 9999 it widens rather than wrapping or refusing.
$mkItem('I9999');
ok(inv_next_item_code($cid) === 'I10000',
    'Past the four-digit run it widens to I10000, got ' . inv_next_item_code($cid));

// Every code it hands out has to satisfy the rule it will be checked against.
ok(inv_item_code_error(inv_next_item_code($cid)) === null,
    'A suggested code is itself a valid code');

echo "\n3. Name and HS code\n";
ok(inv_item_name_error('') !== null, 'An item name is required');
ok(inv_item_name_error(str_repeat('n', 160)) === null, 'A name of 160 characters is accepted');
ok(inv_item_name_error(str_repeat('n', 161)) !== null, '  ...and 161 is refused');

ok(inv_hs_code_error('') === null, 'An HS code is optional');
ok(inv_hs_code_error('8471.30.00') === null, 'A tariff heading written with dots is accepted');
ok(inv_hs_code_error('8471 30 00') === null, 'The same heading written with spaces is accepted');
ok(inv_hs_code_error('84A1') !== null, 'A letter in an HS code is refused');
ok(inv_hs_code_error('.471') !== null, 'An HS code starting with a dot is refused');

echo "\n4. The form and the save path enforce the same rule\n";
$formSource = (string) file_get_contents(dirname(__DIR__) . '/public_html/admin/accounting-inventory.php');
ok(str_contains($formSource, 'inv_item_code_error('),
    'The save path checks the code through the shared rule, not its own');
ok(str_contains($formSource, 'inv_item_name_error(') && str_contains($formSource, 'inv_hs_code_error('),
    '  ...and the name and HS code likewise');
ok(str_contains($formSource, 'maxlength="<?= inv_item_code_max() ?>"'),
    'The code field takes its length from the rule rather than restating it');
ok(str_contains($formSource, 'inv_next_item_code($companyId)'),
    'A new item is offered the next code in the run');
ok(!str_contains($formSource, 'name="sku" maxlength="80"'),
    'The old 80-character code field is gone');

// Ordered by code, which is the rule for every list and report.
ok(str_contains($formSource, 'ORDER BY i.status ASC, i.sku ASC'),
    'The item list is ordered by item code');

echo "\n5. Units and purchase categories come from a list\n";
// Free text on a field that groups a report invites a second spelling, and
// every misspelling is a report line that does not add up with the one above.
$mkUnit = static function (string $name, ?int $baseId = null, float $factor = 1.0) use ($cid): int {
    db()->prepare('INSERT INTO inventory_units (company_id, name, base_unit_id, factor) VALUES (:c, :n, :b, :f)')
        ->execute(['c' => $cid, 'n' => $name, 'b' => $baseId, 'f' => $factor]);
    return (int) db()->lastInsertId();
};
$piece = $mkUnit('PIECE');
$packet = $mkUnit('PACKET', $piece, 10);
$box = $mkUnit('BOX', $piece, 100);

db()->prepare('INSERT INTO inventory_categories (company_id, name) VALUES (:c, :n)')
    ->execute(['c' => $cid, 'n' => 'Raw Materials']);
$catRaw = (int) db()->lastInsertId();

ok(count(inv_units_list($cid)) === 3, 'The unit master lists the three units of this company');
ok(count(inv_categories_list($cid)) === 1, 'The category master lists the one category');

// An id arrives on a posted form and is whatever it was sent with. An item
// pointed at another company's unit would convert its stock by that company's
// factor, so membership is checked rather than assumed.
ok(inv_unit_choice_error($cid, $box) === null, 'A unit of this company may be chosen');
ok(inv_unit_choice_error($cid, 0) !== null, 'No unit at all is refused');
ok(inv_unit_choice_error($cid + 99999, $box) !== null, 'A unit belonging to another company is refused');
ok(inv_category_choice_error($cid, $catRaw) === null, 'A category of this company may be chosen');
ok(inv_category_choice_error($cid + 99999, $catRaw) !== null, '  ...and another company\'s is refused');

// Deactivating takes it out of the list offered, without deleting it — items
// already filed under it keep their filing.
db()->prepare('UPDATE inventory_units SET active = 0 WHERE id = ?')->execute([$box]);
ok(inv_unit_choice_error($cid, $box) !== null, 'A deactivated unit is no longer offered');
ok(isset(inv_units_list($cid, false)[$box]), '  ...but it still exists, so items filed under it are not orphaned');
db()->prepare('UPDATE inventory_units SET active = 1 WHERE id = ?')->execute([$box]);

echo "\n6. Units form a chain, and stock counts in the smallest\n";
ok(inv_unit_base_id($cid, $piece) === $piece, 'A unit naming no other is the base of its own chain');
ok(inv_unit_base_id($cid, $packet) === $piece, 'A packet counts in pieces');
ok(inv_unit_base_id($cid, $box) === $piece, 'So does a box');

ok(near(inv_unit_factor($cid, $piece), 1.0), 'One piece is one piece');
ok(near(inv_qty_to_base($cid, $packet, 3), 30.0), 'Three packets are 30 pieces');
ok(near(inv_qty_to_base($cid, $box, 3), 300.0), 'Three boxes are 300 pieces');
ok(near(inv_qty_from_base($cid, $box, 250), 2.5), '250 pieces read back as 2.5 boxes');

// A chain is not always whole numbers — a carton of 2.5 kg bags is a real
// thing — so the factor carries decimals even though quantities stay at three.
$kg = $mkUnit('KG');
$carton = $mkUnit('CARTON', $kg, 2.5);
ok(near(inv_qty_to_base($cid, $carton, 4), 10.0), 'Four 2.5 kg cartons are 10 kg');

// A factor of zero would make a box worth no pieces, and every quantity
// converted through it would vanish.
$broken = $mkUnit('BROKEN', $piece, 0);
ok(near(inv_unit_factor($cid, $broken), 1.0), 'A factor of zero is treated as one, not as nothing');
ok(near(inv_qty_to_base($cid, $broken, 7), 7.0), '  ...so a quantity through it survives instead of vanishing');

// An unknown unit must not silently multiply a quantity by nothing either.
ok(near(inv_unit_factor($cid, 999999), 1.0), 'An unknown unit converts one for one');
ok(inv_unit_base_id($cid, 999999) === 999999, '  ...and answers as its own base, so a caller always gets a unit back');

echo "\n7. The masters are seeded from what the items already said\n";
// Upgrading a company that has been typing free text must not lose its
// filing, and must not merge two spellings into one — a typo is still the
// category its items are filed under.
db()->prepare("INSERT INTO inventory_items (company_id, sku, name, item_type, valuation_method, unit, category, status)
    VALUES (:c, 'I9001', 'Seeded item', 'stock', 'weighted_average', 'DRUM', 'Packaging', 'active')")
    ->execute(['c' => $cid]);
db()->exec("INSERT IGNORE INTO inventory_units (company_id, name)
    SELECT DISTINCT company_id, TRIM(unit) FROM inventory_items WHERE company_id = {$cid} AND TRIM(COALESCE(unit, '')) <> ''");
db()->exec("INSERT IGNORE INTO inventory_categories (company_id, name)
    SELECT DISTINCT company_id, TRIM(category) FROM inventory_items WHERE company_id = {$cid} AND TRIM(COALESCE(category, '')) <> ''");

$seededUnit = (int) db()->query("SELECT COUNT(*) FROM inventory_units WHERE company_id = {$cid} AND name = 'DRUM'")->fetchColumn();
$seededCat = (int) db()->query("SELECT COUNT(*) FROM inventory_categories WHERE company_id = {$cid} AND name = 'Packaging'")->fetchColumn();
ok($seededUnit === 1, 'A unit only ever typed as free text becomes a master entry');
ok($seededCat === 1, '  ...and so does the category');

// Running it twice must not double the master.
db()->exec("INSERT IGNORE INTO inventory_units (company_id, name)
    SELECT DISTINCT company_id, TRIM(unit) FROM inventory_items WHERE company_id = {$cid} AND TRIM(COALESCE(unit, '')) <> ''");
ok((int) db()->query("SELECT COUNT(*) FROM inventory_units WHERE company_id = {$cid} AND name = 'DRUM'")->fetchColumn() === 1,
    'Seeding again adds nothing, because the master is unique per company');

invcode_cleanup();

echo "\n" . str_repeat('=', 50) . "\n  PASS: $pass    FAIL: $fail\n" . str_repeat('=', 50) . "\n";
exit($fail > 0 ? 1 : 0);
