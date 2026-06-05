<?php
// config/api_keys.php
// Central configuration for third-party services.
// Secrets must be provided through environment variables or a local .env file.

require_once __DIR__ . '/env_loader.php';

if (!function_exists('env_value')) {
    function env_value(string $key, $default = '')
    {
        $value = getenv($key);
        return $value === false ? $default : $value;
    }
}

define('GOOGLE_MAPS_API_KEY', trim((string) env_value('GOOGLE_MAPS_API_KEY', '')));
define('OPENWEATHER_API_KEY', trim((string) env_value('OPENWEATHER_API_KEY', '')));
define('SERPAPI_API_KEY', trim((string) env_value('SERPAPI_API_KEY', '')));
define('GEMINI_API_KEY', trim((string) env_value('GEMINI_API_KEY', '')));
define('GEMINI_MODEL', trim((string) env_value('GEMINI_MODEL', 'gemini-2.5-flash')));

define('SMTP_HOST', trim((string) env_value('SMTP_HOST', 'smtp.gmail.com')));
define('SMTP_PORT', (int) env_value('SMTP_PORT', 587));
define('SMTP_USER', trim((string) env_value('SMTP_USER', '')));
define('SMTP_PASS', (string) env_value('SMTP_PASS', ''));
define('SMTP_FROM', trim((string) env_value('SMTP_FROM', SMTP_USER)));
define('SMTP_FROM_NAME', trim((string) env_value('SMTP_FROM_NAME', 'Admin Smart Travel Itinerary Generator')));

// AI assistant. Gemini is used first when a key is configured; Ollama remains the local fallback.
define('OLLAMA_MODEL', trim((string) env_value('OLLAMA_MODEL', 'qwen2.5:3b')));
define('OLLAMA_BASE_URL', rtrim(trim((string) env_value('OLLAMA_BASE_URL', 'http://127.0.0.1:11434')), '/'));
define('OLLAMA_NUM_CTX', max(128, (int) env_value('OLLAMA_NUM_CTX', '512')));

// Page/API-specific hooks are intentionally loaded outside db_connect.php.
// The bootstrap itself checks the current business endpoint before doing anything.
if (isset($conn) && $conn instanceof mysqli) {
    require_once __DIR__ . '/business_request_bootstrap.php';
}
