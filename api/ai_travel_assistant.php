<?php
// General AI Travel Assistant endpoint.
// Handles conversational questions, trip date detection, weather checks, and explicit date/origin confirmations.
// Database changes are only made by explicit confirm actions.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../config/db_connect.php";
require_once "../config/api_keys.php";
require_once "../config/ai_language_guard.php";
require_once "../services/AiTravelAssistantService.php";

header("Content-Type: application/json; charset=utf-8");

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
    http_response_code(401);
    echo json_encode(["status" => "error", "answer" => "Unauthorized."]);
    exit;
}

$travellerId = (int)($_SESSION["traveller_id"] ?? 0);
$itineraryId = (int)($_POST["itinerary_id"] ?? 0);
$action = trim((string)($_POST["action"] ?? "chat"));
$message = trim((string)($_POST["message"] ?? ""));
if ($action === "chat") {
    ai_reject_chinese_json($message, "answer");
}

if ($travellerId <= 0 || $itineraryId <= 0) {
    http_response_code(400);
    echo json_encode(["status" => "error", "answer" => "Invalid request."]);
    exit;
}

$itinerary = load_itinerary($conn, $itineraryId, $travellerId);
if (!$itinerary) {
    http_response_code(404);
    echo json_encode(["status" => "error", "answer" => "Itinerary not found."]);
    exit;
}

