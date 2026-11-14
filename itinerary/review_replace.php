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
require_once "../config/i18n.php";
require_once "../services/ItineraryEditService.php";

header('Content-Type: application/json; charset=utf-8');

/**
 * One reason of a replacement option in the traveller's language. The reasons are fixed English
 * phrases of the planner (ItineraryDayBuilder); a phrase that is not recognised stays as it is.
 */
function review_reason_text(string $reason): string
{
    $exact = [
        'interest match' => 'rvw.interest', 'an interest you love' => 'rvw.love', 'in a preferred district' => 'rvw.district',
        'traveller type suitability' => 'rvw.suits', 'preferred visit time fit' => 'rvw.time_fit', 'free place supports budget' => 'rvw.free',
        'fits the trip budget' => 'rvw.budget', 'rating considered' => 'rvw.rating', 'adds category diversity' => 'rvw.diversity',
        'category repeated' => 'rvw.repeated', "repeats the previous day's theme" => 'rvw.theme', 'near previous stop' => 'rvw.near',
        'detour penalty' => 'rvw.detour', 'chosen by you' => 'rvw.chosen',
    ];
    if (isset($exact[$reason])) return t($exact[$reason]);
    if (preg_match('/^you rated (.+) places ([\d.]+)\/5 before$/', $reason, $m)) {
        $key = 'opt.interest.' . strtolower($m[1]);
        return t('rvw.rated', ['category' => t($key) === $key ? $m[1] : t($key), 'avg' => $m[2]]);
    }
    if (preg_match('/^waits (\d+) min for opening$/', $reason, $m)) return t('rvw.waits', ['n' => $m[1]]);
    if (preg_match('/^also your (lunch|dinner)$/', $reason, $m)) return t('rvw.also_meal', ['meal' => mb_strtolower(t_choice('itype', $m[1]))]);
    if (preg_match('/^(\d+)% chance of rain forecast: indoor place preferred$/', $reason, $m)) return t('rvw.rain_indoor', ['n' => $m[1]]);
    if (preg_match('/^outdoor although (\d+)% chance of rain: no indoor place fits$/', $reason, $m)) return t('rvw.rain_outdoor', ['n' => $m[1]]);
    if (preg_match('/^(Lunch|Dinner) ([\d.]+) km off your route, open for the whole meal$/', $reason, $m)) {
        return t('rvw.meal_off', ['meal' => t_choice('itype', $m[1]), 'km' => $m[2]]);
    }
    if (preg_match('/^Time kept free for (lunch|dinner) without a set place$/', $reason, $m)) {
        return t('rvw.meal_free', ['meal' => mb_strtolower(t_choice('itype', $m[1]))]);
    }
    return $reason;
}

/** The label of an item type (attraction, food ...) or category in the traveller's language. */
function review_type_label(string $type): string
{
    $type = strtolower(trim($type));
    if ($type !== '' && t('opt.itype.' . $type) !== 'opt.itype.' . $type) return t('opt.itype.' . $type);
    return t_choice('interest', $type !== '' ? $type : 'attraction');
}

function review_replace_reply(array $payload): never
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
    review_replace_reply(['status' => 'error', 'message' => t('rv.e_unauthorized')]);
}
$travellerId = (int)($_SESSION["traveller_id"] ?? 0);
session_write_close();   // the search can take a moment: do not hold up the traveller's other requests

$itineraryId = (int)($_POST["itinerary_id"] ?? 0);
$itemId = (int)($_POST["item_id"] ?? 0);
$offset = max(0, (int)($_POST["offset"] ?? 0));
$exclude = array_values(array_filter(array_map('intval', explode(',', (string)($_POST["exclude_place_ids"] ?? ''))), static fn($x) => $x > 0));
if ($itineraryId <= 0) review_replace_reply(['status' => 'error', 'message' => t('rv.e_itinerary_id')]);
if ($itemId <= 0) review_replace_reply(['status' => 'error', 'message' => t('rv.e_item_id')]);

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
if (!$pref) review_replace_reply(['status' => 'error', 'message' => t('rv.e_not_found')]);

$result = ItineraryEditService::suggestReplacements($conn, $itineraryId, $itemId, 1, $offset, $exclude);
if (!$result['ok']) {
    review_replace_reply(['status' => 'error', 'code' => $result['code'], 'message' => i18n_refusal((string)$result['code'], (string)$result['message'])]);
}
$best = $result['options'][0] ?? null;
if ($best === null) {
    review_replace_reply([
        'status' => 'error',
        'code' => 'NONE',
        'message' => $offset > 0
            ? t('rv.e_no_more', ['what' => $result['kind'] === 'meal' ? mb_strtolower(t_choice('itype', (string)$result['slot'])) : t('rv.what_stop')])
            : (i18n_lang() === 'en' ? $result['message'] : t('edit.suggest_none')),
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
    $pct >= 85 => [t('rv.m_excellent'), 'success'],
    $pct >= 70 => [t('rv.m_good'), 'primary'],
    $pct >= 50 => [t('rv.m_fair'), 'warning'],
    default => [t('rv.m_low'), 'danger'],
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
    'new_item_label' => review_type_label((string)$best['item_type']),
    'new_opening_hours' => (string)$best['opening_hours'],
    'new_start' => (string)$best['start'],
    'new_end' => (string)$best['end'],
    'reasons' => array_map(static fn($reason): string => review_reason_text((string)$reason), (array)$best['reasons']),
    'match_pct' => $pct,
    'match_label' => $matchLabel,
    'match_color' => $matchColor,
    'cat_score' => (int)round($catScore * 100),
    'budget_score' => (int)round($budgetScore * 100),
    'rating_score' => (int)round($ratingScore * 100),
]);
