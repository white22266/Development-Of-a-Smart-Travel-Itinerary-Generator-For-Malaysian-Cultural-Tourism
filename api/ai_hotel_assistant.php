<?php
// api/ai_hotel_assistant.php
// The hotel part of the travel assistant: suggests places to stay near the last stop of an
// itinerary, and saves the one the traveller confirms. Suggestions come from the local lodging
// list; the language model only words the answer.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../config/db_connect.php";
require_once "../config/api_keys.php";
require_once "../config/ai_language_guard.php";
require_once "../services/CostEstimationService.php";
require_once "../services/HotelRecommendationService.php";
require_once "../services/ItineraryHotelService.php";
require_once "../services/ItineraryEditService.php";
require_once "../services/AiTravelAssistantService.php";

header("Content-Type: application/json; charset=utf-8");

function hotel_assistant_reply(array $payload, int $httpStatus = 200): never
{
    http_response_code($httpStatus);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
    hotel_assistant_reply(["status" => "error", "answer" => "Unauthorized."], 401);
}

$travellerId = (int)($_SESSION["traveller_id"] ?? 0);
$itineraryId = (int)($_POST["itinerary_id"] ?? 0);
$action = trim((string)($_POST["action"] ?? "recommend"));
$message = trim((string)($_POST["message"] ?? ""));
ai_reject_chinese_json($message, "answer");

if (!in_array($action, ["recommend", "confirm_hotel"], true) || $travellerId <= 0 || $itineraryId <= 0) {
    hotel_assistant_reply(["status" => "error", "answer" => "Invalid hotel assistant request."], 400);
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
    hotel_assistant_reply(["status" => "error", "answer" => "Itinerary not found."], 404);
}

$tripDays = max(1, (int)$itinerary["total_days"]);
$travellerType = (string)($itinerary["traveller_type"] ?? "solo");
$partySize = CostEstimationService::resolvePartySize($travellerType, isset($itinerary["party_size"]) ? (int)$itinerary["party_size"] : null);
$roomBudget = HotelRecommendationService::nightlyRoomBudget((float)($itinerary["budget"] ?? 0), $tripDays, CostEstimationService::roomCount($partySize));
$hotelService = new HotelRecommendationService($conn);

// ---------------------------------------------------------------- save the hotel the traveller confirmed
if ($action === "confirm_hotel") {
    if ($tripDays < 2) {
        hotel_assistant_reply(["status" => "error", "answer" => "A one-day trip has no night to book a hotel for."]);
    }
    $hotel = $hotelService->find((int)($_POST["hotel_id"] ?? 0), $roomBudget);
    if ($hotel === null) {
        hotel_assistant_reply(["status" => "error", "answer" => "That hotel is no longer in the list. Please ask for hotel suggestions again and choose another one."]);
    }

    try {
        $conn->begin_transaction();
        $saved = ItineraryHotelService::saveNights($conn, $itineraryId, $tripDays, array_fill(1, $tripDays - 1, $hotel));
        ItineraryEditService::refresh($conn, $itineraryId);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        error_log("Hotel confirmation failed: " . $e->getMessage());
        hotel_assistant_reply(["status" => "error", "answer" => "Could not save the selected hotel. Please try again."], 500);
    }
    hotel_assistant_reply([
        "status" => "success",
        "answer" => "Hotel confirmed: " . $hotel["name"] . ". It is now in the itinerary, the route and the cost summary.",
        "hotel_nights_saved" => $saved["nights"],
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
$hotels = array_slice(hotel_assistant_apply_wishes($hotels, $message, $askedMax), 0, 5);

if (!$hotels) {
    hotel_assistant_reply([
        "status" => "success",
        "answer" => "I could not find a place to stay that matches near your last stop. Try a higher price limit, or ask again without one.",
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
], $hotels);

$context = [
    "assistant_rules" => [
        "This reply recommends exactly one hotel: the one in recommended_hotel. Do not name any other hotel.",
        "Use its name, type, estimated price and distance exactly as given; do not invent a rating.",
        "The price is a planning estimate, not an offer. Say so once.",
        "Do not say the hotel is booked or saved. Tell the user to click Confirm Hotel if they want to add it.",
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
$fallbackAnswer = "Suggested: " . $top["name"] . " (" . strtolower($top["kind_label"]) . "), about RM " . number_format($top["price_per_night"], 0) . " per night"
    . ($top["distance_km"] !== null ? ", " . number_format($top["distance_km"], 1) . " km from your last stop" : "") . "."
    . ($budgetForSearch > 0 ? " Your room budget is about RM " . number_format($budgetForSearch, 0) . " per night." : "")
    . " Prices are planning estimates. Look through the options below and click Confirm Hotel to add one to the itinerary.";

$assistant = new AiTravelAssistantService();
$result = $assistant->answer($message !== "" ? $message : "Recommend a suitable hotel for this itinerary.", $context);
$answer = trim((string)($result["answer"] ?? ""));
$source = (string)($result["source"] ?? "local_fallback");
// The first card under the answer is this hotel: an answer about another one would mislead.
if (($result["status"] ?? "") !== "success" || $answer === "" || stripos($answer, $top["name"]) === false) {
    $answer = $fallbackAnswer;
    $source = "local_fallback";
}
// However the answer was worded, the traveller must be told what kind of price this is.
if (stripos($answer, "estimate") === false) {
    $answer .= " Prices are planning estimates, not offers.";
}
$others = array_slice($hotels, 1, 2);
if ($others) {
    $answer .= "\n\nOther options nearby: " . implode("; ", array_map(
        static fn(array $h): string => $h["name"] . " (" . strtolower($h["kind_label"]) . ", about RM " . number_format($h["price_per_night"], 0) . ")",
        $others
    )) . ".";
}

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
