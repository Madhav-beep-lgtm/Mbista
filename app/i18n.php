<?php
declare(strict_types=1);

/**
 * Lightweight multi-language support (English / नेपाली / हिन्दी).
 *
 * - app_lang(): the active language code; ?lang=xx switches it (session +
 *   1-year cookie) so the choice survives login sessions.
 * - t('English text'): server-side translation with English text as the key —
 *   unknown keys fall back to the English text itself, so wrapping a string
 *   is always safe.
 * - The visible chrome (sidebar, buttons, table headers) is translated
 *   client-side by assets/js/i18n.js from the same philosophy: exact-text
 *   dictionaries, silent fallback to English.
 */

const APP_LANGS = ['en' => 'English', 'ne' => 'नेपाली', 'hi' => 'हिन्दी'];

function app_lang(bool $refresh = false): string
{
    static $lang = null;
    if ($refresh) {
        $lang = null; // re-read session/cookie (tests, mid-request switches)
    }
    if ($lang !== null) {
        return $lang;
    }
    $requested = (string) ($_GET['lang'] ?? '');
    if (isset(APP_LANGS[$requested])) {
        $_SESSION['app_lang'] = $requested;
        // bootstrap.php resolves the language BEFORE any output, so the cookie
        // normally goes out fine; if a stray late call gets here after output
        // started, skip the cookie (the session already carries the choice)
        // instead of spraying a headers-already-sent warning into the page.
        if (!headers_sent()) {
            setcookie('app_lang', $requested, [
                'expires' => time() + 31536000,
                'path' => '/',
                'httponly' => false,
                'samesite' => 'Lax',
            ]);
        }
        $lang = $requested;
        return $lang;
    }
    $stored = (string) ($_SESSION['app_lang'] ?? ($_COOKIE['app_lang'] ?? 'en'));
    $lang = isset(APP_LANGS[$stored]) ? $stored : 'en';
    return $lang;
}

/** Translate a string (English text as key). Falls back to the input. */
function t(string $text): string
{
    $lang = app_lang();
    if ($lang === 'en') {
        return $text;
    }
    static $dictionaries = [];
    if (!isset($dictionaries[$lang])) {
        $file = __DIR__ . '/i18n/' . $lang . '.php';
        $dictionaries[$lang] = is_file($file) ? (array) require $file : [];
    }
    return (string) ($dictionaries[$lang][$text] ?? $text);
}

/** The current URL with only the lang parameter swapped (for toggle links). */
function i18n_lang_url(string $code): string
{
    $query = $_GET;
    $query['lang'] = $code;
    $path = strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?') ?: '';
    return $path . '?' . http_build_query($query);
}
