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

define('SMTP_HOST', trim((string) env_value('SMTP_HOST', 'smtp.gmail.com')));
define('SMTP_PORT', (int) env_value('SMTP_PORT', 587));
define('SMTP_USER', trim((string) env_value('SMTP_USER', '')));
define('SMTP_PASS', (string) env_value('SMTP_PASS', ''));
define('SMTP_FROM', trim((string) env_value('SMTP_FROM', SMTP_USER)));
define('SMTP_FROM_NAME', trim((string) env_value('SMTP_FROM_NAME', 'Admin Smart Travel Itinerary Generator')));

// Local AI for traveller chatbox and AI itinerary assistant.
define('OLLAMA_MODEL', trim((string) env_value('OLLAMA_MODEL', 'qwen2.5:3b')));
define('OLLAMA_BASE_URL', rtrim(trim((string) env_value('OLLAMA_BASE_URL', 'http://localhost:11434')), '/'));
