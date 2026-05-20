<?php
// itinerary/ai_chat.php
// AJAX endpoint for AI Travel Assistant.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once "../config/db_connect.php";
require_once "../config/api_keys.php";
require_once "../services/AiTravelAssistantService.php";

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'answer' => 'Unauthorized']);
    exit;
}

$travellerId = (int)($_SESSION["traveller_id"] ?? 0);
$itineraryId = (int)($_POST["itinerary_id"] ?? 0);
$message = trim((string)($_POST["message"] ?? ""));

if ($travellerId <= 0 || $itineraryId <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'answer' => 'Invalid request.']);
    exit;
}

$stmt = $conn->prepare("
    SELECT i.itinerary_id, i.title, i.start_date, i.total_days, i.total_estimated_cost,
           tp.budget, tp.transport_type, tp.interests, tp.preferred_states, tp.preferred_districts
    FROM itineraries i
    LEFT JOIN traveller_preferences tp ON tp.preference_id = i.preference_id
    WHERE i.itinerary_id = ? AND i.traveller_id = ?
    LIMIT 1
");
$stmt->bind_param("ii", $itineraryId, $travellerId);
$stmt->execute();
$it = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$it) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'answer' => 'Itinerary not found.']);
    exit;
}

$hasItemCoords = table_has_column($conn, "itinerary_items", "item_latitude")
    && table_has_column($conn, "itinerary_items", "item_longitude");
$coordSelect = $hasItemCoords
    ? "COALESCE(ii.item_latitude, cp.latitude) AS latitude, COALESCE(ii.item_longitude, cp.longitude) AS longitude"
    : "cp.latitude, cp.longitude";

$itemSql = "
    SELECT ii.day_no, ii.sequence_no, ii.item_type, ii.item_title, ii.start_time, ii.end_time,
           ii.estimated_cost, ii.distance_km, ii.travel_time_min, ii.notes,
           cp.category, cp.state, cp.district, cp.opening_hours, {$coordSelect}
    FROM itinerary_items ii
    LEFT JOIN cultural_places cp ON cp.place_id = ii.place_id
    WHERE ii.itinerary_id = ?
    ORDER BY ii.day_no, ii.sequence_no
";
$itemsStmt = $conn->prepare($itemSql);
$itemsStmt->bind_param("i", $itineraryId);
$itemsStmt->execute();
$res = $itemsStmt->get_result();

$days = [];
$activeStates = [];
while ($row = $res->fetch_assoc()) {
    $day = (int)$row["day_no"];
    $state = trim((string)($row["state"] ?? ""));
    if ($state !== "" && !in_array($state, $activeStates, true)) $activeStates[] = $state;
    $days[$day][] = [
        'sequence' => (int)$row["sequence_no"],
        'title' => $row["item_title"],
        'type' => $row["item_type"],
        'category' => $row["category"],
        'state' => $row["state"],
        'district' => $row["district"],
        'start_time' => $row["start_time"],
        'end_time' => $row["end_time"],
        'cost_rm' => (float)($row["estimated_cost"] ?? 0),
        'distance_km' => $row["distance_km"] !== null ? (float)$row["distance_km"] : null,
        'travel_time_min' => $row["travel_time_min"] !== null ? (int)$row["travel_time_min"] : null,
        'opening_hours' => $row["opening_hours"],
        'notes' => $row["notes"],
        'latitude' => $row["latitude"] !== null ? (float)$row["latitude"] : null,
        'longitude' => $row["longitude"] !== null ? (float)$row["longitude"] : null,
    ];
}
$itemsStmt->close();

$context = [
    'assistant_rules' => [
        'Answer only using the current itinerary data below.',
        'Do not suggest Kelantan or any other state unless it appears in active_itinerary_states or the user explicitly requests it.',
        'If the user asks to modify/add/regenerate stops, tell them to use the confirmation cards shown by the system. Do not claim the itinerary has already been changed.',
        'Use plain text only. Do not use ** markdown.',
    ],
    'title' => $it["title"],
    'start_date' => $it["start_date"],
    'total_days' => (int)$it["total_days"],
    'total_estimated_cost' => (float)$it["total_estimated_cost"],
    'budget' => (float)($it["budget"] ?? 0),
    'transport_type' => $it["transport_type"] ?? "car",
    'interests' => $it["interests"] ?? "",
    'preferred_states' => $it["preferred_states"] ?? "",
    'active_itinerary_states' => $activeStates,
    'preferred_districts' => $it["preferred_districts"] ?? "",
    'days' => $days,
];

$model = defined("OLLAMA_MODEL") ? OLLAMA_MODEL : "qwen2.5:3b";
$baseUrl = defined("OLLAMA_BASE_URL") ? OLLAMA_BASE_URL : "http://localhost:11434";
$assistant = new AiTravelAssistantService($model, $baseUrl);
$result = $assistant->answer($message, $context);

if (table_exists($conn, "ai_chat_logs")) {
    $log = $conn->prepare("
        INSERT INTO ai_chat_logs (itinerary_id, traveller_id, user_message, ai_response, source)
        VALUES (?, ?, ?, ?, ?)
    ");
    if ($log) {
        $source = $result["source"] ?? "unknown";
        $answer = $result["answer"] ?? "";
        $log->bind_param("iisss", $itineraryId, $travellerId, $message, $answer, $source);
        $log->execute();
        $log->close();
    }
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

function table_has_column(mysqli $conn, string $table, string $column): bool
{
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return ($res && $res->num_rows > 0);
}

function table_exists(mysqli $conn, string $table): bool
{
    $table = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '$table'");
    return ($res && $res->num_rows > 0);
}
