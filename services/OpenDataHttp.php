<?php
// services/OpenDataHttp.php
// HTTP access to the free, open services this app uses instead of paid APIs:
// OpenStreetMap (Overpass, Nominatim, Photon), Wikimedia (Wikidata, Wikipedia, Commons),
// OSRM routing and Open-Meteo weather. No API keys are involved.
//
// These services are free because they are used lightly. So every response is cached on
// disk, requests to one host are spaced out, and each request names this application.

class OpenDataHttp
{
    /** Minimum seconds between two requests to the same host (from the usage policies of the hosts). */
    private const HOST_INTERVAL = [
        'nominatim.openstreetmap.org' => 1.1,
        'photon.komoot.io' => 0.5,
        'overpass-api.de' => 2.0,
        'overpass.private.coffee' => 2.0,
        'overpass.kumi.systems' => 2.0,
        'router.project-osrm.org' => 1.1,
        'routing.openstreetmap.de' => 1.1,
    ];
    /** Wikipedia, Wikidata and Commons, whatever the subdomain. */
    private const WIKIMEDIA_INTERVAL = 1.0;
    private const DEFAULT_INTERVAL = 0.2;

    private static string $lastError = '';
    private static bool $patient = false;

    /**
     * Background jobs can afford to wait: when a service answers "slow down" (HTTP 429 or 503)
     * they wait as long as it asks and try again. Page requests never wait; they fall back to
     * a cached copy.
     */
    public static function setPatient(bool $patient): void
    {
        self::$patient = $patient;
    }

    public static function isPatient(): bool
    {
        return self::$patient;
    }

    /**
     * GET a JSON document.
     *
     * @param int   $ttl     seconds a cached copy stays fresh (0 = never cache)
     * @param array $options timeout (s); cache_key; namespace (cache folder, default: the host);
     *                       headers (list); stale_ok (default true: serve an expired copy when the
     *                       request fails); validate (callable(array): bool, a response that fails
     *                       it is treated as an error and not cached); retries (how often to try
     *                       again after HTTP 429/503: default 2 in background jobs, otherwise 0)
     */
    public static function getJson(string $url, int $ttl, array $options = []): ?array
    {
        return self::request('GET', $url, null, $ttl, $options);
    }

    /** POST form fields and read a JSON document back. Same options as getJson(). */
    public static function postFormJson(string $url, array $fields, int $ttl, array $options = []): ?array
    {
        return self::request('POST', $url, $fields, $ttl, $options);
    }

    /** Why the last call returned null. For logs only; never show it to travellers. */
    public static function lastError(): string
    {
        return self::$lastError;
    }

    /** A cached response of any age, or null. For callers that try several mirrors themselves. */
    public static function cachedJson(string $namespace, string $cacheKey): ?array
    {
        $file = self::cacheFile($namespace, $cacheKey);
        if (!is_file($file)) return null;
        $json = json_decode((string)file_get_contents($file), true);
        return is_array($json) ? $json : null;
    }

    /**
     * Download a binary file (an image) to $target. Returns the MIME type on success.
     * The caller decides which hosts are allowed.
     */
    public static function downloadFile(string $url, string $target, int $maxBytes = 8000000): ?string
    {
        self::$lastError = '';
        if (!function_exists('curl_init')) {
            self::$lastError = 'PHP cURL extension is not enabled.';
            return null;
        }
        [$body, $code, $error, $mime] = self::send($url, [CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 60], self::$patient ? 2 : 0);

        if (!is_string($body) || $code !== 200) {
            self::$lastError = "Download failed for {$url}: HTTP {$code} {$error}";
            return null;
        }
        if (strlen($body) < 500 || strlen($body) > $maxBytes) {
            self::$lastError = "Download for {$url} has an unexpected size (" . strlen($body) . " bytes).";
            return null;
        }
        if (@file_put_contents($target, $body) === false) {
            self::$lastError = "Could not write {$target}.";
            return null;
        }
        return $mime;
    }

    private static function request(string $method, string $url, ?array $fields, int $ttl, array $options): ?array
    {
        self::$lastError = '';
        if (!function_exists('curl_init')) {
            self::$lastError = 'PHP cURL extension is not enabled.';
            return null;
        }

        $host = (string)parse_url($url, PHP_URL_HOST);
        $key = (string)($options['cache_key'] ?? sha1($method . '|' . $url . '|' . json_encode($fields)));
        $cacheFile = self::cacheFile((string)($options['namespace'] ?? $host), $key);

        if ($ttl > 0 && is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
            $cached = json_decode((string)file_get_contents($cacheFile), true);
            if (is_array($cached)) return $cached;
        }

        $curlOptions = [
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => (int)($options['timeout'] ?? 20),
            CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $options['headers'] ?? []),
            CURLOPT_ENCODING => '',
        ];
        if ($method === 'POST') {
            $curlOptions[CURLOPT_POST] = true;
            $curlOptions[CURLOPT_POSTFIELDS] = http_build_query($fields ?? []);
        }
        [$raw, $code, $error] = self::send($url, $curlOptions, (int)($options['retries'] ?? (self::$patient ? 2 : 0)));

