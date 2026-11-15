<?php
// api/ai_hotel_assistant.php
// The hotel part of the travel assistant: suggests places to stay near the last stop of an
// itinerary, and saves the one the traveller confirms. Suggestions come from the local lodging
// list; the language model only words the answer. The budget is checked as on the review page:
// the suggestions that keep the trip inside the budget come first, and a hotel that puts it over
// is saved (ItineraryEditService::setHotels()) only when the traveller confirms it a second time
// after being told by how much.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../config/db_connect.php";
require_once "../config/ai_language_guard.php";
require_once "../config/i18n.php";
require_once "../services/CostEstimationService.php";
require_once "../services/HotelRecommendationService.php";
require_once "../services/ItineraryHotelService.php";
require_once "../services/ItineraryEditService.php";
require_once "../services/AiTravelAssistantService.php";

header("Content-Type: application/json; charset=utf-8");

/** The language of this request; set once at the start. */
function hotel_assistant_lang(?string $set = null): string
{
    static $lang = "en";
    if ($set !== null) $lang = in_array($set, AI_LANGUAGES, true) ? $set : "en";
    return $lang;
}

function hotel_assistant_reply(array $payload, int $httpStatus = 200): never
{
    http_response_code($httpStatus);
    $payload["lang"] = hotel_assistant_lang();
    $payload["ui"] = ai_ui_labels(hotel_assistant_lang());
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$message = trim((string)($_POST["message"] ?? ""));
$lang = hotel_assistant_lang(ai_request_language($message));

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
    hotel_assistant_reply(["status" => "error", "answer" => ai_text("unauthorized", $lang)], 401);
}

$travellerId = (int)($_SESSION["traveller_id"] ?? 0);
$itineraryId = (int)($_POST["itinerary_id"] ?? 0);
$action = trim((string)($_POST["action"] ?? "recommend"));

if (!in_array($action, ["recommend", "confirm_hotel"], true) || $travellerId <= 0 || $itineraryId <= 0) {
    hotel_assistant_reply(["status" => "error", "answer" => ai_text("hotel_invalid_request", $lang)], 400);
}

$stmt = $conn->prepare("
    SELECT i.title, i.start_date, i.total_days,
           tp.budget, tp.transport_type, tp.interests, tp.preferred_states, tp.traveller_type, tp.party_size
    FROM itineraries i
    LEFT JOIN traveller_preferences tp ON tp.preference_id = i.preference_id
    WHERE i.itinerary_id = ? AND i.traveller_id = ?
    LIMIT 1
");
$stmt->bind_param("ii", $itineraryId, $travellerId);
$stmt->execute();
$itinerary = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$itinerary) {
    hotel_assistant_reply(["status" => "error", "answer" => ai_text("itinerary_not_found", $lang)], 404);
}

$tripDays = max(1, (int)$itinerary["total_days"]);
$travellerType = (string)($itinerary["traveller_type"] ?? "solo");
$partySize = CostEstimationService::resolvePartySize($travellerType, isset($itinerary["party_size"]) ? (int)$itinerary["party_size"] : null);
$roomBudget = HotelRecommendationService::nightlyRoomBudget((float)($itinerary["budget"] ?? 0), $tripDays, CostEstimationService::roomCount($partySize));
$hotelService = new HotelRecommendationService($conn);

// ---------------------------------------------------------------- save the hotel the traveller confirmed
if ($action === "confirm_hotel") {
    if ($tripDays < 2) {
        hotel_assistant_reply(["status" => "error", "answer" => ai_text("hotel_one_day", $lang)]);
    }
    $hotel = $hotelService->find((int)($_POST["hotel_id"] ?? 0), $roomBudget);
    if ($hotel === null) {
        hotel_assistant_reply(["status" => "error", "answer" => ai_text("hotel_gone", $lang)]);
    }

    // The same check as the review page (ItineraryEditService::setHotels()): a hotel that puts the
    // trip over the budget is not saved, and the answer says by how much. Confirming the same
    // hotel again in this chat is the traveller's explicit confirmation: then it is saved anyway.
    $warned = (int)($_SESSION["hotel_budget_warned"][$itineraryId] ?? 0);
    $confirmed = $warned > 0 && $warned === (int)$hotel["hotel_id"];
    try {
        $saved = ItineraryEditService::setHotels($conn, $itineraryId, array_fill(1, $tripDays - 1, $hotel), $confirmed);
    } catch (Throwable $e) {
        error_log("Hotel confirmation failed: " . $e->getMessage());
        hotel_assistant_reply(["status" => "error", "answer" => ai_text("hotel_save_failed", $lang)], 500);
    }
    if (!$saved["ok"] && $saved["code"] === "HOTEL_BUDGET") {
        $_SESSION["hotel_budget_warned"][$itineraryId] = (int)$hotel["hotel_id"];
        hotel_assistant_reply([
            "status" => "error",
            "code" => "HOTEL_BUDGET",
            "answer" => ai_text("hotel_over_budget", $lang, [
                "name" => $hotel["name"],
                "over" => number_format((float)$saved["over_by"], 2),
                "total" => number_format((float)$saved["total"], 2),
                "budget" => number_format((float)$saved["budget"], 2),
            ]),
            "over_by" => (float)$saved["over_by"],
        ]);
    }
    unset($_SESSION["hotel_budget_warned"][$itineraryId]);
    if (!$saved["ok"]) {
        hotel_assistant_reply(["status" => "error", "code" => $saved["code"], "answer" => i18n_refusal((string)$saved["code"], (string)$saved["message"], $lang)]);
    }
    $answer = ai_text("hotel_confirmed", $lang, ["name" => $hotel["name"]]);
    if ((float)($saved["over_by"] ?? 0) > 0) {
        $answer .= " " . ai_text("hotel_saved_over", $lang, ["over" => number_format((float)$saved["over_by"], 2)]);
    }
    if (!empty($saved["refused_nights"])) {
        $answer .= " " . ai_text("hotel_nights_refused", $lang, ["nights" => implode(", ", array_keys($saved["refused_nights"]))]);
    }
    hotel_assistant_reply([
        "status" => "success",
        "answer" => $answer,
        "hotel_nights_saved" => $saved["nights"],
        "over_by" => (float)($saved["over_by"] ?? 0),
        "reload" => true,
    ]);
}

