<?php
// itinerary/pre_generation_ai_chat.php
// AJAX endpoint for AI support before itinerary generation.
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
$preferenceId = (int)($_POST["preference_id"] ?? 0);
$message = trim((string)($_POST["message"] ?? ""));
$startDate = trim((string)($_POST["start_date"] ?? ""));
$originName = trim((string)($_POST["origin_name"] ?? ""));

if ($travellerId <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'answer' => 'Invalid traveller session.']);
    exit;
}

if ($message === '') {
    echo json_encode(['status' => 'error', 'answer' => 'Please type a question for the AI assistant.']);
    exit;
}

$preference = null;
if ($preferenceId > 0) {
    $hasPartySizeCol = false;
    $colRes = $conn->query("SHOW COLUMNS FROM traveller_preferences LIKE 'party_size'");
    if ($colRes && $colRes->num_rows > 0) {
        $hasPartySizeCol = true;
    }
    $partySizeSql = $hasPartySizeCol ? ", party_size" : "";

    $stmt = $conn->prepare("
        SELECT preference_id, trip_days, budget, budget_tier, transport_type, traveller_type{$partySizeSql}, travel_pace,
               dietary_preference, preferred_visit_time, accessibility_needs, interests, preferred_states, preferred_districts
        FROM traveller_preferences
        WHERE preference_id = ? AND traveller_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $preferenceId, $travellerId);
    $stmt->execute();
    $preference = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$context = [
    'title' => 'Pre-generation AI Travel Assistant',
    'stage' => 'before itinerary generation',
    'start_date' => $startDate !== '' ? $startDate : 'not confirmed',
    'route' => [
        'starting_location' => $originName !== '' ? $originName : 'not confirmed',
    ],
    'preferences' => $preference ?: 'No saved preference selected yet.',
    'assistant_rules' => [
        'Help the traveller understand the selected preference before generating an itinerary.',
        'Help the traveller choose a suitable starting location and start date.',
        'Do not claim that an itinerary has been generated yet.',
        'Do not directly change saved database records.',
        'If the user wants to change budget, state, district, travel pace or interests, tell them to edit the preference in Traveller Preference Analyzer.',
        'Keep the response concise and practical.',
    ],
];

$assistantQuestion = "The user is on the Smart Itinerary Generator page before generating an official itinerary. "
    . "Answer as a pre-generation AI Travel Assistant. "
    . "Focus on preference explanation, starting location, start date, and what to confirm before generation.\n\n"
    . "User question: " . $message;

$model = defined("OLLAMA_MODEL") ? OLLAMA_MODEL : "qwen2.5:3b";
$baseUrl = defined("OLLAMA_BASE_URL") ? OLLAMA_BASE_URL : "http://127.0.0.1:11434";
$assistant = new AiTravelAssistantService($model, $baseUrl);
$result = $assistant->answer($assistantQuestion, $context);

unset($result["source"], $result["model"]);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
