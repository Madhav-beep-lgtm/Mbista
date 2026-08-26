<?php
declare(strict_types=1);

/**
 * Rows this request has already read, and the thing that throws them away.
 *
 * A page asks the same handful of questions over and over: which fiscal year
 * is this, which company, is this period locked, who is logged in. On the
 * voucher register that was 23 reads of one fiscal year, 21 of one period
 * lock, 19 of one user and 9 of one company -- about seventy round trips for
 * four answers that cannot change between them.
 *
 * WHAT MAKES A READ CACHE SAFE IS THE INVALIDATION, and asking twenty-one
 * write sites to remember to call it is not a plan -- it is a plan plus the
 * twenty-second write somebody adds next year. So nothing is asked of the
 * writers: every statement this application executes passes through the two
 * classes below, and anything that is not plainly a read empties the cache.
 *
 * The test is deliberately blunt. Over-invalidating costs one re-read;
 * under-invalidating serves a figure the database no longer holds, and in a
 * ledger that is not a performance bug, it is a wrong number. So a statement
 * is only treated as a read when it obviously is one, and everything else --
 * including anything unrecognised -- clears the lot.
 *
 * Per REQUEST, never longer: this is a static in a PHP process that ends with
 * the response, so nothing here can outlive the page that filled it.
 */
final class DbRequestCache
{
    /** @var array<string, mixed> */
    private static array $rows = [];
    private static int $hits = 0;
    private static int $misses = 0;
    private static int $flushes = 0;

    /** The cached value for $key, loading it once if this request has not. */
    public static function get(string $key, callable $load): mixed
    {
        if (array_key_exists($key, self::$rows)) {
            self::$hits++;

            return self::$rows[$key];
        }
        self::$misses++;
        // Stored AFTER loading, so a loader that happens to write (none do)
        // cannot leave its own result behind in a cache it just emptied.
        $value = $load();
        self::$rows[$key] = $value;

        return $value;
    }

    /** Forget everything. Called for us on every write; safe to call by hand. */
    public static function flush(): void
    {
        if (self::$rows !== []) {
            self::$flushes++;
        }
        self::$rows = [];
    }

    /** Whether this statement can leave the cache standing. */
    public static function noteStatement(string $sql): void
    {
        // Leading whitespace, parentheses and SQL comments are stepped over so
        // a formatted or commented SELECT is still recognised as a read.
        $head = ltrim($sql);
        while ($head !== '' && ($head[0] === '(' || str_starts_with($head, '/*') || str_starts_with($head, '--'))) {
            if ($head[0] === '(') {
                $head = ltrim(substr($head, 1));
            } elseif (str_starts_with($head, '/*')) {
                $end = strpos($head, '*/');
                $head = $end === false ? '' : ltrim(substr($head, $end + 2));
            } else {
                $end = strpos($head, "\n");
                $head = $end === false ? '' : ltrim(substr($head, $end + 1));
            }
        }
        if (preg_match('/^(?:SELECT|SHOW|EXPLAIN|DESCRIBE|DESC|WITH|SET\s+(?:SESSION\s+)?(?:time_zone|NAMES|CHARACTER|group_concat_max_len|profiling))\b/i', $head) === 1) {
            return;
        }
        self::flush();
    }

    /** hits / misses / flushes, for the test that proves this actually works. */
    public static function stats(): array
    {
        return ['hits' => self::$hits, 'misses' => self::$misses, 'flushes' => self::$flushes, 'held' => count(self::$rows)];
    }

    /** Start again from nothing — for tests that measure a clean run. */
    public static function reset(): void
    {
        self::$rows = [];
        self::$hits = 0;
        self::$misses = 0;
        self::$flushes = 0;
    }
}

/**
 * Every prepared statement the application runs. Overriding execute() rather
 * than prepare() means the cache is emptied when the write actually happens,
 * not when it was merely planned.
 */
final class AppPdoStatement extends PDOStatement
{
    /** PDO constructs these itself; the constructor must not be public. */
    protected function __construct()
    {
    }

    public function execute(?array $params = null): bool
    {
        DbRequestCache::noteStatement($this->queryString);

        return parent::execute($params);
    }
}

/** exec() and query() do not go through the statement class, so they are met here. */
final class AppPdo extends PDO
{
    public function exec(string $statement): int|false
    {
        DbRequestCache::noteStatement($statement);

        return parent::exec($statement);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        DbRequestCache::noteStatement($query);

        return parent::query($query, $fetchMode, ...$fetchModeArgs);
    }
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);

    $pdo = new AppPdo($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        // Every prepared statement comes back as one that tells the request
        // cache it ran, so a write can never leave a stale row behind it.
        PDO::ATTR_STATEMENT_CLASS => [AppPdoStatement::class, []],
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