// ---------------------------------------------------------------- suggest hotels
$stmt = $conn->prepare("
    SELECT ii.item_title, ii.item_type,
           COALESCE(ii.item_latitude, cp.latitude) AS latitude,
           COALESCE(ii.item_longitude, cp.longitude) AS longitude,
           cp.state, cp.district
    FROM itinerary_items ii
    LEFT JOIN cultural_places cp ON cp.place_id = ii.place_id
    WHERE ii.itinerary_id = ? AND ii.item_type NOT IN ('hotel', 'transport', 'note')
    ORDER BY ii.day_no DESC, ii.sequence_no DESC
    LIMIT 1
");
$stmt->bind_param("i", $itineraryId);
$stmt->execute();
$lastStop = $stmt->get_result()->fetch_assoc();
$stmt->close();

// "under RM 150" in the message is a budget for this question only.
$askedMax = preg_match('/(?:rm|under|below|less than|budget)\s*([0-9]+(?:\.[0-9]+)?)/i', $message, $m) ? (float)$m[1] : 0.0;
$budgetForSearch = $askedMax > 0 ? $askedMax : $roomBudget;
// "Somewhere cheap" without a figure: look for places well below what the budget would allow.
if ($askedMax <= 0 && preg_match('/\b(cheap|cheapest|budget|affordable)\b/i', $message)) {
    $budgetForSearch = $roomBudget > 0 ? min(80.0, $roomBudget * 0.4) : 80.0;
}

$hotels = [];
if ($lastStop && $lastStop["latitude"] !== null && $lastStop["longitude"] !== null) {
    $hotels = $hotelService->recommend((float)$lastStop["latitude"], (float)$lastStop["longitude"], $budgetForSearch, 12.0, 12);
}
if (!$hotels) {
    $state = trim(explode(",", (string)($lastStop["state"] ?? $itinerary["preferred_states"] ?? ""))[0]);
    if ($state !== "") $hotels = $hotelService->recommendByState($state, $budgetForSearch, 12);
}
$hotels = hotel_assistant_apply_wishes($hotels, $message, $askedMax);
// The budget, as the review page marks it: whether the trip stays inside it with each hotel for
// every night (ItineraryEditService::hotelBudget()); those inside it come first.
$budgetNote = "";
if ($hotels && $tripDays >= 2 && (float)($itinerary["budget"] ?? 0) > 0) {
    $marks = ItineraryEditService::hotelBudget($conn, $itineraryId, range(1, $tripDays - 1), $hotels);
    foreach ($hotels as $k => &$h) $h["budget"] = $marks[$k] ?? null;
    unset($h);
    $order = array_keys($hotels);
    usort($order, static fn(int $a, int $b): int => [empty($hotels[$a]["budget"]["within"]) ? 1 : 0, $a] <=> [empty($hotels[$b]["budget"]["within"]) ? 1 : 0, $b]);
    $hotels = array_map(static fn(int $k): array => $hotels[$k], $order);
    $budgetNote = !empty($hotels[0]["budget"]["within"]) ? ai_text("hotel_budget_first", $lang)
        : ai_text("hotel_budget_none", $lang, ["over" => number_format((float)($hotels[0]["budget"]["over_by"] ?? 0), 2)]);
}
$hotels = array_slice($hotels, 0, 5);

if (!$hotels) {
    hotel_assistant_reply([
        "status" => "success",
        "answer" => ai_text("hotel_none", $lang),
        "hotels" => [],
        "source" => "local_fallback",
    ]);
}

// What the language model may talk about: the first option, and nothing else.
$options = array_map(static fn(array $h): array => [
    "name" => $h["name"],
    "type" => $h["kind_label"] . ($h["stars"] !== null ? ", " . rtrim(rtrim(number_format($h["stars"], 1), "0"), ".") . " stars" : ""),
    "address" => $h["address"],
    "estimated_price_per_night_rm" => $h["price_per_night"],
    "distance_from_last_stop_km" => $h["distance_km"],
    "trip_within_budget_with_it" => isset($h["budget"]) ? (bool)$h["budget"]["within"] : null,
], $hotels);

$context = [
    "assistant_rules" => [
        "This reply recommends exactly one hotel: the one in recommended_hotel. Do not name any other hotel.",
        "Use its name, type, estimated price and distance exactly as given; do not invent a rating.",
        "The price is a planning estimate, not an offer. Say so once.",
        ai_text("hotel_rule_confirm", $lang),
    ],
    "itinerary" => [
        "title" => $itinerary["title"],
        "start_date" => $itinerary["start_date"],
        "total_days" => $tripDays,
        "budget" => (float)($itinerary["budget"] ?? 0),
        "transport_type" => $itinerary["transport_type"],
        "last_stop" => $lastStop ? ["name" => $lastStop["item_title"], "state" => $lastStop["state"], "district" => $lastStop["district"]] : null,
    ],
    "room_budget_per_night_rm" => round($budgetForSearch),
    "recommended_hotel" => $options[0],
];

$top = $hotels[0];
$fallbackAnswer = ai_text("hotel_suggested", $lang, [
        "name" => $top["name"],
        "kind" => strtolower($top["kind_label"]),
        "price" => number_format($top["price_per_night"], 0),
        "distance" => $top["distance_km"] !== null ? ai_text("hotel_distance", $lang, ["km" => number_format($top["distance_km"], 1)]) : "",
    ])
    . ($budgetForSearch > 0 ? " " . ai_text("hotel_room_budget", $lang, ["price" => number_format($budgetForSearch, 0)]) : "")
    . " " . ai_text("hotel_fallback_tail", $lang);

$assistant = new AiTravelAssistantService();
$result = $assistant->answer($message !== "" ? $message : ai_text("hotel_default_question", $lang), $context, $lang);
$answer = trim((string)($result["answer"] ?? ""));
$source = (string)($result["source"] ?? "local_fallback");
// The first card under the answer is this hotel: an answer about another one would mislead.
if (($result["status"] ?? "") !== "success" || $answer === "" || stripos($answer, $top["name"]) === false) {
    $answer = $fallbackAnswer;
    $source = "local_fallback";
}
// However the answer was worded, the traveller must be told what kind of price this is.
if (mb_stripos($answer, ai_text("hotel_estimate_word", $lang)) === false) {
    $answer .= " " . ai_text("hotel_estimate_note", $lang);
}
$others = array_slice($hotels, 1, 2);
if ($others) {
    $answer .= "\n\n" . ai_text("hotel_others", $lang, ["list" => implode("; ", array_map(
        static fn(array $h): string => ai_text("hotel_other_item", $lang, [
            "name" => $h["name"],
            "kind" => strtolower($h["kind_label"]),
            "price" => number_format($h["price_per_night"], 0),
        ]),
        $others
    ))]);
}

if ($budgetNote !== "") $answer .= "\n\n" . $budgetNote;

hotel_assistant_reply(["status" => "success", "answer" => $answer, "hotels" => $hotels, "source" => $source]);

/** Apply what the traveller asked for in words: a price limit, a star class, cheapest or best first. */
function hotel_assistant_apply_wishes(array $hotels, string $message, float $maxPrice): array
{
    if ($maxPrice > 0) {
        $hotels = array_values(array_filter($hotels, static fn($h) => $h["price_per_night"] <= $maxPrice));
    }
    if (preg_match('/([1-5])\s*[- ]?\s*star/i', $message, $m)) {
        $minStars = (float)$m[1];
        // Few places on the map carry a star class. Keep the request only when it leaves a choice.
        $withStars = array_values(array_filter($hotels, static fn($h) => $h["stars"] !== null && $h["stars"] >= $minStars));
        if ($withStars) $hotels = $withStars;
    }
    if (preg_match('/\b(cheap|cheapest|budget|affordable)\b/i', $message)) {
        usort($hotels, static fn($a, $b) => $a["price_per_night"] <=> $b["price_per_night"]);
    } elseif (preg_match('/\b(best|luxury|luxurious|nicest|premium)\b/i', $message)) {
        usort($hotels, static fn($a, $b) => [$b["stars"] ?? 0, $b["price_per_night"]] <=> [$a["stars"] ?? 0, $a["price_per_night"]]);
    }
    return $hotels;
}