if ($action === "confirm_date") {
    $date = parse_trip_date((string)($_POST["start_date"] ?? ""));
    if (!$date) {
        echo json_encode(["status" => "error", "answer" => "I could not understand that trip date. Please use a valid date."], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $stmt = $conn->prepare("UPDATE itineraries SET start_date = ? WHERE itinerary_id = ? AND traveller_id = ?");
    $stmt->bind_param("sii", $date, $itineraryId, $travellerId);
    $stmt->execute();
    $stmt->close();

    echo json_encode([
        "status" => "success",
        "answer" => "Trip start date updated to " . format_display_date($date) . ". Reloading the itinerary with the confirmed date.",
        "reload" => true,
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === "confirm_origin") {
    $originName = trim((string)($_POST["origin_name"] ?? ""));
    $originLat = (float)($_POST["origin_lat"] ?? 0);
    $originLng = (float)($_POST["origin_lng"] ?? 0);
    if ($originName === "" || !is_finite($originLat) || !is_finite($originLng) || $originLat == 0.0 || $originLng == 0.0) {
        echo json_encode(["status" => "error", "answer" => "I could not confirm that starting location. Please choose a valid map location."], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $stmt = $conn->prepare("UPDATE itineraries SET origin_name = ?, origin_lat = ?, origin_lng = ? WHERE itinerary_id = ? AND traveller_id = ?");
    $stmt->bind_param("sddii", $originName, $originLat, $originLng, $itineraryId, $travellerId);
    $stmt->execute();
    $stmt->close();

    echo json_encode([
        "status" => "success",
        "answer" => "Starting location updated to " . $originName . ". Reloading the itinerary with the confirmed origin.",
        "reload" => true,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($message === "") {
    echo json_encode(["status" => "error", "answer" => "Please type a question or trip detail."], JSON_UNESCAPED_SLASHES);
    exit;
}

$items = load_itinerary_items($conn, $itineraryId);
$parsedDate = parse_trip_date($message);
$parsedOrigin = extract_origin_name($message);
$pendingActions = [];

// Only create save/update confirmations when the traveller clearly asks to change saved data.
// Do not infer updates from general questions such as "explain the trip" or from the AI's own reply.
if ($parsedDate && is_explicit_date_update_message($message) && !same_trip_date($parsedDate, (string)($itinerary["start_date"] ?? ""))) {
    $pendingActions[] = [
        "type" => "update_start_date",
        "start_date" => $parsedDate,
        "label" => format_display_date($parsedDate),
        "summary" => "Update this itinerary start date to " . format_display_date($parsedDate),
    ];
}

if ($parsedOrigin !== "" && is_explicit_origin_update_message($message) && !same_origin_name($parsedOrigin, (string)($itinerary["origin_name"] ?? ""))) {
    $pendingActions[] = [
        "type" => "update_origin",
        "origin_name" => $parsedOrigin,
        "label" => $parsedOrigin,
        "summary" => "Update starting location to " . $parsedOrigin,
    ];
}

$pendingAction = $pendingActions[0] ?? null;

if (is_weather_question($message)) {
    $weatherDate = $parsedDate ?: (string)($itinerary["start_date"] ?? "");
    $answer = build_weather_answer($items, $weatherDate);
    if ($pendingAction) {
        $answer .= "\n\nI detected " . describe_pending_action($pendingAction) . ". Please confirm if this is correct.";
    }
    log_ai_chat($conn, $itineraryId, $travellerId, $message, $answer);
    echo json_encode([
        "status" => "success",
        "answer" => $answer,
        "pending_action" => $pendingAction,
        "pending_actions" => $pendingActions,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($pendingAction) {
    $answer = "I detected " . describe_pending_action($pendingAction) . ". Click the confirmation button to update the itinerary. I will not save it until you confirm.";
    log_ai_chat($conn, $itineraryId, $travellerId, $message, $answer);
    echo json_encode([
        "status" => "success",
        "answer" => $answer,
        "pending_action" => $pendingAction,
        "pending_actions" => $pendingActions,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$context = [
    "assistant_role" => "conversational travel planning helper",
    "safety_rules" => [
        "Use the current itinerary as the source of truth.",
        "The saved start date and saved starting location in the itinerary are already confirmed unless they are empty.",
        "When answering summary or explanation questions, do not ask the traveller to reconfirm existing start date, existing starting location, or hotel.",
        "Only suggest a confirmation button when the traveller clearly asks to update a saved field.",
        "Do not say anything has been saved or confirmed unless the system returns a confirmation result.",
        "Do not suggest a rejected state or unrelated state. Keep recommendations aligned with the itinerary states unless the user asks otherwise.",
        "Use plain text only. Avoid markdown symbols.",
    ],
    "itinerary" => [
        "title" => $itinerary["title"],
        "start_date" => $itinerary["start_date"],
        "origin_name" => $itinerary["origin_name"] ?? "",
        "origin_lat" => $itinerary["origin_lat"] ?? null,
        "origin_lng" => $itinerary["origin_lng"] ?? null,
        "total_days" => (int)$itinerary["total_days"],
        "total_estimated_cost" => (float)$itinerary["total_estimated_cost"],
        "budget" => (float)($itinerary["budget"] ?? 0),
        "budget_tier" => $itinerary["budget_tier"] ?? "",
        "transport_type" => $itinerary["transport_type"] ?? "car",
        "traveller_type" => $itinerary["traveller_type"] ?? "",
        "travel_pace" => $itinerary["travel_pace"] ?? "",
        "dietary_preference" => $itinerary["dietary_preference"] ?? "",
        "accessibility_needs" => $itinerary["accessibility_needs"] ?? "",
        "interests" => $itinerary["interests"] ?? "",
        "preferred_states" => $itinerary["preferred_states"] ?? "",
        "preferred_districts" => $itinerary["preferred_districts"] ?? "",
    ],
    "items" => $items,
];

$model = defined("OLLAMA_MODEL") ? OLLAMA_MODEL : "qwen2.5:3b";
$baseUrl = defined("OLLAMA_BASE_URL") ? OLLAMA_BASE_URL : "http://127.0.0.1:11434";
$assistant = new AiTravelAssistantService($model, $baseUrl);
$result = $assistant->answer($message, $context);

$answer = trim((string)($result["answer"] ?? ""));
if (($result["status"] ?? "") !== "success" || $answer === "") {
    $answer = "I can help with this trip, but the local AI service is not available right now. You can still use the rule-based itinerary generator, hotel confirmation, and route tools.";
}

// No secondary parsing of $answer here. The old logic parsed dates/origins from the AI's own explanation,
// which caused false confirmation buttons for already saved start dates and starting locations.
log_ai_chat($conn, $itineraryId, $travellerId, $message, $answer);

echo json_encode([
    "status" => "success",
    "answer" => $answer,
    "pending_action" => null,
    "pending_actions" => [],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

function load_itinerary(mysqli $conn, int $itineraryId, int $travellerId): ?array
{
    $stmt = $conn->prepare("
        SELECT i.itinerary_id, i.title, i.start_date, i.total_days, i.total_estimated_cost,
               i.origin_name, i.origin_lat, i.origin_lng,
               tp.budget, tp.budget_tier, tp.transport_type, tp.traveller_type, tp.travel_pace,
               tp.dietary_preference, tp.accessibility_needs, tp.interests, tp.preferred_states, tp.preferred_districts
        FROM itineraries i
        LEFT JOIN traveller_preferences tp ON tp.preference_id = i.preference_id
        WHERE i.itinerary_id = ? AND i.traveller_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $itineraryId, $travellerId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function load_itinerary_items(mysqli $conn, int $itineraryId): array
{
    $stmt = $conn->prepare("
        SELECT ii.day_no, ii.sequence_no, ii.item_type, ii.item_title, ii.start_time, ii.end_time,
               ii.estimated_cost, ii.distance_km, ii.travel_time_min,
               COALESCE(ii.item_latitude, cp.latitude) AS latitude,
               COALESCE(ii.item_longitude, cp.longitude) AS longitude,
               cp.category, cp.state, cp.district, cp.address, cp.opening_hours
        FROM itinerary_items ii
        LEFT JOIN cultural_places cp ON cp.place_id = ii.place_id
        WHERE ii.itinerary_id = ?
        ORDER BY ii.day_no, ii.sequence_no
    ");
    $stmt->bind_param("i", $itineraryId);
    $stmt->execute();
    $res = $stmt->get_result();
    $items = [];
    while ($row = $res->fetch_assoc()) {
        $items[] = [
            "day_no" => (int)$row["day_no"],
            "sequence_no" => (int)$row["sequence_no"],
            "title" => (string)$row["item_title"],
            "type" => (string)$row["item_type"],
            "category" => (string)($row["category"] ?? ""),
            "state" => (string)($row["state"] ?? ""),
            "district" => (string)($row["district"] ?? ""),
            "address" => (string)($row["address"] ?? ""),
            "start_time" => (string)($row["start_time"] ?? ""),
            "end_time" => (string)($row["end_time"] ?? ""),
            "cost_rm" => (float)($row["estimated_cost"] ?? 0),
            "distance_km" => $row["distance_km"] !== null ? (float)$row["distance_km"] : null,
            "travel_time_min" => $row["travel_time_min"] !== null ? (int)$row["travel_time_min"] : null,
            "opening_hours" => (string)($row["opening_hours"] ?? ""),
            "latitude" => $row["latitude"] !== null ? (float)$row["latitude"] : null,
            "longitude" => $row["longitude"] !== null ? (float)$row["longitude"] : null,
        ];
    }
    $stmt->close();
    return $items;
}

function parse_trip_date(string $text): ?string
{
    $text = trim($text);
    $months = [
        "jan" => 1, "january" => 1,
        "feb" => 2, "february" => 2,
        "mar" => 3, "march" => 3,
        "apr" => 4, "april" => 4,
        "may" => 5,
        "jun" => 6, "june" => 6,
        "jul" => 7, "july" => 7,
        "aug" => 8, "august" => 8,
        "sep" => 9, "sept" => 9, "september" => 9,
        "oct" => 10, "october" => 10,
        "nov" => 11, "november" => 11,
        "dec" => 12, "december" => 12,
    ];
    if (preg_match('/\b(\d{4})[\/\-.](\d{1,2})[\/\-.](\d{1,2})\b/', $text, $m)) {
        return valid_date((int)$m[1], (int)$m[2], (int)$m[3]);
    }
    if (preg_match('/\b(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})\b/', $text, $m)) {
        $first = (int)$m[1];
        $second = (int)$m[2];
        $year = (int)$m[3];
        if ($first > 12) return valid_date($year, $second, $first);
        if ($second > 12) return valid_date($year, $first, $second);
        return valid_date($year, $second, $first);
    }
    if (preg_match('/\b(\d{1,2})(?:st|nd|rd|th)?[\s\/\-.]+([a-zA-Z]+)[,\s\/\-.]+(\d{4})\b/', $text, $m)) {
        $monthKey = strtolower($m[2]);
        if (isset($months[$monthKey])) return valid_date((int)$m[3], $months[$monthKey], (int)$m[1]);
    }
    if (preg_match('/\b([a-zA-Z]+)[\s\/\-.]+(\d{1,2})(?:st|nd|rd|th)?[,\s\/\-.]+(\d{4})\b/', $text, $m)) {
        $monthKey = strtolower($m[1]);
        if (isset($months[$monthKey])) return valid_date((int)$m[3], $months[$monthKey], (int)$m[2]);
    }
    if (preg_match('/\b(\d{4})[\s\/\-.]+([a-zA-Z]+)[\s\/\-.]+(\d{1,2})(?:st|nd|rd|th)?\b/', $text, $m)) {
        $monthKey = strtolower($m[2]);
        if (isset($months[$monthKey])) return valid_date((int)$m[1], $months[$monthKey], (int)$m[3]);
    }
    if (preg_match('/\b(\d{4})[\s\/\-.]+(\d{1,2})(?:st|nd|rd|th)?[\s\/\-.]+([a-zA-Z]+)\b/', $text, $m)) {
        $monthKey = strtolower($m[3]);
        if (isset($months[$monthKey])) return valid_date((int)$m[1], $months[$monthKey], (int)$m[2]);
    }
    if (preg_match('/\b(\d{1,2})(\d{4})([a-zA-Z]+)\b/', $text, $m)) {
        $monthKey = strtolower($m[3]);
        if (isset($months[$monthKey])) return valid_date((int)$m[2], $months[$monthKey], (int)$m[1]);
    }
    if (preg_match('/\b([a-zA-Z]+)(\d{1,2})(\d{4})\b/', $text, $m)) {
        $monthKey = strtolower($m[1]);
        if (isset($months[$monthKey])) return valid_date((int)$m[3], $months[$monthKey], (int)$m[2]);
    }
    if (preg_match('/\b(\d{1,2})(?:st|nd|rd|th)?\s*([a-zA-Z]+)\s*(\d{4})\b/', $text, $m)) {
        $monthKey = strtolower($m[2]);
        if (isset($months[$monthKey])) return valid_date((int)$m[3], $months[$monthKey], (int)$m[1]);
    }
    if (preg_match('/\b([a-zA-Z]+)\s*(\d{1,2})(?:st|nd|rd|th)?[,]?\s*(\d{4})\b/', $text, $m)) {
        $monthKey = strtolower($m[1]);
        if (isset($months[$monthKey])) return valid_date((int)$m[3], $months[$monthKey], (int)$m[2]);
    }
    return null;
}

function valid_date(int $year, int $month, int $day): ?string
{
    if (!checkdate($month, $day, $year)) return null;
    return sprintf("%04d-%02d-%02d", $year, $month, $day);
}

function format_display_date(string $date): string
{
    $dt = DateTime::createFromFormat("Y-m-d", $date);
    return $dt ? $dt->format("d M Y") : $date;
}

function same_trip_date(string $newDate, string $currentDate): bool
{
    $current = parse_trip_date($currentDate);
    return $current !== null && $current === $newDate;
}

function normalize_place_name(string $value): string
{
    $value = strtolower(trim(strip_tags($value)));
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    $value = preg_replace('/[^a-z0-9\s,.-]/', '', $value) ?? $value;
    return trim($value, " \t\n\r\0\x0B,.;");
}

function same_origin_name(string $newOrigin, string $currentOrigin): bool
{
    $new = normalize_place_name($newOrigin);
    $current = normalize_place_name($currentOrigin);
    return $new !== "" && $current !== "" && ($new === $current || str_contains($current, $new) || str_contains($new, $current));
}

function is_weather_question(string $message): bool
{
    return (bool)preg_match('/weather|forecast|rain|raining|temperature|hot|humid|storm|umbrella|cuaca|hujan|天气|下雨|热/iu', $message);
}

function is_explicit_date_update_message(string $message): bool
{
    return (bool)preg_match('/\b(?:change|update|set|move|reschedule|replace|confirm)\b.*\b(?:start\s*date|travel\s*date|trip\s*date|date)\b|\b(?:start\s*date|travel\s*date|trip\s*date|date)\b.*\b(?:to|as|is|=)\b|\b(?:travel|visit|depart|arrive|go)\s+(?:on|at)\b|改.*日期|更改.*日期|换.*日期|出发日期|旅行日期/iu', $message);
}

function is_explicit_origin_update_message(string $message): bool
{
    return (bool)preg_match('/\b(?:change|update|set|use|replace|confirm)\b.*\b(?:starting\s+location|start\s+location|starting\s+point|start\s+point|origin)\b|\b(?:starting\s+location|start\s+location|starting\s+point|start\s+point|origin)\b.*\b(?:to|as|is|=)\b|\b(?:i\s+will\s+start|i\s+start|start\s+from|starting\s+from|depart\s+from|leaving\s+from|leave\s+from)\b|起点|出发地|出发地点/iu', $message);
}

function extract_origin_name(string $message): string
{
    $patterns = [
        '/\b(?:change|update|set|use|replace|confirm)\s+(?:my\s+)?(?:starting\s+location|start\s+location|starting\s+point|start\s+point|origin)\s+(?:to|as)\s+(.+?)(?:[.!?]|$)/iu',
        '/\b(?:my\s+)?(?:starting\s+location|start\s+location|starting\s+point|start\s+point|origin)\s*(?:is|=|:)\s*(.+?)(?:[.!?]|$)/iu',
        '/\b(?:i\s+will\s+start|i\s+start|start\s+me|route\s+me|start\s+from|starting\s+from)\s+(?:at|from)?\s*(.+?)(?:\s+(?:to|on|for)\b|[.!?]|$)/iu',
        '/\b(?:depart(?:ing)? from|leave from|leaving from)\s+(.+?)(?:\s+(?:to|on|at|for)\b|[.!?]|$)/iu',
        '/(?:起点|出发地|出发地点)\s*(?:是|为|=|:)\s*(.+?)(?:[。.!?]|$)/iu',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $message, $m)) {
            $value = trim(strip_tags((string)$m[1]));
            $value = preg_replace('/\s+/', ' ', $value) ?? "";
            $value = preg_replace('/\b(?:on|at|for|to|please|pls)\s*$/iu', '', $value) ?? $value;
            $value = trim($value, " \t\n\r\0\x0B,;:");
            if (function_exists("mb_substr")) return trim(mb_substr($value, 0, 120));
            return trim(substr($value, 0, 120));
        }
    }
    return "";
}

function describe_pending_action(array $action): string
{
    if (($action["type"] ?? "") === "update_origin") {
        return "your starting location as " . ($action["label"] ?? $action["origin_name"] ?? "");
    }
    return "your trip start date as " . ($action["label"] ?? $action["start_date"] ?? "");
}

function build_weather_answer(array $items, string $date): string
{
    $point = first_weather_point($items);
    if (!$point) {
        return "I cannot check weather because this itinerary does not have a usable place coordinate yet.";
    }

    $dateNote = "";
    $target = parse_trip_date($date);
    if ($target) {
        $today = new DateTime("today", new DateTimeZone("Asia/Kuala_Lumpur"));
        $targetDt = DateTime::createFromFormat("Y-m-d", $target, new DateTimeZone("Asia/Kuala_Lumpur"));
        if ($targetDt) {
            $diff = (int)$today->diff($targetDt)->format("%r%a");
            if ($diff > 5) {
                $dateNote = " Exact forecast for " . format_display_date($target) . " is not available yet from the free weather endpoint, so I can only show current weather and planning advice. Check again closer to the date.";
            }
        }
    }

    $current = fetch_current_weather((float)$point["latitude"], (float)$point["longitude"]);
    if (!$current) {
        return "I could not reach the weather service right now. You can still plan normally, but check weather again before travelling.";
    }

    $answer = "Current weather near " . $point["title"] . ": " . $current["description"] . ", about " . $current["temp"] . " C";
    if ($current["humidity"] !== null) {
        $answer .= ", humidity " . $current["humidity"] . "%";
    }
    $answer .= ".";
    if ($current["main"] === "Rain" || $current["main"] === "Thunderstorm" || $current["main"] === "Drizzle") {
        $answer .= " Bring an umbrella and prefer indoor places or museums first.";
    } elseif ($current["temp"] >= 32) {
        $answer .= " It may feel hot, so put outdoor stops in the morning or evening.";
    } else {
        $answer .= " Weather looks usable for normal sightseeing, but verify again on the travel day.";
    }
    return $answer . $dateNote;
}

function first_weather_point(array $items): ?array
{
    foreach ($items as $item) {
        if ($item["latitude"] !== null && $item["longitude"] !== null) {
            return $item;
        }
    }
    return null;
}

function fetch_current_weather(float $lat, float $lng): ?array
{
    if (!defined("OPENWEATHER_API_KEY") || trim((string)OPENWEATHER_API_KEY) === "") {
        return null;
    }
    if (!function_exists("curl_init")) {
        return null;
    }
    $url = "https://api.openweathermap.org/data/2.5/weather?lat=" . rawurlencode((string)$lat)
        . "&lon=" . rawurlencode((string)$lng)
        . "&appid=" . rawurlencode((string)OPENWEATHER_API_KEY)
        . "&units=metric";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false || $code < 200 || $code >= 300) return null;
    $json = json_decode($raw, true);
    if (!is_array($json)) return null;
    return [
        "main" => (string)($json["weather"][0]["main"] ?? ""),
        "description" => ucfirst((string)($json["weather"][0]["description"] ?? "unknown")),
        "temp" => isset($json["main"]["temp"]) ? round((float)$json["main"]["temp"]) : 0,
        "humidity" => isset($json["main"]["humidity"]) ? (int)$json["main"]["humidity"] : null,
    ];
}

function log_ai_chat(mysqli $conn, int $itineraryId, int $travellerId, string $message, string $answer): void
{
    if (!function_exists("table_exists") || !table_exists($conn, "ai_chat_logs")) return;
    $stmt = $conn->prepare("INSERT INTO ai_chat_logs (itinerary_id, traveller_id, user_message, ai_response) VALUES (?, ?, ?, ?)");
    if (!$stmt) return;
    $stmt->bind_param("iiss", $itineraryId, $travellerId, $message, $answer);
    $stmt->execute();
    $stmt->close();
}