        $json = is_string($raw) ? json_decode($raw, true) : null;
        $ok = $code >= 200 && $code < 300 && is_array($json);
        $valid = $ok && (!isset($options['validate']) || (bool)$options['validate']($json));
        if ($valid) {
            if ($ttl > 0) {
                $tmp = $cacheFile . '.' . bin2hex(random_bytes(4)) . '.tmp';
                if (@file_put_contents($tmp, $raw) !== false) {
                    @rename($tmp, $cacheFile);
                }
            }
            return $json;
        }

        self::$lastError = $ok
            ? "{$method} {$host} returned an unusable response."
            : "{$method} {$host} failed: HTTP {$code} {$error}";
        error_log('OpenDataHttp: ' . self::$lastError);

        // An expired copy is better than nothing when the service is down.
        if (($options['stale_ok'] ?? true) && is_file($cacheFile)) {
            $stale = json_decode((string)file_get_contents($cacheFile), true);
            if (is_array($stale)) return $stale;
        }
        return null;
    }

    /**
     * One HTTPS request, repeated when the server asks this client to slow down.
     *
     * @return array{0: string|false, 1: int, 2: string, 3: string} body, HTTP status, cURL error, content type
     */
    private static function send(string $url, array $curlOptions, int $retries): array
    {
        $host = (string)parse_url($url, PHP_URL_HOST);
        for ($attempt = 0; ; $attempt++) {
            self::throttle($host);

            $retryAfter = null;
            $ch = curl_init($url);
            curl_setopt_array($ch, $curlOptions + [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_USERAGENT => self::userAgent(),
                CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$retryAfter): int {
                    if (stripos($line, 'Retry-After:') === 0) $retryAfter = trim(substr($line, 12));
                    return strlen($line);
                },
            ]);
            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $type = strtolower(trim(explode(';', (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE))[0]));
            $error = curl_error($ch);
            curl_close($ch);

            if (!in_array($code, [429, 503], true) || $attempt >= $retries) {
                return [$body, $code, $error, $type];
            }
            $wait = self::retryDelay($retryAfter, $attempt);
            error_log("OpenDataHttp: {$host} answered HTTP {$code}; waiting {$wait}s before trying again.");
            sleep($wait);
        }
    }

    /** Seconds to wait before a retry: what the server asked for, kept between 5 and 120. */
    private static function retryDelay(?string $retryAfter, int $attempt): int
    {
        $seconds = 20 * ($attempt + 1);
        if ($retryAfter !== null && ctype_digit($retryAfter)) {
            $seconds = (int)$retryAfter;
        } elseif ($retryAfter !== null && ($time = strtotime($retryAfter)) !== false) {
            $seconds = $time - time();
        }
        return max(5, min(120, $seconds));
    }

    /** Wait until this host may be called again. Works across concurrent PHP requests. */
    private static function throttle(string $host): void
    {
        $interval = self::intervalFor($host);
        $lockFile = self::cacheDir('throttle') . '/' . preg_replace('/[^a-z0-9.-]/i', '_', $host) . '.lock';
        $handle = @fopen($lockFile, 'c+');
        if (!$handle) return;
        if (flock($handle, LOCK_EX)) {
            $last = (float)stream_get_contents($handle);
            $wait = $last + $interval - microtime(true);
            if ($wait > 0) usleep((int)($wait * 1000000));
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string)microtime(true));
            fflush($handle);
            flock($handle, LOCK_UN);
        }
        fclose($handle);
    }

    private static function intervalFor(string $host): float
    {
        if (isset(self::HOST_INTERVAL[$host])) return self::HOST_INTERVAL[$host];
        if (preg_match('/(^|\.)(wikipedia|wikidata|wikimedia)\.org$/', $host)) return self::WIKIMEDIA_INTERVAL;
        return self::DEFAULT_INTERVAL;
    }

    private static function cacheFile(string $namespace, string $key): string
    {
        return self::cacheDir(preg_replace('/[^a-z0-9.-]/i', '_', $namespace))
            . '/' . preg_replace('/[^a-z0-9_-]/i', '_', $key) . '.json';
    }

    /** storage/cache/<name>, created on first use. storage/ is blocked from the web by .htaccess. */
    private static function cacheDir(string $name): string
    {
        $dir = dirname(__DIR__) . '/storage/cache/' . $name;
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    /** OpenStreetMap and Wikimedia ask every client to identify itself and give a contact. */
    private static function userAgent(): string
    {
        $contact = trim((string)(getenv('OPEN_DATA_CONTACT') ?: ''));
        return 'SmartTravelItineraryGenerator/1.0 (' . ($contact !== '' ? $contact : 'self-hosted travel planner') . ')';
    }
}
