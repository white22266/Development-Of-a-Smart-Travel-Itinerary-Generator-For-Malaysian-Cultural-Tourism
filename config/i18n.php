<?php
// config/i18n.php
// Language mechanism for the traveller pages: English (en), Malay (ms), Simplified Chinese (zh).
//
// Use on a page (before any output; never write the closing PHP tag inside these comments):
//   require_once __DIR__ . '/../config/i18n.php';             path relative to the page
//   html lang attribute:   echo i18n_lang();
//   text in HTML:          echo te('common.back');              already escaped
//   text in PHP:           $msg = t('dash.date', ['date' => $d]); plain text, escape when printing
//   text for JavaScript:   const T = followed by echo i18n_js(['common.back', 'common.next']);
//   switcher:              echo i18n_switcher();              next to the navigation links
//   date:                  echo i18n_date($date, 'short');    styles: short, short_weekday, long, month_year
//
// Texts live in lang/en.php, lang/ms.php and lang/zh.php (one array each, same keys).

const I18N_LANGS = ['en', 'ms', 'zh'];
const I18N_COOKIE = 'lang';

/** The current language code: ?lang=, else session, else cookie, else Accept-Language, else en. */
function i18n_lang(): string
{
    static $lang = null;
    if ($lang !== null) {
        return $lang;
    }

    $sessionActive = session_status() === PHP_SESSION_ACTIVE;
    $asked = $_GET['lang'] ?? null;

    if (is_string($asked) && in_array($asked, I18N_LANGS, true)) {
        $lang = $asked;
        if ($sessionActive) {
            $_SESSION['lang'] = $lang;
        }
        $_COOKIE[I18N_COOKIE] = $lang;
        if (!headers_sent()) {
            setcookie(I18N_COOKIE, $lang, [
                'expires' => time() + 365 * 24 * 3600,
                'path' => '/',
                'samesite' => 'Lax',
            ]);
        }
        return $lang;
    }

    $fromSession = $sessionActive ? ($_SESSION['lang'] ?? null) : null;
    if (is_string($fromSession) && in_array($fromSession, I18N_LANGS, true)) {
        return $lang = $fromSession;
    }

    $fromCookie = $_COOKIE[I18N_COOKIE] ?? null;
    if (is_string($fromCookie) && in_array($fromCookie, I18N_LANGS, true)) {
        return $lang = $fromCookie;
    }

    return $lang = i18n_from_accept_language((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
}

/** Best supported language of an Accept-Language header, by q value; en when none matches. */
function i18n_from_accept_language(string $header): string
{
    $best = 'en';
    $bestQ = -1.0;
    foreach (explode(',', $header) as $part) {
        $bits = explode(';', trim($part));
        $tag = strtolower(trim($bits[0]));
        if ($tag === '') {
            continue;
        }
        $q = 1.0;
        foreach (array_slice($bits, 1) as $param) {
            $param = trim($param);
            if (stripos($param, 'q=') === 0) {
                $q = (float)substr($param, 2);
            }
        }
        $primary = explode('-', $tag)[0];
        $code = match ($primary) {
            'en' => 'en',
            'ms', 'zsm', 'may' => 'ms',
            'zh' => 'zh',
            default => null,
        };
        if ($code !== null && $q > $bestQ) {
            $best = $code;
            $bestQ = $q;
        }
    }
    return $best;
}

/** All texts of one language file; an unreadable or invalid file gives an empty array. */
function i18n_messages(string $lang): array
{
    static $cache = [];
    if (!isset($cache[$lang])) {
        $messages = [];
        if (in_array($lang, I18N_LANGS, true)) {
            $file = __DIR__ . '/../lang/' . $lang . '.php';
            if (is_file($file)) {
                $loaded = require $file;
                if (is_array($loaded)) {
                    $messages = $loaded;
                }
            }
        }
        $cache[$lang] = $messages;
    }
    return $cache[$lang];
}

/**
 * Text for $key in the current language, falling back to English and then to the key itself.
 * {name} placeholders are replaced from $vars. Plain text: escape it when printing in HTML.
 */
function t(string $key, array $vars = [], ?string $lang = null): string
{
    $lang = $lang ?? i18n_lang();
    $text = i18n_messages($lang)[$key] ?? null;
    if (!is_string($text) || $text === '') {
        $text = i18n_messages('en')[$key] ?? $key;
    }
    if ($vars) {
        $pairs = [];
        foreach ($vars as $name => $value) {
            $pairs['{' . $name . '}'] = (string)$value;
        }
        $text = strtr($text, $pairs);
    }
    return $text;
}

/** Same as t(), already escaped for direct output in HTML. */
function te(string $key, array $vars = []): string
{
    return htmlspecialchars(t($key, $vars), ENT_QUOTES, 'UTF-8');
}

/**
 * Label of a fixed choice that is stored as a code: $group is interest, transport, type, pace,
 * tier, diet, visit, access, itype (item type or meal slot) or hotelkind (keys opt.<group>.<code>).
 * Unknown codes come back as "Title Case". $lang overrides the current language (the PDF uses it).
 */
function t_choice(string $group, ?string $value, ?string $lang = null): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '-';
    }
    $key = 'opt.' . $group . '.' . strtolower($value);
    $text = t($key, [], $lang);
    return $text !== $key ? $text : ucwords(str_replace(['_', '-'], ' ', $value));
}

