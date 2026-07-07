<?php
declare(strict_types=1);

/*
 * Bikram Sambat (Nepali) calendar support — self-contained, no external
 * service (cPanel/CSP safe). Uses the canonical BS month-length table
 * (BS 2000–2090) that Hamro Patro–class converters are built on.
 * Each year is encoded as 12 digits, digit = days-in-month minus 28.
 * Epoch: BS 2000-01-01 = AD 1943-04-14.
 */

const BS_EPOCH_AD = '1943-04-14';
const BS_FIRST_YEAR = 2000;

const BS_MONTHS = ['Baishakh', 'Jestha', 'Ashadh', 'Shrawan', 'Bhadra', 'Ashwin', 'Kartik', 'Mangsir', 'Poush', 'Magh', 'Falgun', 'Chaitra'];

const BS_YEAR_DATA = [
    '243432221213', '334333212122', '334432212122', '343432221123', '243432221213',
    '334333212122', '334432212122', '343432221123', '333433122113', '334333212122',
    '334432212122', '343432221123', '333433122122', '334333212122', '334432212122',
    '343432221123', '333433122122', '334333212122', '343432212122', '343432221213',
    '333433212122', '334333212122', '343432221122', '343432221213', '333433212122',
    '334333212122', '343432221123', '243432221213', '334333212122', '334342212122',
    '343432221123', '243432221213', '334333212122', '334432212122', '343432221123',
    '243433122113', '334333212122', '334432212122', '343432221123', '333433122122',
    '334333212122', '334432212122', '343432221123', '333433122122', '334333212122',
    '343432212122', '343432221123', '333433212122', '334333212122', '343432221122',
    '343432221213', '333433212122', '334333212122', '343432221122', '343432221213',
    '334333212122', '334342212122', '343432221123', '243432221213', '334333212122',
    '334432212122', '343432221123', '243433121213', '334333212122', '334432212122',
    '343432221123', '333433122113', '334333212122', '334432212122', '343432221123',
    '333433122122', '334333212122', '343432212122', '343432221123', '333433212122',
    '334333212122', '343432221122', '343432221213', '333433212122', '334333212122',
    '343432221122', '334432221222', '243432221222', '334332221222', '334332221222',
    '343423221222', '243432221222', '334333221222', '234423221222', '243432221222',
    '243432221222',
];

function bs_month_days(int $bsYear, int $bsMonth): int
{
    $row = BS_YEAR_DATA[$bsYear - BS_FIRST_YEAR] ?? null;
    if ($row === null || $bsMonth < 1 || $bsMonth > 12) {
        return 30;
    }
    return 28 + (int) $row[$bsMonth - 1];
}

/** AD date (Y-m-d) -> [year, month, day] in BS, or null outside the table. */
function ad_to_bs(string $adDate): ?array
{
    $epoch = DateTimeImmutable::createFromFormat('!Y-m-d', BS_EPOCH_AD);
    $target = DateTimeImmutable::createFromFormat('!Y-m-d', substr($adDate, 0, 10));
    if (!$epoch || !$target) {
        return null;
    }
    $days = (int) $epoch->diff($target)->format('%r%a');
    if ($days < 0) {
        return null;
    }
    $year = BS_FIRST_YEAR;
    while ($year - BS_FIRST_YEAR < count(BS_YEAR_DATA)) {
        $yearDays = 0;
        for ($m = 1; $m <= 12; $m++) {
            $yearDays += bs_month_days($year, $m);
        }
        if ($days < $yearDays) {
            break;
        }
        $days -= $yearDays;
        $year++;
    }
    if ($year - BS_FIRST_YEAR >= count(BS_YEAR_DATA)) {
        return null;
    }
    $month = 1;
    while ($days >= bs_month_days($year, $month)) {
        $days -= bs_month_days($year, $month);
        $month++;
    }
    return [$year, $month, $days + 1];
}

/** BS date -> AD date string (Y-m-d), or null outside the table. */
function bs_to_ad(int $bsYear, int $bsMonth, int $bsDay): ?string
{
    if ($bsYear < BS_FIRST_YEAR || $bsYear - BS_FIRST_YEAR >= count(BS_YEAR_DATA)) {
        return null;
    }
    $days = 0;
    for ($y = BS_FIRST_YEAR; $y < $bsYear; $y++) {
        for ($m = 1; $m <= 12; $m++) {
            $days += bs_month_days($y, $m);
        }
    }
    for ($m = 1; $m < $bsMonth; $m++) {
        $days += bs_month_days($bsYear, $m);
    }
    $days += $bsDay - 1;
    $epoch = DateTimeImmutable::createFromFormat('!Y-m-d', BS_EPOCH_AD);
    return $epoch ? $epoch->modify('+' . $days . ' days')->format('Y-m-d') : null;
}

/** "23 Ashadh 2083" for an AD date; empty string when unavailable. */
function bs_format(string $adDate): string
{
    $bs = ad_to_bs($adDate);
    if ($bs === null) {
        return '';
    }
    return $bs[2] . ' ' . BS_MONTHS[$bs[1] - 1] . ' ' . $bs[0];
}

/** "23 Ashadh 2083 – 16 Ashadh 2084" period label; empty when unavailable. */
function bs_format_range(string $fromAd, string $toAd): string
{
    $from = bs_format($fromAd);
    $to = bs_format($toAd);
    return ($from !== '' && $to !== '') ? $from . ' – ' . $to : '';
}
