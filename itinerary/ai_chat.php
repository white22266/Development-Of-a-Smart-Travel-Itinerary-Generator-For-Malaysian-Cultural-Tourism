<?php
// itinerary/ai_chat.php
// AJAX endpoint for AI Travel Assistant.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once "../config/db_connect.php";
require_once "../config/ai_language_guard.php";
require_once "../services/AiTravelAssistantService.php";

header('Content-Type: application/json; charset=utf-8');

$message = trim((string)($_POST["message"] ?? ""));
$lang = ai_request_language($message);

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'answer' => ai_text('unauthorized', $lang), 'lang' => $lang], JSON_UNESCAPED_UNICODE);
    exit;
}

$travellerId = (int)($_SESSION["traveller_id"] ?? 0);
$itineraryId = (int)($_POST["itinerary_id"] ?? 0);

if ($travellerId <= 0 || $itineraryId <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'answer' => ai_text('invalid_request', $lang), 'lang' => $lang], JSON_UNESCAPED_UNICODE);
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
    echo json_encode(['status' => 'error', 'answer' => ai_text('itinerary_not_found', $lang), 'lang' => $lang], JSON_UNESCAPED_UNICODE);
    exit;
}

$itemsStmt = $conn->prepare("
    SELECT ii.day_no, ii.sequence_no, ii.item_type, ii.item_title, ii.start_time, ii.end_time,
           ii.estimated_cost, ii.distance_km, ii.travel_time_min, ii.notes,
           cp.category, cp.state, cp.district, cp.opening_hours
    FROM itinerary_items ii
    LEFT JOIN cultural_places cp ON cp.place_id = ii.place_id
    WHERE ii.itinerary_id = ?
    ORDER BY ii.day_no, ii.sequence_no
");
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
    ];
}
$itemsStmt->close();

$context = [
    'assistant_rules' => [
        'Use the current itinerary data below as the source of truth.',
        'Respond like a helpful travel chat assistant instead of repeating a fixed template.',
        'Do not suggest Kelantan or any other state unless it appears in active_itinerary_states or the user explicitly requests it.',
        'If the user asks to modify/add/regenerate stops, explain that the system needs a confirmation button before saving. Do not claim the itinerary has already been changed.',
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

$assistant = new AiTravelAssistantService();
$result = $assistant->answer($message, $context, $lang);

try {
    $answer = (string)($result["answer"] ?? "");
    $log = $conn->prepare("INSERT INTO ai_chat_logs (itinerary_id, traveller_id, user_message, ai_response) VALUES (?, ?, ?, ?)");
    $log->bind_param("iiss", $itineraryId, $travellerId, $message, $answer);
    $log->execute();
    $log->close();
} catch (mysqli_sql_exception $e) {
    error_log("ai_chat.php: could not log chat: " . $e->getMessage());
}

unset($result["source"]);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
