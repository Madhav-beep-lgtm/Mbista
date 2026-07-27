<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);

    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    // Put the connection on the SAME clock PHP is on.
    //
    // Setting PHP's timezone alone would only move the disagreement: NOW(),
    // CURDATE() and every CURRENT_TIMESTAMP default come from the database
    // server's clock, and a shared host's MySQL is often hours from what the
    // application thinks the time is. Everything that compares a PHP-generated
    // date against a SQL one — a fiscal-year boundary, a token expiry, a
    // throttle window — depends on the two agreeing.
    //
    // The numeric OFFSET is sent rather than the zone name because the named
    // timezone tables are frequently not loaded on shared MySQL, and
    // SET time_zone = 'Asia/Kathmandu' then fails outright.
    if (defined('APP_TIMEZONE')) {
        try {
            $offsetSeconds = (new DateTimeZone(APP_TIMEZONE))
                ->getOffset(new DateTime('now', new DateTimeZone('UTC')));
            $sign = $offsetSeconds < 0 ? '-' : '+';
            $offsetSeconds = abs($offsetSeconds);
            $pdo->exec(sprintf("SET time_zone = '%s%02d:%02d'",
                $sign, intdiv($offsetSeconds, 3600), intdiv($offsetSeconds % 3600, 60)));
        } catch (Throwable $timezoneError) {
            // A database that refuses the offset keeps its own clock. Nothing
            // breaks; the two are simply not aligned, which is where this
            // started — so it is left visible rather than pretended away.
        }
    }

    return $pdo;
}
