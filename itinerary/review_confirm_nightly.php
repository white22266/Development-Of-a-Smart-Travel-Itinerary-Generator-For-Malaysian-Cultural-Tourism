<?php
// itinerary/review_confirm_nightly.php
// Saves what the traveller decided on the review page: stops removed, stops replaced and,
// if they picked any, a hotel for each night.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../config/db_connect.php";
require_once "../services/CostEstimationService.php";
require_once "../services/HotelRecommendationService.php";
require_once "../services/ItineraryHotelService.php";
require_once "../services/ItineraryEditService.php";

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$travellerId = (int)($_SESSION["traveller_id"] ?? 0);
$itineraryId = (int)($_POST["itinerary_id"] ?? 0);
if ($itineraryId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid itinerary ID']);
    exit;
}

$own = $conn->prepare("
    SELECT i.total_days, tp.budget, tp.traveller_type, tp.party_size
    FROM itineraries i
    LEFT JOIN traveller_preferences tp ON tp.preference_id = i.preference_id
    WHERE i.itinerary_id = ? AND i.traveller_id = ?
    LIMIT 1
");
$own->bind_param('ii', $itineraryId, $travellerId);
$own->execute();
$itinerary = $own->get_result()->fetch_assoc();
$own->close();
if (!$itinerary) {
    echo json_encode(['status' => 'error', 'message' => 'Itinerary not found']);
    exit;
}

$totalDays = max(1, (int)$itinerary['total_days']);

$rejectedIds = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($_POST['rejected_ids'] ?? ''))))));

$replacements = json_decode((string)($_POST['replacements_json'] ?? ''), true);
if (!is_array($replacements)) $replacements = [];

$hotelSelections = json_decode((string)($_POST['hotel_selections_json'] ?? ''), true);
if (!is_array($hotelSelections)) $hotelSelections = [];

// Only the id of each chosen hotel is taken from the browser. Its name, location and price
// are read from the lodging list here, with the same budget the suggestions were made with.
$travellerType = (string)($itinerary['traveller_type'] ?? 'solo');
$partySize = CostEstimationService::resolvePartySize($travellerType, isset($itinerary['party_size']) ? (int)$itinerary['party_size'] : null);
$roomBudget = HotelRecommendationService::nightlyRoomBudget((float)($itinerary['budget'] ?? 0), $totalDays, CostEstimationService::roomCount($partySize));
$hotelService = new HotelRecommendationService($conn);
$hotelByNight = [];
foreach ($hotelSelections as $nightNo => $selection) {
    $hotel = is_array($selection) ? ($selection['hotel'] ?? $selection) : null;
    $found = is_array($hotel) ? $hotelService->find((int)($hotel['hotel_id'] ?? 0), $roomBudget) : null;
    if ($found !== null) $hotelByNight[(int)$nightNo] = $found;
}

$conn->begin_transaction();
try {
    foreach ($replacements as $itemIdRaw => $replacement) {
        $itemId = (int)$itemIdRaw;
        $placeId = is_array($replacement) ? (int)($replacement['place_id'] ?? 0) : (int)$replacement;
        if ($itemId <= 0 || $placeId <= 0 || in_array($itemId, $rejectedIds, true)) continue;
        ItineraryEditService::replaceStop($conn, $itineraryId, $itemId, $placeId);
    }

    if ($rejectedIds) {
        $placeholders = implode(',', array_fill(0, count($rejectedIds), '?'));
        $delete = $conn->prepare("DELETE FROM itinerary_items WHERE item_id IN ({$placeholders}) AND itinerary_id = ?");
        $params = array_merge($rejectedIds, [$itineraryId]);
        $delete->bind_param(str_repeat('i', count($params)), ...$params);
        $delete->execute();
        $delete->close();
    }

    $saved = ItineraryHotelService::saveNights($conn, $itineraryId, $totalDays, $hotelByNight);
    ItineraryEditService::refresh($conn, $itineraryId);

    $conn->commit();
    echo json_encode(['status' => 'success', 'hotel_nights_saved' => $saved['nights']]);
} catch (Throwable $e) {
    $conn->rollback();
    error_log('Review confirm failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Could not save review changes.']);
}
