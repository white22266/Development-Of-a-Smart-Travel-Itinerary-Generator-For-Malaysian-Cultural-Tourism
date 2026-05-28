<?php
// api/ai_preference_chat.php
// Ollama chat assistant for a selected traveller preference. This endpoint only
// advises; it does not create or update itinerary records.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . "/../config/db_connect.php";
require_once __DIR__ . "/../config/api_keys.php";

header("Content-Type: application/json; charset=utf-8");

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
    http_response_code(401);
    respond_error("Unauthorized.");
}

$travellerId = (int)($_SESSION["traveller_id"] ?? 0);
$preferenceId = (int)($_POST["preference_id"] ?? 0);
$message = sanitize_text((string)($_POST["message"] ?? ""), 900);

if ($travellerId <= 0) {
    http_response_code(401);
    respond_error("Unauthorized.");
}
if ($preferenceId <= 0) {
    http_response_code(422);
    respond_error("Please select a saved preference first.");
}
if ($message === "") {
    http_response_code(422);
    respond_error("Please type a question for the AI assistant.");
}

$preference = load_preference($conn, $travellerId, $preferenceId);
if (!$preference) {
    http_response_code(404);
    respond_error("Selected preference was not found.");
}

$model = defined("OLLAMA_MODEL") ? trim((string)OLLAMA_MODEL) : "qwen2.5:3b";
if ($model === "") $model = "qwen2.5:3b";

