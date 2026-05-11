<?php
// itinerary/review_replace.php
// AJAX endpoint for the itinerary review page.
//
// Actions:
//   POST (no action field)  -> Find a replacement place for a rejected item
//   POST action=confirm     -> Apply replacements, delete rejected items, add hotel item, resequence
//
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once "../config/db_connect.php";
require_once "../config/api_keys.php";
require_once "../services/CostEstimationService.php";
require_once "../services/RouteService.php";

header('Content-Type: application/json; charset=utf-8');

// Auth guard
if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}
$travellerId = (int)($_SESSION["traveller_id"] ?? 0);

$action      = trim((string)($_POST["action"] ?? ""));
$itineraryId = (int)($_POST["itinerary_id"] ?? 0);

if ($itineraryId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid itinerary ID']);
    exit;
}

// Verify ownership
$own = $conn->prepare("SELECT itinerary_id FROM itineraries WHERE itinerary_id = ? AND traveller_id = ? LIMIT 1");
$own->bind_param("ii", $itineraryId, $travellerId);
$own->execute();
if (!$own->get_result()->fetch_assoc()) {
    echo json_encode(['status' => 'error', 'message' => 'Itinerary not found']);
    exit;
}
$own->close();

function item_type_from_category(string $category): string
{
    $cat = strtolower(trim($category));
    if ($cat === "food") return "food";
    if ($cat === "festival") return "festival";
    return "attraction";
}

