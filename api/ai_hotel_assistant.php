<?php
// Controlled hotel assistant: uses live Google Places recommendations.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../config/db_connect.php";
require_once "../config/api_keys.php";
require_once "../config/ai_language_guard.php";
require_once "../services/CostEstimationService.php";
require_once "../services/HotelRecommendationService.php";
require_once "../services/RouteService.php";
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
ai_reject_chinese_json($message, "answer");

if (!in_array($action, ["recommend", "confirm_hotel"], true) || $travellerId <= 0 || $itineraryId <= 0) {
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

$budget = (float)($itinerary["budget"] ?? 0);
$tripDays = max(1, (int)($itinerary["total_days"] ?? 1));

if ($action === "confirm_hotel") {
    $placeId = trim((string)($_POST["hotel_place_id"] ?? $_POST["google_place_id"] ?? ""));
    if ($placeId === "") {
        echo json_encode([
            "status" => "error",
            "answer" => "No hotel was selected. Please choose a hotel option first.",
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $nights = max(1, $tripDays - 1);
    $nightlyBudget = $budget > 0 ? ($budget * 0.30) / $nights : 0.0;
    $hotelService = new HotelRecommendationService($conn);
    $hotel = $hotelService->detailsByPlaceId($placeId, $nightlyBudget);

    if (!$hotel || trim((string)($hotel["name"] ?? "")) === "") {
        echo json_encode([
            "status" => "error",
            "answer" => "I could not verify this hotel from Google Places. Please load hotel suggestions again and choose another hotel.",
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    try {
        $conn->begin_transaction();
        ai_hotel_save_confirmed_hotel($conn, $itineraryId, $tripDays, $hotel, $placeId);
        ai_hotel_resequence_items($conn, $itineraryId);
        ai_hotel_recalculate_routes($conn, $itineraryId);
        ai_hotel_recalculate_total($conn, $itineraryId);
        $conn->commit();

        $savedNights = max(1, $tripDays - 1);
        echo json_encode([
            "status" => "success",
            "answer" => "Hotel confirmed: " . (string)$hotel["name"] . ". It has been saved into the itinerary, route, and cost summary.",
            "hotel_nights_saved" => $savedNights,
            "reload" => true,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        $conn->rollback();
        error_log("AI hotel confirmation failed: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            "status" => "error",
            "answer" => "Could not save the selected hotel. Please try again.",
        ], JSON_UNESCAPED_SLASHES);
    }
    exit;
}

$lastPlace = load_last_place($conn, $itineraryId);
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

    if (stripos($message, "cheap") !== false || stripos($message, "budget") !== false) {
        usort($hotels, fn($a, $b) => (float)$a["price_per_night"] <=> (float)$b["price_per_night"]);
    } elseif (stripos($message, "best") !== false || stripos($message, "luxury") !== false) {
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

function ai_hotel_table_has_column(mysqli $conn, string $table, string $column): bool
{
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return ($res && $res->num_rows > 0);
}

function ai_hotel_save_confirmed_hotel(mysqli $conn, int $itineraryId, int $tripDays, array $hotel, string $fallbackPlaceId): void
{
    $clear = $conn->prepare("DELETE FROM itinerary_items WHERE itinerary_id = ? AND item_type = 'hotel'");
    if ($clear) {
        $clear->bind_param("i", $itineraryId);
        $clear->execute();
        $clear->close();
    }

    $nights = max(1, $tripDays - 1);
    $nightlyRate = max(0.0, (float)($hotel["price_per_night"] ?? 0));
    $hotelTotal = round($nightlyRate * $nights, 2);
    $name = trim((string)($hotel["name"] ?? ""));
    if ($name === "") $name = "Selected hotel";
    $lat = isset($hotel["latitude"]) ? (float)$hotel["latitude"] : null;
    $lng = isset($hotel["longitude"]) ? (float)$hotel["longitude"] : null;
    $address = trim((string)($hotel["address"] ?? ""));
    $state = trim((string)($hotel["state"] ?? ""));
    $district = trim((string)($hotel["district"] ?? ""));
    $googlePlaceId = trim((string)($hotel["google_place_id"] ?? $fallbackPlaceId));
    $source = trim((string)($hotel["price_source"] ?? $hotel["source"] ?? "google_places_live"));

    $hasCoords = ai_hotel_table_has_column($conn, "itinerary_items", "item_latitude")
        && ai_hotel_table_has_column($conn, "itinerary_items", "item_longitude");

    for ($nightNo = 1; $nightNo <= $nights; $nightNo++) {
        $dayNo = max(1, min($nightNo, $tripDays));
        $seqStmt = $conn->prepare("SELECT COALESCE(MAX(sequence_no), 0) AS max_seq FROM itinerary_items WHERE itinerary_id = ? AND day_no = ?");
        $seqStmt->bind_param("ii", $itineraryId, $dayNo);
        $seqStmt->execute();
        $seqRow = $seqStmt->get_result()->fetch_assoc();
        $seqStmt->close();
        $seqNo = (int)($seqRow["max_seq"] ?? 0) + 1;

        $hotelStartTime = "20:00:00";
        $hotelEndTime = "20:30:00";
        $timeStmt = $conn->prepare("
            SELECT end_time
            FROM itinerary_items
            WHERE itinerary_id = ? AND day_no = ? AND item_type <> 'hotel' AND end_time IS NOT NULL
            ORDER BY sequence_no DESC
            LIMIT 1
        ");
        if ($timeStmt) {
            $timeStmt->bind_param("ii", $itineraryId, $dayNo);
            $timeStmt->execute();
            $timeRow = $timeStmt->get_result()->fetch_assoc();
            $timeStmt->close();
            if (!empty($timeRow["end_time"])) {
                $lastEndMin = ((int)date("G", strtotime($timeRow["end_time"])) * 60) + (int)date("i", strtotime($timeRow["end_time"]));
                $hotelStartMin = max($lastEndMin + 30, 20 * 60);
                if ($hotelStartMin > (22 * 60 + 30)) $hotelStartMin = 22 * 60 + 30;
                $hotelStartTime = sprintf("%02d:%02d:00", intdiv($hotelStartMin, 60), $hotelStartMin % 60);
                $hotelEndTime = date("H:i:s", strtotime($hotelStartTime . " +30 minutes"));
            }
        }

        $locationBits = array_filter([$district, $state, $address]);
        $note = "AI confirmed hotel night " . $nightNo . " of " . $nights
            . " | Saved after traveller confirmation"
            . " | Source: " . $source
            . (!empty($locationBits) ? " | " . implode(", ", $locationBits) : "")
            . ($googlePlaceId !== "" ? " | Google Place ID: " . $googlePlaceId : "")
            . " | RM " . number_format($nightlyRate, 2) . "/night";

        if ($hasCoords) {
            $ins = $conn->prepare("
                INSERT INTO itinerary_items
                    (itinerary_id, day_no, sequence_no, item_type, place_id, item_title, item_latitude, item_longitude, start_time, end_time, estimated_cost, notes)
                VALUES
                    (?, ?, ?, 'hotel', NULL, ?, ?, ?, ?, ?, ?, ?)
            ");
            if (!$ins) throw new RuntimeException("Could not prepare hotel insert.");
            $ins->bind_param("iiisddssds", $itineraryId, $dayNo, $seqNo, $name, $lat, $lng, $hotelStartTime, $hotelEndTime, $nightlyRate, $note);
        } else {
            $ins = $conn->prepare("
                INSERT INTO itinerary_items
                    (itinerary_id, day_no, sequence_no, item_type, place_id, item_title, start_time, end_time, estimated_cost, notes)
                VALUES
                    (?, ?, ?, 'hotel', NULL, ?, ?, ?, ?, ?)
            ");
            if (!$ins) throw new RuntimeException("Could not prepare hotel insert.");
            $ins->bind_param("iiisssds", $itineraryId, $dayNo, $seqNo, $name, $hotelStartTime, $hotelEndTime, $nightlyRate, $note);
        }
        if (!$ins->execute()) {
            $err = $ins->error;
            $ins->close();
            throw new RuntimeException("Could not save hotel item: " . $err);
        }
        $ins->close();
    }

    if (ai_hotel_table_has_column($conn, "itineraries", "selected_hotel_name")) {
        $hotelId = null;
        $upd = $conn->prepare("
            UPDATE itineraries
            SET selected_hotel_id = ?, selected_hotel_name = ?, selected_hotel_nights = ?, selected_hotel_total_cost = ?
            WHERE itinerary_id = ?
        ");
        if ($upd) {
            $upd->bind_param("isidi", $hotelId, $name, $nights, $hotelTotal, $itineraryId);
            $upd->execute();
            $upd->close();
        }
    }
}

function ai_hotel_resequence_items(mysqli $conn, int $itineraryId): void
{
    $res = $conn->query("SELECT item_id, day_no FROM itinerary_items WHERE itinerary_id = " . (int)$itineraryId . " ORDER BY day_no, sequence_no, item_id");
    if (!$res) return;
    $byDay = [];
    while ($row = $res->fetch_assoc()) {
        $byDay[(int)$row["day_no"]][] = (int)$row["item_id"];
    }
    foreach ($byDay as $items) {
        foreach ($items as $idx => $itemId) {
            $conn->query("UPDATE itinerary_items SET sequence_no = " . ($idx + 1) . " WHERE item_id = " . (int)$itemId);
        }
    }
}

function ai_hotel_recalculate_routes(mysqli $conn, int $itineraryId): void
{
    $metaStmt = $conn->prepare("
        SELECT tp.transport_type
        FROM itineraries i
        LEFT JOIN traveller_preferences tp ON tp.preference_id = i.preference_id
        WHERE i.itinerary_id = ?
        LIMIT 1
    ");
    if (!$metaStmt) return;
    $metaStmt->bind_param("i", $itineraryId);
    $metaStmt->execute();
    $meta = $metaStmt->get_result()->fetch_assoc();
    $metaStmt->close();

    $transportType = RouteService::normalizeTransportType((string)($meta["transport_type"] ?? "car"));
    $routeSvc = new RouteService($transportType, defined("GOOGLE_MAPS_API_KEY") ? GOOGLE_MAPS_API_KEY : "");
    $hasCoords = ai_hotel_table_has_column($conn, "itinerary_items", "item_latitude")
        && ai_hotel_table_has_column($conn, "itinerary_items", "item_longitude");
    $coordSelect = $hasCoords
        ? "COALESCE(ii.item_latitude, cp.latitude) AS latitude, COALESCE(ii.item_longitude, cp.longitude) AS longitude"
        : "cp.latitude, cp.longitude";

    $itemsStmt = $conn->prepare("
        SELECT ii.item_id, {$coordSelect}
        FROM itinerary_items ii
        LEFT JOIN cultural_places cp ON cp.place_id = ii.place_id
        WHERE ii.itinerary_id = ?
        ORDER BY ii.day_no, ii.sequence_no
    ");
    if (!$itemsStmt) return;
    $itemsStmt->bind_param("i", $itineraryId);
    $itemsStmt->execute();
    $res = $itemsStmt->get_result();

    $prevLat = null;
    $prevLng = null;
    $updates = [];
    while ($row = $res->fetch_assoc()) {
        $itemId = (int)$row["item_id"];
        $lat = $row["latitude"] !== null ? (float)$row["latitude"] : null;
        $lng = $row["longitude"] !== null ? (float)$row["longitude"] : null;
        $hasValidCoords = $lat !== null && $lng !== null && !($lat === 0.0 && $lng === 0.0);
        $distKm = null;
        $timeMin = null;
        if ($prevLat !== null && $prevLng !== null && $hasValidCoords) {
            $seg = $routeSvc->getSegment($prevLat, $prevLng, $lat, $lng);
            $distKm = (float)($seg["distance_km"] ?? 0);
            $timeMin = (int)($seg["travel_time_min"] ?? 0);
        }
        $updates[] = [$distKm, $timeMin, $itemId];
        if ($hasValidCoords) {
            $prevLat = $lat;
            $prevLng = $lng;
        }
    }
    $itemsStmt->close();

    $upd = $conn->prepare("UPDATE itinerary_items SET distance_km = ?, travel_time_min = ? WHERE item_id = ?");
    if (!$upd) return;
    foreach ($updates as [$distKm, $timeMin, $itemId]) {
        $upd->bind_param("dii", $distKm, $timeMin, $itemId);
        $upd->execute();
    }
    $upd->close();
}

function ai_hotel_recalculate_total(mysqli $conn, int $itineraryId): void
{
    $partySelect = ai_hotel_table_has_column($conn, "traveller_preferences", "party_size") ? ", tp.party_size" : "";
    $stmt = $conn->prepare("
        SELECT i.total_days, tp.transport_type, tp.budget, tp.budget_tier, tp.traveller_type{$partySelect}
        FROM itineraries i
        LEFT JOIN traveller_preferences tp ON tp.preference_id = i.preference_id
        WHERE i.itinerary_id = ?
        LIMIT 1
    ");
    if (!$stmt) return;
    $stmt->bind_param("i", $itineraryId);
    $stmt->execute();
    $meta = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$meta) return;

    $itemsStmt = $conn->prepare("SELECT item_type, estimated_cost, distance_km FROM itinerary_items WHERE itinerary_id = ?");
    if (!$itemsStmt) return;
    $itemsStmt->bind_param("i", $itineraryId);
    $itemsStmt->execute();
    $res = $itemsStmt->get_result();
    $items = [];
    $totalDistanceKm = 0.0;
    while ($row = $res->fetch_assoc()) {
        $items[] = $row;
        $totalDistanceKm += (float)($row["distance_km"] ?? 0);
    }
    $itemsStmt->close();

    $travellerType = (string)($meta["traveller_type"] ?? "solo");
    $partySize = CostEstimationService::resolvePartySize($travellerType, isset($meta["party_size"]) ? (int)$meta["party_size"] : null);
    $tierDefaults = CostEstimationService::budgetTierDefaults(
        (string)($meta["budget_tier"] ?? "normal"),
        (float)($meta["budget"] ?? 0),
        (int)($meta["total_days"] ?? 1),
        $partySize
    );
    $costService = new CostEstimationService(
        (string)($meta["transport_type"] ?? "car"),
        (int)($meta["total_days"] ?? 1),
        (float)($meta["budget"] ?? 0),
        $travellerType,
        $partySize
    );
    $breakdown = $costService->calculate($items, $totalDistanceKm, $tierDefaults["hotel"], 3, $tierDefaults["meal"]);
    $total = (float)($breakdown["total_cost"] ?? 0);

    $upd = $conn->prepare("UPDATE itineraries SET total_estimated_cost = ? WHERE itinerary_id = ?");
    if (!$upd) return;
    $upd->bind_param("di", $total, $itineraryId);
    $upd->execute();
    $upd->close();
}
