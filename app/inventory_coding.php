<?php
declare(strict_types=1);

/**
 * How an inventory item is identified: its code, its name, its HS code.
 *
 * These were enforced nowhere. The item form asked for a SKU, uppercased
 * whatever was typed and wrote it, so a code could carry a space, a slash or a
 * comma — and those characters go on to be a barcode's payload, a CSV column
 * and a URL parameter. The database already refused a duplicate within the
 * company; nothing else was checked at all.
 *
 * One definition of each rule lives here, so the item form, the spreadsheet
 * import and the barcode scanner cannot drift into disagreeing about what a
 * valid code is.
 */

/** The one pattern an item code has to match. */
function inv_item_code_pattern(): string
{
    return '/^[A-Za-z0-9][A-Za-z0-9.\-]*$/';
}

/** Longest item code accepted. */
function inv_item_code_max(): int
{
    return 40;
}

/**
 * Why this item code is not acceptable, or null when it is.
 *
 * Returns the reason rather than a boolean: a refusal that does not say which
 * rule was broken sends the user back to guess, and there are four here.
 */
function inv_item_code_error(string $code): ?string
{
    $code = trim($code);
    if ($code === '') {
        return 'Item code is required.';
    }
    if (mb_strlen($code) > inv_item_code_max()) {
        return 'Item code cannot be longer than ' . inv_item_code_max() . ' characters.';
    }
    if (preg_match('/^[A-Za-z0-9]/', $code) !== 1) {
        return 'Item code must start with a letter or a digit.';
    }
    if (preg_match(inv_item_code_pattern(), $code) !== 1) {
        return 'Item code may contain only letters, digits, dots and hyphens.';
    }

    return null;
}

/**
 * The next code in the company's own run: I0001, I0002, ...
 *
 * Only codes that are exactly "I" followed by digits are counted. A shop that
 * types its own codes -- RICE-5KG, or a supplier's part number -- is not
 * renumbered around them, and those codes do not push the run forward either.
 *
 * MAX() on a varchar would sort I9 above I10, so the numeric part is compared
 * as a number. Padded to four digits, and longer once the run passes 9999
 * rather than wrapping or refusing.
 */
function inv_next_item_code(int $companyId): string
{
    if ($companyId <= 0 || !table_exists('inventory_items')) {
        return 'I0001';
    }

    $stmt = db()->prepare("SELECT MAX(CAST(SUBSTRING(sku, 2) AS UNSIGNED))
        FROM inventory_items
        WHERE company_id = :cid AND sku REGEXP '^[Ii][0-9]+$'");
    $stmt->execute(['cid' => $companyId]);
    $highest = (int) ($stmt->fetchColumn() ?: 0);

    return 'I' . str_pad((string) ($highest + 1), 4, '0', STR_PAD_LEFT);
}

/** Longest item name accepted. */
function inv_item_name_max(): int
{
    return 160;
}

/** Why this item name is not acceptable, or null when it is. */
function inv_item_name_error(string $name): ?string
{
    $name = trim($name);
    if ($name === '') {
        return 'Item name is required.';
    }
    if (mb_strlen($name) > inv_item_name_max()) {
        return 'Item name cannot be longer than ' . inv_item_name_max() . ' characters.';
    }

    return null;
}

/**
 * Why this HS code is not acceptable, or null when it is.
 *
 * Optional, so an empty one is fine. Digits, and the dots or spaces a customs
 * tariff is written with -- 8471.30.00 and 8471 30 00 are the same heading
 * written two ways, and both are what a user copies off a tariff schedule.
 */
function inv_hs_code_error(string $hsCode): ?string
{
    $hsCode = trim($hsCode);
    if ($hsCode === '') {
        return null;
    }
    if (preg_match('/^[0-9][0-9. ]*$/', $hsCode) !== 1) {
        return 'HS code may contain only digits, dots and spaces.';
    }

    return null;
}
