<?php
// api/ollama_health.php
// Server-side Ollama connectivity check for deployment debugging.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../config/api_keys.php";

header("Content-Type: application/json; charset=utf-8");

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true) {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "Unauthorized."]);
    exit;
}

$baseUrl = defined("OLLAMA_BASE_URL") ? rtrim((string)OLLAMA_BASE_URL, "/") : "http://127.0.0.1:11434";
$model = defined("OLLAMA_MODEL") ? (string)OLLAMA_MODEL : "qwen2.5:3b";
$url = $baseUrl . "/api/tags";

$result = [
    "status" => "error",
    "php_version" => PHP_VERSION,
    "curl_enabled" => function_exists("curl_init"),
    "ollama_base_url" => $baseUrl,
    "ollama_model" => $model,
    "http_code" => 0,
    "message" => "",
];

if (!function_exists("curl_init")) {
    $result["message"] = "PHP cURL extension is not enabled.";
    echo json_encode($result, JSON_UNESCAPED_SLASHES);
    exit;
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_CONNECTTIMEOUT => 3,
    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
]);
$raw = curl_exec($ch);
$err = curl_error($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$result["http_code"] = $code;
if ($raw === false || $err !== "") {
    $result["message"] = $err !== "" ? $err : "No response from Ollama.";
    echo json_encode($result, JSON_UNESCAPED_SLASHES);
    exit;
}

$json = json_decode($raw, true);
if ($code < 200 || $code >= 300 || !is_array($json)) {
    $result["message"] = "Unexpected Ollama response.";
    $result["response_preview"] = substr($raw, 0, 300);
    echo json_encode($result, JSON_UNESCAPED_SLASHES);
    exit;
}

$models = [];
foreach (($json["models"] ?? []) as $row) {
    if (isset($row["name"])) $models[] = (string)$row["name"];
}

$result["status"] = "success";
$result["message"] = "PHP can reach Ollama.";
$result["installed_models"] = $models;
$result["model_available"] = in_array($model, $models, true);
echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
