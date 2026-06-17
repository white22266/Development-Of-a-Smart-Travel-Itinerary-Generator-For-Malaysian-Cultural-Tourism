<?php
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/config/api_keys.php';

$model = defined('OLLAMA_MODEL') ? OLLAMA_MODEL : 'qwen2.5:3b';
$baseUrl = defined('OLLAMA_BASE_URL') ? OLLAMA_BASE_URL : 'http://127.0.0.1:11434';
$url = rtrim($baseUrl, '/') . '/api/chat';

echo "Ollama connectivity test\n";
echo "========================\n";
echo "Model: " . $model . "\n";
echo "URL: " . $url . "\n";
echo "PHP cURL enabled: " . (function_exists('curl_init') ? 'yes' : 'no') . "\n\n";

if (!function_exists('curl_init')) {
    echo "ERROR: PHP cURL is disabled. Enable extension=curl in php.ini, then restart Apache.\n";
    exit;
}

$payload = [
    'model' => $model,
    'messages' => [
        ['role' => 'user', 'content' => 'Say hello in one short sentence.'],
    ],
    'stream' => false,
    'options' => [
        'temperature' => 0.2,
        'num_predict' => 50,
    ],
];

$startedAt = microtime(true);
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    CURLOPT_TIMEOUT => 10,
    CURLOPT_CONNECTTIMEOUT => 2,
    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
    CURLOPT_NOSIGNAL => true,
]);

$raw = curl_exec($ch);
$err = curl_error($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$elapsed = round(microtime(true) - $startedAt, 2);

echo "Elapsed: " . $elapsed . " second(s)\n";
echo "HTTP Code: " . $code . "\n";
echo "cURL Error: " . ($err !== '' ? $err : 'none') . "\n\n";
echo "Response:\n";
echo $raw !== false && $raw !== '' ? $raw : '[empty response]';