function table_has_column(mysqli $conn, string $table, string $column): bool
{
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return ($res && $res->num_rows > 0);
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

    $transportType = RouteService::normalizeTransportType((string)($meta["transport_type"] ?? "car"));
    $apiKey = defined("GOOGLE_MAPS_API_KEY") ? GOOGLE_MAPS_API_KEY : "";
    $routeSvc = new RouteService($transportType, $apiKey);

    $hasItemCoords = table_has_column($conn, "itinerary_items", "item_latitude")
        && table_has_column($conn, "itinerary_items", "item_longitude");
    $coordSelect = $hasItemCoords
        ? "COALESCE(ii.item_latitude, cp.latitude) AS latitude, COALESCE(ii.item_longitude, cp.longitude) AS longitude"
        : "cp.latitude, cp.longitude";

    $itemsStmt = $conn->prepare("
        SELECT ii.item_id, ii.place_id, {$coordSelect}
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
        $hasCoord = $lat !== null && $lng !== null && !($lat === 0.0 && $lng === 0.0);

        $distKm = null;
        $timeMin = null;
        if ($prevLat !== null && $prevLng !== null && $hasCoord) {
            $seg = $routeSvc->getSegment($prevLat, $prevLng, $lat, $lng);
            $distKm = (float)($seg["distance_km"] ?? 0);
            $timeMin = (int)($seg["travel_time_min"] ?? 0);
        }

        $updates[] = [$distKm, $timeMin, $itemId];
        if ($hasCoord) {
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
        SELECT i.total_days, tp.transport_type, tp.budget
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

    $itemsStmt = $conn->prepare("
        SELECT item_type, estimated_cost, distance_km
        FROM itinerary_items
        WHERE itinerary_id = ?
    ");
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

    $costService = new CostEstimationService(
        (string)($meta["transport_type"] ?? "car"),
        (int)($meta["total_days"] ?? 1),
        (float)($meta["budget"] ?? 0)
    );
    $breakdown = $costService->calculate($items, $totalDistanceKm);
    $total = (float)($breakdown["total_cost"] ?? 0);

    $upd = $conn->prepare("UPDATE itineraries SET total_estimated_cost = ? WHERE itinerary_id = ?");
    if (!$upd) return;
    $upd->bind_param("di", $total, $itineraryId);
    $upd->execute();
    $upd->close();
}

function load_replacement_place(mysqli $conn, int $placeId): ?array
{
    $hasEntranceFeeCol = table_has_column($conn, "cultural_places", "entrance_fee");
    $feeSelect = $hasEntranceFeeCol ? ", entrance_fee" : "";
    $stmt = $conn->prepare("
        SELECT place_id, name, state, district, category, latitude, longitude, estimated_cost{$feeSelect}, opening_hours, rating
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

function apply_item_replacement(mysqli $conn, int $itineraryId, int $itemId, int $placeId): bool
{
    $place = load_replacement_place($conn, $placeId);
    if (!$place) return false;

    $hasEntranceFeeCol = array_key_exists("entrance_fee", $place);
    $newCost = $hasEntranceFeeCol
        ? max(0.0, (float)($place["entrance_fee"] ?? 0))
        : max(0.0, (float)($place["estimated_cost"] ?? 0));
    $newType = item_type_from_category((string)($place["category"] ?? ""));
    $districtNote = !empty($place["district"]) ? " | District: " . $place["district"] : "";
    $newNotes = "State: " . ($place["state"] ?? "") . $districtNote . " | Category: " . ($place["category"] ?? "");

    $hasItemCoords = table_has_column($conn, "itinerary_items", "item_latitude")
        && table_has_column($conn, "itinerary_items", "item_longitude");

    if ($hasItemCoords) {
        $stmt = $conn->prepare("
            UPDATE itinerary_items
            SET place_id = ?, item_type = ?, item_title = ?, item_latitude = ?, item_longitude = ?, estimated_cost = ?, notes = ?
            WHERE item_id = ? AND itinerary_id = ?
        ");
        if (!$stmt) return false;
        $lat = $place["latitude"] !== null ? (float)$place["latitude"] : null;
        $lng = $place["longitude"] !== null ? (float)$place["longitude"] : null;
        $stmt->bind_param("issdddsii", $placeId, $newType, $place["name"], $lat, $lng, $newCost, $newNotes, $itemId, $itineraryId);
    } else {
        $stmt = $conn->prepare("
            UPDATE itinerary_items
            SET place_id = ?, item_type = ?, item_title = ?, estimated_cost = ?, notes = ?
            WHERE item_id = ? AND itinerary_id = ?
        ");
        if (!$stmt) return false;
        $stmt->bind_param("issdsii", $placeId, $newType, $place["name"], $newCost, $newNotes, $itemId, $itineraryId);
    }

    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

// =========================================================
// ACTION: confirm - apply replacements, delete rejected items, add hotel, resequence
// =========================================================
if ($action === 'confirm') {
    $rejectedCsv = trim((string)($_POST["rejected_ids"] ?? ""));
    $hotelId     = (int)($_POST["hotel_id"] ?? 0);
    $replacementsJson = trim((string)($_POST["replacements_json"] ?? ""));

    $rejectedIds = [];
    if ($rejectedCsv !== '') {
        $rejectedIds = array_values(array_unique(array_filter(array_map('intval', explode(',', $rejectedCsv)))));
    }

    if ($replacementsJson !== "") {
        $decoded = json_decode($replacementsJson, true);
        if (is_array($decoded)) {
            foreach ($decoded as $itemIdRaw => $replacement) {
                $itemId = (int)$itemIdRaw;
                $placeId = is_array($replacement) ? (int)($replacement["place_id"] ?? 0) : (int)$replacement;
                if ($itemId <= 0 || $placeId <= 0 || in_array($itemId, $rejectedIds, true)) continue;
                apply_item_replacement($conn, $itineraryId, $itemId, $placeId);
            }
        }
    }

    // Delete rejected items
    if (!empty($rejectedIds)) {
            $ph   = implode(',', array_fill(0, count($rejectedIds), '?'));
            $types = str_repeat('i', count($rejectedIds));
            $del  = $conn->prepare("DELETE FROM itinerary_items WHERE item_id IN ($ph) AND itinerary_id = ?");
            $allParams = array_merge($rejectedIds, [$itineraryId]);
            $allTypes  = $types . 'i';
            $del->bind_param($allTypes, ...$allParams);
            $del->execute();
            $del->close();
    }

    // Keep only one selected hotel for the itinerary.
    $clearHotel = $conn->prepare("DELETE FROM itinerary_items WHERE itinerary_id = ? AND item_type = 'hotel'");
    if ($clearHotel) {
        $clearHotel->bind_param("i", $itineraryId);
        $clearHotel->execute();
        $clearHotel->close();
    }
    if (table_has_column($conn, "itineraries", "selected_hotel_id")) {
        $clearSelectedHotel = $conn->prepare("
            UPDATE itineraries
            SET selected_hotel_id = NULL,
                selected_hotel_name = NULL,
                selected_hotel_nights = 0,
                selected_hotel_total_cost = 0.00
            WHERE itinerary_id = ?
        ");
        if ($clearSelectedHotel) {
            $clearSelectedHotel->bind_param("i", $itineraryId);
            $clearSelectedHotel->execute();
            $clearSelectedHotel->close();
        }
    }

    // Add hotel as last item of last day (if selected)
    if ($hotelId > 0) {
        $hStmt = $conn->prepare("SELECT hotel_id, name, state, district, latitude, longitude, price_per_night FROM hotels WHERE hotel_id = ? AND is_active = 1 LIMIT 1");
        $hStmt->bind_param("i", $hotelId);
        $hStmt->execute();
        $hotel = $hStmt->get_result()->fetch_assoc();
        $hStmt->close();

        if ($hotel) {
            $daysStmt = $conn->prepare("SELECT total_days FROM itineraries WHERE itinerary_id = ? LIMIT 1");
            $daysStmt->bind_param("i", $itineraryId);
            $daysStmt->execute();
            $daysRow = $daysStmt->get_result()->fetch_assoc();
            $daysStmt->close();
            $tripDays = max(1, (int)($daysRow["total_days"] ?? 1));
            $nights = max(1, $tripDays - 1);
            $nightlyRate = (float)($hotel["price_per_night"] ?? 0);
            $hotelTotal = round($nightlyRate * $nights, 2);

            // Find last day_no and last sequence_no
            $lastDay = $conn->prepare("SELECT MAX(day_no) as md FROM itinerary_items WHERE itinerary_id = ?");
            $lastDay->bind_param("i", $itineraryId);
            $lastDay->execute();
            $ldRow   = $lastDay->get_result()->fetch_assoc();
            $lastDay->close();
            $dayNo   = (int)($ldRow["md"] ?? 1);

            $lastSeq = $conn->prepare("SELECT MAX(sequence_no) as ms FROM itinerary_items WHERE itinerary_id = ? AND day_no = ?");
            $lastSeq->bind_param("ii", $itineraryId, $dayNo);
            $lastSeq->execute();
            $lsRow   = $lastSeq->get_result()->fetch_assoc();
            $lastSeq->close();
            $seqNo   = (int)($lsRow["ms"] ?? 0) + 1;

            $hotelNote = "Hotel | " . ($hotel["district"] ? $hotel["district"] . ", " : "") . $hotel["state"]
                . " | RM " . number_format($nightlyRate, 2) . "/night x " . $nights . " night(s)";

            $hasHotelIdCol = table_has_column($conn, "itinerary_items", "hotel_id");
            $hasItemCoords = table_has_column($conn, "itinerary_items", "item_latitude")
                && table_has_column($conn, "itinerary_items", "item_longitude");
            $hotelDbId = (int)$hotel["hotel_id"];
            $hotelLat = $hotel["latitude"] !== null ? (float)$hotel["latitude"] : null;
            $hotelLng = $hotel["longitude"] !== null ? (float)$hotel["longitude"] : null;

            if ($hasHotelIdCol && $hasItemCoords) {
                $ins = $conn->prepare("
                    INSERT INTO itinerary_items
                      (itinerary_id, day_no, sequence_no, item_type, place_id, hotel_id, item_title, item_latitude, item_longitude, estimated_cost, notes)
                    VALUES
                      (?, ?, ?, 'hotel', NULL, ?, ?, ?, ?, ?, ?)
                ");
                $ins->bind_param("iiiisddds", $itineraryId, $dayNo, $seqNo, $hotelDbId, $hotel["name"], $hotelLat, $hotelLng, $hotelTotal, $hotelNote);
            } elseif ($hasHotelIdCol) {
                $ins = $conn->prepare("
                    INSERT INTO itinerary_items
                      (itinerary_id, day_no, sequence_no, item_type, place_id, hotel_id, item_title, estimated_cost, notes)
                    VALUES
                      (?, ?, ?, 'hotel', NULL, ?, ?, ?, ?)
                ");
                $ins->bind_param("iiiisds", $itineraryId, $dayNo, $seqNo, $hotelDbId, $hotel["name"], $hotelTotal, $hotelNote);
            } else {
                $ins = $conn->prepare("
                    INSERT INTO itinerary_items
                      (itinerary_id, day_no, sequence_no, item_type, place_id, item_title, estimated_cost, notes)
                    VALUES
                      (?, ?, ?, 'hotel', NULL, ?, ?, ?)
                ");
                $ins->bind_param("iiisds", $itineraryId, $dayNo, $seqNo, $hotel["name"], $hotelTotal, $hotelNote);
            }
            if ($ins) {
                $ins->execute();
                $ins->close();
            }

            if (table_has_column($conn, "itineraries", "selected_hotel_id")) {
                $selUpd = $conn->prepare("
                    UPDATE itineraries
                    SET selected_hotel_id = ?, selected_hotel_name = ?, selected_hotel_nights = ?, selected_hotel_total_cost = ?
                    WHERE itinerary_id = ?
                ");
                if ($selUpd) {
                    $selUpd->bind_param("isidi", $hotelDbId, $hotel["name"], $nights, $hotelTotal, $itineraryId);
                    $selUpd->execute();
                    $selUpd->close();
                }
            }
        }
    }

    // Resequence remaining items per day
    $seqRes = $conn->query("SELECT item_id, day_no, sequence_no FROM itinerary_items WHERE itinerary_id = $itineraryId ORDER BY day_no, sequence_no");
    $byDay  = [];
    while ($r = $seqRes->fetch_assoc()) $byDay[(int)$r["day_no"]][] = (int)$r["item_id"];
    foreach ($byDay as $d => $itemIds) {
        foreach ($itemIds as $seq => $iid) {
            $conn->query("UPDATE itinerary_items SET sequence_no = " . ($seq + 1) . " WHERE item_id = $iid");
        }
    }

    recalculate_itinerary_routes($conn, $itineraryId);
    recalculate_itinerary_total($conn, $itineraryId);

    echo json_encode(['status' => 'success']);
    exit;
}

// =========================================================
// ACTION: replace - find a replacement place
// =========================================================
$itemId   = (int)($_POST["item_id"]   ?? 0);
$dayNo    = (int)($_POST["day_no"]    ?? 1);
$state    = trim((string)($_POST["state"]    ?? ""));
$category = trim((string)($_POST["category"] ?? ""));

if ($itemId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid item ID']);
    exit;
}

// Load the current item to know what to exclude
$curStmt = $conn->prepare("SELECT place_id FROM itinerary_items WHERE item_id = ? AND itinerary_id = ? LIMIT 1");
$curStmt->bind_param("ii", $itemId, $itineraryId);
$curStmt->execute();
$curRow = $curStmt->get_result()->fetch_assoc();
$curStmt->close();
$currentPlaceId = (int)($curRow["place_id"] ?? 0);

// Get all place_ids already in this itinerary (to avoid duplicates)
$usedRes = $conn->prepare("SELECT DISTINCT place_id FROM itinerary_items WHERE itinerary_id = ? AND place_id IS NOT NULL");
$usedRes->bind_param("i", $itineraryId);
$usedRes->execute();
$usedResult = $usedRes->get_result();
$usedRes->close();
$usedIds = [];
while ($r = $usedResult->fetch_assoc()) {
    $pid = (int)$r["place_id"];
    if ($pid > 0) $usedIds[] = $pid;
}

// Load preference for scoring
$prefStmt = $conn->prepare("
    SELECT tp.budget, tp.interests, tp.transport_type
    FROM itineraries i
    LEFT JOIN traveller_preferences tp ON tp.preference_id = i.preference_id
    WHERE i.itinerary_id = ? LIMIT 1
");
$prefStmt->bind_param("i", $itineraryId);
$prefStmt->execute();
$pref = $prefStmt->get_result()->fetch_assoc();
$prefStmt->close();

$budget    = (float)($pref["budget"]   ?? 0);
$interests = strtolower((string)($pref["interests"] ?? ""));
$interestArr = array_filter(array_map('trim', explode(',', $interests)));

// ---- Find replacement candidates ----
// Strategy: same state + same/similar category, not already used, not current item
$candidates = [];
$hasEntranceFeeCol = table_has_column($conn, "cultural_places", "entrance_fee");
$feeSelect = $hasEntranceFeeCol ? ", entrance_fee" : "";

// Build exclude list
$excludeIds = $usedIds;
if ($currentPlaceId > 0 && !in_array($currentPlaceId, $excludeIds)) {
    $excludeIds[] = $currentPlaceId;
}

$exPh    = '';
$exTypes = '';
$exParams = [];
if (!empty($excludeIds)) {
    $exPh    = " AND place_id NOT IN (" . implode(',', array_fill(0, count($excludeIds), '?')) . ")";
    $exTypes = str_repeat('i', count($excludeIds));
    $exParams = $excludeIds;
}

// Try: same state + same category
$sql = "SELECT place_id, name, state, district, category, latitude, longitude, estimated_cost{$feeSelect}, opening_hours, rating
        FROM cultural_places
        WHERE is_active = 1 AND state = ? AND category = ?$exPh
        ORDER BY RAND() LIMIT 10";

$params = array_merge([$state, $category], $exParams);
$types  = 'ss' . $exTypes;

$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $candidates[] = $r;
    $stmt->close();
}

// Fallback 1: same state, any category
if (empty($candidates) && $state !== '') {
    $sql2 = "SELECT place_id, name, state, district, category, latitude, longitude, estimated_cost{$feeSelect}, opening_hours, rating
             FROM cultural_places
             WHERE is_active = 1 AND state = ?$exPh
             ORDER BY RAND() LIMIT 10";
    $params2 = array_merge([$state], $exParams);
    $types2  = 's' . $exTypes;
    $stmt2   = $conn->prepare($sql2);
    if ($stmt2) {
        $stmt2->bind_param($types2, ...$params2);
        $stmt2->execute();
        $res2 = $stmt2->get_result();
        while ($r = $res2->fetch_assoc()) $candidates[] = $r;
        $stmt2->close();
    }
}

// Fallback 2: any state, same category
if (empty($candidates) && $category !== '') {
    $sql3 = "SELECT place_id, name, state, district, category, latitude, longitude, estimated_cost{$feeSelect}, opening_hours, rating
             FROM cultural_places
             WHERE is_active = 1 AND category = ?$exPh
             ORDER BY RAND() LIMIT 10";
    $params3 = array_merge([$category], $exParams);
    $types3  = 's' . $exTypes;
    $stmt3   = $conn->prepare($sql3);
    if ($stmt3) {
        $stmt3->bind_param($types3, ...$params3);
        $stmt3->execute();
        $res3 = $stmt3->get_result();
        while ($r = $res3->fetch_assoc()) $candidates[] = $r;
        $stmt3->close();
    }
}

if (empty($candidates)) {
    echo json_encode(['status' => 'error', 'message' => 'No replacement found. All similar places are already in your itinerary.']);
    exit;
}

// ---- Score candidates ----
// Score = W_category * cat_match + W_budget * budget_fit + W_rating * rating_score
$W_CATEGORY = 0.40;
$W_BUDGET   = 0.30;
$W_RATING   = 0.30;

$maxCost = max(array_map(fn($c) => (float)($c["estimated_cost"] ?? 0), $candidates) ?: [1]);

foreach ($candidates as &$c) {
    $cat         = strtolower(trim((string)($c["category"] ?? "")));
    $cost        = (float)($c["estimated_cost"] ?? 0);
    $rating      = (float)($c["rating"] ?? 0);

    $catScore    = in_array($cat, $interestArr, true) ? 1.0 : 0.3;
    $budgetScore = ($budget > 0 && $cost > 0) ? (($cost <= $budget) ? max(0.2, 1 - $cost / $budget) : 0.0) : 1.0;
    $ratingScore = ($rating > 0) ? min(1.0, $rating / 5.0) : 0.5;

    $c["_score"]  = $W_CATEGORY * $catScore + $W_BUDGET * $budgetScore + $W_RATING * $ratingScore;
    $c["_cat_s"]  = (int)round($catScore * 100);
    $c["_bud_s"]  = (int)round($budgetScore * 100);
    $c["_rat_s"]  = (int)round($ratingScore * 100);
    $c["_pct"]    = (int)round($c["_score"] * 100);
}
unset($c);

// Sort by score descending
usort($candidates, fn($a, $b) => $b["_score"] <=> $a["_score"]);
$best = $candidates[0];

$newTitle = (string)($best["name"] ?? "");
$newCost  = $hasEntranceFeeCol && array_key_exists("entrance_fee", $best)
    ? max(0.0, (float)($best["entrance_fee"] ?? 0))
    : (float)($best["estimated_cost"] ?? 0);
$newPid   = (int)($best["place_id"] ?? 0);
$newCategory = (string)($best["category"] ?? "");
$newItemType = item_type_from_category($newCategory);
$districtNote = !empty($best["district"]) ? " | District: " . $best["district"] : "";
$newNotes = "State: " . ($best["state"] ?? "") . $districtNote . " | Category: " . $newCategory;

// ---- Match label ----
$pct = $best["_pct"];
[$matchLabel, $matchColor] = match(true) {
    $pct >= 85 => ['Excellent Match', 'success'],
    $pct >= 70 => ['Good Match',      'primary'],
    $pct >= 50 => ['Fair Match',      'warning'],
    default    => ['Low Match',       'danger'],
};

echo json_encode([
    'status'       => 'success',
    'place_id'     => $newPid,
    'new_title'    => $newTitle,
    'new_cost'     => $newCost,
    'new_state'    => $best["state"] ?? "",
    'new_district' => $best["district"] ?? "",
    'new_category' => $newCategory,
    'new_item_type' => $newItemType,
    'new_notes'    => $newNotes,
    'new_opening_hours' => $best["opening_hours"] ?? "",
    'match_pct'    => $pct,
    'match_label'  => $matchLabel,
    'match_color'  => $matchColor,
    'cat_score'    => $best["_cat_s"],
    'budget_score' => $best["_bud_s"],
    'rating_score' => $best["_rat_s"],
]);
