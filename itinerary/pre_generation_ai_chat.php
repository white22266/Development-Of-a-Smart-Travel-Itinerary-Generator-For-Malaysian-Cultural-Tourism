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
    echo json_encode(['status' => 'error', 'answer' => 'Please log in again before using the travel assistant.']);
    exit;
}

$travellerId = (int)($_SESSION["traveller_id"] ?? 0);
$preferenceId = (int)($_POST["preference_id"] ?? 0);
$message = trim((string)($_POST["message"] ?? ""));
$startDate = trim((string)($_POST["start_date"] ?? ""));
$originName = trim((string)($_POST["origin_name"] ?? ""));

if ($travellerId <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'answer' => 'Please log in again before planning your trip.']);
    exit;
}

if ($message === '') {
    echo json_encode(['status' => 'error', 'answer' => 'Please ask about your start date, starting location, selected preference, or route readiness.']);
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
    if ($stmt) {
        $stmt->bind_param("ii", $preferenceId, $travellerId);
        $stmt->execute();
        $preference = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

$quickReply = pre_generation_rule_reply($message, is_array($preference) ? $preference : [], $startDate, $originName);
if ($quickReply !== null) {
    echo json_encode([
        'status' => 'success',
        'answer' => $quickReply,
        'source' => 'pre_generation_rule',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$context = [
    'title' => 'Pre-generation Travel Assistant',
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
        'Keep the response concise, professional, and practical.',
        'Do not mention internal model names, local AI, Ollama, cURL, timeout, or server details to travellers.',
    ],
];

$model = defined("OLLAMA_MODEL") ? OLLAMA_MODEL : "qwen2.5:3b";
$baseUrl = defined("OLLAMA_BASE_URL") ? OLLAMA_BASE_URL : "http://127.0.0.1:11434";
$assistant = new AiTravelAssistantService($model, $baseUrl);

// Pass the traveller's actual message only. Do not prepend generic text containing
// "generate itinerary", because that causes every message to be classified as a
// preference-readiness question.
$result = $assistant->answer($message, $context);

unset($result["source"], $result["model"]);
if (($result['status'] ?? '') !== 'success') {
    $result['status'] = 'success';
    $result['answer'] = 'Please ask about your selected preference, starting location, start date, route readiness, or what to confirm before generating the itinerary.';
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

function pre_generation_rule_reply(string $message, array $preference, string $startDate, string $originName): ?string
{
    $normalized = normalize_pre_generation_text($message);

    if ($normalized === '') {
        return 'Please ask about your start date, starting location, selected preference, or route readiness.';
    }

    if (preg_match('/^(hi|hello|hey|yo|hai|helo|good morning|good afternoon|good evening)$/i', $normalized)) {
        return 'Hello. I can help you check your selected preference, choose a starting location, review route readiness, and confirm what is needed before generating the itinerary.';
    }

    if (preg_match('/\b(?:what\s+is\s+your\s+model|which\s+model|model\s+name|are\s+you\s+ollama|are\s+you\s+ai|who\s+are\s+you)\b/i', $normalized)) {
        return 'I am the Smart Travel Assistant for this itinerary generator. I help with preference checks, starting location, route readiness, and pre-generation planning guidance.';
    }

    if (preg_match('/\b(?:what\s+(?:can|could)\s+you\s+do|what\s+u\s+can\s+do|what\s+you\s+can\s+do|how\s+can\s+you\s+help|help\s+me|your\s+features)\b/i', $normalized)) {
        return 'I can explain your selected preference, suggest a starting location, check route readiness, review cost and travel time considerations, and tell you what to confirm before clicking Generate Itinerary.';
    }

    if (preg_match('/\b(?:why\s+(?:do\s+)?you\s+keep\s+repeat|why\s+repeat|keep\s+repeating|same\s+answer|repeat\s+just\s+now)\b/i', $normalized)) {
        return 'That happened because your earlier message was interpreted as a preference-readiness check. Ask me a specific item such as starting location, start date, route readiness, cost, or what to confirm before generation.';
    }

    if (preg_match('/^(then|next|continue|more|go on|and then|what next|so)$/i', $normalized)) {
        return 'Next, confirm your start date and starting location. After that, you can click Generate Itinerary and let the system rules create the official plan.';
    }

    if (is_starting_location_prompt($normalized)) {
        return build_pre_generation_starting_location_reply($preference, $originName);
    }

    if (is_confirmation_prompt($normalized)) {
        return build_pre_generation_confirmation_reply($preference, $startDate, $originName);
    }

    if (is_preference_check_prompt($normalized)) {
        return build_pre_generation_readiness_reply($preference, $startDate, $originName);
    }

    return null;
}

function is_starting_location_prompt(string $normalized): bool
{
    return (bool)preg_match('/\b(?:suggest|recommend|choose|decide|suitable)\s+(?:a\s+)?(?:suitable\s+)?(?:starting|start|origin)\s+location\b|\bstarting\s+location\b|\bstart\s+location\b|\borigin\s+location\b/i', $normalized);
}

function is_confirmation_prompt(string $normalized): bool
{
    return (bool)preg_match('/\b(?:what\s+should\s+i\s+confirm|what\s+to\s+confirm|before\s+(?:clicking\s+)?generate|before\s+generating|before\s+generate|generate\s+itinerary\s+confirm|before\s+i\s+generate)\b/i', $normalized);
}

function is_preference_check_prompt(string $normalized): bool
{
    if (preg_match('/^(check my preference|check preference|selected preference|preference check)$/i', $normalized)) {
        return true;
    }

    return (bool)preg_match('/\b(?:explain\s+whether\s+my\s+selected\s+preference|selected\s+preference\s+(?:suitable|ready|complete)|preference\s+(?:suitable|ready|complete)|suitable\s+for\s+generating\s+an\s+itinerary|check\s+(?:my\s+)?preference)\b/i', $normalized);
}

function build_pre_generation_starting_location_reply(array $preference, string $originName): string
{
    if (is_meaningful_value($originName)) {
        return 'Use ' . clean_short_value($originName) . ' as the starting location if that is where the traveller will begin the trip. If not, update it before generating so route time and cost are more accurate.';
    }

    $district = first_meaningful_preference_value($preference, ['preferred_districts']);
    $state = first_meaningful_preference_value($preference, ['preferred_states']);
    $area = is_meaningful_value($district) ? $district : (is_meaningful_value($state) ? $state : 'the selected destination area');

    return 'Choose a central and easy-to-reach point in ' . clean_short_value($area) . ', such as the hotel, main bus terminal, train station, or town centre. Confirm it before generating the itinerary.';
}

function build_pre_generation_confirmation_reply(array $preference, string $startDate, string $originName): string
{
    $missing = pre_generation_missing_items($preference, $startDate, $originName);
    if (empty($missing)) {
        return 'Before clicking Generate Itinerary, confirm the start date, starting location, budget, transport type, travel days, destination area, and interests. Your preference looks ready, but the official itinerary will still be generated by system rules.';
    }

    return 'Before clicking Generate Itinerary, complete these missing items: ' . implode(', ', array_slice($missing, 0, 5)) . '. This helps the system calculate route order, time, and cost more accurately.';
}

function build_pre_generation_readiness_reply(array $preference, string $startDate, string $originName): string
{
    $missing = pre_generation_missing_items($preference, $startDate, $originName);
    if (empty($missing)) {
        return 'Your selected preference looks suitable for itinerary generation. Confirm the start date and starting location one more time, then use Generate Itinerary to create the official plan.';
    }

    return 'Your selected preference is not fully ready yet. Please complete ' . implode(', ', array_slice($missing, 0, 5)) . ' before generating the itinerary.';
}

function pre_generation_missing_items(array $preference, string $startDate, string $originName): array
{
    $missing = [];

    if (!is_meaningful_value($startDate)) $missing[] = 'start date';
    if (!is_meaningful_value($originName)) $missing[] = 'starting location';
    if (!is_meaningful_value(first_meaningful_preference_value($preference, ['trip_days']))) $missing[] = 'travel days';
    if (!is_meaningful_value(first_meaningful_preference_value($preference, ['budget', 'budget_tier']))) $missing[] = 'budget';
    if (!is_meaningful_value(first_meaningful_preference_value($preference, ['transport_type']))) $missing[] = 'transport type';

    $state = first_meaningful_preference_value($preference, ['preferred_states']);
    $district = first_meaningful_preference_value($preference, ['preferred_districts']);
    if (!is_meaningful_value($state) && !is_meaningful_value($district)) $missing[] = 'destination state or district';

    if (!is_meaningful_value(first_meaningful_preference_value($preference, ['interests']))) $missing[] = 'travel interests';

    return array_values(array_unique($missing));
}

function first_meaningful_preference_value(array $preference, array $keys): string
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $preference)) {
            continue;
        }
        $value = $preference[$key];
        if (is_array($value)) {
            $value = implode(', ', array_filter(array_map('strval', $value)));
        }
        $text = trim((string)$value);
        if (is_meaningful_value($text)) {
            return clean_short_value($text);
        }
    }

    return '';
}

function normalize_pre_generation_text(string $text): string
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9\s]/i', ' ', $text) ?? $text;
    return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
}

function is_meaningful_value(string $value): bool
{
    $value = trim($value);
    if ($value === '') return false;
    return !preg_match('/^(0|0\.0|none|null|not provided|not confirmed|not set|unknown|n\/a)$/i', $value);
}

function clean_short_value(string $value): string
{
    $value = trim(strip_tags($value));
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, 80);
    }
    return substr($value, 0, 80);
}
