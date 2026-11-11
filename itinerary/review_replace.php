<?php
// itinerary/review_replace.php
// AJAX endpoint for the itinerary review page: finds a replacement place for one stop.
// Nothing is saved here; the review page saves all changes through review_confirm_nightly.php.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once "../config/db_connect.php";
require_once "../services/StrictFestivalCandidatePoolService.php";

// Planning must never see a festival without valid dates. For this request the place table is
// shadowed by a pool without them; the permanent place records are not touched.
StrictFestivalCandidatePoolService::install($conn);

header('Content-Type: application/json; charset=utf-8');

// Auth guard
if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}
$travellerId = (int)($_SESSION["traveller_id"] ?? 0);

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
