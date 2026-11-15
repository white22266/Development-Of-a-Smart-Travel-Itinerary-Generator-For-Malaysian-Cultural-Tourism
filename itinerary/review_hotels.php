<?php
// itinerary/review_hotels.php
// Hotel suggestions for a night of an itinerary, for the review page: around the base of that
// night (GET night) when the itinerary was planned with bases, otherwise near a stop. They come
// from the local lodging list (see HotelRecommendationService); prices are planning estimates.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../config/db_connect.php";
require_once "../config/i18n.php";
require_once "../services/CostEstimationService.php";
require_once "../services/HotelRecommendationService.php";
require_once "../services/ItineraryEditService.php";

header('Content-Type: application/json; charset=utf-8');

function review_hotels_reply(array $payload, int $httpStatus = 200): never
{
    http_response_code($httpStatus);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
    review_hotels_reply(['status' => 'error', 'message' => t('rv.e_unauthorized')], 401);
}

$travellerId = (int)($_SESSION["traveller_id"] ?? 0);
$itineraryId = (int)($_GET["itinerary_id"] ?? 0);
$requestedItemId = (int)($_GET["item_id"] ?? 0);
$requestedPlaceId = (int)($_GET["place_id"] ?? 0);
session_write_close();   // nothing more is stored in the session; let the traveller's other requests through

if ($itineraryId <= 0 || $travellerId <= 0) {
    review_hotels_reply(['status' => 'error', 'message' => t('rv.e_itinerary_id')], 422);
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
    review_hotels_reply(['status' => 'error', 'message' => t('rv.e_not_found')], 404);
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
    review_hotels_reply(['status' => 'empty', 'message' => t('rv.h_no_stop'), 'hotels' => []]);
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
// A night of an itinerary planned with bases is spent at the base of its day: the hotels are
// searched around it (ItineraryEditService::dayBases()), else around the stop as before.
$base = null;
$nightNo = (int)($_GET["night"] ?? 0);
if ($nightNo >= 1 && $nightNo < (int)$itinerary['total_days']) {
    $base = ItineraryEditService::dayBases($conn, $itineraryId)[$nightNo] ?? null;
}
if ($base !== null) {
    $hotels = $hotelService->recommend($base['point'][0], $base['point'][1], $roomBudget, 15.0, 8);
    $message = 'Places to stay near ' . $base['name'] . ', where night ' . $nightNo . ' is planned.';
    $lastStop['day_no'] = $nightNo;
    $lastStop['base'] = ['name' => $base['name'], 'latitude' => $base['point'][0], 'longitude' => $base['point'][1]];
} else {
    $hotels = $hotelService->recommend($lat, $lng, $roomBudget, 15.0, 8);
    $message = t('rv.h_near', ['title' => $lastStop['title']]);
}
if (!$hotels && $state !== '') {
    $hotels = $hotelService->recommendByState($state, $roomBudget, 8);
    $message = t('rv.h_elsewhere', ['state' => $state]);
}
if (!$hotels) {
    review_hotels_reply(['status' => 'empty', 'message' => t('rv.h_none'), 'last_stop' => $lastStop, 'hotels' => []]);
}

// The link for checking the real price carries the night's date when the stop belongs to a day of the trip.
$firstDay = DateTimeImmutable::createFromFormat('!Y-m-d', (string)($itinerary['start_date'] ?? ''));
$checkIn = $firstDay && $lastStop['day_no'] > 0 ? $firstDay->modify('+' . ($lastStop['day_no'] - 1) . ' days')->format('Y-m-d') : null;
foreach ($hotels as &$hotel) {
    $hotel['kind_label'] = t_choice('hotelkind', (string)($hotel['kind'] ?? 'hotel'));
    $hotel['price_check_url'] = HotelRecommendationService::priceCheckUrl($hotel['name'], $hotel['state'], $checkIn, 1, $partySize, CostEstimationService::roomCount($partySize));
}
unset($hotel);

// The budget: whether the trip stays inside it with each hotel for the night (and, when the night
// belongs to a run of nights at one base, for the whole run: GET nights=first-last), with the
// hotels the page chose for other nights (GET chosen=night:hotel_id,...) and the stored ones for
// the rest, computed as the stored total is (ItineraryEditService::hotelBudget()). The hotels
// inside the budget come first, each group in its own order.
if ($nightNo >= 1 && $nightNo < (int)$itinerary['total_days']) {
    $others = [];
    foreach (explode(',', (string)($_GET['chosen'] ?? '')) as $pair) {
        if (!preg_match('/^(\d{1,3}):(\d{1,10})$/', trim($pair), $m) || (int)$m[1] === $nightNo) continue;
        $found = $hotelService->find((int)$m[2], $roomBudget);
        if ($found !== null) $others[(int)$m[1]] = $found;
    }
    $run = [$nightNo];
    if (preg_match('/^(\d{1,3})-(\d{1,3})$/', (string)($_GET['nights'] ?? ''), $m) && (int)$m[1] <= $nightNo && (int)$m[2] >= $nightNo) {
        $run = range((int)$m[1], min((int)$m[2], (int)$itinerary['total_days'] - 1));
    }
    $one = ItineraryEditService::hotelBudget($conn, $itineraryId, [$nightNo], $hotels, $others);
    $all = count($run) > 1 ? ItineraryEditService::hotelBudget($conn, $itineraryId, $run, $hotels, array_diff_key($others, array_flip($run))) : $one;
    foreach ($hotels as $k => &$hotel) {
        $hotel['budget'] = $one[$k] ?? null;
        $hotel['budget_run'] = count($run) > 1 ? ($all[$k] ?? null) : null;
        $hotel['budget_label'] = $hotel['budget'] === null || (float)$hotel['budget']['budget'] <= 0 ? ''
            : ($hotel['budget']['within'] ? t('rv.hotel_within') : t('rv.hotel_over', ['n' => number_format((float)$hotel['budget']['over_by'], 0)]));
    }
    unset($hotel);
    $order = array_keys($hotels);
    usort($order, static fn(int $a, int $b): int => [empty($hotels[$a]['budget']['within']) ? 1 : 0, $a] <=> [empty($hotels[$b]['budget']['within']) ? 1 : 0, $b]);
    $hotels = array_map(static fn(int $k): array => $hotels[$k], $order);
}

review_hotels_reply(['status' => 'success', 'message' => $message, 'last_stop' => $lastStop, 'hotels' => $hotels]);
