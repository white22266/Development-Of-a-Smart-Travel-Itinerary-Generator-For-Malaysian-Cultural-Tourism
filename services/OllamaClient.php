<?php
// services/OllamaClient.php
// The single HTTP client for the local Ollama server. Every AI feature calls chat()
// instead of building its own cURL request. Settings come from .env (see .env.example);
// per-call options override them.

class OllamaClient
{
    private string $model;
    private string $baseUrl;

    public function __construct(?string $model = null, ?string $baseUrl = null)
    {
        $this->model = trim((string)($model ?: self::setting('OLLAMA_MODEL', 'qwen2.5:3b')));
        $this->baseUrl = rtrim(trim((string)($baseUrl ?: self::setting('OLLAMA_BASE_URL', 'http://127.0.0.1:11434'))), '/');
    }

    public function model(): string
    {
        return $this->model;
    }

    /**
     * Send one system + user message pair and return the reply.
     *
     * Options: num_ctx, num_predict, temperature, timeout (seconds, at least 2), keep_alive, format ('json').
     * When the server refused a connection, later calls fail at once for a few seconds.
     *
     * @return array{ok: bool, text: string, done_reason: string, error: string, timed_out: bool, unreachable: bool}
     *   On failure `error` holds the technical reason for the log; never show it to travellers.
     */
    public function chat(string $system, string $user, array $options = []): array
    {
        if (!function_exists('curl_init')) {
            return self::failure('PHP cURL extension is not enabled.');
        }

        $timeout = (int)($options['timeout'] ?? self::setting('OLLAMA_TIMEOUT', 60));
        $timeout = max(2, min(300, $timeout));
        $connectTimeout = max(1, min(10, (int)self::setting('OLLAMA_CONNECT_TIMEOUT', 3)));
        $numCtx = (int)($options['num_ctx'] ?? self::setting('OLLAMA_NUM_CTX', 4096));

        $payload = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            'stream' => false,
            'keep_alive' => (string)($options['keep_alive'] ?? self::setting('OLLAMA_KEEP_ALIVE', '30m')),
            'options' => [
                'temperature' => (float)($options['temperature'] ?? 0.2),
                'num_ctx' => max(1024, min(16384, $numCtx)),
                'num_predict' => max(32, min(2048, (int)($options['num_predict'] ?? 300))),
            ],
        ];
        if (($options['format'] ?? '') === 'json') {
            $payload['format'] = 'json';
        }

        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payloadJson === false) {
            return self::failure('Could not encode the request as JSON.');
        }

        $url = str_ends_with($this->baseUrl, '/api') ? $this->baseUrl . '/chat' : $this->baseUrl . '/api/chat';

        // The server refused a connection a moment ago: answer at once instead of waiting again.
        if ($this->recentlyUnreachable()) {
            return self::failure("Ollama at {$this->baseUrl} was unreachable a moment ago; not retrying yet.", false, true);
        }
        $start = microtime(true);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $payloadJson,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_NOSIGNAL => true,
        ]);
        $raw = curl_exec($ch);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $seconds = round(microtime(true) - $start, 2);

        if ($raw === false || $curlError !== '') {
            $timedOut = $curlErrno === CURLE_OPERATION_TIMEDOUT;
            // Refused, or no answer within the connect timeout: the server is not running.
            $unreachable = $curlErrno === CURLE_COULDNT_CONNECT
                || ($timedOut && $seconds <= $connectTimeout + 0.5);
            if ($unreachable) {
                $this->markUnreachable();
                $timedOut = false;
            }
            return self::failure("Request to {$url} failed after {$seconds}s: {$curlError}", $timedOut, $unreachable);
        }

        $json = json_decode((string)$raw, true);
        $apiError = is_array($json) ? trim((string)($json['error'] ?? '')) : '';

        if ($httpCode === 404 || stripos($apiError, 'not found') !== false) {
            return self::failure("Model '{$this->model}' is not installed. Run: ollama pull {$this->model}");
        }
        if ($httpCode < 200 || $httpCode >= 300 || !is_array($json)) {
            $detail = $apiError !== '' ? $apiError : substr((string)$raw, 0, 300);
            return self::failure("Ollama returned HTTP {$httpCode}: {$detail}");
        }

        $text = trim((string)($json['message']['content'] ?? ''));
        if ($text === '') {
            return self::failure('Ollama returned an empty reply.');
        }

        return [
            'ok' => true,
            'text' => $text,
            'done_reason' => strtolower((string)($json['done_reason'] ?? '')),
            'error' => '',
            'timed_out' => false,
            'unreachable' => false,
        ];
    }

    /** Seconds a refused connection is remembered (OLLAMA_DOWN_RETRY_SECONDS, default 20). */
    private function downMarkerFile(): string
    {
        return rtrim(sys_get_temp_dir(), '\/') . DIRECTORY_SEPARATOR . 'ollama_down_' . md5($this->baseUrl) . '.flag';
    }

    private function recentlyUnreachable(): bool
    {
        $file = $this->downMarkerFile();
        $retry = max(0, (int)self::setting('OLLAMA_DOWN_RETRY_SECONDS', 20));
        $mtime = @filemtime($file);
        return $retry > 0 && $mtime !== false && (time() - $mtime) < $retry;
    }

    private function markUnreachable(): void
    {
        @touch($this->downMarkerFile());
    }

    /**
     * A constant defined in config/api_keys.php wins, then the environment (.env), then the default.
     */
    public static function setting(string $key, $default)
    {
        if (defined($key)) {
            return constant($key);
        }
        $value = getenv($key);
        return ($value === false || trim((string)$value) === '') ? $default : $value;
    }

    private static function failure(string $error, bool $timedOut = false, bool $unreachable = false): array
    {
        return ['ok' => false, 'text' => '', 'done_reason' => '', 'error' => $error, 'timed_out' => $timedOut, 'unreachable' => $unreachable];
    }
}
