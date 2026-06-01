<?php
// Controlled hotel assistant: uses live Google Places recommendations.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../config/db_connect.php";
require_once "../config/api_keys.php";
require_once "../services/HotelRecommendationService.php";
require_once "../services/AiTravelAssistantService.php";

header("Content-Type: application/json; charset=utf-8");

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
    http_response_code(401);
    echo json_encode(["status" => "error", "answer" => "Unauthorized."]);
    exit;
}

$travellerId = (int)($_SESSION["traveller_id"] ?? 0);
$itineraryId = (int)($_POST["itinerary_id"] ?? 0);
$action = trim((string)($_POST["action"] ?? "recommend"));
$message = trim((string)($_POST["message"] ?? ""));

if ($action !== "recommend" || $travellerId <= 0 || $itineraryId <= 0) {
    http_response_code(400);
    echo json_encode(["status" => "error", "answer" => "Invalid hotel assistant request."]);
    exit;
}

$stmt = $conn->prepare("
    SELECT i.itinerary_id, i.title, i.start_date, i.total_days, i.total_estimated_cost,
           tp.budget, tp.budget_tier, tp.transport_type, tp.interests, tp.preferred_states
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
    http_response_code(404);
    echo json_encode(["status" => "error", "answer" => "Itinerary not found."]);
    exit;
}

$lastPlace = load_last_place($conn, $itineraryId);
$budget = (float)($itinerary["budget"] ?? 0);
$tripDays = max(1, (int)($itinerary["total_days"] ?? 1));
$nightlyBudget = extract_budget_from_message($message);
if ($nightlyBudget <= 0 && $budget > 0) {
    $nightlyBudget = ($budget * 0.30) / max(1, $tripDays - 1);
}

$hotelService = new HotelRecommendationService($conn);
$hotels = [];
if ($lastPlace && $lastPlace["latitude"] !== null && $lastPlace["longitude"] !== null) {
    $hotels = $hotelService->recommend((float)$lastPlace["latitude"], (float)$lastPlace["longitude"], $nightlyBudget, 12.0, 6);
}
if (empty($hotels)) {
    $state = (string)($lastPlace["state"] ?? $itinerary["preferred_states"] ?? "");
    $state = trim(explode(",", $state)[0] ?? "");
    if ($state !== "") {
        $hotels = $hotelService->recommendByState($state, $nightlyBudget, 6);
    }
}

$hotels = unique_hotels(filter_hotels_by_message($hotels, $message));
$hotelOptions = array_map(function ($hotel) {
    return [
        "hotel_id" => 0,
        "google_place_id" => (string)($hotel["google_place_id"] ?? ""),
        "name" => (string)($hotel["name"] ?? ""),
        "state" => (string)($hotel["state"] ?? ""),
        "district" => (string)($hotel["district"] ?? ""),
        "address" => (string)($hotel["address"] ?? ""),
        "price_per_night" => (float)($hotel["price_per_night"] ?? 0),
        "price_level" => isset($hotel["price_level"]) ? (int)$hotel["price_level"] : null,
        "rating" => (float)($hotel["rating"] ?? 0),
        "distance_km" => isset($hotel["distance_km"]) ? (float)$hotel["distance_km"] : null,
        "map_url" => (string)($hotel["map_url"] ?? ""),
        "source" => "google_places",
    ];
}, array_slice($hotels, 0, 5));

