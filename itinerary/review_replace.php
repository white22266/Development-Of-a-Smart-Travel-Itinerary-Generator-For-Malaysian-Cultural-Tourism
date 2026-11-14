<?php
// itinerary/review_replace.php
// AJAX endpoint for the itinerary review page: the next replacement for one stop or meal.
// The options come from ItineraryEditService::suggestReplacements(): places that keep every
// rule of the trip in that position, best first by the planner's own score. Nothing is saved
// here; the review page saves its changes through review_confirm_nightly.php, which checks
// them again.
//
//   POST itinerary_id, item_id, offset (options already shown), exclude_place_ids (comma list:
//        places pending as replacements of other stops on the page)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once "../config/db_connect.php";
require_once "../services/ItineraryEditService.php";

header('Content-Type: application/json; charset=utf-8');

function review_replace_reply(array $payload): never
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
    review_replace_reply(['status' => 'error', 'message' => 'Unauthorized']);
}
$travellerId = (int)($_SESSION["traveller_id"] ?? 0);
session_write_close();   // the search can take a moment: do not hold up the traveller's other requests

$itineraryId = (int)($_POST["itinerary_id"] ?? 0);
$itemId = (int)($_POST["item_id"] ?? 0);
$offset = max(0, (int)($_POST["offset"] ?? 0));
$exclude = array_values(array_filter(array_map('intval', explode(',', (string)($_POST["exclude_place_ids"] ?? ''))), static fn($x) => $x > 0));
if ($itineraryId <= 0) review_replace_reply(['status' => 'error', 'message' => 'Invalid itinerary ID']);
if ($itemId <= 0) review_replace_reply(['status' => 'error', 'message' => 'Invalid item ID']);

$stmt = $conn->prepare("
    SELECT tp.budget, tp.interests
    FROM itineraries i
    LEFT JOIN traveller_preferences tp ON tp.preference_id = i.preference_id
    WHERE i.itinerary_id = ? AND i.traveller_id = ?
    LIMIT 1
");
$stmt->bind_param("ii", $itineraryId, $travellerId);
$stmt->execute();
$pref = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$pref) review_replace_reply(['status' => 'error', 'message' => 'Itinerary not found']);

$result = ItineraryEditService::suggestReplacements($conn, $itineraryId, $itemId, 1, $offset, $exclude);
if (!$result['ok']) {
    review_replace_reply(['status' => 'error', 'code' => $result['code'], 'message' => $result['message']]);
}
$best = $result['options'][0] ?? null;
if ($best === null) {
    review_replace_reply([
        'status' => 'error',
        'code' => 'NONE',
        'message' => $offset > 0
            ? 'No further option fits this ' . ($result['kind'] === 'meal' ? $result['slot'] : 'stop') . '. Try Another starts again from the best one.'
            : $result['message'],
        'offset' => $offset,
        'more' => false,
    ]);
}

// The match bars of the review page, worked out as on the page (a display measure only; the
// order of the options is the planner's).
$interests = array_filter(array_map('trim', explode(',', strtolower((string)($pref['interests'] ?? '')))));
$budget = (float)($pref['budget'] ?? 0);
$catScore = in_array(strtolower((string)$best['category']), $interests, true) ? 1.0 : 0.3;
$cost = (float)$best['cost'];
$budgetScore = ($budget > 0 && $cost > 0) ? (($cost <= $budget) ? max(0.2, 1 - $cost / $budget) : 0.0) : 1.0;
$ratingScore = (float)$best['rating'] > 0 ? min(1.0, (float)$best['rating'] / 5.0) : 0.5;
$pct = (int)round((0.40 * $catScore + 0.30 * $budgetScore + 0.30 * $ratingScore) * 100);
[$matchLabel, $matchColor] = match (true) {
    $pct >= 85 => ['Excellent Match', 'success'],
    $pct >= 70 => ['Good Match', 'primary'],
    $pct >= 50 => ['Fair Match', 'warning'],
    default => ['Low Match', 'danger'],
};

review_replace_reply([
    'status' => 'success',
    'kind' => $result['kind'],
    'slot' => $result['slot'] ?? null,
    'offset' => $offset,
    'more' => (bool)$result['more'],
    'place_id' => (int)$best['place_id'],
    'new_title' => (string)$best['name'],
    'new_cost' => (float)$best['cost'],
    'new_state' => (string)$best['state'],
    'new_district' => (string)$best['district'],
    'new_category' => (string)$best['category'],
    'new_item_type' => (string)$best['item_type'],
    'new_opening_hours' => (string)$best['opening_hours'],
    'new_start' => (string)$best['start'],
    'new_end' => (string)$best['end'],
    'reasons' => $best['reasons'],
    'match_pct' => $pct,
    'match_label' => $matchLabel,
    'match_color' => $matchColor,
    'cat_score' => (int)round($catScore * 100),
    'budget_score' => (int)round($budgetScore * 100),
    'rating_score' => (int)round($ratingScore * 100),
]);
