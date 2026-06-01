<?php
// itinerary/review_hotels.php
// Returns hotel suggestions near the current final confirmed/active itinerary stop.
// Google Places is used for hotel discovery. SerpAPI is used only for optional pricing enrichment.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../config/db_connect.php";
require_once "../config/api_keys.php";
require_once "../services/HotelRecommendationService.php";
require_once "../services/HotelSearchCacheService.php";
require_once "../services/SerpApiHotelPricingService.php";

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$travellerId = (int)($_SESSION["traveller_id"] ?? 0);
$itineraryId = (int)($_GET["itinerary_id"] ?? $_POST["itinerary_id"] ?? 0);
$requestedItemId = (int)($_GET["item_id"] ?? $_POST["item_id"] ?? 0);
$requestedPlaceId = (int)($_GET["place_id"] ?? $_POST["place_id"] ?? 0);
$lookupPricing = (string)($_GET["lookup_pricing"] ?? $_POST["lookup_pricing"] ?? "0") === "1";

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

function review_hotels_load_place(mysqli $conn, int $placeId): ?array
{
    if ($placeId <= 0) return null;
    $stmt = $conn->prepare("
        SELECT place_id, name AS item_title, latitude, longitude, state, district
        FROM cultural_places
        WHERE place_id = ? AND is_active = 1
        LIMIT 1
    ");
    if (!$stmt) return null;
    $stmt->bind_param("i", $placeId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return null;
    $row['source_item'] = 'requested_place';
    return $row;
}

function review_hotels_load_item(mysqli $conn, int $itineraryId, int $itemId, bool $hasItemCoords): ?array
{
    if ($itemId <= 0) return null;
    $coordSelect = $hasItemCoords
        ? "COALESCE(ii.item_latitude, cp.latitude) AS latitude, COALESCE(ii.item_longitude, cp.longitude) AS longitude"
        : "cp.latitude AS latitude, cp.longitude AS longitude";

    $stmt = $conn->prepare("
        SELECT ii.item_id, ii.place_id, ii.item_title, ii.day_no, ii.sequence_no,
               {$coordSelect},
               COALESCE(cp.state, '') AS state,
               COALESCE(cp.district, '') AS district
        FROM itinerary_items ii
        LEFT JOIN cultural_places cp ON cp.place_id = ii.place_id
        WHERE ii.itinerary_id = ?
          AND ii.item_id = ?
          AND ii.item_type NOT IN ('hotel', 'transport', 'note')
        LIMIT 1
    ");
    if (!$stmt) return null;
    $stmt->bind_param("ii", $itineraryId, $itemId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return null;
    $row['source_item'] = 'requested_item';
    return $row;
}

function review_hotels_load_database_last_stop(mysqli $conn, int $itineraryId, bool $hasItemCoords): ?array
{
    $coordSelect = $hasItemCoords
        ? "COALESCE(ii.item_latitude, cp.latitude) AS latitude, COALESCE(ii.item_longitude, cp.longitude) AS longitude"
        : "cp.latitude AS latitude, cp.longitude AS longitude";

    $stmt = $conn->prepare("
        SELECT ii.item_id, ii.place_id, ii.item_title, ii.day_no, ii.sequence_no,
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
    if (!$stmt) return null;
    $stmt->bind_param("i", $itineraryId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return null;
    $row['source_item'] = 'database_last_stop';
    return $row;
}

function review_hotels_has_serp_prices(array $hotels): bool
{
    foreach ($hotels as $hotel) {
        if (($hotel['price_source'] ?? '') === 'serpapi_google_maps_price') return true;
    }
    return false;
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

$lastStop = null;
if ($requestedPlaceId > 0) {
    $lastStop = review_hotels_load_place($conn, $requestedPlaceId);
}
if (!$lastStop && $requestedItemId > 0) {
    $lastStop = review_hotels_load_item($conn, $itineraryId, $requestedItemId, $hasItemCoords);
}
if (!$lastStop) {
    $lastStop = review_hotels_load_database_last_stop($conn, $itineraryId, $hasItemCoords);
}

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
$district = trim((string)($lastStop['district'] ?? ''));
$placeName = trim((string)($lastStop['item_title'] ?? ''));

$lastStopPayload = [
    'item_id' => (int)($lastStop['item_id'] ?? 0),
    'place_id' => (int)($lastStop['place_id'] ?? $requestedPlaceId),
    'title' => $placeName,
    'day_no' => (int)($lastStop['day_no'] ?? 0),
    'state' => $state,
    'district' => $district,
    'latitude' => $lat,
    'longitude' => $lng,
    'source_item' => (string)($lastStop['source_item'] ?? ''),
];

if (!review_hotels_valid_coord($lat, $lng)) {
    echo json_encode([
        'status' => 'empty',
        'message' => 'The selected final stop does not have valid coordinates.',
        'last_stop' => $lastStopPayload,
        'hotels' => [],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$tripDays = max(1, (int)($itinerary['total_days'] ?? 1));
$budget = (float)($itinerary['budget'] ?? 0);
$nightlyBudget = $budget > 0 ? ($budget * 0.30) / max(1, $tripDays - 1) : 0.0;

$cache = new HotelSearchCacheService($conn, 87600); // Permanent practical cache: 10 years.
$cacheKey = $cache->makeKey((float)$lat, (float)$lng, $placeName, $state, $district);
$cached = $cache->get($cacheKey);

if ($cached) {
    $hotels = $cached['hotels'];
    $debug = [[
        'stage' => 'hotel_cache',
        'status' => 'HIT',
        'source' => $cached['source'],
        'has_serpapi_prices' => review_hotels_has_serp_prices($hotels),
    ]];

    // Only use SerpAPI when user explicitly asks for pricing and cached hotels do not already have SerpAPI prices.
    if ($lookupPricing && !review_hotels_has_serp_prices($hotels)) {
        $pricingService = new SerpApiHotelPricingService();
        $hotels = $pricingService->enrichPrices($hotels, (float)$lat, (float)$lng, $placeName, $state, $district);
        $debug = array_merge($debug, $pricingService->getLastDebug());
        $cache->set($cacheKey, (float)$lat, (float)$lng, $placeName, $state, $district, $hotels, 'google_places_with_optional_serpapi_pricing');
    }

    echo json_encode([
        'status' => 'success',
        'message' => $lookupPricing ? 'Hotel suggestions loaded from database cache with pricing check.' : 'Hotel suggestions loaded from database cache.',
        'last_stop' => $lastStopPayload,
        'source' => 'database_cache',
        'pricing_lookup_used' => $lookupPricing,
        'debug' => $debug,
        'hotels' => $hotels,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$hotelService = new HotelRecommendationService($conn);
$hotels = $hotelService->recommendNearPlace((float)$lat, (float)$lng, $placeName, $state, $district, $nightlyBudget, 15.0, 8);
$source = 'live_google_places_current_final_stop';
$debug = $hotelService->getLastDebug();

if (empty($hotels) && $state !== '') {
    $source = 'live_google_places_state_fallback_after_empty_nearby';
    $hotels = $hotelService->recommendByState($state, $nightlyBudget, 8);
    $debug = array_merge($debug, $hotelService->getLastDebug());
}

if (empty($hotels)) {
    echo json_encode([
        'status' => 'empty',
        'message' => 'No nearby hotels were returned by live Google Places for the selected final stop.',
        'last_stop' => $lastStopPayload,
        'source' => $source,
        'pricing_lookup_used' => false,
        'debug' => $debug,
        'hotels' => [],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

foreach ($hotels as &$hotel) {
    $hotel['price_source'] = $hotel['price_source'] ?? 'planning_estimate';
}
unset($hotel);

// Do not use SerpAPI automatically. Only call it when lookup_pricing=1.
if ($lookupPricing) {
    $pricingService = new SerpApiHotelPricingService();
    $hotels = $pricingService->enrichPrices($hotels, (float)$lat, (float)$lng, $placeName, $state, $district);
    $debug = array_merge($debug, $pricingService->getLastDebug());
    $source = 'google_places_with_optional_serpapi_pricing';
}

$cache->set($cacheKey, (float)$lat, (float)$lng, $placeName, $state, $district, $hotels, $source);

echo json_encode([
    'status' => 'success',
    'message' => $lookupPricing ? 'Live Google Places hotels loaded and pricing lookup completed.' : 'Live Google Places hotel suggestions loaded with estimated prices.',
    'last_stop' => $lastStopPayload,
    'source' => $source,
    'pricing_lookup_used' => $lookupPricing,
    'debug' => $debug,
    'hotels' => $hotels,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
