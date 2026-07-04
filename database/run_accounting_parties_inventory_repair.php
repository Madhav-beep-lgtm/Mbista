<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/accounting_module_repair.php';

$errors = accounting_module_repair_database();

if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

echo 'Accounting parties and inventory repair completed.' . PHP_EOL;
