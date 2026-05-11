<?php
// api/ai_itinerary.php
// Controlled Ollama-backed itinerary recommendation API. It never writes generated
// plans to the database unless the traveller explicitly clicks Save Itinerary.
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
if ($travellerId <= 0) {
    http_response_code(401);
    respond_error("Unauthorized.");
}

$action = strtolower(trim((string)($_POST["action"] ?? "generate")));
if ($action === "save") {
    save_ai_itinerary($conn, $travellerId);
}

generate_ai_itinerary($travellerId);

function generate_ai_itinerary(int $travellerId): void
{
    $input = validate_request_input($_POST);
    if (!empty($input["errors"])) {
        http_response_code(422);
        echo json_encode(["status" => "error", "message" => $input["errors"][0]], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $data = $input["data"];
    $model = defined("OLLAMA_MODEL") ? trim((string)OLLAMA_MODEL) : "qwen2.5:3b";
    if ($model === "") $model = "qwen2.5:3b";

    $prompt = build_itinerary_prompt($data);
    $result = call_ollama_chat($model, $prompt);

    if (($result["status"] ?? "") !== "success") {
        http_response_code(503);
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $content = (string)($result["content"] ?? "");
    $parsed = parse_ai_json($content);
    if (!is_array($parsed)) {
        echo json_encode([
            "status" => "error",
            "message" => "AI response was received, but it was not valid structured JSON. Please try again.",
            "source" => "ollama",
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    echo json_encode([
        "status" => "success",
        "source" => "ollama",
        "model" => $model,
        "request" => [
            "destination" => $data["destination"],
            "start_location" => $data["start_location"],
            "days" => $data["days"],
            "budget" => format_budget_range($data["budget_min"], $data["budget_max"]),
            "preferences" => build_preferences_json($data),
        ],
        "itinerary" => $parsed,
        "ai_response" => json_encode($parsed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function save_ai_itinerary(mysqli $conn, int $travellerId): void
{
    $input = validate_request_input($_POST);
    if (!empty($input["errors"])) {
        http_response_code(422);
        echo json_encode(["status" => "error", "message" => $input["errors"][0]], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $aiResponse = trim((string)($_POST["ai_response"] ?? ""));
    if ($aiResponse === "") {
        http_response_code(422);
        respond_error("No AI itinerary is available to save.");
    }

    $decoded = json_decode($aiResponse, true);
    if (!is_array($decoded)) {
        http_response_code(422);
        respond_error("AI itinerary format is invalid. Please generate the itinerary again.");
    }

    if (!table_exists($conn, "ai_itinerary_logs")) {
        http_response_code(500);
        respond_error("AI itinerary log table is missing. Please run migration_ai_itinerary_logs.sql.");
    }

    $data = $input["data"];
    $budget = format_budget_range($data["budget_min"], $data["budget_max"]);
    $preferences = build_preferences_json($data);

    $stmt = $conn->prepare("
        INSERT INTO ai_itinerary_logs
          (user_id, destination, start_location, days, budget, preferences, ai_response)
        VALUES
          (?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) {
        http_response_code(500);
        respond_error("Could not save AI itinerary.");
    }

    $stmt->bind_param(
        "ississs",
        $travellerId,
        $data["destination"],
        $data["start_location"],
        $data["days"],
        $budget,
        $preferences,
        $aiResponse
    );
    $ok = $stmt->execute();
    $savedId = (int)$stmt->insert_id;
    $stmt->close();

    if (!$ok) {
        http_response_code(500);
        respond_error("Could not save AI itinerary.");
    }

    echo json_encode([
        "status" => "success",
        "message" => "AI itinerary saved.",
        "id" => $savedId,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function validate_request_input(array $src): array
{
    $destination = sanitize_text((string)($src["destination"] ?? ""), 120);
    $startLocation = sanitize_text((string)($src["start_location"] ?? ""), 180);
    $daysRaw = trim((string)($src["days"] ?? ""));
    $budgetMinRaw = trim((string)($src["budget_min"] ?? ""));
    $budgetMaxRaw = trim((string)($src["budget_max"] ?? ""));
    $travelStyle = sanitize_enum((string)($src["travel_style"] ?? "normal"), ["budget", "normal", "luxury"], "normal");
    $transportMode = sanitize_enum((string)($src["transport_mode"] ?? "car"), ["car", "public_transport", "walking"], "car");
    $specialNotes = sanitize_text((string)($src["special_notes"] ?? ""), 700);

    $categories = $src["categories"] ?? [];
    if (!is_array($categories)) {
        $categories = [$categories];
    }
    $allowedCategories = ["culture", "nature", "food", "shopping", "family", "historical"];
    $cleanCategories = [];
    foreach ($categories as $cat) {
        $cat = sanitize_enum((string)$cat, $allowedCategories, "");
        if ($cat !== "" && !in_array($cat, $cleanCategories, true)) {
            $cleanCategories[] = $cat;
        }
    }

    $errors = [];
    if ($destination === "") $errors[] = "Destination cannot be empty.";
    if ($startLocation === "") $errors[] = "Start location cannot be empty.";
    if ($daysRaw === "" || !ctype_digit($daysRaw)) {
        $errors[] = "Number of days must be 1 to 7.";
    }
    $days = (int)$daysRaw;
    if ($days < 1 || $days > 7) {
        $errors[] = "Number of days must be 1 to 7.";
    }
    if ($budgetMinRaw === "" || !is_numeric($budgetMinRaw) || $budgetMaxRaw === "" || !is_numeric($budgetMaxRaw)) {
        $errors[] = "Budget must be numeric.";
    }
    $budgetMin = (float)$budgetMinRaw;
    $budgetMax = (float)$budgetMaxRaw;
    if ($budgetMin < 0 || $budgetMax <= 0 || $budgetMax < $budgetMin) {
        $errors[] = "Budget range is invalid.";
    }
    if (empty($cleanCategories)) {
        $cleanCategories[] = "culture";
    }

    return [
        "errors" => $errors,
        "data" => [
            "destination" => $destination,
            "start_location" => $startLocation,
            "days" => $days,
            "budget_min" => round($budgetMin, 2),
            "budget_max" => round($budgetMax, 2),
            "travel_style" => $travelStyle,
            "categories" => $cleanCategories,
            "transport_mode" => $transportMode,
            "special_notes" => $specialNotes,
        ],
    ];
}

function call_ollama_chat(string $model, string $prompt): array
{
    if (!function_exists("curl_init")) {
        return ["status" => "error", "message" => "PHP cURL is not enabled."];
    }

    $baseUrl = defined("OLLAMA_BASE_URL") ? trim((string)OLLAMA_BASE_URL) : "http://localhost:11434/api";
    $baseUrl = rtrim($baseUrl, "/");
    $url = str_ends_with($baseUrl, "/api") ? $baseUrl . "/chat" : $baseUrl . "/api/chat";

    $payload = [
        "model" => $model,
        "messages" => [
            [
                "role" => "system",
                "content" => "You are a travel itinerary assistant. Generate practical, realistic, budget-aware travel plans. Avoid impossible long-distance travel in one day. Return only valid JSON and no markdown.",
            ],
            [
                "role" => "user",
                "content" => $prompt,
            ],
        ],
        "stream" => false,
        "options" => [
            "temperature" => 0.4,
        ],
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ["Content-Type: application/json"],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT => 180,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);

    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $err !== "") {
        return [
            "status" => "error",
            "message" => "AI service is currently unavailable. Please make sure Ollama is running.",
            "source" => "ollama",
        ];
    }

    $json = json_decode($raw, true);
    if ($code === 404 || (is_array($json) && isset($json["error"]) && stripos((string)$json["error"], "not found") !== false)) {
        return [
            "status" => "error",
            "message" => "AI model is not installed. Please run: ollama pull " . $model,
            "source" => "ollama",
        ];
    }

    if ($code < 200 || $code >= 300) {
        return [
            "status" => "error",
            "message" => "AI service is currently unavailable. Please make sure Ollama is running.",
            "source" => "ollama",
        ];
    }

    if (!is_array($json) || !isset($json["message"]["content"])) {
        return [
            "status" => "error",
            "message" => "AI response is invalid. Please try again.",
            "source" => "ollama",
        ];
    }

    return [
        "status" => "success",
        "source" => "ollama",
        "content" => (string)$json["message"]["content"],
    ];
}

function build_itinerary_prompt(array $data): string
{
    $request = [
        "destination_state" => $data["destination"],
        "start_location" => $data["start_location"],
        "number_of_days" => $data["days"],
        "budget_range_rm" => [
            "min" => $data["budget_min"],
            "max" => $data["budget_max"],
        ],
        "travel_style" => $data["travel_style"],
        "preferred_categories" => $data["categories"],
        "transport_mode" => $data["transport_mode"],
        "special_notes" => $data["special_notes"],
    ];

    $schema = [
        "title" => "string",
        "summary" => "string",
        "days" => [
            [
                "day" => "number",
                "route_order" => ["place name"],
                "places" => [
                    [
                        "name" => "string",
                        "category" => "string",
                        "reason" => "string",
                        "estimated_transport_time" => "string",
                        "estimated_cost" => "string",
                    ],
                ],
                "food_suggestion" => "string",
                "hotel_suggestion" => "string",
                "estimated_day_cost" => "string",
                "explanation" => "string",
            ],
        ],
        "total_estimated_cost" => "string",
        "transport_notes" => "string",
        "why_suitable" => "string",
    ];

    return "Generate a realistic Malaysian cultural tourism itinerary for this request:\n"
        . json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        . "\n\nRules:\n"
        . "- Destination places must be inside the requested destination_state. The start_location is only the travel origin.\n"
        . "- Keep travel distance realistic for each day.\n"
        . "- Respect budget range and travel style.\n"
        . "- Use Malaysian Ringgit only. Format all costs as RM, never USD or other currencies.\n"
        . "- Include realistic inter-city transfer time from start_location to the destination before the first stop when relevant.\n"
        . "- For a 1-day trip from a far origin, keep the plan focused in one district and avoid too many stops.\n"
        . "- Do not book hotels, send emails, or claim a reservation was made.\n"
        . "- If public transport is selected, include realistic transfer/walking notes.\n"
        . "- Return only a valid JSON object matching this schema:\n"
        . json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function parse_ai_json(string $content): ?array
{
    $content = trim($content);
    $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
    $content = preg_replace('/\s*```$/', '', (string)$content);
    $decoded = json_decode((string)$content, true);
    if (is_array($decoded)) return $decoded;

    $start = strpos((string)$content, "{");
    $end = strrpos((string)$content, "}");
    if ($start !== false && $end !== false && $end > $start) {
        $slice = substr((string)$content, $start, $end - $start + 1);
        $decoded = json_decode($slice, true);
        if (is_array($decoded)) return $decoded;
    }
    return null;
}

function sanitize_text(string $value, int $maxLen): string
{
    $value = trim(strip_tags($value));
    $value = preg_replace('/\s+/', ' ', $value) ?? "";
    if (function_exists("mb_substr")) {
        return mb_substr($value, 0, $maxLen);
    }
    return substr($value, 0, $maxLen);
}

function sanitize_enum(string $value, array $allowed, string $default): string
{
    $value = strtolower(trim($value));
    return in_array($value, $allowed, true) ? $value : $default;
}

function format_budget_range(float $min, float $max): string
{
    return "RM " . number_format($min, 2) . " - RM " . number_format($max, 2);
}

function build_preferences_json(array $data): string
{
    return json_encode([
        "travel_style" => $data["travel_style"],
        "categories" => $data["categories"],
        "transport_mode" => $data["transport_mode"],
        "special_notes" => $data["special_notes"],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function table_exists(mysqli $conn, string $table): bool
{
    $table = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '$table'");
    return ($res && $res->num_rows > 0);
}

function respond_error(string $message): void
{
    echo json_encode(["status" => "error", "message" => $message], JSON_UNESCAPED_UNICODE);
    exit;
}
