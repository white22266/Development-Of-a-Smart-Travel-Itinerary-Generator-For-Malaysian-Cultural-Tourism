<?php
// itinerary/review_replace.php
// AJAX endpoint for the itinerary review page.
//
// Actions:
//   POST (no action field)  → Find a replacement place for a rejected item
//   POST action=confirm     → Delete rejected items, optionally add hotel item, resequence
//
session_start();
require_once "../config/db_connect.php";
require_once "../config/api_keys.php";

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

// =========================================================
// ACTION: confirm — delete rejected items, add hotel, resequence
// =========================================================
if ($action === 'confirm') {
    $rejectedCsv = trim((string)($_POST["rejected_ids"] ?? ""));
    $hotelId     = (int)($_POST["hotel_id"] ?? 0);

    // Delete rejected items
    if ($rejectedCsv !== '') {
        $ids = array_filter(array_map('intval', explode(',', $rejectedCsv)));
        if (!empty($ids)) {
            $ph   = implode(',', array_fill(0, count($ids), '?'));
            $types = str_repeat('i', count($ids));
            $del  = $conn->prepare("DELETE FROM itinerary_items WHERE item_id IN ($ph) AND itinerary_id = ?");
            $allParams = array_merge($ids, [$itineraryId]);
            $allTypes  = $types . 'i';
            $del->bind_param($allTypes, ...$allParams);
            $del->execute();
            $del->close();
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

            $hotelNote = "Hotel | " . ($hotel["district"] ? $hotel["district"] . ", " : "") . $hotel["state"];
            $ins = $conn->prepare("
                INSERT INTO itinerary_items
                  (itinerary_id, day_no, sequence_no, item_type, place_id, item_title, estimated_cost, notes)
                VALUES
                  (?, ?, ?, 'hotel', NULL, ?, ?, ?)
            ");
            $ins->bind_param("iiisds", $itineraryId, $dayNo, $seqNo, $hotel["name"], $hotel["price_per_night"], $hotelNote);
            $ins->execute();
            $ins->close();
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

    // Update total cost
    $costRes = $conn->query("SELECT SUM(estimated_cost) as tc FROM itinerary_items WHERE itinerary_id = $itineraryId");
    $costRow = $costRes->fetch_assoc();
    $total   = (float)($costRow["tc"] ?? 0);
    $conn->query("UPDATE itineraries SET total_estimated_cost = $total WHERE itinerary_id = $itineraryId");

    echo json_encode(['status' => 'success']);
    exit;
}

// =========================================================
// ACTION: replace — find a replacement place
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
$sql = "SELECT place_id, name, state, district, category, latitude, longitude, estimated_cost, opening_hours, rating
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
    $sql2 = "SELECT place_id, name, state, district, category, latitude, longitude, estimated_cost, opening_hours, rating
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
    $sql3 = "SELECT place_id, name, state, district, category, latitude, longitude, estimated_cost, opening_hours, rating
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

// ---- Update the itinerary_items row ----
$newTitle = (string)($best["name"] ?? "");
$newCost  = (float)($best["estimated_cost"] ?? 0);
$newPid   = (int)($best["place_id"] ?? 0);
$newNotes = "State: " . ($best["state"] ?? "") . " | Category: " . ($best["category"] ?? "");

$upd = $conn->prepare("
    UPDATE itinerary_items
    SET place_id = ?, item_title = ?, estimated_cost = ?, notes = ?
    WHERE item_id = ? AND itinerary_id = ?
");
$upd->bind_param("isdsii", $newPid, $newTitle, $newCost, $newNotes, $itemId, $itineraryId);
$upd->execute();
$upd->close();

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
    'new_title'    => $newTitle,
    'new_cost'     => $newCost,
    'new_state'    => $best["state"] ?? "",
    'new_district' => $best["district"] ?? "",
    'new_category' => $best["category"] ?? "",
    'match_pct'    => $pct,
    'match_label'  => $matchLabel,
    'match_color'  => $matchColor,
    'cat_score'    => $best["_cat_s"],
    'budget_score' => $best["_bud_s"],
    'rating_score' => $best["_rat_s"],
]);
