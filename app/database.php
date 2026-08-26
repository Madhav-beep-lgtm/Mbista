<?php
declare(strict_types=1);

/**
 * Rows this request has already read, and the thing that throws them away.
 *
 * A page asks the same handful of questions over and over: which fiscal year
 * is this, which company, is this period locked, who is logged in, has this
 * voucher been reconciled. On the voucher register that was 23 reads of one
 * fiscal year, 21 of one period lock, 19 of one user, 9 of one company and one
 * reconciliation count per row -- well over a hundred round trips for a
 * handful of answers that cannot change between them.
 *
 * WHAT MAKES A READ CACHE SAFE IS THE INVALIDATION, and asking twenty-one
 * write sites to remember to call it is not a plan -- it is a plan plus the
 * twenty-second write somebody adds next year. So nothing is asked of the
 * writers: every statement this application executes passes through AppPdo and
 * AppPdoStatement, and each one says what it did.
 *
 * WHAT A WRITE THROWS AWAY depends on what kind of write it is, and the
 * distinction is about foreign keys rather than tidiness:
 *
 *   a read                       throws away nothing.
 *   an INSERT, or DDL            throws away only the cached tables it NAMES.
 *                                Neither can reach a row in a table it does
 *                                not mention: there are no triggers, views or
 *                                routines in this schema, so nothing can act
 *                                on its behalf, and an insert cannot cascade.
 *   an UPDATE, a DELETE,         throws away everything. These CAN reach a
 *   or anything unrecognised     table they do not name, because a delete on
 *                                a parent cascades to its children, and a
 *                                cache that missed that would report a
 *                                voucher as free to edit after the entry
 *                                backing that answer had gone.
 *
 * The unrecognised case sits with the blunt ones deliberately. Over-throwing
 * costs a re-read; under-throwing puts a figure on screen that the database no
 * longer holds, and in a ledger that is not a performance bug.
 *
 * Per REQUEST, never longer: a static in a PHP process that ends with the
 * response, so nothing here outlives the page that filled it.
 */
final class DbRequestCache
{
    /** @var array<string, array<string, mixed>> table => key => value */
    private static array $rows = [];
    private static int $hits = 0;
    private static int $misses = 0;
    private static int $flushes = 0;

    /**
     * The cached value for $key, loading it once if this request has not.
     * $table is the table the answer came out of, so a write to it can find
     * this again.
     */
    public static function get(string $table, string $key, callable $load): mixed
    {
        if (isset(self::$rows[$table]) && array_key_exists($key, self::$rows[$table])) {
            self::$hits++;

            return self::$rows[$table][$key];
        }
        self::$misses++;
        // Stored AFTER loading, so a loader that happens to write (none do)
        // cannot leave its own result behind in a bucket it just emptied.
        $value = $load();
        self::$rows[$table][$key] = $value;

        return $value;
    }

    /** Forget everything. */
    public static function flush(): void
    {
        if (self::$rows !== []) {
            self::$flushes++;
        }
        self::$rows = [];
    }

    /** Forget one table's rows. */
    public static function forgetTable(string $table): void
    {
        if (isset(self::$rows[$table])) {
            unset(self::$rows[$table]);
            self::$flushes++;
        }
    }

    /** What this statement costs the cache. See the note above the class. */
    public static function noteStatement(string $sql): void
    {
        $head = self::stripLeadingNoise($sql);
        if (preg_match('/^(?:SELECT|SHOW|EXPLAIN|DESCRIBE|DESC|WITH|SET\s+(?:SESSION\s+|GLOBAL\s+)?(?:time_zone|NAMES|CHARACTER|group_concat_max_len|profiling|autocommit))\b/i', $head) === 1) {
            return;
        }
        // Only the two that cannot reach a table they do not name are scoped.
        if (preg_match('/^(?:INSERT|REPLACE|CREATE|ALTER|DROP|TRUNCATE|RENAME)\b/i', $head) === 1) {
            if (self::$rows === []) {
                return;
            }
            foreach (array_keys(self::$rows) as $table) {
                if (preg_match('/\b' . preg_quote($table, '/') . '\b/i', $sql) === 1) {
                    self::forgetTable($table);
                }
            }

            return;
        }
        self::flush();
    }

    /** Step over whitespace, brackets and SQL comments to the first keyword. */
    private static function stripLeadingNoise(string $sql): string
    {
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

        return $head;
    }

    /** hits / misses / flushes, for the test that proves this actually works. */
    public static function stats(): array
    {
        $held = 0;
        foreach (self::$rows as $bucket) {
            $held += count($bucket);
        }

        return ['hits' => self::$hits, 'misses' => self::$misses, 'flushes' => self::$flushes, 'held' => $held];
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
