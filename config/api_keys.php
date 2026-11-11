<?php
// config/api_keys.php
// Settings for the two things this app talks to that need them: the mail server (SMTP) and the
// local AI model (Ollama). Maps, routing, place data and weather come from open services that
// need no key (see services/OpenDataHttp.php). Secrets come from environment variables or a
// local .env file, never from this file.

require_once __DIR__ . '/env_loader.php';

if (!function_exists('env_value')) {
    function env_value(string $key, $default = '')
    {
        $value = getenv($key);
        return $value === false ? $default : $value;
    }
}

if (!function_exists('set_default_env')) {
    function set_default_env(string $key, string $value): void
    {
        if (getenv($key) === false || trim((string)getenv($key)) === '') {
            putenv($key . '=' . $value);
        }
    }
}

define('SMTP_HOST', trim((string) env_value('SMTP_HOST', 'smtp.gmail.com')));
define('SMTP_PORT', (int) env_value('SMTP_PORT', 587));
define('SMTP_USER', trim((string) env_value('SMTP_USER', '')));
define('SMTP_PASS', (string) env_value('SMTP_PASS', ''));
define('SMTP_FROM', trim((string) env_value('SMTP_FROM', SMTP_USER)));
define('SMTP_FROM_NAME', trim((string) env_value('SMTP_FROM_NAME', 'Admin Smart Travel Itinerary Generator')));

// Local AI (Ollama). services/OllamaClient.php reads these; see .env.example.
define('OLLAMA_MODEL', trim((string) env_value('OLLAMA_MODEL', 'qwen2.5:3b')));
define('OLLAMA_BASE_URL', rtrim(trim((string) env_value('OLLAMA_BASE_URL', 'http://127.0.0.1:11434')), '/'));
define('OLLAMA_NUM_CTX', max(1024, (int) env_value('OLLAMA_NUM_CTX', '4096')));

// Admin report AI defaults: try Ollama first, then use the local deterministic report if Ollama is unavailable or too slow.
// num_predict controls the generated answer length. Keep it high enough for all required report headings.
set_default_env('ADMIN_AI_FAST_MODE', 'false');
set_default_env('ADMIN_AI_TIMEOUT', '60');
set_default_env('ADMIN_AI_NUM_PREDICT', '700');
set_default_env('ADMIN_AI_NUM_CTX', (string) OLLAMA_NUM_CTX);
set_default_env('ADMIN_AI_KEEP_ALIVE', '30m');