if (empty($hotelOptions)) {
    echo json_encode([
        "status" => "success",
        "answer" => "I could not find live nearby accommodation from Google Places right now. Please check the Google Maps API key, quota, or try a broader budget.",
        "hotels" => [],
        "source" => "local_fallback",
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$context = [
    "task" => "hotel recommendation only",
    "important_rule" => "Do not say the hotel is booked or saved. Tell the user to click Confirm Hotel if they want to add it.",
    "itinerary" => [
        "title" => $itinerary["title"],
        "start_date" => $itinerary["start_date"],
        "total_days" => (int)$itinerary["total_days"],
        "budget" => $budget,
        "transport_type" => $itinerary["transport_type"],
        "interests" => $itinerary["interests"],
        "preferred_states" => $itinerary["preferred_states"],
        "last_stop" => $lastPlace,
    ],
    "hotel_options" => $hotelOptions,
];

$fallbackAnswer = build_local_hotel_answer($hotelOptions, $nightlyBudget);
$model = defined("OLLAMA_MODEL") ? OLLAMA_MODEL : "qwen2.5:3b";
$baseUrl = defined("OLLAMA_BASE_URL") ? OLLAMA_BASE_URL : "http://127.0.0.1:11434";
$assistant = new AiTravelAssistantService($model, $baseUrl);
$result = $assistant->answer($message !== "" ? $message : "Recommend a suitable hotel for this itinerary.", $context);

$answer = trim((string)($result["answer"] ?? ""));
$source = (string)($result["source"] ?? "local_fallback");
if (($result["status"] ?? "") !== "success" || $answer === "") {
    $answer = $fallbackAnswer;
    $source = "local_fallback";
}

echo json_encode([
    "status" => "success",
    "answer" => $answer,
    "hotels" => $hotelOptions,
    "source" => $source,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

function load_last_place(mysqli $conn, int $itineraryId): ?array
{
    $stmt = $conn->prepare("
        SELECT ii.item_title, ii.item_type,
               COALESCE(ii.item_latitude, cp.latitude) AS latitude,
               COALESCE(ii.item_longitude, cp.longitude) AS longitude,
               cp.state, cp.district
        FROM itinerary_items ii
        LEFT JOIN cultural_places cp ON cp.place_id = ii.place_id
        WHERE ii.itinerary_id = ? AND ii.item_type <> 'hotel'
        ORDER BY ii.day_no DESC, ii.sequence_no DESC
        LIMIT 1
    ");
    if (!$stmt) return null;
    $stmt->bind_param("i", $itineraryId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return null;
    return [
        "name" => (string)$row["item_title"],
        "type" => (string)$row["item_type"],
        "latitude" => $row["latitude"] !== null ? (float)$row["latitude"] : null,
        "longitude" => $row["longitude"] !== null ? (float)$row["longitude"] : null,
        "state" => (string)($row["state"] ?? ""),
        "district" => (string)($row["district"] ?? ""),
    ];
}

function extract_budget_from_message(string $message): float
{
    if (preg_match('/(?:rm|under|below|less than|budget)\s*([0-9]+(?:\.[0-9]+)?)/i', $message, $m)) {
        return max(0.0, (float)$m[1]);
    }
    return 0.0;
}

function filter_hotels_by_message(array $hotels, string $message): array
{
    $maxPrice = extract_budget_from_message($message);
    if ($maxPrice > 0) {
        $hotels = array_values(array_filter($hotels, fn($h) => (float)($h["price_per_night"] ?? 0) <= $maxPrice));
    }

    if (preg_match('/([3-5](?:\.[0-9])?)\s*(?:star|rating)/i', $message, $m)) {
        $minRating = (float)$m[1];
        $hotels = array_values(array_filter($hotels, fn($h) => (float)($h["rating"] ?? 0) >= $minRating));
    }

    if (stripos($message, "cheap") !== false || stripos($message, "budget") !== false || str_contains($message, "便宜")) {
        usort($hotels, fn($a, $b) => (float)$a["price_per_night"] <=> (float)$b["price_per_night"]);
    } elseif (stripos($message, "best") !== false || stripos($message, "luxury") !== false || str_contains($message, "好")) {
        usort($hotels, fn($a, $b) => (float)$b["rating"] <=> (float)$a["rating"]);
    }

    return $hotels;
}

function unique_hotels(array $hotels): array
{
    $seen = [];
    $unique = [];
    foreach ($hotels as $hotel) {
        $key = strtolower(trim((string)($hotel["google_place_id"] ?? "")));
        if ($key === "") {
            $key = strtolower(trim(($hotel["name"] ?? "") . "|" . ($hotel["address"] ?? "")));
        }
        if ($key === "" || isset($seen[$key])) continue;
        $seen[$key] = true;
        $unique[] = $hotel;
    }
    return $unique;
}

function build_local_hotel_answer(array $hotels, float $nightlyBudget): string
{
    $top = $hotels[0];
    $budgetText = $nightlyBudget > 0 ? " Your nightly planning budget is about RM " . number_format($nightlyBudget, 0) . "." : "";
    return "Recommended hotel: " . $top["name"] . " at estimated RM " . number_format((float)$top["price_per_night"], 0)
        . "/night with Google rating " . number_format((float)$top["rating"], 1) . "."
        . $budgetText
        . " Review the live Google Places options below and click Confirm Hotel to add one into the itinerary and cost summary.";
}
