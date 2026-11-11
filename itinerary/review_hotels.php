<?php
// itinerary/review_hotels.php
// Hotel suggestions near a stop of an itinerary, for the review page. They come from the
// local lodging list (see HotelRecommendationService); prices are planning estimates.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../config/db_connect.php";
require_once "../services/CostEstimationService.php";
require_once "../services/HotelRecommendationService.php";

header('Content-Type: application/json; charset=utf-8');

function review_hotels_reply(array $payload, int $httpStatus = 200): never
{
    http_response_code($httpStatus);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
    review_hotels_reply(['status' => 'error', 'message' => 'Unauthorized'], 401);
}

$travellerId = (int)($_SESSION["traveller_id"] ?? 0);
$itineraryId = (int)($_GET["itinerary_id"] ?? 0);
$requestedItemId = (int)($_GET["item_id"] ?? 0);
$requestedPlaceId = (int)($_GET["place_id"] ?? 0);
session_write_close();   // nothing more is stored in the session; let the traveller's other requests through

if ($itineraryId <= 0 || $travellerId <= 0) {
    review_hotels_reply(['status' => 'error', 'message' => 'Invalid itinerary ID'], 422);
}

$stmt = $conn->prepare("
    SELECT i.total_days, i.start_date, tp.budget, tp.traveller_type, tp.party_size
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
    review_hotels_reply(['status' => 'error', 'message' => 'Itinerary not found'], 404);
}

// Search around: a place the traveller is previewing as a replacement, else a given stop,
// else the last stop of the trip.
$stop = null;
if ($requestedPlaceId > 0) {
    $stmt = $conn->prepare("
        SELECT 0 AS item_id, place_id, name AS item_title, 0 AS day_no, latitude, longitude, state, district
        FROM cultural_places
        WHERE place_id = ? AND is_active = 1
        LIMIT 1
    ");
    $stmt->bind_param("i", $requestedPlaceId);
    $stmt->execute();
    $stop = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
/** The last visitable stop of the itinerary that meets a condition on its item row, or null. */
$findStop = static function (string $condition, int ...$ids) use ($conn, $itineraryId): ?array {
    $stmt = $conn->prepare("
        SELECT ii.item_id, ii.place_id, ii.item_title, ii.day_no,
               COALESCE(ii.item_latitude, cp.latitude) AS latitude,
               COALESCE(ii.item_longitude, cp.longitude) AS longitude,
               COALESCE(cp.state, '') AS state, COALESCE(cp.district, '') AS district
        FROM itinerary_items ii
        LEFT JOIN cultural_places cp ON cp.place_id = ii.place_id
        WHERE ii.itinerary_id = ? AND ii.item_type NOT IN ('hotel', 'transport', 'note') {$condition}
        ORDER BY ii.day_no DESC, ii.sequence_no DESC
        LIMIT 1
    ");
    $params = array_merge([$itineraryId], $ids);
    $stmt->bind_param(str_repeat("i", count($params)), ...$params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
};
if (!$stop && $requestedItemId > 0) {
    // The stop that was named. If it is not a place to visit (a hotel row), the last stop of its day.
    $stop = $findStop("AND ii.item_id = ?", $requestedItemId)
        ?? $findStop("AND ii.day_no = (SELECT day_no FROM itinerary_items WHERE item_id = ? AND itinerary_id = ii.itinerary_id)", $requestedItemId);
}
if (!$stop) {
    $stop = $findStop("");
}
if (!$stop) {
    review_hotels_reply(['status' => 'empty', 'message' => 'No itinerary stop was found to look for hotels near.', 'hotels' => []]);
}

$lat = $stop['latitude'] !== null ? (float)$stop['latitude'] : 0.0;
$lng = $stop['longitude'] !== null ? (float)$stop['longitude'] : 0.0;
$state = trim((string)$stop['state']);
$lastStop = [
    'item_id' => (int)$stop['item_id'],
    'place_id' => (int)$stop['place_id'],
    'title' => (string)$stop['item_title'],
    'day_no' => (int)$stop['day_no'],
    'state' => $state,
    'district' => trim((string)$stop['district']),
    'latitude' => $lat,
    'longitude' => $lng,
];

$travellerType = (string)($itinerary['traveller_type'] ?? 'solo');
$partySize = CostEstimationService::resolvePartySize($travellerType, isset($itinerary['party_size']) ? (int)$itinerary['party_size'] : null);
$roomBudget = HotelRecommendationService::nightlyRoomBudget(
    (float)($itinerary['budget'] ?? 0),
    (int)$itinerary['total_days'],
    CostEstimationService::roomCount($partySize)
);

$hotelService = new HotelRecommendationService($conn);
$hotels = $hotelService->recommend($lat, $lng, $roomBudget, 15.0, 8);
$message = 'Places to stay near ' . $lastStop['title'] . '. Prices are planning estimates, not offers.';
if (!$hotels && $state !== '') {
    $hotels = $hotelService->recommendByState($state, $roomBudget, 8);
    $message = 'Nothing is listed close to this stop, so these are places to stay elsewhere in ' . $state . '. Prices are planning estimates.';
}
if (!$hotels) {
    review_hotels_reply(['status' => 'empty', 'message' => 'No places to stay are listed near this stop yet.', 'last_stop' => $lastStop, 'hotels' => []]);
}

// The link for checking the real price carries the night's date when the stop belongs to a day of the trip.
$firstDay = DateTimeImmutable::createFromFormat('!Y-m-d', (string)($itinerary['start_date'] ?? ''));
$checkIn = $firstDay && $lastStop['day_no'] > 0 ? $firstDay->modify('+' . ($lastStop['day_no'] - 1) . ' days')->format('Y-m-d') : null;
foreach ($hotels as &$hotel) {
    $hotel['price_check_url'] = HotelRecommendationService::priceCheckUrl($hotel['name'], $hotel['state'], $checkIn, 1, $partySize, CostEstimationService::roomCount($partySize));
}
unset($hotel);

review_hotels_reply(['status' => 'success', 'message' => $message, 'last_stop' => $lastStop, 'hotels' => $hotels]);
