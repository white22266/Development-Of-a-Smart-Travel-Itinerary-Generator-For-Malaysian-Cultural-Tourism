<?php
// config/api_keys.php
// CHANGED: central place to store API keys
define("GOOGLE_MAPS_API_KEY", "AIzaSyBSQiDWcPpKeLEJAwCe2jcKWAnlTDKnHT8");
define("OPENWEATHER_API_KEY", "72e6d568ecd49f6d56f0f7fbd0a8f04c");
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'adminpeck2222@gmail.com');
define('SMTP_PASS', 'vuuu imdy uqrl uwro'); // 注意：是 App Password，不是邮箱登录密码
define('SMTP_FROM', 'adminpeck2222@gmail.com');
define('SMTP_FROM_NAME', 'Admin Smart Travel Itinerary Generator');

// Optional: add your OpenAI API key here to enable live AI answers.
// Leave empty for the built-in local fallback used during offline demos.
if (!defined('OPENAI_API_KEY')) define('OPENAI_API_KEY', '');
if (!defined('OPENAI_MODEL')) define('OPENAI_MODEL', 'gpt-4.1-mini');
