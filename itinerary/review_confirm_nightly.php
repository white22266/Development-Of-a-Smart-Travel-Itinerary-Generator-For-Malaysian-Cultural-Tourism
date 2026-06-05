<?php
// itinerary/review_confirm_nightly.php
// Applies itinerary review changes and optionally saves one hotel per selected overnight night.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../config/db_connect.php";
require_once "../config/api_keys.php";
require_once "../services/CostEstimationService.php";
require_once "../services/RouteService.php";
require_once "../services/HotelRecommendationService.php";

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

function nightly_table_has_column(mysqli $conn, string $table, string $column): bool
{
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return ($res && $res->num_rows > 0);
}

function nightly_item_type_from_category(string $category): string
{
    $cat = strtolower(trim($category));
    if ($cat === 'food') return 'food';
    if ($cat === 'festival') return 'festival';
    return 'attraction';
}

function nightly_load_replacement_place(mysqli $conn, int $placeId): ?array
{
    $hasEntranceFeeCol = nightly_table_has_column($conn, 'cultural_places', 'entrance_fee');
    $feeSelect = $hasEntranceFeeCol ? ', entrance_fee' : '';
    $stmt = $conn->prepare("SELECT place_id, name, state, district, category, latitude, longitude, estimated_cost{$feeSelect}, opening_hours, rating FROM cultural_places WHERE place_id = ? AND is_active = 1 LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('i', $placeId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function nightly_apply_item_replacement(mysqli $conn, int $itineraryId, int $itemId, int $placeId): bool
{
    $place = nightly_load_replacement_place($conn, $placeId);
    if (!$place) return false;

    $newCost = array_key_exists('entrance_fee', $place)
        ? max(0.0, (float)($place['entrance_fee'] ?? 0))
        : max(0.0, (float)($place['estimated_cost'] ?? 0));
    $newType = nightly_item_type_from_category((string)($place['category'] ?? ''));
    $districtNote = !empty($place['district']) ? ' | District: ' . $place['district'] : '';
    $newNotes = 'State: ' . ($place['state'] ?? '') . $districtNote . ' | Category: ' . ($place['category'] ?? '');

    $hasItemCoords = nightly_table_has_column($conn, 'itinerary_items', 'item_latitude')
        && nightly_table_has_column($conn, 'itinerary_items', 'item_longitude');

    if ($hasItemCoords) {
        $stmt = $conn->prepare("UPDATE itinerary_items SET place_id = ?, item_type = ?, item_title = ?, item_latitude = ?, item_longitude = ?, estimated_cost = ?, notes = ? WHERE item_id = ? AND itinerary_id = ?");
        if (!$stmt) return false;
        $lat = $place['latitude'] !== null ? (float)$place['latitude'] : null;
        $lng = $place['longitude'] !== null ? (float)$place['longitude'] : null;
        $stmt->bind_param('issdddsii', $placeId, $newType, $place['name'], $lat, $lng, $newCost, $newNotes, $itemId, $itineraryId);
    } else {
        $stmt = $conn->prepare("UPDATE itinerary_items SET place_id = ?, item_type = ?, item_title = ?, estimated_cost = ?, notes = ? WHERE item_id = ? AND itinerary_id = ?");
        if (!$stmt) return false;
        $stmt->bind_param('issdsii', $placeId, $newType, $place['name'], $newCost, $newNotes, $itemId, $itineraryId);
    }

    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function nightly_recalculate_routes(mysqli $conn, int $itineraryId): void
{
    $metaStmt = $conn->prepare("SELECT tp.transport_type FROM itineraries i LEFT JOIN traveller_preferences tp ON tp.preference_id = i.preference_id WHERE i.itinerary_id = ? LIMIT 1");
    if (!$metaStmt) return;
    $metaStmt->bind_param('i', $itineraryId);
    $metaStmt->execute();
    $meta = $metaStmt->get_result()->fetch_assoc();
    $metaStmt->close();

    $transportType = RouteService::normalizeTransportType((string)($meta['transport_type'] ?? 'car'));
    $apiKey = defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : '';
    $routeSvc = new RouteService($transportType, $apiKey);

    $hasItemCoords = nightly_table_has_column($conn, 'itinerary_items', 'item_latitude')
        && nightly_table_has_column($conn, 'itinerary_items', 'item_longitude');
    $coordSelect = $hasItemCoords
        ? 'COALESCE(ii.item_latitude, cp.latitude) AS latitude, COALESCE(ii.item_longitude, cp.longitude) AS longitude'
        : 'cp.latitude, cp.longitude';

    $itemsStmt = $conn->prepare("SELECT ii.item_id, {$coordSelect} FROM itinerary_items ii LEFT JOIN cultural_places cp ON cp.place_id = ii.place_id WHERE ii.itinerary_id = ? ORDER BY ii.day_no, ii.sequence_no");
    if (!$itemsStmt) return;
    $itemsStmt->bind_param('i', $itineraryId);
    $itemsStmt->execute();
    $res = $itemsStmt->get_result();

    $prevLat = null;
    $prevLng = null;
    $updates = [];
    while ($row = $res->fetch_assoc()) {
        $itemId = (int)$row['item_id'];
        $lat = $row['latitude'] !== null ? (float)$row['latitude'] : null;
        $lng = $row['longitude'] !== null ? (float)$row['longitude'] : null;
        $hasCoord = $lat !== null && $lng !== null && !($lat === 0.0 && $lng === 0.0);
        $distKm = null;
        $timeMin = null;
        if ($prevLat !== null && $prevLng !== null && $hasCoord) {
            $seg = $routeSvc->getSegment($prevLat, $prevLng, $lat, $lng);
            $distKm = (float)($seg['distance_km'] ?? 0);
            $timeMin = (int)($seg['travel_time_min'] ?? 0);
        }
        $updates[] = [$distKm, $timeMin, $itemId];
        if ($hasCoord) {
            $prevLat = $lat;
            $prevLng = $lng;
        }
    }
    $itemsStmt->close();

    $upd = $conn->prepare('UPDATE itinerary_items SET distance_km = ?, travel_time_min = ? WHERE item_id = ?');
    if (!$upd) return;
    foreach ($updates as [$distKm, $timeMin, $itemId]) {
        $upd->bind_param('dii', $distKm, $timeMin, $itemId);
        $upd->execute();
    }
    $upd->close();
}

function nightly_recalculate_total(mysqli $conn, int $itineraryId): void
{
    $partySelect = nightly_table_has_column($conn, 'traveller_preferences', 'party_size') ? ', tp.party_size' : '';
    $stmt = $conn->prepare("SELECT i.total_days, tp.transport_type, tp.budget, tp.budget_tier, tp.traveller_type{$partySelect} FROM itineraries i LEFT JOIN traveller_preferences tp ON tp.preference_id = i.preference_id WHERE i.itinerary_id = ? LIMIT 1");
    if (!$stmt) return;
    $stmt->bind_param('i', $itineraryId);
    $stmt->execute();
    $meta = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$meta) return;

    $itemsStmt = $conn->prepare('SELECT item_type, estimated_cost, distance_km FROM itinerary_items WHERE itinerary_id = ?');
    if (!$itemsStmt) return;
    $itemsStmt->bind_param('i', $itineraryId);
    $itemsStmt->execute();
    $res = $itemsStmt->get_result();

    $items = [];
    $totalDistanceKm = 0.0;
    while ($row = $res->fetch_assoc()) {
        $items[] = $row;
        $totalDistanceKm += (float)($row['distance_km'] ?? 0);
    }
    $itemsStmt->close();

    $travellerType = (string)($meta['traveller_type'] ?? 'solo');
    $partySize = CostEstimationService::resolvePartySize($travellerType, isset($meta['party_size']) ? (int)$meta['party_size'] : null);
    $costService = new CostEstimationService((string)($meta['transport_type'] ?? 'car'), (int)($meta['total_days'] ?? 1), (float)($meta['budget'] ?? 0), $travellerType, $partySize);
    $tierDefaults = CostEstimationService::budgetTierDefaults((string)($meta['budget_tier'] ?? 'normal'), (float)($meta['budget'] ?? 0), (int)($meta['total_days'] ?? 1), $partySize);
    $breakdown = $costService->calculate($items, $totalDistanceKm, $tierDefaults['hotel'], 3, $tierDefaults['meal']);
    $total = (float)($breakdown['total_cost'] ?? 0);

    $upd = $conn->prepare('UPDATE itineraries SET total_estimated_cost = ? WHERE itinerary_id = ?');
    if (!$upd) return;
    $upd->bind_param('di', $total, $itineraryId);
    $upd->execute();
    $upd->close();
}

function nightly_insert_hotel_item(mysqli $conn, int $itineraryId, int $dayNo, int $nightNo, int $totalNights, array $hotel): void
{
    $name = trim((string)($hotel['name'] ?? ''));
    if ($name === '') $name = 'Selected hotel';

    $nightlyRate = max(0.0, (float)($hotel['price_per_night'] ?? 0));
    $hotelLat = isset($hotel['latitude']) ? (float)$hotel['latitude'] : null;
    $hotelLng = isset($hotel['longitude']) ? (float)$hotel['longitude'] : null;
    $hotelAddress = trim((string)($hotel['address'] ?? ''));
    $hotelState = trim((string)($hotel['state'] ?? ''));
    $hotelDistrict = trim((string)($hotel['district'] ?? ''));
    $googlePlaceId = trim((string)($hotel['google_place_id'] ?? $hotel['place_id'] ?? ''));
    $priceSource = trim((string)($hotel['price_source'] ?? 'planning_estimate'));

    $lastSeq = $conn->prepare('SELECT MAX(sequence_no) AS ms FROM itinerary_items WHERE itinerary_id = ? AND day_no = ?');
    $lastSeq->bind_param('ii', $itineraryId, $dayNo);
    $lastSeq->execute();
    $lsRow = $lastSeq->get_result()->fetch_assoc();
    $lastSeq->close();
    $seqNo = (int)($lsRow['ms'] ?? 0) + 1;

    $hotelStartTime = '20:00:00';
    $hotelEndTime = '20:30:00';
    $lastTime = $conn->prepare("SELECT end_time FROM itinerary_items WHERE itinerary_id = ? AND day_no = ? AND item_type <> 'hotel' AND end_time IS NOT NULL ORDER BY sequence_no DESC LIMIT 1");
    if ($lastTime) {
        $lastTime->bind_param('ii', $itineraryId, $dayNo);
        $lastTime->execute();
        $lastTimeRow = $lastTime->get_result()->fetch_assoc();
        $lastTime->close();
        if (!empty($lastTimeRow['end_time'])) {
            $lastEndMin = ((int)date('G', strtotime($lastTimeRow['end_time'])) * 60) + (int)date('i', strtotime($lastTimeRow['end_time']));
            $hotelStartMin = max($lastEndMin + 30, 20 * 60);
            if ($hotelStartMin > (22 * 60 + 30)) $hotelStartMin = 22 * 60 + 30;
            $hotelStartTime = sprintf('%02d:%02d:00', intdiv($hotelStartMin, 60), $hotelStartMin % 60);
            $hotelEndTime = date('H:i:s', strtotime($hotelStartTime . ' +30 minutes'));
        }
    }

    $locationBits = array_filter([$hotelDistrict, $hotelState, $hotelAddress]);
    $hotelNote = 'Optional hotel night ' . $nightNo . ' of ' . $totalNights
        . ' | Nightly accommodation after Day ' . $dayNo
        . ' | Source: ' . $priceSource
        . (!empty($locationBits) ? ' | ' . implode(', ', $locationBits) : '')
        . ($googlePlaceId !== '' ? ' | Google Place ID: ' . $googlePlaceId : '')
        . ' | RM ' . number_format($nightlyRate, 2) . '/night';

    $hasItemCoords = nightly_table_has_column($conn, 'itinerary_items', 'item_latitude')
        && nightly_table_has_column($conn, 'itinerary_items', 'item_longitude');

    if ($hasItemCoords) {
        $ins = $conn->prepare("INSERT INTO itinerary_items (itinerary_id, day_no, sequence_no, item_type, place_id, item_title, item_latitude, item_longitude, start_time, end_time, estimated_cost, notes) VALUES (?, ?, ?, 'hotel', NULL, ?, ?, ?, ?, ?, ?, ?)");
        if ($ins) {
            $ins->bind_param('iiisddssds', $itineraryId, $dayNo, $seqNo, $name, $hotelLat, $hotelLng, $hotelStartTime, $hotelEndTime, $nightlyRate, $hotelNote);
            $ins->execute();
            $ins->close();
        }
    } else {
        $ins = $conn->prepare("INSERT INTO itinerary_items (itinerary_id, day_no, sequence_no, item_type, place_id, item_title, start_time, end_time, estimated_cost, notes) VALUES (?, ?, ?, 'hotel', NULL, ?, ?, ?, ?, ?)");
        if ($ins) {
            $ins->bind_param('iiisssds', $itineraryId, $dayNo, $seqNo, $name, $hotelStartTime, $hotelEndTime, $nightlyRate, $hotelNote);
            $ins->execute();
            $ins->close();
        }
    }
}

$own = $conn->prepare("SELECT i.itinerary_id, i.total_days, tp.budget FROM itineraries i LEFT JOIN traveller_preferences tp ON tp.preference_id = i.preference_id WHERE i.itinerary_id = ? AND i.traveller_id = ? LIMIT 1");
$own->bind_param('ii', $itineraryId, $travellerId);
$own->execute();
$itinerary = $own->get_result()->fetch_assoc();
$own->close();
if (!$itinerary) {
    echo json_encode(['status' => 'error', 'message' => 'Itinerary not found']);
    exit;
}

$totalDays = max(1, (int)($itinerary['total_days'] ?? 1));
$totalNights = max(0, $totalDays - 1);
$rejectedCsv = trim((string)($_POST['rejected_ids'] ?? ''));
$replacementsJson = trim((string)($_POST['replacements_json'] ?? ''));
$hotelSelectionsJson = trim((string)($_POST['hotel_selections_json'] ?? ''));

$hotelSelections = $hotelSelectionsJson !== '' ? json_decode($hotelSelectionsJson, true) : [];
if (!is_array($hotelSelections)) $hotelSelections = [];

$rejectedIds = [];
if ($rejectedCsv !== '') {
    $rejectedIds = array_values(array_unique(array_filter(array_map('intval', explode(',', $rejectedCsv)))));
}

$conn->begin_transaction();
try {
    if ($replacementsJson !== '') {
        $decoded = json_decode($replacementsJson, true);
        if (is_array($decoded)) {
            foreach ($decoded as $itemIdRaw => $replacement) {
                $itemId = (int)$itemIdRaw;
                $placeId = is_array($replacement) ? (int)($replacement['place_id'] ?? 0) : (int)$replacement;
                if ($itemId <= 0 || $placeId <= 0 || in_array($itemId, $rejectedIds, true)) continue;
                nightly_apply_item_replacement($conn, $itineraryId, $itemId, $placeId);
            }
        }
    }

    if (!empty($rejectedIds)) {
        $ph = implode(',', array_fill(0, count($rejectedIds), '?'));
        $types = str_repeat('i', count($rejectedIds));
        $del = $conn->prepare("DELETE FROM itinerary_items WHERE item_id IN ($ph) AND itinerary_id = ?");
        $allParams = array_merge($rejectedIds, [$itineraryId]);
        $allTypes = $types . 'i';
        $del->bind_param($allTypes, ...$allParams);
        $del->execute();
        $del->close();
    }

    $clearHotel = $conn->prepare("DELETE FROM itinerary_items WHERE itinerary_id = ? AND item_type = 'hotel'");
    if ($clearHotel) {
        $clearHotel->bind_param('i', $itineraryId);
        $clearHotel->execute();
        $clearHotel->close();
    }

    $hotelTotal = 0.0;
    $selectedHotelCount = 0;
    for ($nightNo = 1; $nightNo <= $totalNights; $nightNo++) {
        $selection = $hotelSelections[(string)$nightNo] ?? $hotelSelections[$nightNo] ?? null;
        if (!is_array($selection)) continue;
        $hotel = $selection['hotel'] ?? $selection;
        if (!is_array($hotel) || trim((string)($hotel['name'] ?? '')) === '') continue;
        $dayNo = max(1, min($nightNo, $totalDays));
        nightly_insert_hotel_item($conn, $itineraryId, $dayNo, $nightNo, $totalNights, $hotel);
        $hotelTotal += max(0.0, (float)($hotel['price_per_night'] ?? 0));
        $selectedHotelCount++;
    }

    if (nightly_table_has_column($conn, 'itineraries', 'selected_hotel_name')) {
        $summaryName = $selectedHotelCount > 1 ? 'Optional nightly hotels selected' : ($selectedHotelCount === 1 ? 'Optional hotel selected' : null);
        $upd = $conn->prepare("UPDATE itineraries SET selected_hotel_name = ?, selected_hotel_nights = ?, selected_hotel_total_cost = ? WHERE itinerary_id = ?");
        if ($upd) {
            $upd->bind_param('sidi', $summaryName, $selectedHotelCount, $hotelTotal, $itineraryId);
            $upd->execute();
            $upd->close();
        }
    }

    $seqRes = $conn->query("SELECT item_id, day_no, sequence_no FROM itinerary_items WHERE itinerary_id = $itineraryId ORDER BY day_no, sequence_no");
    $byDay = [];
    while ($r = $seqRes->fetch_assoc()) $byDay[(int)$r['day_no']][] = (int)$r['item_id'];
    foreach ($byDay as $d => $itemIds) {
        foreach ($itemIds as $seq => $iid) {
            $conn->query('UPDATE itinerary_items SET sequence_no = ' . ($seq + 1) . ' WHERE item_id = ' . (int)$iid);
        }
    }

    nightly_recalculate_routes($conn, $itineraryId);
    nightly_recalculate_total($conn, $itineraryId);

    $conn->commit();
    echo json_encode(['status' => 'success', 'hotel_nights_saved' => $selectedHotelCount]);
} catch (Throwable $e) {
    $conn->rollback();
    error_log('Nightly confirm failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Could not save review changes.']);
}
