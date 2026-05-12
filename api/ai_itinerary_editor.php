<?php
// api/ai_itinerary_editor.php
// Controlled AI itinerary editor: suggests replacement stops and applies them only after user confirmation.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../config/db_connect.php";
require_once "../config/api_keys.php";
require_once "../services/AiTravelAssistantService.php";
require_once "../services/CostEstimationService.php";
require_once "../services/RouteService.php";

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

if ($travellerId <= 0 || $itineraryId <= 0) {
    http_response_code(400);
    echo json_encode(["status" => "error", "answer" => "Invalid itinerary editor request."]);
    exit;
}

$itinerary = load_itinerary($conn, $itineraryId, $travellerId);
if (!$itinerary) {
    http_response_code(404);
    echo json_encode(["status" => "error", "answer" => "Itinerary not found."]);
    exit;
}

if ($action === "confirm") {
    $itemId = (int)($_POST["item_id"] ?? 0);
    $placeId = (int)($_POST["place_id"] ?? 0);
    if ($itemId <= 0 || $placeId <= 0) {
        echo json_encode(["status" => "error", "answer" => "Invalid replacement selection."]);
        exit;
    }

    $item = load_item($conn, $itineraryId, $itemId);
    $place = load_place($conn, $placeId);
    if (!$item || !$place) {
        echo json_encode(["status" => "error", "answer" => "Replacement item or place was not found."]);
        exit;
    }

    if (is_duplicate_place($conn, $itineraryId, $placeId, $itemId)) {
        echo json_encode(["status" => "error", "answer" => "This place already exists in the itinerary. Choose another replacement."]);
        exit;
    }

    $ok = apply_replacement($conn, $itineraryId, $itemId, $place);
    if (!$ok) {
        echo json_encode(["status" => "error", "answer" => "Could not update the itinerary item."]);
        exit;
    }

    recalculate_itinerary_routes($conn, $itineraryId);
    recalculate_itinerary_total($conn, $itineraryId);

    echo json_encode([
        "status" => "success",
        "answer" => "Itinerary updated. The route and cost summary have been recalculated.",
        "updated_item" => [
            "item_id" => $itemId,
            "place_id" => $placeId,
            "name" => $place["name"],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$items = load_editable_items($conn, $itineraryId);
if (empty($items)) {
    echo json_encode(["status" => "success", "answer" => "There are no editable itinerary stops in this itinerary yet.", "proposals" => []]);
    exit;
}

$targets = select_target_items($items, $message);
$usedPlaceIds = array_values(array_filter(array_map(fn($item) => (int)($item["place_id"] ?? 0), $items)));
$tripStartDate = (string)($itinerary["start_date"] ?? "");
$proposals = [];

foreach ($targets as $target) {
    $alternative = find_alternative_place($conn, $target, $usedPlaceIds, $message, $tripStartDate);
    if (!$alternative) continue;
    $proposals[] = [
        "item_id" => (int)$target["item_id"],
        "current_title" => (string)$target["item_title"],
        "day_no" => (int)$target["day_no"],
        "sequence_no" => (int)$target["sequence_no"],
        "current_category" => (string)($target["category"] ?? $target["item_type"]),
        "new_place" => format_place($alternative),
        "reason" => build_replacement_reason($target, $alternative),
    ];
}

if (empty($proposals)) {
    echo json_encode([
        "status" => "success",
        "answer" => "I could not find a suitable replacement from the current cultural places database. Try asking for a different category, state, or budget direction.",
        "proposals" => [],
        "source" => "local_fallback",
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$context = [
    "task" => "itinerary stop replacement suggestion only",
    "important_rule" => "Do not say changes are saved. The user must click Confirm Change before the itinerary is updated.",
    "itinerary" => [
        "title" => $itinerary["title"],
        "start_date" => $itinerary["start_date"],
        "total_days" => (int)$itinerary["total_days"],
        "budget" => (float)($itinerary["budget"] ?? 0),
        "transport_type" => $itinerary["transport_type"],
        "interests" => $itinerary["interests"],
        "preferred_states" => $itinerary["preferred_states"],
    ],
    "replacement_proposals" => $proposals,
];

$fallbackAnswer = build_local_editor_answer($proposals);
$model = defined("OLLAMA_MODEL") ? OLLAMA_MODEL : "qwen2.5:3b";
$baseUrl = defined("OLLAMA_BASE_URL") ? OLLAMA_BASE_URL : "http://localhost:11434";
$assistant = new AiTravelAssistantService($model, $baseUrl);
$result = $assistant->answer($message !== "" ? $message : "Suggest improvements for this itinerary.", $context);

$answer = trim((string)($result["answer"] ?? ""));
$source = (string)($result["source"] ?? "local_fallback");
if (($result["status"] ?? "") !== "success" || $answer === "") {
    $answer = $fallbackAnswer;
    $source = "local_fallback";
}

echo json_encode([
    "status" => "success",
    "answer" => $answer,
    "proposals" => $proposals,
    "source" => $source,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

function load_itinerary(mysqli $conn, int $itineraryId, int $travellerId): ?array
{
    $stmt = $conn->prepare("
        SELECT i.itinerary_id, i.title, i.start_date, i.total_days, i.total_estimated_cost,
               tp.budget, tp.budget_tier, tp.transport_type, tp.interests, tp.preferred_states
        FROM itineraries i
        LEFT JOIN traveller_preferences tp ON tp.preference_id = i.preference_id
        WHERE i.itinerary_id = ? AND i.traveller_id = ?
        LIMIT 1
    ");
    if (!$stmt) return null;
    $stmt->bind_param("ii", $itineraryId, $travellerId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function load_editable_items(mysqli $conn, int $itineraryId): array
{
    $stmt = $conn->prepare("
        SELECT ii.item_id, ii.place_id, ii.day_no, ii.sequence_no, ii.item_type, ii.item_title,
               ii.start_time, ii.end_time, ii.estimated_cost,
               COALESCE(ii.item_latitude, cp.latitude) AS latitude,
               COALESCE(ii.item_longitude, cp.longitude) AS longitude,
               cp.state, cp.district, cp.category, cp.opening_hours, cp.rating
        FROM itinerary_items ii
        LEFT JOIN cultural_places cp ON cp.place_id = ii.place_id
        WHERE ii.itinerary_id = ? AND ii.item_type <> 'hotel' AND ii.place_id IS NOT NULL
        ORDER BY ii.day_no, ii.sequence_no
    ");
    if (!$stmt) return [];
    $stmt->bind_param("i", $itineraryId);
    $stmt->execute();
    $res = $stmt->get_result();
    $items = [];
    while ($row = $res->fetch_assoc()) $items[] = $row;
    $stmt->close();
    return $items;
}

function load_item(mysqli $conn, int $itineraryId, int $itemId): ?array
{
    $stmt = $conn->prepare("SELECT * FROM itinerary_items WHERE itinerary_id = ? AND item_id = ? AND item_type <> 'hotel' LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param("ii", $itineraryId, $itemId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function load_place(mysqli $conn, int $placeId): ?array
{
    $stmt = $conn->prepare("
        SELECT place_id, name, state, district, category, latitude, longitude, estimated_cost,
               entrance_fee, opening_hours, rating, visit_duration_min
        FROM cultural_places
        WHERE place_id = ? AND is_active = 1
        LIMIT 1
    ");
    if (!$stmt) return null;
    $stmt->bind_param("i", $placeId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function select_target_items(array $items, string $message): array
{
    $msg = strtolower($message);

    if (preg_match('/day\s*(\d+).*?(?:#|place|stop)?\s*(\d+)?/i', $message, $m)) {
        $day = (int)$m[1];
        $seq = isset($m[2]) && $m[2] !== "" ? (int)$m[2] : 0;
        $matched = array_values(array_filter($items, fn($item) =>
            (int)$item["day_no"] === $day && ($seq <= 0 || (int)$item["sequence_no"] === $seq)
        ));
        if (!empty($matched)) return array_slice($matched, 0, 2);
    }

    foreach ($items as $item) {
        $title = strtolower((string)$item["item_title"]);
        if ($title !== "" && str_contains($msg, $title)) return [$item];
    }

    $category = preferred_category_from_message($message);
    if ($category !== "") {
        $matched = array_values(array_filter($items, fn($item) =>
            strtolower((string)($item["category"] ?? $item["item_type"])) === $category
        ));
        if (!empty($matched)) return array_slice($matched, 0, 3);
    }

    if (str_contains($msg, "cost") || str_contains($msg, "cheap") || str_contains($msg, "budget") || str_contains($msg, "便宜") || str_contains($msg, "省钱")) {
        usort($items, fn($a, $b) => (float)$b["estimated_cost"] <=> (float)$a["estimated_cost"]);
        return array_slice($items, 0, 3);
    }

    return array_slice($items, 0, 3);
}

function preferred_category_from_message(string $message): string
{
    $msg = strtolower($message);
    $map = [
        "food" => ["food", "restaurant", "eat", "halal", "makan", "美食", "吃"],
        "nature" => ["nature", "park", "outdoor", "自然"],
        "shopping" => ["shopping", "mall", "shop", "购物"],
        "museum" => ["museum", "gallery", "博物馆"],
        "heritage" => ["heritage", "historical", "history", "temple", "文化", "历史"],
        "culture" => ["culture", "cultural", "文化"],
    ];
    foreach ($map as $category => $terms) {
        foreach ($terms as $term) {
            if (str_contains($msg, $term)) return $category;
        }
    }
    return "";
}

function find_alternative_place(mysqli $conn, array $target, array $usedPlaceIds, string $message, string $tripStartDate): ?array
{
    $state = (string)($target["state"] ?? "");
    $district = (string)($target["district"] ?? "");
    $category = preferred_category_from_message($message);
    if ($category === "") $category = strtolower((string)($target["category"] ?? ""));
    $currentPlaceId = (int)($target["place_id"] ?? 0);
    $excludeIds = array_values(array_unique(array_filter(array_merge($usedPlaceIds, [$currentPlaceId]))));
    $excludeSql = "";
    $params = [];
    $types = "";
    if (!empty($excludeIds)) {
        $excludeSql = " AND place_id NOT IN (" . implode(",", array_fill(0, count($excludeIds), "?")) . ")";
        $params = $excludeIds;
        $types = str_repeat("i", count($excludeIds));
    }

    $dateFilter = festival_date_filter($tripStartDate);
    $sql = "
        SELECT place_id, name, state, district, category, latitude, longitude, estimated_cost,
               entrance_fee, opening_hours, rating, visit_duration_min
        FROM cultural_places
        WHERE is_active = 1
          AND state = ?
          AND category = ?
          {$excludeSql}
          {$dateFilter}
        ORDER BY
          CASE WHEN district = ? THEN 0 ELSE 1 END,
          COALESCE(rating, avg_rating, 0) DESC,
          COALESCE(entrance_fee, estimated_cost, 0) ASC
        LIMIT 1
    ";
    $allParams = array_merge([$state, $category], $params, [$district]);
    $allTypes = "ss" . $types . "s";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return null;
    $stmt->bind_param($allTypes, ...$allParams);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) return $row;

    $sql = "
        SELECT place_id, name, state, district, category, latitude, longitude, estimated_cost,
               entrance_fee, opening_hours, rating, visit_duration_min
        FROM cultural_places
        WHERE is_active = 1
          AND state = ?
          {$excludeSql}
          {$dateFilter}
        ORDER BY
          CASE WHEN district = ? THEN 0 ELSE 1 END,
          COALESCE(rating, avg_rating, 0) DESC,
          COALESCE(entrance_fee, estimated_cost, 0) ASC
        LIMIT 1
    ";
    $allParams = array_merge([$state], $params, [$district]);
    $allTypes = "s" . $types . "s";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return null;
    $stmt->bind_param($allTypes, ...$allParams);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function festival_date_filter(string $tripStartDate): string
{
    if ($tripStartDate === "" || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tripStartDate)) {
        return "AND (category <> 'festival' OR festival_start_date IS NULL OR festival_end_date IS NULL)";
    }
    $date = addslashes($tripStartDate);
    return "AND (category <> 'festival' OR festival_start_date IS NULL OR festival_end_date IS NULL OR ('$date' BETWEEN festival_start_date AND festival_end_date))";
}

function format_place(array $place): array
{
    $cost = array_key_exists("entrance_fee", $place)
        ? (float)$place["entrance_fee"]
        : (float)($place["estimated_cost"] ?? 0);
    return [
        "place_id" => (int)$place["place_id"],
        "name" => (string)$place["name"],
        "state" => (string)($place["state"] ?? ""),
        "district" => (string)($place["district"] ?? ""),
        "category" => (string)($place["category"] ?? ""),
        "estimated_cost" => $cost,
        "opening_hours" => (string)($place["opening_hours"] ?? ""),
        "rating" => isset($place["rating"]) ? (float)$place["rating"] : null,
        "visit_duration_min" => (int)($place["visit_duration_min"] ?? 90),
    ];
}

function build_replacement_reason(array $target, array $place): string
{
    $parts = [];
    if (($target["district"] ?? "") !== "" && ($target["district"] ?? "") === ($place["district"] ?? "")) {
        $parts[] = "same district";
    }
    if (($target["category"] ?? "") === ($place["category"] ?? "")) {
        $parts[] = "same category";
    }
    $newCost = (float)($place["entrance_fee"] ?? $place["estimated_cost"] ?? 0);
    $oldCost = (float)($target["estimated_cost"] ?? 0);
    if ($newCost < $oldCost) $parts[] = "lower cost";
    if (!empty($place["opening_hours"])) $parts[] = "has opening hours";
    return !empty($parts) ? implode(", ", $parts) : "better alternative from the place database";
}

function build_local_editor_answer(array $proposals): string
{
    $lines = ["I found these itinerary changes. Nothing has been saved yet; click Confirm Change to apply one."];
    foreach ($proposals as $proposal) {
        $lines[] = "Day " . $proposal["day_no"] . " stop " . $proposal["sequence_no"] . ": replace "
            . $proposal["current_title"] . " with " . $proposal["new_place"]["name"] . ".";
    }
    return implode("\n", $lines);
}

function item_type_from_category(string $category): string
{
    $cat = strtolower(trim($category));
    if ($cat === "food") return "food";
    if ($cat === "festival") return "festival";
    return "attraction";
}

function is_duplicate_place(mysqli $conn, int $itineraryId, int $placeId, int $exceptItemId): bool
{
    $stmt = $conn->prepare("
        SELECT item_id FROM itinerary_items
        WHERE itinerary_id = ? AND place_id = ? AND item_id <> ?
        LIMIT 1
    ");
    if (!$stmt) return true;
    $stmt->bind_param("iii", $itineraryId, $placeId, $exceptItemId);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $exists;
}

function apply_replacement(mysqli $conn, int $itineraryId, int $itemId, array $place): bool
{
    $placeId = (int)$place["place_id"];
    $newType = item_type_from_category((string)$place["category"]);
    $newCost = array_key_exists("entrance_fee", $place)
        ? max(0.0, (float)$place["entrance_fee"])
        : max(0.0, (float)($place["estimated_cost"] ?? 0));
    $lat = $place["latitude"] !== null ? (float)$place["latitude"] : null;
    $lng = $place["longitude"] !== null ? (float)$place["longitude"] : null;
    $districtNote = !empty($place["district"]) ? " | District: " . $place["district"] : "";
    $newNotes = "State: " . ($place["state"] ?? "") . $districtNote . " | Category: " . ($place["category"] ?? "");

    $stmt = $conn->prepare("
        UPDATE itinerary_items
        SET place_id = ?, item_type = ?, item_title = ?, item_latitude = ?, item_longitude = ?, estimated_cost = ?, notes = ?
        WHERE item_id = ? AND itinerary_id = ?
    ");
    if (!$stmt) return false;
    $stmt->bind_param("issdddsii", $placeId, $newType, $place["name"], $lat, $lng, $newCost, $newNotes, $itemId, $itineraryId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function recalculate_itinerary_routes(mysqli $conn, int $itineraryId): void
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

    $routeSvc = new RouteService((string)($meta["transport_type"] ?? "car"), defined("GOOGLE_MAPS_API_KEY") ? GOOGLE_MAPS_API_KEY : "");
    $itemsStmt = $conn->prepare("
        SELECT ii.item_id,
               COALESCE(ii.item_latitude, cp.latitude) AS latitude,
               COALESCE(ii.item_longitude, cp.longitude) AS longitude
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
        $distKm = null;
        $timeMin = null;
        if ($prevLat !== null && $prevLng !== null && $lat !== null && $lng !== null) {
            $seg = $routeSvc->getSegment($prevLat, $prevLng, $lat, $lng);
            $distKm = (float)($seg["distance_km"] ?? 0);
            $timeMin = (int)($seg["travel_time_min"] ?? 0);
        }
        $updates[] = [$distKm, $timeMin, $itemId];
        if ($lat !== null && $lng !== null) {
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

function recalculate_itinerary_total(mysqli $conn, int $itineraryId): void
{
    $stmt = $conn->prepare("
        SELECT i.total_days, tp.transport_type, tp.budget, tp.budget_tier
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

    $budgetTier = strtolower((string)($meta["budget_tier"] ?? "normal"));
    $tierDefaults = match ($budgetTier) {
        "budget" => ["hotel" => 90.0, "meal" => 12.0],
        "luxury" => ["hotel" => 280.0, "meal" => 35.0],
        default => ["hotel" => 150.0, "meal" => 20.0],
    };

    $costService = new CostEstimationService(
        (string)($meta["transport_type"] ?? "car"),
        (int)($meta["total_days"] ?? 1),
        (float)($meta["budget"] ?? 0)
    );
    $breakdown = $costService->calculate($items, $totalDistanceKm, $tierDefaults["hotel"], 3, $tierDefaults["meal"]);
    $total = (float)($breakdown["total_cost"] ?? 0);

    $upd = $conn->prepare("UPDATE itineraries SET total_estimated_cost = ? WHERE itinerary_id = ?");
    if (!$upd) return;
    $upd->bind_param("di", $total, $itineraryId);
    $upd->execute();
    $upd->close();
}
