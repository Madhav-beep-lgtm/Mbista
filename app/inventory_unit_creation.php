<?php
declare(strict_types=1);

function inventory_unit_creation_values(array $input, array $units): array
{
    $name = trim((string) ($input['unit_name'] ?? ''));
    $baseId = (int) ($input['base_unit_id'] ?? 0);
    $factor = $baseId > 0 ? ($input['factor'] ?? '') : '1';
    if ($name === '' || mb_strlen($name) > 40) throw new RuntimeException('Enter a unit name of 1–40 characters.');
    if ($baseId > 0 && (!isset($units[$baseId]) || !$units[$baseId]['active'] || (int) $units[$baseId]['base_unit_id'] !== $baseId)) {
        throw new RuntimeException('Select an active base unit belonging to this company.');
    }
    if (!is_numeric($factor) || !is_finite((float) $factor) || (float) $factor < 0.000001 || (float) $factor > 999999999999) {
        throw new RuntimeException('Enter a positive conversion factor with up to six decimal places.');
    }
    foreach ($units as $unit) {
        if (mb_strtolower($unit['name']) === mb_strtolower($name)) throw new RuntimeException('A unit with that name already exists.');
    }
    return ['name' => $name, 'base_unit_id' => $baseId ?: null, 'factor' => round((float) $factor, 6)];
}