/**
 * A date written in the traveller's language, without the intl extension. $date is anything
 * strtotime() reads (2027-02-22 or a date and time). $style:
 *   short          22 Feb 2027       zh: 2027年2月22日
 *   short_weekday  Mon, 22 Feb 2027  zh: 2027年2月22日 周一
 *   long           Monday, 22 February 2027   zh: 2027年2月22日 星期一
 *   month_year     February 2027     zh: 2027年2月
 * Month and weekday names and the patterns are in the language files (date.* keys). A text that
 * is not a date comes back unchanged. $lang overrides the current language.
 */
function i18n_date(string $date, string $style = 'short', ?string $lang = null): string
{
    $ts = strtotime($date);
    if ($ts === false) {
        return $date;
    }
    if (!in_array($style, ['short', 'short_weekday', 'long', 'month_year'], true)) {
        $style = 'short';
    }
    $month = (int)date('n', $ts);
    $weekday = (int)date('N', $ts);
    return t('date.fmt_' . $style, [
        'd' => (int)date('j', $ts),
        'y' => date('Y', $ts),
        'mon' => t('date.mon.' . $month, [], $lang),
        'month' => t('date.month.' . $month, [], $lang),
        'wd' => t('date.wd.' . $weekday, [], $lang),
        'wds' => t('date.wds.' . $weekday, [], $lang),
    ], $lang);
}

/**
 * Label of a row of the cost breakdown, which CostEstimationService writes in English
 * ("Transport (Car)", "Accommodation" and so on). Other labels come back unchanged.
 */
function i18n_cost_label(string $label, string $transportType, ?string $lang = null): string
{
    $fixed = [
        'Attraction / Entrance Fees' => 'trip.cl_attraction',
        'Accommodation' => 'trip.cl_accommodation',
        'Food & Meals' => 'trip.cl_food',
    ];
    if (isset($fixed[$label])) {
        return t($fixed[$label], [], $lang);
    }
    if (str_starts_with($label, 'Transport (')) {
        return t('trip.cl_transport', ['mode' => t_choice('transport', $transportType, $lang)], $lang);
    }
    return $label;
}

/** JSON object {key: text} of the given keys in the current language, safe inside <script>. */
function i18n_js(array $keys): string
{
    $out = [];
    foreach ($keys as $key) {
        $out[(string)$key] = t((string)$key);
    }
    $json = json_encode(
        $out === [] ? new stdClass() : $out,
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
    return $json === false ? '{}' : $json;
}

/** The small "EN | BM | 中文" switcher; links keep the current page and query, only lang changes. */
function i18n_switcher(): string
{
    $current = i18n_lang();
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    $path = (string)(parse_url($uri, PHP_URL_PATH) ?? '');
    if ($path === '') {
        $path = (string)($_SERVER['PHP_SELF'] ?? '');
    }
    $query = [];
    parse_str((string)(parse_url($uri, PHP_URL_QUERY) ?? ''), $query);
    unset($query['lang']);

    $labels = ['en' => 'EN', 'ms' => 'BM', 'zh' => '中文'];
    $names = ['en' => 'English', 'ms' => 'Bahasa Melayu', 'zh' => '简体中文'];

    $html = '<span class="lang-switcher" role="group" aria-label="' . te('common.language')
        . '" style="display:inline-flex;align-items:center;gap:6px;font-size:12px;line-height:1.4;">';
    $first = true;
    foreach ($labels as $code => $label) {
        if (!$first) {
            $html .= '<span aria-hidden="true" style="opacity:.5;">|</span>';
        }
        $first = false;
        $href = $path . '?' . http_build_query($query + ['lang' => $code]);
        $isCurrent = $code === $current;
        $style = 'color:inherit;text-decoration:none;padding:1px 2px;'
            . ($isCurrent ? 'font-weight:800;text-decoration:underline;' : 'opacity:.75;');
        $html .= '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" lang="' . $code
            . '" hreflang="' . $code . '" title="' . htmlspecialchars($names[$code], ENT_QUOTES, 'UTF-8')
            . '" style="' . $style . '"' . ($isCurrent ? ' aria-current="true"' : '') . '>'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
    }
    return $html . '</span>';
}

// Resolve the language now, so the cookie is sent before the page writes any output.
i18n_lang();
