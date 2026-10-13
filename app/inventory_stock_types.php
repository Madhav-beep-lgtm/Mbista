<?php
declare(strict_types=1);

function inv_stock_types_schema(): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS inventory_stock_types (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, company_id INT UNSIGNED NOT NULL,
        name VARCHAR(100) NOT NULL, UNIQUE KEY company_name (company_id, name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if (!column_exists('inventory_items', 'stock_type_id')) {
        db()->exec('ALTER TABLE inventory_items ADD stock_type_id INT UNSIGNED DEFAULT NULL, ADD INDEX stock_type_id (stock_type_id)');
    }
}

function inv_stock_types(int $companyId): array
{
    if (!table_exists('inventory_stock_types')) return [];
    $stmt = db()->prepare('SELECT id, name FROM inventory_stock_types WHERE company_id = ? ORDER BY name');
    $stmt->execute([$companyId]);
    return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

function inv_stock_type_create(int $companyId, string $name): int
{
    $name = trim($name);
    if ($name === '' || mb_strlen($name) > 100) throw new InvalidArgumentException('Enter a stock type name of 1–100 characters.');
    db()->prepare('INSERT INTO inventory_stock_types (company_id, name) VALUES (?, ?)')->execute([$companyId, $name]);
    return (int)db()->lastInsertId();
}

function inv_stock_type_validate(int $companyId, int $id): void
{
    if (!isset(inv_stock_types($companyId)[$id])) throw new InvalidArgumentException('Select a stock type created for this company.');
}
