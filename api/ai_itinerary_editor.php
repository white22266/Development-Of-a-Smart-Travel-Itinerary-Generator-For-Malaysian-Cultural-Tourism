<?php
// api/ai_itinerary_editor.php
// Itinerary editor for the chat: proposes one replacement, addition or timetable change from the
// cultural places database, and saves it only when the traveller presses a confirmation button.
// Every proposal and every confirmation passes ItineraryRuleValidationService first.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../config/db_connect.php";
require_once "../config/api_keys.php";
require_once "../config/ai_language_guard.php";
require_once "../services/CostEstimationService.php";
require_once "../services/ItineraryEditService.php";
require_once "../services/ItineraryRuleValidationService.php";

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
if ($action === "recommend") {
    ai_reject_chinese_json($message, "answer");
}

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

    $check = ItineraryRuleValidationService::validatePlaceChange($conn, $travellerId, $itineraryId, $placeId, (int)$item["day_no"], $itemId);
    if (!$check["ok"]) {
        echo json_encode(["status" => "error", "answer" => $check["message"]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $ok = ItineraryEditService::replaceStop($conn, $itineraryId, $itemId, $placeId);
    if (!$ok) {
        echo json_encode(["status" => "error", "answer" => "Could not update the itinerary item."]);
        exit;
    }

    ItineraryEditService::routes($conn, $itineraryId);
    ItineraryEditService::total($conn, $itineraryId);

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

if ($action === "confirm_add") {
    $dayNo = (int)($_POST["day_no"] ?? 0);
    $placeId = (int)($_POST["place_id"] ?? 0);
    if ($dayNo <= 0 || $placeId <= 0 || $dayNo > (int)$itinerary["total_days"]) {
        echo json_encode(["status" => "error", "answer" => "Invalid add-place selection."]);
        exit;
    }

    $place = load_place($conn, $placeId);
    if (!$place) {
        echo json_encode(["status" => "error", "answer" => "Selected place was not found."]);
        exit;
    }

    $check = ItineraryRuleValidationService::validatePlaceChange($conn, $travellerId, $itineraryId, $placeId, $dayNo, 0);
    if (!$check["ok"]) {
        echo json_encode(["status" => "error", "answer" => $check["message"]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $ok = apply_addition($conn, $itineraryId, $dayNo, $place);
    if (!$ok) {
        echo json_encode(["status" => "error", "answer" => "Could not add this place into the itinerary."]);
        exit;
    }

    ItineraryEditService::routes($conn, $itineraryId);
    ItineraryEditService::total($conn, $itineraryId);

    echo json_encode([
        "status" => "success",
        "answer" => "Place added. The route and cost summary have been recalculated.",
        "added_place" => [
            "day_no" => $dayNo,
            "place_id" => $placeId,
            "name" => $place["name"],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$requestedStartTime = extract_requested_start_time($message);
if ($action === "confirm_retime") {
    $startTime = normalize_requested_start_time((string)($_POST["start_time"] ?? ""));
    if ($startTime === null) {
        echo json_encode(["status" => "error", "answer" => "Invalid timetable start time. Please use a time such as 9:00 AM."]);
        exit;
    }

    $ok = retime_itinerary_days($conn, $itineraryId, $startTime);
    if (!$ok) {
        echo json_encode(["status" => "error", "answer" => "Could not rearrange the timetable because this itinerary has no scheduled places."]);
        exit;
    }

    ItineraryEditService::total($conn, $itineraryId);
    echo json_encode([
        "status" => "success",
        "answer" => "Timetable rearranged to start from " . display_time_label($startTime) . ". The existing rule-based route places were kept; only the schedule time was updated.",
        "reload" => true,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === "recommend" && $requestedStartTime !== null && is_timetable_rearrange_request($message)) {
    $label = display_time_label($requestedStartTime);
    echo json_encode([
        "status" => "success",
        "answer" => "I detected a timetable change request, not a starting location change. I can rearrange this itinerary to start from {$label} while keeping the current rule-based route places. Nothing will be saved until you confirm.",
        "pending_action" => [
            "type" => "retime_itinerary",
            "start_time" => $requestedStartTime,
            "label" => $label,
            "summary" => "Rearrange timetable to start from {$label}. Places stay based on your saved preference and rule-based itinerary logic.",
        ],
        "pending_actions" => [[
            "type" => "retime_itinerary",
            "start_time" => $requestedStartTime,
            "label" => $label,
            "summary" => "Rearrange timetable to start from {$label}. Places stay based on your saved preference and rule-based itinerary logic.",
        ]],
        "source" => "local_rule",
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$items = load_editable_items($conn, $itineraryId);

// An explicit replace request ("change day 2 stop 1 to X") is never treated as an add request.
if (!is_replace_request($message) && is_add_or_regenerate_request($message)) {
    $additions = build_addition_proposals($conn, $travellerId, $itinerary, $items, $message);
    echo json_encode([
        "status" => "success",
        "answer" => empty($additions)
            ? "I could not find a place that can be added to that day under your itinerary rules (state, travel pace, opening hours and distance). Try another day or a different kind of place."
            : build_local_addition_answer($additions),
        "proposals" => $additions,
        "source" => "local_rule",
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (empty($items)) {
    echo json_encode(["status" => "success", "answer" => "There are no editable itinerary stops yet. Ask me to add places for a specific day, for example: add a museum on day 2.", "proposals" => []]);
    exit;
}

$reference = parse_stop_reference($message);
$target = select_target_item($items, $message, $reference);
if ($target === null) {
    echo json_encode([
        "status" => "success",
        "answer" => describe_missing_stop($items, $reference),
        "proposals" => [],
        "source" => "local_rule",
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$usedPlaceIds = array_values(array_filter(array_map(fn($item) => (int)($item["place_id"] ?? 0), $items)));
$requestedName = extract_requested_replacement_name($message);

if ($requestedName !== "") {
    // The traveller named the new place: offer exactly that place or explain why not.
    $outcome = find_requested_place_by_name($conn, $travellerId, $itinerary, $target, $requestedName, $usedPlaceIds);
    if ($outcome["place"] === null) {
        echo json_encode(["status" => "success", "answer" => $outcome["message"], "proposals" => [], "source" => "local_rule"], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    $newPlace = $outcome["place"];
    $reason = "matches the place you asked for";
} else {
    $newPlace = find_alternative_place($conn, $travellerId, $itinerary, $target, $usedPlaceIds, $message);
    if ($newPlace === null) {
        echo json_encode([
            "status" => "success",
            "answer" => "I could not find a replacement for " . $target["item_title"] . " that fits your itinerary rules (state, opening hours, travel time and distance). Try asking for a different kind of place.",
            "proposals" => [],
            "source" => "local_rule",
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    $reason = build_replacement_reason($target, $newPlace);
}

$proposal = [
    "item_id" => (int)$target["item_id"],
    "current_title" => (string)$target["item_title"],
    "day_no" => (int)$target["day_no"],
    "sequence_no" => (int)$target["sequence_no"],
    "current_category" => (string)($target["category"] ?? $target["item_type"]),
    "new_place" => format_place($newPlace),
    "reason" => $reason,
];

// The answer is built from the proposal itself, so the text always matches the confirmation card.
echo json_encode([
    "status" => "success",
    "answer" => build_local_editor_answer($proposal),
    "proposals" => [$proposal],
    "source" => "local_rule",
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

function load_itinerary(mysqli $conn, int $itineraryId, int $travellerId): ?array
{
    $stmt = $conn->prepare("
        SELECT i.itinerary_id, i.title, i.start_date, i.total_days, i.total_estimated_cost,
               tp.budget, tp.budget_tier, tp.transport_type, tp.interests, tp.preferred_states, tp.travel_pace
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

/**
 * Read "day 2", "stop 3", "2nd stop", "second place", "#3" from a message.
 * Stop numbers follow sequence_no, which starts at 1 for each day's first place.
 *
 * @return array{day: int, stop: int} 0 when not given
 */
function parse_stop_reference(string $message): array
{
    $day = preg_match('/\bday\s*(\d{1,2})\b/i', $message, $m) ? (int)$m[1] : 0;

    $stop = 0;
    $stopNoun = '(?:stop|place|point|location|destination|attraction)';
    if (preg_match('/\b(\d{1,2})(?:st|nd|rd|th)\s+' . $stopNoun . '\b/i', $message, $m)) {
        $stop = (int)$m[1];
    } elseif (preg_match('/\b' . $stopNoun . '\s*(?:no\.?|number|#)?\s*(\d{1,2})\b/i', $message, $m)) {
        $stop = (int)$m[1];
    } else {
        $words = ['first' => 1, 'second' => 2, 'third' => 3, 'fourth' => 4, 'fifth' => 5, 'sixth' => 6, 'seventh' => 7, 'eighth' => 8, 'ninth' => 9, 'tenth' => 10];
        foreach ($words as $word => $number) {
            if (preg_match('/\b' . $word . '\s+' . $stopNoun . '\b/i', $message)) {
                $stop = $number;
                break;
            }
        }
    }

    return ['day' => ($day >= 1 && $day <= 30) ? $day : 0, 'stop' => ($stop >= 1 && $stop <= 20) ? $stop : 0];
}

/**
 * Pick the one stop the traveller means. Returns null when a day/stop was given but does not exist,
 * so the caller can say so instead of silently changing a different stop.
 */
function select_target_item(array $items, string $message, array $reference): ?array
{
    $day = $reference['day'];
    $stop = $reference['stop'];

    if ($day > 0 || $stop > 0) {
        foreach ($items as $item) {
            $dayMatches = $day <= 0 || (int)$item["day_no"] === $day;
            $stopMatches = $stop <= 0 || (int)$item["sequence_no"] === $stop;
            if ($dayMatches && $stopMatches) return $item;   // items are ordered by day, then sequence
        }
        return null;
    }

    $msg = strtolower($message);
    foreach ($items as $item) {
        $title = strtolower((string)$item["item_title"]);
        if ($title !== "" && str_contains($msg, $title)) return $item;
    }

    $category = preferred_category_from_message($message);
    if ($category !== "") {
        foreach ($items as $item) {
            if (strtolower((string)($item["category"] ?? $item["item_type"])) === $category) return $item;
        }
    }

    if (preg_match('/\b(?:cost|cheap|cheaper|budget|expensive|save money)\b/i', $message)) {
        usort($items, fn($a, $b) => (float)$b["estimated_cost"] <=> (float)$a["estimated_cost"]);
    }
    return $items[0];
}

function describe_missing_stop(array $items, array $reference): string
{
    $day = $reference['day'];
    $stop = $reference['stop'];
    $dayItems = array_values(array_filter($items, fn($item) => $day <= 0 || (int)$item["day_no"] === $day));
    if ($day > 0 && empty($dayItems)) {
        return "Day {$day} has no places that can be changed. Ask me to add a place on day {$day} instead.";
    }
    $count = count($dayItems);
    $where = $day > 0 ? "Day {$day}" : "This itinerary";
    return "{$where} has {$count} " . ($count === 1 ? "stop" : "stops") . ", so there is no stop {$stop}. Please check the stop number.";
}

/** "replace", "change ... to", "swap" etc.: a request to change an existing stop. */
function is_replace_request(string $message): bool
{
    return (bool)preg_match('/\b(?:replace|swap|switch|substitute|instead\s+of)\b|\bchange\b.*\b(?:to|with|into)\b/i', $message);
}

/**
 * "change the 2nd stop to Muzium Negara" -> "Muzium Negara".
 * Returns "" when no name was given or the tail is a category request such as "a museum".
 */
function extract_requested_replacement_name(string $message): string
{
    if (!preg_match('/\b(?:replace|change|swap|switch|update)\b.*?\b(?:with|to|into)\s+(.+)$/i', $message, $m)) {
        return "";
    }
    $name = trim((string)$m[1]);
    $name = preg_replace('/\s+(?:please|pls|thanks|thank you)\s*$/i', '', $name) ?? $name;
    $name = trim(preg_replace('/[.!?,;:]+$/', '', $name) ?? $name);
    $name = trim(preg_replace('/^(?:the|a|an)\s+/i', '', $name) ?? $name);
    if ($name === "" || mb_strlen($name) > 120) return "";

    // A category or a vague wish is not a place name: let the normal alternative search handle it.
    if (preg_match('/^(?:something|somewhere|another|other|different|cheaper|closer|nearby|better)\b/i', $name)
        || preg_match('/^(?:museum|food|restaurant|nature|park|temple|heritage|culture|cultural|shopping|mall|festival|place|stop)s?$/i', $name)
        || preg_match('/\b(?:day|stop|date|time|am|pm)\b/i', $name)) {
        return "";
    }
    return $name;
}

/**
 * Look up a place the traveller named, inside the itinerary's states, and check it against the
 * itinerary rules before offering it.
 *
 * @return array{place: ?array, message: string}
 */
function find_requested_place_by_name(mysqli $conn, int $travellerId, array $itinerary, array $target, string $name, array $usedPlaceIds): array
{
    $states = itinerary_states($itinerary, [$target]);
    if (empty($states)) {
        return ["place" => null, "message" => "I could not tell which state this itinerary covers, so I cannot look up \"{$name}\"."];
    }
    $statePlaceholders = implode(",", array_fill(0, count($states), "?"));
    $like = '%' . addcslashes($name, '%_\\') . '%';
    $sql = "
        SELECT place_id, name, state, district, category, latitude, longitude, estimated_cost,
               entrance_fee, opening_hours, rating, visit_duration_min
        FROM cultural_places
        WHERE is_active = 1
          AND state IN ($statePlaceholders)
          AND (name = ? OR name LIKE ?)
        ORDER BY CASE WHEN name = ? THEN 0 ELSE 1 END,
                 CASE WHEN district = ? THEN 0 ELSE 1 END,
                 COALESCE(rating, avg_rating, 0) DESC
        LIMIT 5
    ";
    $params = array_merge($states, [$name, $like, $name, (string)($target["district"] ?? "")]);
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(str_repeat("s", count($params)), ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $matches = [];
    while ($row = $res->fetch_assoc()) $matches[] = $row;
    $stmt->close();

    $stateText = implode(", ", $states);
    if (empty($matches)) {
        if ($stateText === "") $stateText = "this itinerary's states";
        return ["place" => null, "message" => "I could not find \"{$name}\" among the cultural places in {$stateText}. Please check the name, or ask for a kind of place instead (for example: a museum)."];
    }

    $lastReason = "";
    foreach ($matches as $place) {
        if ((int)$place["place_id"] === (int)$target["place_id"]) {
            return ["place" => null, "message" => $place["name"] . " is already this stop."];
        }
        if (in_array((int)$place["place_id"], $usedPlaceIds, true)) {
            $lastReason = $place["name"] . " is already in your itinerary.";
            continue;
        }
        $check = ItineraryRuleValidationService::validatePlaceChange($conn, $travellerId, (int)$itinerary["itinerary_id"], (int)$place["place_id"], (int)$target["day_no"], (int)$target["item_id"]);
        if ($check["ok"]) {
            return ["place" => $place, "message" => ""];
        }
        $lastReason = $place["name"] . " cannot replace this stop: " . $check["message"];
    }
    return ["place" => null, "message" => $lastReason];
}

/** States the itinerary may use: its preferred states, else the states of its current stops. */
function itinerary_states(array $itinerary, array $items): array
{
    $states = array_values(array_filter(array_map('trim', explode(",", (string)($itinerary["preferred_states"] ?? "")))));
    if (empty($states)) {
        foreach ($items as $item) {
            $state = trim((string)($item["state"] ?? ""));
            if ($state !== "" && !in_array($state, $states, true)) $states[] = $state;
        }
    }
    return $states;
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

/**
 * Find a replacement for one stop: same state, preferably the category the traveller asked for (or
 * the stop's own category), nearest district first. Candidates are checked with the same rules the
 * confirm step uses, so the traveller is never offered a change that cannot be saved.
 */
function find_alternative_place(mysqli $conn, int $travellerId, array $itinerary, array $target, array $usedPlaceIds, string $message): ?array
{
    $state = (string)($target["state"] ?? "");
    $district = (string)($target["district"] ?? "");
    $requestedCategory = preferred_category_from_message($message);
    $category = $requestedCategory !== "" ? $requestedCategory : strtolower((string)($target["category"] ?? ""));
    $excludeIds = array_values(array_unique(array_filter(array_merge($usedPlaceIds, [(int)($target["place_id"] ?? 0)]))));
    $wantsCheaper = (bool)preg_match('/\b(?:cheap|cheaper|cost|budget|expensive|save money)\b/i', $message);

    $excludeSql = empty($excludeIds) ? "" : " AND place_id NOT IN (" . implode(",", array_fill(0, count($excludeIds), "?")) . ")";
    $festivalSql = festival_date_filter((string)($itinerary["start_date"] ?? ""), (int)($itinerary["total_days"] ?? 1));
    $costOrder = $wantsCheaper ? "COALESCE(entrance_fee, estimated_cost, 0) ASC, COALESCE(rating, avg_rating, 0) DESC" : "COALESCE(rating, avg_rating, 0) DESC, COALESCE(entrance_fee, estimated_cost, 0) ASC";

    // First try the wanted category; then any category in the same state.
    foreach ([$category, ""] as $categoryFilter) {
        $sql = "
            SELECT place_id, name, state, district, category, latitude, longitude, estimated_cost,
                   entrance_fee, opening_hours, rating, visit_duration_min
            FROM cultural_places
            WHERE is_active = 1
              AND state = ?
              " . ($categoryFilter !== "" ? "AND category = ?" : "") . "
              {$excludeSql}
              {$festivalSql}
            ORDER BY CASE WHEN district = ? THEN 0 ELSE 1 END, {$costOrder}
            LIMIT 15
        ";
        $params = array_merge([$state], $categoryFilter !== "" ? [$categoryFilter] : [], $excludeIds, [$district]);
        $types = "s" . ($categoryFilter !== "" ? "s" : "") . str_repeat("i", count($excludeIds)) . "s";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $candidates = [];
        while ($row = $res->fetch_assoc()) $candidates[] = $row;
        $stmt->close();

        foreach ($candidates as $place) {
            if ($wantsCheaper && (float)($place["entrance_fee"] ?? $place["estimated_cost"] ?? 0) > (float)($target["estimated_cost"] ?? 0)) {
                continue;
            }
            $check = ItineraryRuleValidationService::validatePlaceChange($conn, $travellerId, (int)$itinerary["itinerary_id"], (int)$place["place_id"], (int)$target["day_no"], (int)$target["item_id"]);
            if ($check["ok"]) return $place;
        }
    }
    return null;
}

/**
 * Festivals are only offered when they have verified dates that overlap the trip
 * (start_date .. start_date + total_days - 1). The exact day is checked later by the rules.
 */
function festival_date_filter(string $tripStartDate, int $totalDays): string
{
    $start = DateTimeImmutable::createFromFormat("Y-m-d", $tripStartDate);
    if (!$start) {
        return "AND category <> 'festival'";
    }
    $from = $start->format("Y-m-d");
    $to = $start->modify("+" . max(0, $totalDays - 1) . " days")->format("Y-m-d");
    return "AND (category <> 'festival' OR (festival_start_date IS NOT NULL AND festival_end_date IS NOT NULL"
        . " AND festival_start_date <= '{$to}' AND festival_end_date >= '{$from}'))";
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

function build_local_editor_answer(array $proposal): string
{
    $place = $proposal["new_place"];
    $cost = (float)($place["estimated_cost"] ?? 0);
    return "Day " . $proposal["day_no"] . " stop " . $proposal["sequence_no"] . ": replace " . $proposal["current_title"]
        . " with " . $place["name"] . " (" . ($place["category"] ?? "place") . ", "
        . ($cost > 0 ? "RM " . number_format($cost, 2) : "free entry") . "). Reason: " . $proposal["reason"] . ".\n"
        . "Nothing has been saved yet. Click Confirm Change to apply it.";
}

/** "add a place on day 2", "fill my day", "more places", "another stop": a request to add stops. */
function is_add_or_regenerate_request(string $message): bool
{
    return (bool)preg_match(
        '/\b(?:add|include|fill|extra|empty|regenerate|replan)\b|\b(?:more|another|new)\s+(?:places?|stops?|attractions?)\b|\b(?:complete|plan)\s+my\s+day\b/i',
        $message
    );
}

/** The day named in the message, or else the day with the fewest stops (the most room). */
function requested_day_no(string $message, int $totalDays, array $items): int
{
    if (preg_match('/\bday\s*(\d+)\b/i', $message, $m)) {
        $day = (int)$m[1];
        if ($day >= 1 && $day <= $totalDays) return $day;
    }
    $bestDay = 1;
    $fewest = PHP_INT_MAX;
    for ($day = 1; $day <= $totalDays; $day++) {
        $count = count(array_filter($items, fn($item) => (int)$item["day_no"] === $day));
        if ($count < $fewest) {
            $fewest = $count;
            $bestDay = $day;
        }
    }
    return $bestDay;
}

function denied_states_from_message(string $message): array
{
    $msg = strtolower($message);
    $denied = [];
    $states = ["johor", "kelantan", "perak", "penang", "melaka", "kuala lumpur", "selangor", "kedah", "perlis", "pahang", "terengganu", "sabah", "sarawak", "negeri sembilan"];
    foreach ($states as $state) {
        if ((str_contains($msg, "no " . $state) || str_contains($msg, "dont want " . $state) || str_contains($msg, "don't want " . $state)) && !in_array($state, $denied, true)) {
            $denied[] = $state;
        }
    }
    return $denied;
}

function allowed_states_for_addition(array $itinerary, array $items, string $message): array
{
    $states = itinerary_states($itinerary, $items);
    $denied = denied_states_from_message($message);
    if (!empty($denied)) {
        $states = array_values(array_filter($states, fn($state) => !in_array(strtolower($state), $denied, true)));
    }
    return $states;
}

function build_addition_proposals(mysqli $conn, int $travellerId, array $itinerary, array $items, string $message): array
{
    $totalDays = max(1, (int)($itinerary["total_days"] ?? 1));
    $dayNo = requested_day_no($message, $totalDays, $items);
    $states = allowed_states_for_addition($itinerary, $items, $message);
    if (empty($states)) return [];

    $existingDayCount = count(array_filter($items, fn($item) => (int)$item["day_no"] === $dayNo));
    $usedPlaceIds = array_values(array_filter(array_map(fn($item) => (int)($item["place_id"] ?? 0), $items)));
    $category = preferred_category_from_message($message);
    $festivalSql = festival_date_filter((string)($itinerary["start_date"] ?? ""), $totalDays);

    $params = $states;
    $types = str_repeat("s", count($states));
    $categorySql = "";
    if ($category !== "") {
        $categorySql = " AND category = ?";
        $params[] = $category;
        $types .= "s";
    }
    $excludeSql = "";
    if (!empty($usedPlaceIds)) {
        $excludeSql = " AND place_id NOT IN (" . implode(",", array_fill(0, count($usedPlaceIds), "?")) . ")";
        $params = array_merge($params, $usedPlaceIds);
        $types .= str_repeat("i", count($usedPlaceIds));
    }

    $statePlaceholders = implode(",", array_fill(0, count($states), "?"));
    $stmt = $conn->prepare("
        SELECT place_id, name, state, district, category, latitude, longitude, estimated_cost,
               entrance_fee, opening_hours, rating, avg_rating, visit_duration_min
        FROM cultural_places
        WHERE is_active = 1
          AND state IN ($statePlaceholders)
          {$categorySql}
          {$excludeSql}
          {$festivalSql}
        ORDER BY
          CASE WHEN category='food' THEN 1 ELSE 0 END,
          COALESCE(rating, avg_rating, 0) DESC,
          COALESCE(entrance_fee, estimated_cost, 0) ASC,
          name ASC
        LIMIT 40
    ");
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $candidates = [];
    while ($place = $res->fetch_assoc()) $candidates[] = $place;
    $stmt->close();

    // Prefer places close to where the day currently ends.
    $anchor = addition_anchor_for_day($conn, (int)$itinerary["itinerary_id"], $dayNo);
    if ($anchor !== null) {
        usort($candidates, function ($a, $b) use ($anchor) {
            $da = place_distance_from_anchor($a, $anchor);
            $db = place_distance_from_anchor($b, $anchor);
            if ($da === $db) {
                return ((float)($b["rating"] ?? $b["avg_rating"] ?? 0)) <=> ((float)($a["rating"] ?? $a["avg_rating"] ?? 0));
            }
            return $da <=> $db;
        });
    }

    // One proposal, the first candidate that passes the same rules as the confirm step.
    foreach ($candidates as $place) {
        $check = ItineraryRuleValidationService::validatePlaceChange($conn, $travellerId, (int)$itinerary["itinerary_id"], (int)$place["place_id"], $dayNo, 0);
        if (!$check["ok"]) continue;
        return [[
            "proposal_type" => "add",
            "item_id" => 0,
            "current_title" => "Add new stop",
            "day_no" => $dayNo,
            "sequence_no" => $existingDayCount + 1,
            "current_category" => "",
            "new_place" => format_place($place),
            "reason" => $anchor !== null
                ? "close to " . $anchor["title"] . " (" . $anchor["source"] . ")"
                : "highly rated place in " . $place["state"],
        ]];
    }
    return [];
}

function addition_anchor_for_day(mysqli $conn, int $itineraryId, int $dayNo): ?array
{
    $queries = [];

    // If the requested day already has places, continue from its last real stop.
    $queries[] = [
        "source" => "last place in this day",
        "sql" => "
            SELECT ii.item_title AS title,
                   COALESCE(ii.item_latitude, cp.latitude) AS latitude,
                   COALESCE(ii.item_longitude, cp.longitude) AS longitude
            FROM itinerary_items ii
            LEFT JOIN cultural_places cp ON cp.place_id = ii.place_id
            WHERE ii.itinerary_id = ? AND ii.day_no = ? AND ii.item_type <> 'hotel'
            ORDER BY ii.sequence_no DESC
            LIMIT 1
        ",
        "day" => $dayNo,
    ];

    if ($dayNo > 1) {
        // For an empty day, start from previous night's hotel when available.
        $queries[] = [
            "source" => "previous night hotel",
            "sql" => "
                SELECT ii.item_title AS title,
                       ii.item_latitude AS latitude,
                       ii.item_longitude AS longitude
                FROM itinerary_items ii
                WHERE ii.itinerary_id = ? AND ii.day_no = ? AND ii.item_type = 'hotel'
                ORDER BY ii.sequence_no DESC
                LIMIT 1
            ",
            "day" => $dayNo - 1,
        ];

        // Fallback: previous day's last stop.
        $queries[] = [
            "source" => "previous day last place",
            "sql" => "
                SELECT ii.item_title AS title,
                       COALESCE(ii.item_latitude, cp.latitude) AS latitude,
                       COALESCE(ii.item_longitude, cp.longitude) AS longitude
                FROM itinerary_items ii
                LEFT JOIN cultural_places cp ON cp.place_id = ii.place_id
                WHERE ii.itinerary_id = ? AND ii.day_no = ?
                ORDER BY ii.sequence_no DESC
                LIMIT 1
            ",
            "day" => $dayNo - 1,
        ];
    }

    foreach ($queries as $query) {
        $stmt = $conn->prepare($query["sql"]);
        if (!$stmt) continue;
        $anchorDay = (int)$query["day"];
        $stmt->bind_param("ii", $itineraryId, $anchorDay);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) continue;
        $lat = $row["latitude"] !== null ? (float)$row["latitude"] : null;
        $lng = $row["longitude"] !== null ? (float)$row["longitude"] : null;
        if ($lat === null || $lng === null || ($lat == 0.0 && $lng == 0.0)) continue;

        return [
            "title" => (string)($row["title"] ?? "anchor point"),
            "latitude" => $lat,
            "longitude" => $lng,
            "source" => $query["source"],
        ];
    }

    return null;
}

function place_distance_from_anchor(array $place, array $anchor): float
{
    $lat = $place["latitude"] !== null ? (float)$place["latitude"] : null;
    $lng = $place["longitude"] !== null ? (float)$place["longitude"] : null;
    if ($lat === null || $lng === null || ($lat == 0.0 && $lng == 0.0)) return PHP_FLOAT_MAX;

    $earthRadius = 6371.0;
    $dLat = deg2rad($lat - (float)$anchor["latitude"]);
    $dLng = deg2rad($lng - (float)$anchor["longitude"]);
    $a = sin($dLat / 2) ** 2
        + cos(deg2rad((float)$anchor["latitude"])) * cos(deg2rad($lat)) * sin($dLng / 2) ** 2;
    return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

function build_local_addition_answer(array $proposals): string
{
    $lines = ["I found places that can be added. Nothing has been saved yet; click Confirm Add to update the itinerary."];
    foreach ($proposals as $proposal) {
        $place = $proposal["new_place"];
        $lines[] = "Day " . $proposal["day_no"] . ": add " . $place["name"] . " (" . $place["district"] . ", " . $place["state"] . ").";
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

/**
 * An explicit request to restart the days at a clock time, e.g. "start at 9.30am",
 * "rearrange the timetable to 8am", "change the start time to 10am". Same rule as
 * detectChatRoute() in assets/trip_ai_chat.js.
 */
function is_timetable_rearrange_request(string $message): bool
{
    if (preg_match('/\b(?:hotels?|accommodation|check\s*-?in)\b/i', $message)) return false;
    return (bool)preg_match('/\b(?:re-?arrange|reschedule|re-?time|new\s+timetable|timetable|schedule)\b/i', $message)
        || (bool)preg_match('/\b(?:start|begin)(?:\s+time)?\s*(?:at|from|to)?\s*\d{1,2}(?:[:.]\d{2})?\s*(?:am|pm)\b/i', $message)
        || (bool)preg_match('/\b(?:change|set|move|update)\s+(?:the\s+|my\s+)?(?:start(?:ing)?\s+)?time\b/i', $message);
}

function extract_requested_start_time(string $message): ?string
{
    if (preg_match('/\b(?:start|begin|starting|beginning)(?:\s+time)?\s*(?:at|from|to)?\s*(\d{1,2})(?:[:.](\d{2}))?\s*(am|pm)\b/i', $message, $m)) {
        return build_sql_time_from_match($m);
    }
    if (preg_match('/\b(\d{1,2})(?:[:.](\d{2}))?\s*(am|pm)\b/i', $message, $m)) {
        return build_sql_time_from_match($m);
    }
    return null;
}

function normalize_requested_start_time(string $value): ?string
{
    $value = trim($value);
    if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $value, $m)) {
        $hour = (int)$m[1];
        $minute = (int)$m[2];
        if ($hour >= 0 && $hour <= 23 && $minute >= 0 && $minute <= 59) {
            return sprintf("%02d:%02d:00", $hour, $minute);
        }
    }
    if (preg_match('/^(\d{1,2})(?:[:.](\d{2}))?\s*(am|pm)$/i', $value, $m)) {
        return build_sql_time_from_match($m);
    }
    return null;
}

function build_sql_time_from_match(array $m): ?string
{
    $hour = (int)($m[1] ?? 0);
    $minute = isset($m[2]) && $m[2] !== "" ? (int)$m[2] : 0;
    $ampm = strtolower((string)($m[3] ?? ""));
    if ($hour < 1 || $hour > 12 || $minute < 0 || $minute > 59 || !in_array($ampm, ["am", "pm"], true)) {
        return null;
    }
    if ($ampm === "pm" && $hour < 12) $hour += 12;
    if ($ampm === "am" && $hour === 12) $hour = 0;
    return sprintf("%02d:%02d:00", $hour, $minute);
}

function display_time_label(string $sqlTime): string
{
    $dt = DateTime::createFromFormat("H:i:s", $sqlTime);
    return $dt ? $dt->format("g:i A") : $sqlTime;
}

function retime_itinerary_days(mysqli $conn, int $itineraryId, string $startTime): bool
{
    $stmt = $conn->prepare("
        SELECT item_id, day_no, sequence_no, item_type, start_time, end_time, travel_time_min
        FROM itinerary_items
        WHERE itinerary_id = ?
        ORDER BY day_no, sequence_no, item_id
    ");
    if (!$stmt) return false;
    $stmt->bind_param("i", $itineraryId);
    $stmt->execute();
    $res = $stmt->get_result();
    $byDay = [];
    while ($row = $res->fetch_assoc()) {
        $byDay[(int)$row["day_no"]][] = $row;
    }
    $stmt->close();
    if (empty($byDay)) return false;

    $update = $conn->prepare("UPDATE itinerary_items SET start_time = ?, end_time = ? WHERE item_id = ? AND itinerary_id = ?");
    if (!$update) return false;

    $startMin = sql_time_to_minutes($startTime);
    if ($startMin === null) return false;
    $updated = 0;

    foreach ($byDay as $rows) {
        $cursor = $startMin;
        $prevOldEnd = null;
        $prevNewEnd = null;
        $hotelRows = [];

        foreach ($rows as $row) {
            $itemType = strtolower((string)$row["item_type"]);
            if ($itemType === "hotel") {
                $hotelRows[] = $row;
                continue;
            }
            if (in_array($itemType, ["note", "transport"], true)) {
                continue;
            }

            $oldStart = sql_time_to_minutes($row["start_time"] ?? null);
            $oldEnd = sql_time_to_minutes($row["end_time"] ?? null);
            $duration = estimate_item_duration_minutes($row);

            if ($prevNewEnd !== null) {
                $travel = max(0, (int)($row["travel_time_min"] ?? 0));
                $gap = estimate_old_schedule_gap($prevOldEnd, $oldStart, $travel);
                $cursor = $prevNewEnd + $travel + $gap;
            }

            $newStart = $cursor;
            $newEnd = $newStart + $duration;
            $newStartSql = sql_time_from_minutes($newStart);
            $newEndSql = sql_time_from_minutes($newEnd);
            $itemId = (int)$row["item_id"];
            $update->bind_param("ssii", $newStartSql, $newEndSql, $itemId, $itineraryId);
            $update->execute();
            $updated++;

            $prevOldEnd = $oldEnd;
            $prevNewEnd = $newEnd;
        }

        foreach ($hotelRows as $hotel) {
            $travel = max(0, (int)($hotel["travel_time_min"] ?? 0));
            $hotelStart = max(20 * 60, ($prevNewEnd ?? $startMin) + $travel + 30);
            $hotelEnd = $hotelStart + 30;
            $newStartSql = sql_time_from_minutes($hotelStart);
            $newEndSql = sql_time_from_minutes($hotelEnd);
            $itemId = (int)$hotel["item_id"];
            $update->bind_param("ssii", $newStartSql, $newEndSql, $itemId, $itineraryId);
            $update->execute();
            $updated++;
        }
    }

    $update->close();
    return $updated > 0;
}

function estimate_item_duration_minutes(array $row): int
{
    $oldStart = sql_time_to_minutes($row["start_time"] ?? null);
    $oldEnd = sql_time_to_minutes($row["end_time"] ?? null);
    if ($oldStart !== null && $oldEnd !== null && $oldEnd > $oldStart) {
        return max(30, min(240, $oldEnd - $oldStart));
    }
    $type = strtolower((string)($row["item_type"] ?? ""));
    if ($type === "food") return 60;
    if ($type === "festival") return 120;
    return 90;
}

function estimate_old_schedule_gap(?int $prevOldEnd, ?int $oldStart, int $travelMinutes): int
{
    if ($prevOldEnd !== null && $oldStart !== null && $oldStart > $prevOldEnd) {
        return max(15, min(120, $oldStart - $prevOldEnd - max(0, $travelMinutes)));
    }
    return 45;
}

function sql_time_from_minutes(int $minutes): string
{
    $minutes = max(0, min(23 * 60 + 30, $minutes));
    return sprintf("%02d:%02d:00", intdiv($minutes, 60), $minutes % 60);
}

function sql_time_to_minutes(?string $time): ?int
{
    if (!$time || !preg_match('/^(\d{1,2}):(\d{2})/', $time, $m)) return null;
    return ((int)$m[1] * 60) + (int)$m[2];
}

function apply_addition(mysqli $conn, int $itineraryId, int $dayNo, array $place): bool
{
    $seqStmt = $conn->prepare("SELECT COALESCE(MAX(sequence_no),0) AS max_seq FROM itinerary_items WHERE itinerary_id = ? AND day_no = ? AND item_type <> 'hotel'");
    if (!$seqStmt) return false;
    $seqStmt->bind_param("ii", $itineraryId, $dayNo);
    $seqStmt->execute();
    $seqRow = $seqStmt->get_result()->fetch_assoc();
    $seqStmt->close();
    $sequenceNo = (int)($seqRow["max_seq"] ?? 0) + 1;

    $timeStmt = $conn->prepare("SELECT end_time FROM itinerary_items WHERE itinerary_id = ? AND day_no = ? AND item_type <> 'hotel' AND end_time IS NOT NULL ORDER BY sequence_no DESC LIMIT 1");
    if (!$timeStmt) return false;
    $timeStmt->bind_param("ii", $itineraryId, $dayNo);
    $timeStmt->execute();
    $timeRow = $timeStmt->get_result()->fetch_assoc();
    $timeStmt->close();

    // Start after the day's last stop. The rule check before saving already guarantees it ends in time.
    $lastEnd = sql_time_to_minutes($timeRow["end_time"] ?? null);
    $startMin = $lastEnd !== null ? $lastEnd + 30 : 9 * 60;
    $duration = max(45, min(180, (int)($place["visit_duration_min"] ?? 90)));
    $startTime = sql_time_from_minutes($startMin);
    $endTime = sql_time_from_minutes($startMin + $duration);

    $placeId = (int)$place["place_id"];
    $itemType = item_type_from_category((string)$place["category"]);
    $cost = array_key_exists("entrance_fee", $place)
        ? max(0.0, (float)$place["entrance_fee"])
        : max(0.0, (float)($place["estimated_cost"] ?? 0));
    $lat = $place["latitude"] !== null ? (float)$place["latitude"] : null;
    $lng = $place["longitude"] !== null ? (float)$place["longitude"] : null;
    $districtNote = !empty($place["district"]) ? " | District: " . $place["district"] : "";
    $notes = "State: " . ($place["state"] ?? "") . $districtNote . " | Category: " . ($place["category"] ?? "");

    $conn->query("UPDATE itinerary_items SET sequence_no = sequence_no + 1 WHERE itinerary_id = " . (int)$itineraryId . " AND day_no = " . (int)$dayNo . " AND item_type = 'hotel' AND sequence_no >= " . (int)$sequenceNo);

    $stmt = $conn->prepare("
        INSERT INTO itinerary_items
            (itinerary_id, day_no, sequence_no, item_type, place_id, item_title, item_latitude, item_longitude, start_time, end_time, estimated_cost, notes)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) return false;
    $stmt->bind_param("iiisisddssds", $itineraryId, $dayNo, $sequenceNo, $itemType, $placeId, $place["name"], $lat, $lng, $startTime, $endTime, $cost, $notes);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}
