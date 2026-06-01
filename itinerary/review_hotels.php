<?php
// itinerary/review_hotels.php
// Returns hotel suggestions near the final non-hotel stop of an itinerary.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../config/db_connect.php";
require_once "../config/api_keys.php";
require_once "../services/HotelRecommendationService.php";

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$travellerId = (int)($_SESSION["traveller_id"] ?? 0);
$itineraryId = (int)($_GET["itinerary_id"] ?? $_POST["itinerary_id"] ?? 0);

if ($itineraryId <= 0 || $travellerId <= 0) {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'Invalid itinerary ID']);
    exit;
}

function review_hotels_table_has_column(mysqli $conn, string $table, string $column): bool
{
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return ($res && $res->num_rows > 0);
}

function review_hotels_valid_coord($lat, $lng): bool
{
    if ($lat === null || $lng === null) return false;
    $lat = (float)$lat;
    $lng = (float)$lng;
    return $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180 && !($lat === 0.0 && $lng === 0.0);
}

// Verify ownership and load budget context.
$stmt = $conn->prepare("
    SELECT i.itinerary_id, i.total_days, tp.budget
    FROM itineraries i
    LEFT JOIN traveller_preferences tp ON tp.preference_id = i.preference_id
    WHERE i.itinerary_id = ? AND i.traveller_id = ?
    LIMIT 1
");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'System error while loading itinerary.']);
    exit;
}
$stmt->bind_param("ii", $itineraryId, $travellerId);
$stmt->execute();
$itinerary = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$itinerary) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Itinerary not found']);
    exit;
}

$hasItemCoords = review_hotels_table_has_column($conn, "itinerary_items", "item_latitude")
    && review_hotels_table_has_column($conn, "itinerary_items", "item_longitude");

$coordSelect = $hasItemCoords
    ? "COALESCE(ii.item_latitude, cp.latitude) AS latitude, COALESCE(ii.item_longitude, cp.longitude) AS longitude"
    : "cp.latitude AS latitude, cp.longitude AS longitude";

// The hotel should be recommended near the final real trip stop, not merely the state.
$lastStmt = $conn->prepare("
    SELECT ii.item_id, ii.item_title, ii.day_no, ii.sequence_no,
           {$coordSelect},
           COALESCE(cp.state, '') AS state,
           COALESCE(cp.district, '') AS district
    FROM itinerary_items ii
    LEFT JOIN cultural_places cp ON cp.place_id = ii.place_id
    WHERE ii.itinerary_id = ?
      AND ii.item_type NOT IN ('hotel', 'transport', 'note')
    ORDER BY ii.day_no DESC, ii.sequence_no DESC
    LIMIT 1
");
if (!$lastStmt) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'System error while locating the final stop.']);
    exit;
}
$lastStmt->bind_param("i", $itineraryId);
$lastStmt->execute();
$lastStop = $lastStmt->get_result()->fetch_assoc();
$lastStmt->close();

if (!$lastStop) {
    echo json_encode([
        'status' => 'empty',
        'message' => 'No itinerary stop was found for hotel recommendation.',
        'hotels' => [],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$lat = $lastStop['latitude'] !== null ? (float)$lastStop['latitude'] : null;
$lng = $lastStop['longitude'] !== null ? (float)$lastStop['longitude'] : null;
$state = trim((string)($lastStop['state'] ?? ''));

$tripDays = max(1, (int)($itinerary['total_days'] ?? 1));
$budget = (float)($itinerary['budget'] ?? 0);
$nightlyBudget = $budget > 0 ? ($budget * 0.30) / max(1, $tripDays - 1) : 0.0;

$hotelService = new HotelRecommendationService($conn);
$hotels = [];
$source = 'last_stop_nearby';

if (review_hotels_valid_coord($lat, $lng)) {
    $hotels = $hotelService->recommend((float)$lat, (float)$lng, $nightlyBudget, 10.0, 6);
}

// Fallback only when coordinates or nearby results are unavailable.
if (empty($hotels) && $state !== '') {
    $source = review_hotels_valid_coord($lat, $lng) ? 'state_fallback_after_empty_nearby' : 'state_fallback_no_coordinates';
    $hotels = $hotelService->recommendByState($state, $nightlyBudget, 6);
}

if (empty($hotels)) {
    echo json_encode([
        'status' => 'empty',
        'message' => 'No nearby hotels were returned by Google Places for the final stop.',
        'last_stop' => [
            'title' => (string)($lastStop['item_title'] ?? ''),
            'day_no' => (int)($lastStop['day_no'] ?? 0),
            'state' => $state,
            'district' => (string)($lastStop['district'] ?? ''),
            'latitude' => $lat,
            'longitude' => $lng,
        ],
        'source' => $source,
        'hotels' => [],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

echo json_encode([
    'status' => 'success',
    'message' => 'Hotel suggestions loaded.',
    'last_stop' => [
        'title' => (string)($lastStop['item_title'] ?? ''),
        'day_no' => (int)($lastStop['day_no'] ?? 0),
        'state' => $state,
        'district' => (string)($lastStop['district'] ?? ''),
        'latitude' => $lat,
        'longitude' => $lng,
    ],
    'source' => $source,
    'hotels' => $hotels,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
