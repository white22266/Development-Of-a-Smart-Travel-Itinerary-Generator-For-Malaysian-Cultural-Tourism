<?php
// itinerary/pre_generation_ai_chat.php
// AJAX endpoint for AI support before itinerary generation.
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
    echo json_encode(['status' => 'error', 'answer' => ai_text('pre_login', $lang), 'lang' => $lang], JSON_UNESCAPED_UNICODE);
    exit;
}

$travellerId = (int)($_SESSION["traveller_id"] ?? 0);
$preferenceId = (int)($_POST["preference_id"] ?? 0);
$startDate = trim((string)($_POST["start_date"] ?? ""));
$originName = trim((string)($_POST["origin_name"] ?? ""));

if ($travellerId <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'answer' => ai_text('pre_login_plan', $lang), 'lang' => $lang], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($message === '') {
    echo json_encode(['status' => 'error', 'answer' => ai_text('pre_ask_about', $lang), 'lang' => $lang], JSON_UNESCAPED_UNICODE);
    exit;
}

$preference = null;
if ($preferenceId > 0) {
    $stmt = $conn->prepare("
        SELECT preference_id, trip_days, budget, budget_tier, transport_type, traveller_type, party_size, travel_pace,
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

$quickReply = pre_generation_rule_reply($message, is_array($preference) ? $preference : [], $startDate, $originName, $lang);
if ($quickReply !== null) {
    echo json_encode([
        'status' => 'success',
        'answer' => $quickReply,
        'source' => 'pre_generation_rule',
        'lang' => $lang,
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

// Pass the traveller's actual message only. The service's answer is safe to show even
// when status is 'error' (for example when the model is busy).
$assistant = new AiTravelAssistantService();
$result = $assistant->answer($message, $context, $lang);
unset($result["source"]);

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

function pre_generation_rule_reply(string $message, array $preference, string $startDate, string $originName, string $lang = 'en'): ?string
{
    if (preg_match('/^(?:你好|您好|嗨|哈喽)[\s\p{P}]*$/u', trim($message))) {
        return ai_text('pre_hello', $lang);
    }
    if ($lang === 'zh') {
        return null;   // the patterns below read English (and a few Malay greetings)
    }

    $normalized = normalize_pre_generation_text($message);

    if ($normalized === '') {
        return ai_text('pre_ask_about', $lang);
    }

    if (preg_match('/^(hi|hello|hey|yo|hai|helo|good morning|good afternoon|good evening|selamat pagi|selamat petang|selamat malam|salam)$/i', $normalized)) {
        return ai_text('pre_hello', $lang);
    }
    if ($lang !== 'en') {
        return null;
    }

    if (preg_match('/\b(?:what\s+is\s+your\s+model|which\s+model|model\s+name|are\s+you\s+ollama|are\s+you\s+ai|who\s+are\s+you)\b/i', $normalized)) {
        return ai_text('pre_identity', $lang);
    }

    if (preg_match('/\b(?:what\s+(?:can|could)\s+you\s+do|what\s+u\s+can\s+do|what\s+you\s+can\s+do|how\s+can\s+you\s+help|help\s+me|your\s+features)\b/i', $normalized)) {
        return ai_text('pre_can_do', $lang);
    }

    if (preg_match('/\b(?:why\s+(?:do\s+)?you\s+keep\s+repeat|why\s+repeat|keep\s+repeating|same\s+answer|repeat\s+just\s+now)\b/i', $normalized)) {
        return ai_text('pre_repeat', $lang);
    }

    if (preg_match('/^(then|next|continue|more|go on|and then|what next|so)$/i', $normalized)) {
        return ai_text('pre_next', $lang);
    }

    if (is_starting_location_prompt($normalized)) {
        return build_pre_generation_starting_location_reply($preference, $originName, $lang);
    }

    if (is_confirmation_prompt($normalized)) {
        return build_pre_generation_confirmation_reply($preference, $startDate, $originName, $lang);
    }

    if (is_preference_check_prompt($normalized)) {
        return build_pre_generation_readiness_reply($preference, $startDate, $originName, $lang);
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

function build_pre_generation_starting_location_reply(array $preference, string $originName, string $lang = 'en'): string
{
    if (is_meaningful_value($originName)) {
        return ai_text('pre_use_origin', $lang, ['name' => clean_short_value($originName)]);
    }

    $district = first_meaningful_preference_value($preference, ['preferred_districts']);
    $state = first_meaningful_preference_value($preference, ['preferred_states']);
    $area = is_meaningful_value($district) ? $district : (is_meaningful_value($state) ? $state : ai_text('pre_area_fallback', $lang));

    return ai_text('pre_choose_origin', $lang, ['area' => clean_short_value($area)]);
}

function build_pre_generation_confirmation_reply(array $preference, string $startDate, string $originName, string $lang = 'en'): string
{
    $missing = pre_generation_missing_items($preference, $startDate, $originName, $lang);
    if (empty($missing)) {
        return ai_text('pre_confirm_ready', $lang);
    }

    return ai_text('pre_confirm_missing', $lang, ['items' => implode(', ', array_slice($missing, 0, 5))]);
}

function build_pre_generation_readiness_reply(array $preference, string $startDate, string $originName, string $lang = 'en'): string
{
    $missing = pre_generation_missing_items($preference, $startDate, $originName, $lang);
    if (empty($missing)) {
        return ai_text('pre_ready', $lang);
    }

    return ai_text('pre_not_ready', $lang, ['items' => implode(', ', array_slice($missing, 0, 5))]);
}

function pre_generation_missing_items(array $preference, string $startDate, string $originName, string $lang = 'en'): array
{
    $missing = [];

    if (!is_meaningful_value($startDate)) $missing[] = ai_text('missing_start_date', $lang);
    if (!is_meaningful_value($originName)) $missing[] = ai_text('missing_origin', $lang);
    if (!is_meaningful_value(first_meaningful_preference_value($preference, ['trip_days']))) $missing[] = ai_text('missing_days', $lang);
    if (!is_meaningful_value(first_meaningful_preference_value($preference, ['budget', 'budget_tier']))) $missing[] = ai_text('missing_budget', $lang);
    if (!is_meaningful_value(first_meaningful_preference_value($preference, ['transport_type']))) $missing[] = ai_text('missing_transport', $lang);

    $state = first_meaningful_preference_value($preference, ['preferred_states']);
    $district = first_meaningful_preference_value($preference, ['preferred_districts']);
    if (!is_meaningful_value($state) && !is_meaningful_value($district)) $missing[] = ai_text('missing_area', $lang);

    if (!is_meaningful_value(first_meaningful_preference_value($preference, ['interests']))) $missing[] = ai_text('missing_interests', $lang);

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
