<?php
// config/api_keys.php
// CHANGED: central place to store API keys
define("GOOGLE_MAPS_API_KEY", "AIzaSyB2FuPDTk3Ckde7xiS1YUkr94LkIq1j9OI");
define("OPENWEATHER_API_KEY", "72e6d568ecd49f6d56f0f7fbd0a8f04c");
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'adminpeck2222@gmail.com');
define('SMTP_PASS', 'vuuu imdy uqrl uwro'); // 注意：是 App Password，不是邮箱登录密码
define('SMTP_FROM', 'adminpeck2222@gmail.com');
define('SMTP_FROM_NAME', 'Admin Smart Travel Itinerary Generator');

// AI provider for the traveller AI chatbox.
// Supported: ollama, openai, gemini. Ollama runs locally and does not need a paid API key.
define('AI_PROVIDER', 'ollama');
define('OLLAMA_MODEL', 'qwen2.5:3b');
define('OLLAMA_BASE_URL', 'http://localhost:11434');
