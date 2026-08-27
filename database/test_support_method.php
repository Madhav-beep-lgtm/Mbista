<?php
declare(strict_types=1);

/**
 * Pin the inventory accounting system for a test suite, and put it back after.
 *
 * WHY THIS EXISTS. Several suites assert the PERPETUAL postings — a purchase
 * debiting Inventory, a sale carrying its own cost of sales. Those assertions
 * are correct and worth keeping, but they were silently reading whatever the
 * database happened to be set to. So the moment a shop switched itself to the
 * periodic system, its own test suite started failing eight assertions at a
 * time and, in one case, dying on a foreign key: a sale posts no voucher under
 * periodic, and the suite wrote the returned nought into a column that points
 * at the voucher table.
 *
 * None of that was a fault in the application. It was tests depending on a live
 * setting they had never been told to control.
 *
 * A suite that asserts one system's postings must SAY SO, and must hand the
 * setting back exactly as it found it — a test run that changes how a real shop
 * posts is a far worse bug than any it could catch.
 *
 *   require_once __DIR__ . '/test_support_method.php';
 *   test_pin_inventory_method('perpetual');
 */

/**
 * Force one accounting system for the rest of this process, restoring whatever
 * was there when the process ends — including when it ends by dying.
 */
function test_pin_inventory_method(string $method): void
{
    static $pinned = false;
    if ($pinned) {
        return;
    }
    $pinned = true;

    $previous = db()->query("SELECT setting_value FROM settings WHERE setting_key = 'inventory_accounting'")
        ->fetchColumn();

    // Registered BEFORE the change, so an exception halfway through a suite
    // still hands the setting back.
    register_shutdown_function(static function () use ($previous): void {
        if ($previous === false) {
            db()->exec("DELETE FROM settings WHERE setting_key = 'inventory_accounting'");
        } else {
            db()->prepare('REPLACE INTO settings (setting_key, setting_value) VALUES (:k, :v)')
                ->execute(['k' => 'inventory_accounting', 'v' => (string) $previous]);
        }
        setting('inventory_accounting', '', true);
    });

    db()->prepare('REPLACE INTO settings (setting_key, setting_value) VALUES (:k, :v)')
        ->execute(['k' => 'inventory_accounting', 'v' => $method]);
    setting('inventory_accounting', '', true);
}
