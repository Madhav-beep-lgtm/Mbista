<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/voucher_types.php';
$mode = 'ad';
function date_mode(): string { return $GLOBALS['mode']; }
function fiscal_year_by_id(int $id): array { return ['company_id' => 1, 'start_date' => '2026-07-17', 'end_date' => '2027-07-16', 'label' => 'FY-2026-2027']; }
function table_exists(string $table): bool { return true; }
function db(): object {
    return new class {
        public function prepare(string $sql): object {
            if (!str_contains($sql, 'fiscal_year_id = :fiscal_year_id') || !str_contains($sql, 'voucher_type = :voucher_type')) { throw new RuntimeException('Unscoped sequence'); }
            return new class {
                public function execute(array $params): void { if ($params['company_id'] !== 1) { throw new RuntimeException('Wrong tenant'); } }
                public function fetchColumn(): int { return 10; }
            };
        }
    };
}
foreach (['contra'=>'CO','payment'=>'PM','receipt'=>'RC','journal'=>'JV','sales'=>'SL','purchase'=>'PU','debit_note'=>'DN','credit_note'=>'CN'] as $type=>$prefix) {
    $mode = 'ad';
    if (voucher_next_number(1, 1, $type) !== $prefix . '/26-27/11') { throw new RuntimeException('AD numbering failed'); }
    $mode = 'bs';
    if (voucher_next_number(1, 1, $type) !== $prefix . '/83-84/11') { throw new RuntimeException('BS numbering failed'); }
    if (voucher_next_number(1, 1, $type, 1) !== $prefix . '/83-84/12') { throw new RuntimeException('Retry failed'); }
}
if (voucher_short_series(['start_date'=>'2025-07-17','end_date'=>'2026-07-16'], 'bs') !== '82-83') { throw new RuntimeException('BS FY pair failed'); }
try { voucher_next_number(2, 1, 'payment'); throw new LogicException('Foreign fiscal year accepted'); } catch (RuntimeException $expected) {}
echo "All eight voucher types: short AD/BS numbering, continuation, retry and company guard passed.\n";