$result = call_ollama_chat($model, build_chat_prompt($preference, $message));
if (($result["status"] ?? "") !== "success") {
    http_response_code(503);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

echo json_encode([
    "status" => "success",
    "source" => "ollama",
    "model" => $model,
    "reply" => trim((string)($result["content"] ?? "")),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;

function load_preference(mysqli $conn, int $travellerId, int $preferenceId): ?array
{
    $stmt = $conn->prepare("
        SELECT preference_id, trip_days, budget, budget_tier, transport_type,
               traveller_type, travel_pace, dietary_preference, preferred_visit_time,
               accessibility_needs, interests, preferred_states, preferred_districts
        FROM traveller_preferences
        WHERE preference_id = ? AND traveller_id = ?
        LIMIT 1
    ");
    if (!$stmt) return null;
    $stmt->bind_param("ii", $preferenceId, $travellerId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return null;

    $row["interests"] = preference_interest_csv($conn, $preferenceId, (string)($row["interests"] ?? ""));
    $row["preferred_states"] = preference_state_csv($conn, $preferenceId, (string)($row["preferred_states"] ?? ""));
    return $row;
}

function build_chat_prompt(array $pref, string $message): string
{
    $context = [
        "selected_preference_id" => (int)$pref["preference_id"],
        "trip_days" => (int)$pref["trip_days"],
        "budget_rm" => (float)$pref["budget"],
        "budget_tier" => $pref["budget_tier"] ?? "normal",
        "transport_type" => $pref["transport_type"] ?? "car",
        "traveller_type" => $pref["traveller_type"] ?? "solo",
        "travel_pace" => $pref["travel_pace"] ?? "normal",
        "dietary_preference" => $pref["dietary_preference"] ?? "none",
        "preferred_visit_time" => $pref["preferred_visit_time"] ?? "any",
        "accessibility_needs" => $pref["accessibility_needs"] ?? "",
        "interests" => $pref["interests"] ?? "",
        "preferred_states" => $pref["preferred_states"] ?? "",
        "preferred_districts" => $pref["preferred_districts"] ?? "",
    ];

    return "You are an AI Travel Assistant inside a Malaysian cultural tourism itinerary system.\n"
        . "Use the selected saved traveller preference as context. Answer the traveller's question with practical, concise advice.\n"
        . "Do not ask the user to re-enter all preferences. Do not claim bookings are made. Do not write to the database. If recommending changes, say they should generate or review the itinerary after confirming.\n"
        . "If festival activities are mentioned, remind that festivals should only be included when their dates match the travel date.\n\n"
        . "Selected preference:\n"
        . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        . "\n\nTraveller question:\n"
        . $message;
}

function call_ollama_chat(string $model, string $prompt): array
{
    if (!function_exists("curl_init")) {
        return ["status" => "error", "message" => "PHP cURL is not enabled."];
    }

    $baseUrl = defined("OLLAMA_BASE_URL") ? trim((string)OLLAMA_BASE_URL) : "http://localhost:11434";
    $baseUrl = rtrim($baseUrl, "/");
    $url = str_ends_with($baseUrl, "/api") ? $baseUrl . "/chat" : $baseUrl . "/api/chat";

    $payload = [
        "model" => $model,
        "messages" => [
            [
                "role" => "system",
                "content" => "You are a controlled travel assistant. Give useful itinerary advice, route checks, budget checks, cultural notes, and improvement suggestions. Keep answers clear and not too long.",
            ],
            [
                "role" => "user",
                "content" => $prompt,
            ],
        ],
        "stream" => false,
        "options" => ["temperature" => 0.45],
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ["Content-Type: application/json"],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT => 120,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);

    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $err !== "") {
        return ["status" => "error", "message" => "AI service is currently unavailable.", "source" => "ollama"];
    }

    $json = json_decode($raw, true);
    if ($code === 404 || (is_array($json) && isset($json["error"]) && stripos((string)$json["error"], "not found") !== false)) {
        return ["status" => "error", "message" => "AI model is not installed. Please run: ollama pull " . $model, "source" => "ollama"];
    }
    if ($code < 200 || $code >= 300 || !is_array($json) || !isset($json["message"]["content"])) {
        return ["status" => "error", "message" => "AI response is invalid. Please try again.", "source" => "ollama"];
    }

    return ["status" => "success", "content" => (string)$json["message"]["content"]];
}

function preference_interest_csv(mysqli $conn, int $preferenceId, string $fallbackCsv): string
{
    $values = [];
    if (table_exists($conn, "traveller_preference_interests") && table_exists($conn, "travel_interests")) {
        $stmt = $conn->prepare("
            SELECT ti.interest_code
            FROM traveller_preference_interests tpi
            JOIN travel_interests ti ON ti.interest_id = tpi.interest_id
            WHERE tpi.preference_id = ?
            ORDER BY ti.interest_code
        ");
        if ($stmt) {
            $stmt->bind_param("i", $preferenceId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) $values[] = (string)$row["interest_code"];
            $stmt->close();
        }
    }
    return empty($values) ? trim($fallbackCsv) : implode(",", array_values(array_unique($values)));
}

function preference_state_csv(mysqli $conn, int $preferenceId, string $fallbackCsv): string
{
    $values = [];
    if (table_exists($conn, "traveller_preference_states") && table_exists($conn, "malaysia_states")) {
        $stmt = $conn->prepare("
            SELECT ms.state_name
            FROM traveller_preference_states tps
            JOIN malaysia_states ms ON ms.state_id = tps.state_id
            WHERE tps.preference_id = ?
            ORDER BY ms.state_name
        ");
        if ($stmt) {
            $stmt->bind_param("i", $preferenceId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) $values[] = (string)$row["state_name"];
            $stmt->close();
        }
    }
    return empty($values) ? trim($fallbackCsv) : implode(",", array_values(array_unique($values)));
}

function table_exists(mysqli $conn, string $table): bool
{
    $table = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '$table'");
    return ($res && $res->num_rows > 0);
}

function sanitize_text(string $value, int $maxLen): string
{
    $value = trim(strip_tags($value));
    $value = preg_replace('/\s+/', ' ', $value) ?? "";
    if (function_exists("mb_substr")) return mb_substr($value, 0, $maxLen);
    return substr($value, 0, $maxLen);
}

function respond_error(string $message): void
{
    echo json_encode(["status" => "error", "message" => $message], JSON_UNESCAPED_UNICODE);
    exit;
}
