<?php
// itinerary/itinerary_review.php
// Post-generation review: users accept/remove/preview replacements, select hotel, then confirm changes.
// Visits can be kept, removed or replaced; a meal (lunch or dinner) can be replaced by another
// food place or a break; note rows are shown as information only. Every change is checked on
// the server by ItineraryEditService when the review is confirmed.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once "../config/db_connect.php";
require_once "../services/PlannerRules.php";
require_once "../services/ItineraryEditService.php";
require_once "../config/i18n.php";

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
    header("Location: ../auth/login.php?role=traveller");
    exit;
}
$travellerName = $_SESSION["traveller_name"] ?? t('common.traveller_default');
$travellerId   = (int)($_SESSION["traveller_id"] ?? 0);

$itineraryId = (int)($_GET["itinerary_id"] ?? 0);
if ($itineraryId <= 0) {
    header("Location: my_itineraries.php");
    exit;
}

// ---- Load itinerary header + preference ----
$stmt = $conn->prepare("
    SELECT i.*, tp.transport_type, tp.budget, tp.interests, tp.preferred_states
    FROM itineraries i
    LEFT JOIN traveller_preferences tp ON tp.preference_id = i.preference_id
    WHERE i.itinerary_id = ? AND i.traveller_id = ?
    LIMIT 1
");
$stmt->bind_param("ii", $itineraryId, $travellerId);
$stmt->execute();
$it = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$it) {
    header("Location: my_itineraries.php");
    exit;
}

$transportType = $it["transport_type"] ?? "car";
$budget        = (float)($it["budget"] ?? 0);
$interests     = strtolower((string)($it["interests"] ?? ""));
$preferredStates = (string)($it["preferred_states"] ?? "");

// ---- Load all itinerary items with place details ----
$mealSlotSelect = PlannerRules::hasColumn($conn, 'itinerary_items', 'meal_slot') ? 'ii.meal_slot' : 'NULL AS meal_slot';
// The place type (migration 20261119_place_types.sql), for opening hours the planner assumed.
$placeTypeSelect = PlannerRules::hasColumn($conn, 'cultural_places', 'place_type') ? 'cp.place_type' : 'NULL AS place_type';
$stmt = $conn->prepare("
    SELECT ii.item_id, ii.day_no, ii.sequence_no, ii.item_type, ii.place_id, {$mealSlotSelect},
           ii.item_title, ii.start_time, ii.end_time, ii.estimated_cost, ii.distance_km, ii.travel_time_min, ii.notes,
           cp.latitude, cp.longitude, cp.address, cp.category, cp.state, cp.district,
           cp.opening_hours, {$placeTypeSelect}, cp.description, cp.rating
    FROM itinerary_items ii
    LEFT JOIN cultural_places cp ON cp.place_id = ii.place_id
    WHERE ii.itinerary_id = ?
    ORDER BY ii.day_no, ii.sequence_no
");
$stmt->bind_param("i", $itineraryId);
$stmt->execute();
$res = $stmt->get_result();
$stmt->close();

$days = [];
while ($r = $res->fetch_assoc()) {
    // The hours of a place as the planner reads them: assumed hours are marked "(assumed)".
    if ($r['place_id'] !== null) $r['opening_hours'] = OpeningHours::shown($r);
    $days[(int)$r["day_no"]][] = $r;
}
$totalDays = (int)$it["total_days"];

// ---- Compute match score for each place ----
// Score = W_rating * (rating/5) + W_budget * budget_fit + W_category * category_match
// Weights: Rating 30%, Budget 30%, Category 40%
$interestArr = array_filter(array_map('trim', explode(',', $interests)));

function computeMatchScore(array $item, array $interests, float $budget): array
{
    $W_RATING   = 0.30;
    $W_BUDGET   = 0.30;
    $W_CATEGORY = 0.40;

    $category = strtolower(trim((string)($item["category"] ?? $item["item_type"] ?? "")));
    $cost     = (float)($item["estimated_cost"] ?? 0);
    $rating   = (float)($item["rating"] ?? 0);

    // Category match
    $catScore = in_array($category, $interests, true) ? 1.0 : 0.3;

    // Budget fit: free = 1.0, within budget = scale, over budget = 0
    $budgetScore = 1.0;
    if ($budget > 0 && $cost > 0) {
        $budgetScore = ($cost <= $budget) ? max(0.2, 1 - ($cost / $budget)) : 0.0;
    }

    // Rating (0-5 -> 0-1)
    $ratingScore = ($rating > 0) ? min(1.0, $rating / 5.0) : 0.5; // default 0.5 if no rating

    $total = $W_CATEGORY * $catScore + $W_BUDGET * $budgetScore + $W_RATING * $ratingScore;
    $pct   = (int)round($total * 100);

    // Label
    $label = match(true) {
        $pct >= 85 => [t('rv.m_excellent'), 'success'],
        $pct >= 70 => [t('rv.m_good'),      'primary'],
        $pct >= 50 => [t('rv.m_fair'),      'warning'],
        default    => [t('rv.m_low'),       'danger'],
    };

    return [
        'score'       => $total,
        'pct'         => $pct,
        'label'       => $label[0],
        'color'       => $label[1],
        'cat_score'   => (int)round($catScore * 100),
        'budget_score'=> (int)round($budgetScore * 100),
        'rating_score'=> (int)round($ratingScore * 100),
    ];
}

function extractReasonSelected(?string $notes): string
{
    $text = (string)$notes;
    $pos = strpos($text, 'Reason:');
    if ($pos === false) return '';
    return trim(substr($text, $pos + 7));
}

function js_arg($value): string
{
    return htmlspecialchars(
        json_encode((string)$value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP),
        ENT_QUOTES,
        'UTF-8'
    );
}

/** What a row is on this page: a visit, a meal (lunch or dinner), a hotel night or a note. */
function reviewItemKind(array $item): string
{
    $type = strtolower((string)($item['item_type'] ?? ''));
    if ($type === 'hotel') return 'hotel';
    if ($type === 'note' || $type === 'transport') return 'note';
    if (($item['meal_slot'] ?? null) !== null) return 'meal';
    return 'visit';
}

/** Label of a stored item type or category (attraction, food, museum ...) in the traveller's language. */
function reviewTypeLabel(string $type): string
{
    $type = strtolower(trim($type));
    if ($type !== '' && t('opt.itype.' . $type) !== 'opt.itype.' . $type) return t('opt.itype.' . $type);
    return t_choice('interest', $type !== '' ? $type : 'attraction');
}

/** "09:30:00" => "09:30", or "" */
function reviewClock(?string $time): string
{
    return preg_match('/^(\d{1,2}):(\d{2})/', (string)$time, $m) ? sprintf('%02d:%02d', (int)$m[1], (int)$m[2]) : '';
}

function reviewLastSchedulableStop(array $items): ?array
{
    $last = null;
    foreach ($items as $item) {
        if (reviewItemKind($item) !== 'visit') {
            continue;
        }
        $last = $item;
    }
    return $last;
}

// Day colors
$dayColors = [
    1 => '#EF4444', 2 => '#3B82F6', 3 => '#22C55E',
    4 => '#F59E0B', 5 => '#8B5CF6', 6 => '#EC4899', 7 => '#14B8A6',
];

$startDate = $it["start_date"] ?? null;
$totalNights = max(0, $totalDays - 1);
// Where each night is spent (the base of its day, ItineraryEditService::dayBases()), and the runs
// of consecutive nights at the same base: one hotel choice can be applied to all of them.
$reviewBases = ItineraryEditService::dayBases($conn, $itineraryId);
$nightRuns = [];
for ($nightNo = 1; $nightNo <= $totalNights; $nightNo++) {
    $key = isset($reviewBases[$nightNo]) ? $reviewBases[$nightNo]['name'] . '|' . implode(',', $reviewBases[$nightNo]['point']) : null;
    $prevKey = isset($reviewBases[$nightNo - 1]) && $nightNo > 1 ? $reviewBases[$nightNo - 1]['name'] . '|' . implode(',', $reviewBases[$nightNo - 1]['point']) : null;
    $nightRuns[$nightNo] = ($key !== null && $key === $prevKey) ? [$nightRuns[$nightNo - 1][0], $nightNo] : [$nightNo, $nightNo];
    if ($nightRuns[$nightNo][0] < $nightNo) {
        for ($k = $nightRuns[$nightNo][0]; $k < $nightNo; $k++) $nightRuns[$k][1] = $nightNo;
    }
}

// ---- Versions of the plan (api/itinerary_versions.php): the same trip planned in another style ----
// A version is reviewed through the plan it belongs to; the comparison shows the plan under review
// and its versions side by side (ItineraryEditService::versionSummary()).
$versionsReady = ItineraryEditService::hasVersions($conn);
if ($versionsReady && $it['version_of'] !== null) {
    header("Location: itinerary_review.php?itinerary_id=" . (int)$it['version_of'] . "#versions");
    exit;
}
$versionRows = $versionsReady ? ItineraryEditService::versionList($conn, $itineraryId) : [];
$stylesMade = array_merge(array_column($versionRows, 'plan_style'), $versionsReady && $it['plan_style'] !== null ? [(string)$it['plan_style']] : []);
$compare = [];
if ($versionRows) {
    $first = ItineraryEditService::versionSummary($conn, $itineraryId);
    if ($first !== null) $compare[] = $first + ['first' => true];
    foreach ($versionRows as $v) {
        $summary = ItineraryEditService::versionSummary($conn, (int)$v['itinerary_id']);
        if ($summary !== null) $compare[] = $summary + ['first' => false];
    }
}
$styleNames = ['relaxed' => t('rv.style_relaxed'), 'budget' => t('rv.style_budget'), 'interest' => t('rv.style_interest')];
$styleNotes = ['relaxed' => t('rv.style_relaxed_note'), 'budget' => t('rv.style_budget_note'), 'interest' => t('rv.style_interest_note')];
$styleLabel = static fn(?string $style): string => $styleNames[(string)$style] ?? t('rv.style_standard');
$minutesLabel = static fn(int $min): string => $min >= 60 ? t('rv.cmp_hours_min', ['h' => intdiv($min, 60), 'm' => $min % 60]) : t('rv.cmp_min', ['m' => $min]);
$clock = static fn(?int $m): string => $m === null ? '-' : sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
?>
<!DOCTYPE html>
<html lang="<?php echo i18n_lang(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo te('rv.title'); ?> - <?php echo htmlspecialchars($it["title"]); ?></title>
    <link rel="stylesheet" href="../assets/dashboard_style.css?v=20260617j">
    <style>
        /* ===== Review page styles ===== */
        .review-header {
            background: #fff;
            border: 1px solid rgba(15,23,42,0.08);
            border-radius: 12px;
            padding: 18px 20px;
            margin-bottom: 16px;
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            align-items: center;
            gap: 18px;
            box-shadow: 0 10px 24px rgba(15,23,42,0.06);
        }
        .review-header h2 { margin: 0; font-size: 22px; color:#001a4d; }
        .review-header p  { margin: 5px 0 0; color:#52627d; font-size: 13px; line-height:1.45; }
        .review-summary {
            display:flex;
            align-items:center;
            justify-content:flex-end;
            gap:10px;
            flex-wrap:wrap;
        }
        .review-pill {
            min-width: 96px;
            border:1px solid rgba(15,23,42,0.10);
            border-radius:10px;
            padding:9px 12px;
            background:#f8fafc;
            text-align:center;
        }
        .review-pill strong { display:block; color:#001a4d; font-size:18px; line-height:1; }
        .review-pill span { display:block; margin-top:4px; color:#64748b; font-size:11px; font-weight:700; }

        /* ===== Versions of the plan ===== */
        .versions-card { background:#fff; border:1px solid rgba(15,23,42,0.08); border-radius:12px; padding:16px 18px; margin-bottom:16px; }
        .versions-card h3 { margin:0 0 4px; color:#001a4d; font-size:17px; }
        .versions-card .meta { margin:0 0 12px; color:#52627d; font-size:12.5px; line-height:1.45; }
        .versions-actions { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:12px; }
        .versions-actions .btn small { display:block; font-weight:600; font-size:10.5px; opacity:.8; }
        .versions-scroll { overflow-x:auto; -webkit-overflow-scrolling:touch; }
        .versions-table { border-collapse:collapse; width:100%; min-width:520px; font-size:12px; }
        .versions-table th, .versions-table td { border-top:1px solid rgba(15,23,42,0.08); padding:7px 8px; text-align:left; vertical-align:top; }
        .versions-table thead th { border-top:none; color:#001a4d; font-size:13px; }
        .versions-table tbody th { color:#64748b; font-weight:700; width:150px; }
        .versions-table .v-ok { color:#15803d; font-weight:700; }
        .versions-table .v-over { color:#b91c1c; font-weight:700; }
        .versions-table .v-day { display:block; margin-bottom:3px; }
        .versions-table .v-day b { color:#334155; }
        .hotel-budget-ok { display:inline-block; margin-top:4px; font-size:11px; font-weight:800; color:#15803d; }
        .hotel-budget-over { display:inline-block; margin-top:4px; font-size:11px; font-weight:800; color:#b91c1c; }

        /* ===== Place card ===== */
        .place-card {
            border: 1.5px solid rgba(15,23,42,0.10);
            border-radius: 10px;
            padding: 14px;
            margin-bottom: 12px;
            background: #fff;
            transition: border-color .2s, box-shadow .2s;
            position: relative;
        }
        .place-card.accepted  { border-color: #22c55e; background: #f0fdf4; }
        .place-card.rejected  { border-color: #ef4444; background: #fef2f2; opacity: .65; }
        .place-card.replacing { border-color: #f59e0b; background: #fffbeb; }
        .place-card.replacement-pending { border-color: #f59e0b; background:#fffaf0; }

        .place-card-top {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 286px;
            gap: 18px;
            align-items: start;
        }
        .place-title {
            font-weight: 800;
            font-size: 15px;
        }
        .place-meta {
            font-size: 11.5px;
            color: #64748b;
            margin-top: 3px;
        }
        .place-actions {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
            align-items: center;
            justify-self: end;
            width: 286px;
        }
        .btn-accept,
        .btn-reject,
        .btn-replace {
            min-height: 38px;
            border: none;
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 800;
            cursor: pointer;
            transition: background .15s, transform .12s, opacity .15s;
            white-space: nowrap;
        }
        .btn-accept {
            background: #22c55e;
            color: #fff;
        }
        .btn-accept:hover { background: #16a34a; }
        .btn-reject {
            background: #ef4444;
            color: #fff;
        }
        .btn-reject:hover { background: #dc2626; }
        .btn-replace {
            background: #f59e0b;
            color: #fff;
        }
        .btn-replace:hover { background: #d97706; }
        .btn-accept:disabled,
        .btn-reject:disabled,
        .btn-replace:disabled { opacity:.7; cursor:not-allowed; transform:none; }
        .review-status {
            grid-column: 1 / -1;
            justify-self: end;
            min-height: 24px;
            border-radius: 999px;
            padding: 4px 10px;
            font-size: 11px;
            font-weight: 900;
            background:#dcfce7;
            color:#15803d;
        }
        .place-card.rejected .review-status { background:#fee2e2; color:#b91c1c; }
        .place-card.replacing .review-status,
        .place-card.replacement-pending .review-status { background:#fef3c7; color:#92400e; }

        /* A stop the traveller chose to keep: actions hidden until Reset All. Saved on Confirm & View Trip. */
        .place-card.kept { border-color: #16a34a; background: #f0fdf4; }
        .place-card.kept .place-actions button { display: none; }
        .place-card.kept .review-status { background: #dcfce7; color: #15803d; border: 1px solid rgba(34, 197, 94, .28); }
        .kept-note { background: #dcfce7; color: #15803d; }

        /* ===== Match score bar ===== */
        .match-score-wrap {
            margin-top: 10px;
        }
        .match-bar-row {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 4px;
        }
        .match-bar-label {
            font-size: 10.5px;
            color: #64748b;
            width: 80px;
            flex-shrink: 0;
        }
        .match-bar-track {
            flex: 1;
            height: 7px;
            background: rgba(15,23,42,0.08);
            border-radius: 999px;
            overflow: hidden;
        }
        .match-bar-fill {
            height: 100%;
            border-radius: 999px;
            transition: width .5s ease;
        }
        .match-bar-pct {
            font-size: 10.5px;
            font-weight: 800;
            width: 32px;
            text-align: right;
            flex-shrink: 0;
        }
        .match-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 800;
        }
        .badge-success { background: #dcfce7; color: #15803d; }
        .badge-primary { background: #dbeafe; color: #1d4ed8; }
        .badge-warning { background: #fef9c3; color: #854d0e; }
        .badge-danger  { background: #fee2e2; color: #b91c1c; }

        /* ===== Score breakdown tooltip ===== */
        .score-breakdown {
            display: none;
            background: rgba(15,23,42,0.04);
            border-radius: 8px;
            padding: 8px 10px;
            margin-top: 6px;
            font-size: 11px;
        }
        .score-breakdown.visible { display: block; }

        /* ===== Day section ===== */
        .day-section {
            margin-bottom: 24px;
        }
        .day-header {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            border-radius: 10px;
            margin-bottom: 12px;
            border-left: 4px solid;
        }

        /* ===== Optional hotel section ===== */
        .optional-hotel-card {
            padding: 18px;
            margin-bottom: 20px;
        }
        .hotel-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 14px;
        }
        .hotel-night-panel {
            border: 1px solid rgba(15,23,42,0.12);
            border-radius: 12px;
            padding: 14px;
            background: #fff;
            margin-top: 12px;
        }
        .hotel-night-head {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 12px;
            align-items: center;
        }
        .hotel-night-title {
            font-weight: 900;
            color: #001a4d;
            font-size: 14px;
        }
        .hotel-night-meta {
            font-size: 12px;
            color: #64748b;
            margin-top: 4px;
        }
        .hotel-night-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }
        .hotel-results {
            display: none;
            margin-top: 12px;
        }
        .hotel-results.open {
            display: block;
        }
        .hotel-message {
            border: 1px solid rgba(15,23,42,0.10);
            border-radius: 10px;
            padding: 12px;
            background: #f8fafc;
            color: #475569;
            font-size: 12.5px;
        }
        .hotel-price-source {
            display: inline-flex;
            width: fit-content;
            max-width: 100%;
            padding: 4px 9px;
            border-radius: 999px;
            background: #fef3c7;
            color: #92400e;
            font-size: 11px;
            font-weight: 900;
            line-height: 1.2;
            margin-top: 7px;
        }
        .hotel-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
            gap: 12px;
            margin-top: 10px;
        }
        .hotel-result-card {
            border: 1.5px solid rgba(15,23,42,0.10);
            border-radius: 12px;
            padding: 12px 14px;
            background: #fff;
            transition: border-color .15s, box-shadow .15s;
            position: relative;
        }
        .hotel-result-card:hover { border-color: #4f46e5; box-shadow: 0 2px 10px rgba(79,70,229,.12); }
        .hotel-result-card.selected { border-color: #22c55e; background: #f0fdf4; }
        .hotel-name-rv { font-weight: 800; font-size: 13px; }
        .hotel-meta-rv { font-size: 11px; color: #64748b; margin-top: 3px; }
        .hotel-price-rv { font-weight: 900; color: #4f46e5; font-size: 14px; margin-top: 6px; }
        .hotel-card-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 10px;
        }
        .hotel-selected-note {
            display: inline-block;
            margin-top: 8px;
            padding: 4px 9px;
            border-radius: 999px;
            background: #dcfce7;
            color: #15803d;
            font-size: 11px;
            font-weight: 900;
        }

        /* ===== Spinner ===== */
        .spinner {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255,255,255,.4);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin .6s linear infinite;
            vertical-align: middle;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* ===== Sticky confirm bar ===== */
        .confirm-bar {
            position: sticky;
            bottom: 0;
            background: #fff;
            border-top: 1px solid rgba(15,23,42,0.10);
            padding: 14px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
            z-index: 100;
            box-shadow: 0 -4px 20px rgba(15,23,42,.08);
            border-radius: 10px 10px 0 0;
            margin-top: 16px;
        }
        .confirm-stats {
            font-size: 13px;
            color: #475569;
        }
        .confirm-stats strong { color: #0f172a; }

        /* ===== Replacement result ===== */
        .replacement-result {
            margin-top: 10px;
            padding: 10px 14px;
            border-radius: 10px;
            border: 1.5px dashed #f59e0b;
            background: #fffbeb;
            font-size: 12.5px;
            display: none;
        }
        .replacement-result.visible { display: block; }

        /* ===== Type badge ===== */
        .type-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 10.5px;
            font-weight: 800;
        }
        .badge-attraction { background:#dbeafe; color:#1d4ed8; }
        .badge-food       { background:#fef9c3; color:#854d0e; }
        .badge-hotel      { background:#f0fdf4; color:#166534; }
        .badge-festival   { background:#fdf4ff; color:#7e22ce; }
        .badge-museum     { background:#fff7ed; color:#c2410c; }
        .badge-heritage   { background:#fef3c7; color:#92400e; }
        .badge-culture    { background:#ede9fe; color:#5b21b6; }
        .badge-nature     { background:#dcfce7; color:#15803d; }
        .badge-note       { background:#f1f5f9; color:#475569; }
        /* A note row explains a day (a free day, a must-see place not scheduled): information only. */
        .place-card.note-card { border-style: dashed; border-color: rgba(15,23,42,0.18); background:#f8fafc; }
        .review-refused {
            display:none;
            border:1.5px solid #f59e0b;
            background:#fffbeb;
            border-radius:10px;
            padding:14px 16px;
            margin: 0 0 16px;
            font-size:13px;
            color:#78350f;
        }
        .review-refused.visible { display:block; }
        .review-refused ul { margin:8px 0 10px 18px; padding:0; }
        .pending-change-note {
            display: inline-block;
            margin-top: 8px;
            padding: 4px 9px;
            border-radius: 999px;
            background: rgba(245,158,11,.14);
            color: #92400e;
            font-size: 11px;
            font-weight: 800;
        }
        .ai-chat-fab {
            position: fixed;
            right: 22px;
            bottom: 22px;
            z-index: 1200;
            border: none;
            border-radius: 999px;
            background: #0f172a;
            color: #fff;
            padding: 12px 18px;
            font-weight: 800;
            box-shadow: 0 14px 34px rgba(15,23,42,0.22);
            cursor: pointer;
        }
        .ai-chat-panel {
            position: fixed;
            right: 22px;
            bottom: 82px;
            z-index: 1200;
            width: min(390px, calc(100vw - 32px));
            max-height: min(620px, calc(100vh - 120px));
            display: none;
            flex-direction: column;
            overflow: hidden;
            background: #fff;
            border: 1px solid rgba(15,23,42,0.14);
            border-radius: 12px;
            box-shadow: 0 20px 50px rgba(15,23,42,0.24);
        }
        .ai-chat-panel.open { display: flex; }
        .ai-chat-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 12px 14px;
            background: #0f172a;
            color: #fff;
        }
        .ai-chat-title { font-weight: 900; font-size: 13px; }
        .ai-chat-subtitle { font-size: 11px; color: #cbd5e1; margin-top: 2px; }
        .ai-chat-close {
            border: 0;
            background: rgba(255,255,255,0.12);
            color: #fff;
            width: 30px;
            height: 30px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 18px;
            line-height: 1;
        }
        .ai-chat-body {
            min-height: 210px;
            max-height: 320px;
            overflow-y: auto;
            padding: 12px;
            background: #f8fafc;
        }
        .ai-msg {
            width: fit-content;
            max-width: 88%;
            white-space: pre-wrap;
            word-wrap: break-word;
            font-size: 12.5px;
            line-height: 1.45;
            padding: 9px 10px;
            border-radius: 10px;
            margin-bottom: 9px;
        }
        .ai-msg.user { margin-left: auto; background: #4f46e5; color: #fff; }
        .ai-msg.bot { margin-right: auto; background: #fff; color: #334155; border: 1px solid rgba(15,23,42,0.08); }
        .ai-chat-prompts {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            padding: 10px 12px 0;
            background: #fff;
        }
        .ai-chat-prompts button {
            border: 1px solid rgba(15,23,42,0.12);
            background: #fff;
            color: #334155;
            border-radius: 999px;
            padding: 6px 9px;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
        }
        .ai-chat-form {
            display: flex;
            gap: 8px;
            padding: 12px;
            background: #fff;
            border-top: 1px solid rgba(15,23,42,0.08);
        }
        .ai-chat-form input {
            flex: 1;
            min-width: 0;
            border: 1px solid rgba(15,23,42,0.14);
            border-radius: 10px;
            padding: 9px 10px;
            font-size: 12.5px;
        }
        .ai-chat-form button {
            border: 0;
            border-radius: 10px;
            background: #4f46e5;
            color: #fff;
            padding: 9px 12px;
            font-weight: 800;
            cursor: pointer;
        }
        @media (max-width: 980px) {
            .review-header { grid-template-columns: 1fr; }
            .review-summary { justify-content:flex-start; }
            .place-card-top { grid-template-columns: 1fr; }
            .place-actions { width:100%; justify-self:stretch; }
            .review-status { justify-self:start; }
            .ai-chat-fab { right: 14px; bottom: 14px; }
            .ai-chat-panel { right: 14px; bottom: 72px; }
        }
    </style>
</head>
<body>
<div class="app">

    <!-- ===== Sidebar ===== -->
    <aside class="sidebar">
        <div class="brand">
            <div class="brand-badge">ST</div>
            <div class="brand-title">
                <strong>Smart Travel Itinerary Generator</strong>
                <span><?php echo te('rv.title'); ?></span>
            </div>
        </div>
        <nav class="nav" aria-label="<?php echo te('nav.aria'); ?>">
            <a href="../traveller/traveller_dashboard.php"><span class="dot"></span> <?php echo te('nav.dashboard'); ?></a>
            <a href="../preference/preference_form.php"><span class="dot"></span> <?php echo te('nav.preference'); ?></a>
            <a href="../itinerary/select_preference.php"><span class="dot"></span> <?php echo te('nav.generator'); ?></a>
            <a class="active" href="../itinerary/my_itineraries.php"><span class="dot"></span> <?php echo te('nav.trips'); ?></a>
            <a href="../cultural/cultural_guide.php"><span class="dot"></span> <?php echo te('nav.guide'); ?></a>
            <a href="../auth/profile/profile.php"><span class="dot"></span> <?php echo te('nav.profile'); ?></a>
            <a href="../auth/logout.php"><span class="dot"></span> <?php echo te('nav.logout'); ?></a>
        </nav>
        <div class="lang-switch-wrap" style="margin:12px 14px;"><?php echo i18n_switcher(); ?></div>
        <div class="sidebar-footer">
            <div class="small"><?php echo te('nav.logged_in_as'); ?></div>
            <div style="margin-top:6px; font-weight:800;"><?php echo htmlspecialchars($travellerName); ?></div>
            <div class="chip"><?php echo te('nav.role_traveller'); ?></div>
        </div>
    </aside>

    <!-- ===== Main Content ===== -->
    <main class="content" style="padding:20px 24px 100px;">

        <!-- Review header banner -->
        <div class="review-header">
            <div>
                <h2><?php echo te('rv.heading'); ?></h2>
                <p><?php echo te('rv.intro'); ?></p>
            </div>
            <div class="review-summary">
                <div class="review-pill">
                    <strong><?php echo $totalDays; ?></strong>
                    <span><?php echo te($totalDays > 1 ? 'rv.day_many' : 'rv.day_one'); ?></span>
                </div>
                <div class="review-pill" style="min-width:180px;">
                    <strong style="font-size:13px; line-height:1.2;"><?php echo htmlspecialchars($it["title"]); ?></strong>
                    <span><?php echo te('rv.generated'); ?></span>
                </div>
                <button type="button" class="btn btn-primary" onclick="toggleAiChat()"><?php echo te('rv.ai_chat'); ?></button>
            </div>
        </div>

        <?php if ($versionsReady): ?>
        <!-- Versions of the plan: the same trip in another style, side by side -->
        <section class="versions-card" id="versions" aria-labelledby="versions-title">
            <h3 id="versions-title"><?php echo te('rv.versions_title'); ?></h3>
            <p class="meta"><?php echo te('rv.versions_intro'); ?></p>
            <div class="versions-actions">
                <?php foreach (PlannerRules::STYLES as $style):
                    $made = in_array($style, $stylesMade, true);
                    $full = count($versionRows) >= ItineraryGenerator::MAX_VERSIONS;
                ?>
                <button type="button" class="btn btn-ghost" data-style="<?php echo $style; ?>" data-label="<?php echo htmlspecialchars($styleLabel($style)); ?>"
                        onclick="makeVersion(this)" <?php echo ($made || $full) ? 'disabled' : ''; ?>>
                    <?php echo te('rv.version_make', ['style' => $styleLabel($style)]); ?>
                    <small><?php echo htmlspecialchars($styleNotes[$style] ?? ''); ?></small>
                </button>
                <?php endforeach; ?>
            </div>
            <?php if ($compare): ?>
            <div class="versions-scroll">
                <table class="versions-table">
                    <thead>
                        <tr>
                            <th scope="col"></th>
                            <?php foreach ($compare as $v): ?>
                            <th scope="col"><?php echo $v['first'] ? te('rv.style_this', ['style' => $styleLabel($v['style'])]) : htmlspecialchars($styleLabel($v['style'])); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <th scope="row"><?php echo te('rv.cmp_stops'); ?></th>
                            <?php foreach ($compare as $v): ?>
                            <td><?php echo htmlspecialchars(implode(' / ', array_map(static fn(array $d): string => (string)$d['stops'], $v['days']))); ?> (<?php echo (int)$v['stops']; ?>)</td>
                            <?php endforeach; ?>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo te('rv.cmp_hours'); ?></th>
                            <?php foreach ($compare as $v): ?>
                            <td><?php foreach ($v['days'] as $dn => $d): ?><span class="v-day"><b><?php echo te('trip.day', ['n' => $dn]); ?></b> <?php echo $clock($d['first']); ?>-<?php echo $clock($d['last']); ?></span><?php endforeach; ?></td>
                            <?php endforeach; ?>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo te('rv.cmp_travel'); ?></th>
                            <?php foreach ($compare as $v): ?>
                            <td><?php echo htmlspecialchars($minutesLabel((int)$v['travel_min'])); ?></td>
                            <?php endforeach; ?>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo te('rv.cmp_cost'); ?></th>
                            <?php foreach ($compare as $v): ?>
                            <td>RM <?php echo number_format((float)$v['total'], 2); ?><br>
                                <?php if ((float)$v['budget'] <= 0): ?><?php echo te('rv.cmp_no_budget'); ?>
                                <?php elseif ((float)$v['over_by'] > 0): ?><span class="v-over"><?php echo te('rv.cmp_over', ['n' => number_format((float)$v['over_by'], 2)]); ?></span>
                                <?php else: ?><span class="v-ok"><?php echo te('rv.cmp_within', ['n' => number_format((float)$v['budget'] - (float)$v['total'], 2)]); ?></span><?php endif; ?>
                            </td>
                            <?php endforeach; ?>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo te('rv.cmp_interest'); ?></th>
                            <?php foreach ($compare as $v): ?>
                            <td><?php echo te('rv.cmp_interest_n', ['n' => (int)$v['interest_stops'], 'total' => (int)$v['stops'], 'loved' => (int)$v['loved_stops']]); ?></td>
                            <?php endforeach; ?>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo te('rv.cmp_places'); ?></th>
                            <?php foreach ($compare as $v): ?>
                            <td><?php foreach ($v['days'] as $dn => $d): ?><span class="v-day"><b><?php echo te('trip.day', ['n' => $dn]); ?>:</b> <?php echo $d['places'] ? htmlspecialchars(implode(', ', $d['places'])) : te('rv.cmp_free_day'); ?></span><?php endforeach; ?></td>
                            <?php endforeach; ?>
                        </tr>
                        <tr>
                            <th scope="row"></th>
                            <?php foreach ($compare as $v): ?>
                            <td>
                                <button type="button" class="btn <?php echo $v['first'] ? 'btn-ghost' : 'btn-primary'; ?>" data-version="<?php echo (int)$v['itinerary_id']; ?>"
                                        data-label="<?php echo htmlspecialchars($v['first'] ? t('rv.style_this', ['style' => $styleLabel($v['style'])]) : $styleLabel($v['style'])); ?>"
                                        onclick="useVersion(this)"><?php echo $v['first'] ? te('rv.version_keep') : te('rv.version_use'); ?></button>
                            </td>
                            <?php endforeach; ?>
                        </tr>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <!-- Legend -->
        <div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:18px; font-size:12px;">
            <span style="display:flex; align-items:center; gap:5px;"><span style="width:12px;height:12px;border-radius:50%;background:#22c55e;display:inline-block;"></span> <?php echo te('rv.kept'); ?></span>
            <span style="display:flex; align-items:center; gap:5px;"><span style="width:12px;height:12px;border-radius:50%;background:#ef4444;display:inline-block;"></span> <?php echo te('rv.lg_removed'); ?></span>
            <span style="display:flex; align-items:center; gap:5px;"><span style="width:12px;height:12px;border-radius:50%;background:#f59e0b;display:inline-block;"></span> <?php echo te('rv.lg_pending'); ?></span>
            <span style="color:#64748b;"><?php echo te('rv.lg_score'); ?></span>
        </div>

        <!-- ===== Day-by-day place review ===== -->
        <?php foreach ($days as $d => $items):
            $col = $dayColors[$d] ?? '#6366f1';
            $dateStr = '';
            if ($startDate) {
                $ts = strtotime($startDate . ' +' . ($d - 1) . ' days');
                $dateStr = i18n_date(date('Y-m-d', $ts), 'long');
            }
        ?>
        <div class="day-section" id="day-section-<?php echo $d; ?>">
            <div class="day-header" style="background:<?php echo $col; ?>12; border-color:<?php echo $col; ?>;">
                <span style="font-size:20px;"><?php echo te('rv.day_one'); ?></span>
                <div>
                    <div style="font-weight:900; color:<?php echo $col; ?>; font-size:15px;"><?php echo te('trip.day', ['n' => $d]); ?></div>
                    <?php if ($dateStr): ?><div style="font-size:12px; color:#64748b;"><?php echo $dateStr; ?></div><?php endif; ?>
                </div>
                <?php $visitCount = count(array_filter($items, static fn($i) => reviewItemKind($i) === 'visit')); ?>
                <div style="margin-left:auto; font-size:12px; color:#64748b;" id="day-<?php echo $d; ?>-count">
                    <?php echo $visitCount === 1 ? te('rv.place_one') : te('rv.place_many', ['n' => $visitCount]); ?>
                </div>
            </div>

            <?php foreach ($items as $idx => $item):
                $kind = reviewItemKind($item);
                if ($kind === 'note' || $kind === 'hotel'):
                    $info = i18n_reason_text(extractReasonSelected($item['notes'] ?? ''));
                    if ($info === '') $info = i18n_stop_note((string)($item['notes'] ?? ''));
            ?>
            <div class="place-card note-card" data-kind="<?php echo $kind; ?>" data-day="<?php echo $d; ?>">
                <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                    <span class="type-badge badge-<?php echo $kind; ?>"><?php echo $kind === 'hotel' ? te('rv.badge_hotel') : te('rv.badge_note'); ?></span>
                    <span class="place-title"><?php echo htmlspecialchars(i18n_item_title((string)$item['item_title'])); ?></span>
                </div>
                <?php if ($info !== ''): ?>
                <div class="place-meta" style="margin-top:4px;"><?php echo htmlspecialchars($info); ?></div>
                <?php endif; ?>
            </div>
            <?php
                    continue;
                endif;
                if ($kind === 'meal'):
                    $slotLabel = t_choice('itype', (string)$item['meal_slot']);
                    $mealFrom = reviewClock($item['start_time'] ?? null);
                    $mealTo = reviewClock($item['end_time'] ?? null);
            ?>
            <div class="place-card accepted meal-card"
                 id="card-<?php echo (int)$item['item_id']; ?>"
                 data-item-id="<?php echo (int)$item['item_id']; ?>"
                 data-day="<?php echo $d; ?>"
                 data-kind="meal"
                 data-slot="<?php echo htmlspecialchars((string)$item['meal_slot']); ?>"
                 data-place-id="<?php echo (int)($item['place_id'] ?? 0); ?>"
                 data-title="<?php echo htmlspecialchars($item['item_title'] ?? ''); ?>"
                 data-status="accepted">
                <div class="place-card-top">
                    <div style="flex:1;">
                        <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                            <span class="type-badge badge-food"><?php echo htmlspecialchars($slotLabel); ?></span>
                            <span class="place-title"><?php echo htmlspecialchars(i18n_item_title((string)$item['item_title'])); ?></span>
                        </div>
                        <div class="place-meta place-time" style="margin-top:3px;"><?php if ($mealFrom !== ''): ?><?php echo te('rv.planned', ['from' => $mealFrom, 'to' => $mealTo]); ?><?php endif; ?></div>
                        <div class="place-meta place-meta-main">
                            <?php if ((int)($item['place_id'] ?? 0) > 0): ?>
                                <?php if (!empty($item['state'])): ?><?php echo te('iv.location'); ?> <?php echo htmlspecialchars($item['district'] ? $item['district'] . ', ' . $item['state'] : $item['state']); ?> &middot; <?php endif; ?>
                                <?php if (!empty($item['opening_hours'])): ?><?php echo te('iv.hours'); ?> <?php echo htmlspecialchars($item['opening_hours']); ?> &middot; <?php endif; ?>
                            <?php else: ?>
                                <?php echo te('edit.meal_break'); ?> &middot;
                            <?php endif; ?>
                            <?php echo te('rv.in_meal'); ?>
                        </div>
                        <?php $mealReason = extractReasonSelected($item['notes'] ?? ''); ?>
                        <?php if ($mealReason !== ''): ?>
                        <div class="place-meta" style="margin-top:4px;"><strong><?php echo te('rv.why_here'); ?></strong> <?php echo htmlspecialchars(i18n_reason_text($mealReason)); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="place-actions">
                        <button type="button" class="btn-replace" style="grid-column:3;" onclick="replacePlace(<?php echo (int)$item['item_id']; ?>)" id="btn-replace-<?php echo (int)$item['item_id']; ?>"><?php echo te('edit.replace'); ?></button>
                        <span class="review-status" id="status-<?php echo (int)$item['item_id']; ?>"><?php echo te('rv.slot_planned', ['slot' => $slotLabel]); ?></span>
                    </div>
                </div>
                <div class="replacement-result" id="replacement-<?php echo (int)$item['item_id']; ?>"></div>
            </div>
            <?php
                    continue;
                endif;
                $score = computeMatchScore($item, $interestArr, $budget);
                $typeClass = 'badge-' . strtolower($item['item_type'] ?? 'attraction');
                $barColor = match($score['color']) {
                    'success' => '#22c55e',
                    'primary' => '#3b82f6',
                    'warning' => '#f59e0b',
                    default   => '#ef4444',
                };
            ?>
            <div class="place-card accepted"
                 id="card-<?php echo (int)$item['item_id']; ?>"
                 data-item-id="<?php echo (int)$item['item_id']; ?>"
                 data-day="<?php echo $d; ?>"
                 data-kind="visit"
                 data-place-id="<?php echo (int)($item['place_id'] ?? 0); ?>"
                 data-state="<?php echo htmlspecialchars($item['state'] ?? ''); ?>"
                 data-category="<?php echo htmlspecialchars($item['category'] ?? $item['item_type'] ?? ''); ?>"
                 data-title="<?php echo htmlspecialchars($item['item_title'] ?? ''); ?>"
                 data-status="accepted">

                <div class="place-card-top">
                    <div style="flex:1;">
                        <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                            <span class="type-badge <?php echo htmlspecialchars($typeClass); ?>">
                                <?php echo htmlspecialchars(reviewTypeLabel((string)($item['item_type'] ?? 'attraction'))); ?>
                            </span>
                            <span class="place-title"><?php echo htmlspecialchars(i18n_item_title((string)$item['item_title'])); ?></span>
                        </div>
                        <?php $visitFrom = reviewClock($item['start_time'] ?? null); $visitTo = reviewClock($item['end_time'] ?? null); ?>
                        <div class="place-meta place-time" style="margin-top:3px;"><?php if ($visitFrom !== ''): ?><?php echo te('rv.planned', ['from' => $visitFrom, 'to' => $visitTo]); ?><?php endif; ?></div>
                        <div class="place-meta place-meta-main">
                            <?php if (!empty($item['state'])): ?>
                                <?php echo te('iv.location'); ?> <?php echo htmlspecialchars($item['district'] ? $item['district'] . ', ' . $item['state'] : $item['state']); ?>
                            <?php endif; ?>
                            <?php if (!empty($item['opening_hours'])): ?>
                                &middot; <?php echo te('iv.hours'); ?> <?php echo htmlspecialchars($item['opening_hours']); ?>
                            <?php endif; ?>
                            <?php if ((float)$item['estimated_cost'] > 0): ?>
                                &middot; RM <?php echo number_format((float)$item['estimated_cost'], 2); ?>
                            <?php else: ?>
                                &middot; <span style="color:#16a34a; font-weight:700;"><?php echo te('iv.free'); ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($item['address'])): ?>
                        <div class="place-meta" style="margin-top:2px;"><?php echo te('pdf.address'); ?> <?php echo htmlspecialchars($item['address']); ?></div>
                        <?php endif; ?>
                        <?php $reasonSelected = extractReasonSelected($item['notes'] ?? ''); ?>
                        <?php if ($reasonSelected !== ''): ?>
                        <div class="place-meta" style="margin-top:4px;"><strong><?php echo te('trip.reason'); ?></strong> <?php echo htmlspecialchars(i18n_reason_text($reasonSelected)); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="place-actions">
                        <button type="button" class="btn-accept" onclick="acceptPlace(<?php echo (int)$item['item_id']; ?>)" id="btn-accept-<?php echo (int)$item['item_id']; ?>"><?php echo te('rv.keep'); ?></button>
                        <button type="button" class="btn-reject" onclick="rejectPlace(<?php echo (int)$item['item_id']; ?>)" id="btn-reject-<?php echo (int)$item['item_id']; ?>"><?php echo te('edit.remove'); ?></button>
                        <button type="button" class="btn-replace" onclick="replacePlace(<?php echo (int)$item['item_id']; ?>)" id="btn-replace-<?php echo (int)$item['item_id']; ?>"><?php echo te('edit.replace'); ?></button>
                        <span class="review-status" id="status-<?php echo (int)$item['item_id']; ?>"><?php echo te('rv.kept'); ?></span>
                    </div>
                </div>

                <!-- Match score bars -->
                <div class="match-score-wrap">
                    <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px; flex-wrap:wrap;">
                        <span class="match-badge badge-<?php echo $score['color']; ?>"><?php echo $score['label']; ?></span>
                        <span class="match-main-pct" style="font-size:13px; font-weight:900; color:<?php echo $barColor; ?>;"><?php echo $score['pct']; ?>%</span>
                        <span style="font-size:11px; color:#94a3b8;"><?php echo te('rv.match_word'); ?></span>
                        <button onclick="toggleBreakdown(<?php echo (int)$item['item_id']; ?>)"
                                style="background:none; border:none; font-size:11px; color:#6366f1; cursor:pointer; text-decoration:underline;">
                            <?php echo te('rv.details'); ?>
                        </button>
                    </div>

                    <!-- Overall bar -->
                    <div class="match-bar-row">
                        <span class="match-bar-label"><?php echo te('rv.overall'); ?></span>
                        <div class="match-bar-track">
                            <div class="match-bar-fill match-overall-fill" style="width:<?php echo $score['pct']; ?>%; background:<?php echo $barColor; ?>;"></div>
                        </div>
                        <span class="match-bar-pct match-overall-pct" style="color:<?php echo $barColor; ?>;"><?php echo $score['pct']; ?>%</span>
                    </div>

                    <!-- Breakdown (hidden by default) -->
                    <div class="score-breakdown" id="breakdown-<?php echo (int)$item['item_id']; ?>">
                        <div class="match-bar-row">
                            <span class="match-bar-label"><?php echo te('rv.bar_category'); ?></span>
                            <div class="match-bar-track">
                                <div class="match-bar-fill" style="width:<?php echo $score['cat_score']; ?>%; background:#6366f1;"></div>
                            </div>
                            <span class="match-bar-pct"><?php echo $score['cat_score']; ?>%</span>
                        </div>
                        <div class="match-bar-row">
                            <span class="match-bar-label"><?php echo te('rv.bar_budget'); ?></span>
                            <div class="match-bar-track">
                                <div class="match-bar-fill" style="width:<?php echo $score['budget_score']; ?>%; background:#22c55e;"></div>
                            </div>
                            <span class="match-bar-pct"><?php echo $score['budget_score']; ?>%</span>
                        </div>
                        <div class="match-bar-row">
                            <span class="match-bar-label"><?php echo te('rv.bar_rating'); ?></span>
                            <div class="match-bar-track">
                                <div class="match-bar-fill" style="width:<?php echo $score['rating_score']; ?>%; background:#f59e0b;"></div>
                            </div>
                            <span class="match-bar-pct"><?php echo $score['rating_score']; ?>%</span>
                        </div>
                        <div style="margin-top:6px; color:#64748b;">
                            <?php echo te('rv.d_category'); ?> <strong><?php echo htmlspecialchars(reviewTypeLabel((string)($item['category'] ?? $item['item_type'] ?? 'attraction'))); ?></strong>
                            &middot; <?php echo te('rv.d_cost'); ?> <strong>RM <?php echo number_format((float)$item['estimated_cost'], 2); ?></strong>
                            &middot; <?php echo te('rv.d_rating'); ?> <strong><?php echo $item['rating'] > 0 ? number_format((float)$item['rating'], 1) . '/5' : te('rv.na'); ?></strong>
                        </div>
                    </div>
                </div>

                <!-- Replacement result area -->
                <div class="replacement-result" id="replacement-<?php echo (int)$item['item_id']; ?>"></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>

        <!-- ===== Optional Hotel Suggestions ===== -->
        <div class="card optional-hotel-card" data-native-hotel-review="1">
            <div class="hotel-toolbar">
                <div>
                    <h3 style="margin:0 0 6px;"><?php echo te('rv.hotels_title'); ?></h3>
                    <p class="meta" style="margin:0;"><?php echo te('rv.hotels_intro'); ?></p>
                </div>
            </div>

            <?php if ($totalNights <= 0): ?>
                <?php $lastOneDayStop = reviewLastSchedulableStop($days[1] ?? []); ?>
                <div class="hotel-night-panel">
                    <div class="hotel-night-head">
                        <div>
                            <div class="hotel-night-title"><?php echo te('rv.no_overnight'); ?></div>
                            <div class="hotel-night-meta">
                                <?php echo te('rv.one_day_note'); ?>
                            </div>
                        </div>
                        <?php if ($lastOneDayStop): ?>
                        <div class="hotel-night-actions">
                            <button type="button" class="btn btn-ghost" id="hotel-toggle-night-0" onclick="toggleNightHotels(0, 1, false)"><?php echo te('rv.browse_hotels'); ?></button>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="hotel-results" id="hotel-results-night-0"></div>
                </div>
            <?php else: ?>
                <?php for ($nightNo = 1; $nightNo <= $totalNights; $nightNo++):
                    $stop = reviewLastSchedulableStop($days[$nightNo] ?? []);
                    $stopTitle = $stop ? (string)$stop['item_title'] : t('rv.final_stop', ['day' => $nightNo]);
                ?>
                <div class="hotel-night-panel" id="hotel-night-<?php echo $nightNo; ?>" data-night="<?php echo $nightNo; ?>" data-day="<?php echo $nightNo; ?>">
                    <div class="hotel-night-head">
                        <div>
                            <div class="hotel-night-title"><?php echo te('rv.night_title', ['n' => $nightNo]); ?></div>
                            <div class="hotel-night-meta">
                                <?php if (isset($reviewBases[$nightNo])): ?>
                                    Night at <strong><?php echo htmlspecialchars($reviewBases[$nightNo]['name']); ?></strong>: suggestions are near this base.
                                    <?php if ($nightRuns[$nightNo][1] > $nightRuns[$nightNo][0]): ?>
                                        Nights <?php echo (int)$nightRuns[$nightNo][0]; ?> to <?php echo (int)$nightRuns[$nightNo][1]; ?> are spent at the same base.
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?php echo strtr(te('rv.night_meta'), ['{stop}' => '<strong>' . htmlspecialchars($stopTitle) . '</strong>']); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="hotel-night-actions">
                            <button type="button" class="btn btn-ghost" id="hotel-toggle-night-<?php echo $nightNo; ?>" onclick="toggleNightHotels(<?php echo $nightNo; ?>, <?php echo $nightNo; ?>, true)"><?php echo te('rv.load_hotels'); ?></button>
                            <button type="button" class="btn btn-ghost" onclick="skipHotelNight(<?php echo $nightNo; ?>)"><?php echo te('rv.skip'); ?></button>
                        </div>
                    </div>
                    <div class="hotel-results" id="hotel-results-night-<?php echo $nightNo; ?>"></div>
                </div>
                <?php endfor; ?>
            <?php endif; ?>
        </div>

        <div class="review-refused" id="review-refused" role="status" aria-live="polite">
            <strong><?php echo te('rv.refused_head'); ?></strong>
            <ul id="review-refused-list"></ul>
            <div><?php echo te('rv.refused_tail'); ?></div>
            <div style="margin-top:10px; display:flex; gap:10px; flex-wrap:wrap;">
                <a href="itinerary_view.php?itinerary_id=<?php echo $itineraryId; ?>" class="btn btn-primary"><?php echo te('rv.view_trip'); ?></a>
                <a href="itinerary_review.php?itinerary_id=<?php echo $itineraryId; ?>" class="btn btn-ghost"><?php echo te('rv.review_again'); ?></a>
            </div>
        </div>

        <div class="confirm-bar">
            <div class="confirm-stats">
                <strong id="stat-accepted">0</strong> <?php echo te('rv.stat_kept'); ?> &middot;
                <strong id="stat-rejected">0</strong> <?php echo te('rv.stat_removed'); ?> &middot;
                <strong id="stat-replaced">0</strong> <?php echo te('rv.stat_pending'); ?> &middot;
                <?php echo te('rv.stat_hotel'); ?> <strong id="stat-hotel"><?php echo te('rv.none'); ?></strong>
            </div>
            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                <a href="my_itineraries.php" class="btn btn-ghost"><?php echo te('rv.back_trips'); ?></a>
                <button type="button" class="btn btn-ghost" onclick="resetAll()"><?php echo te('rv.reset_all'); ?></button>
                <button type="button" class="btn btn-primary" id="btn-confirm" onclick="confirmReview()"><?php echo te('rv.confirm_view'); ?></button>
            </div>
        </div>

    </main>


</div><!-- /app -->

<button type="button" class="ai-chat-fab" onclick="toggleAiChat()"><?php echo te('trip.ai_fab'); ?></button>
<div class="ai-chat-panel" id="aiChatPanel" aria-live="polite">
    <div class="ai-chat-header">
        <div>
            <div class="ai-chat-title"><?php echo te('rv.ai_title'); ?></div>
            <div class="ai-chat-subtitle"><?php echo te('rv.ai_sub'); ?></div>
        </div>
        <button type="button" class="ai-chat-close" onclick="toggleAiChat()" aria-label="<?php echo te('trip.ai_close'); ?>">&times;</button>
    </div>
    <div class="ai-chat-body" id="aiChatBody">
        <div class="ai-msg bot"><?php echo te('rv.ai_hello'); ?></div>
    </div>
    <form class="ai-chat-form" onsubmit="sendAiMessage(event)">
        <input id="aiChatInput" type="text" maxlength="700" autocomplete="off" placeholder="<?php echo te('trip.ai_placeholder'); ?>">
        <button type="submit"><?php echo te('trip.ai_send'); ?></button>
    </form>
</div>

<script>
// ---- Texts of this page, in the page's language ----
const RV = <?php echo i18n_js([
    'rv.kept', 'rv.repl_kept', 'rv.repl_kept_note', 'rv.lg_removed', 'rv.lg_pending', 'rv.finding', 'rv.finding_btn', 'edit.replace', 'edit.remove',
    'rv.planned', 'rv.planned_reset', 'iv.hours', 'rv.in_meal', 'edit.meal_break', 'iv.free', 'rv.why_label', 'rv.match_word', 'rv.pending_note',
    'rv.keep_repl', 'rv.keep_orig', 'rv.try_another', 'rv.last_meal', 'rv.last_stop', 'rv.warning', 'rv.no_repl', 'rv.still_pending', 'rv.err_network',
    'rv.planned_word', 'rv.fallback_repl', 'rv.fallback_final', 'rv.show_results', 'rv.hide_results', 'rv.loading_hotels', 'rv.no_final_stop',
    'rv.try_again', 'rv.looking_hotels', 'rv.hotels_failed', 'rv.no_hotels', 'rv.hotels_loaded', 'trip.star', 'trip.km_away', 'rv.unnamed_hotel',
    'rv.price_about', 'rv.price_none', 'rv.price_source', 'rv.selected_night', 'rv.select_night', 'trip.map', 'rv.website', 'trip.check_price',
    'trip.check_price_title', 'opt.hotelkind.hotel', 'rv.hotel_read_failed', 'rv.hotel_skipped', 'rv.browse_hotels', 'rv.load_hotels', 'rv.none',
    'rv.n_selected', 'rv.saving', 'rv.keep_one', 'trip.day', 'rv.refused_hotel_night', 'rv.refused_hotels', 'rv.refused_remove', 'rv.refused_replace',
    'rv.a_stop', 'rv.not_allowed', 'rv.saved', 'rv.error_prefix', 'rv.save_failed', 'rv.confirm_view', 'rv.writing', 'rv.ai_failed',
    'rv.version_making', 'rv.version_failed', 'rv.version_use_confirm', 'rv.version_using', 'rv.hotel_budget_kept', 'rv.hotel_run_over',]); ?>;
function rt(key, vars) {
    let text = RV[key] || key;
    Object.keys(vars || {}).forEach(name => { text = text.split('{' + name + '}').join(String(vars[name])); });
    return text;
}

// ---- State ----
const ITINERARY_ID = <?php echo $itineraryId; ?>;
const TOTAL_NIGHTS = <?php echo (int)$totalNights; ?>;
// night => [first, last] night of the run of consecutive nights at the same base
const NIGHT_RUNS = <?php echo json_encode((object)$nightRuns); ?>;
const itemStatus   = {}; // item_id -> 'accepted' | 'rejected'
const replacementMap = {}; // item_id -> selected replacement place payload, saved only on Confirm
const hotelSelections = {}; // night_no -> selected hotel payload, saved only on Confirm
const hotelResultState = {}; // night_no -> lazy-load/collapse state
const originalCards = {};
const replaceOffsets = {}; // item_id -> options already shown for it (Try Another shows the next)

// Initialise all as accepted
<?php foreach ($days as $d => $items): ?>
<?php foreach ($items as $item): ?>
<?php if (reviewItemKind($item) === 'visit'): ?>
itemStatus[<?php echo (int)$item['item_id']; ?>] = 'accepted';
<?php endif; ?>
<?php endforeach; ?>
<?php endforeach; ?>

updateStats();

document.querySelectorAll('.place-card[data-item-id]').forEach(card => {
    originalCards[card.dataset.itemId] = {
        html: card.innerHTML,
        state: card.dataset.state || '',
        category: card.dataset.category || '',
        placeId: card.dataset.placeId || '',
    };
});

// ---- Accept ----
function acceptPlace(itemId) {
    const card = document.getElementById('card-' + itemId);
    if (!card) return;
    card.classList.remove('rejected', 'replacing', 'replacement-pending');
    card.classList.add('accepted', 'kept');
    card.dataset.status = 'accepted';
    if (card.dataset.kind === 'visit') itemStatus[itemId] = 'accepted';
    const status = document.getElementById('status-' + itemId);
    if (status) status.textContent = '✔ ' + rt(replacementMap[itemId] ? 'rv.repl_kept' : 'rv.kept');
    const replacement = document.getElementById('replacement-' + itemId);
    if (replacementMap[itemId] && replacement && replacement.classList.contains('visible')) {
        replacement.innerHTML = '<span class="pending-change-note kept-note">✔ ' + escHtml(rt('rv.repl_kept_note')) + '</span>';
    }
    updateStats();
}

// ---- Reject ----
function rejectPlace(itemId) {
    const card = document.getElementById('card-' + itemId);
    if (!card) return;
    card.classList.remove('accepted', 'replacing', 'replacement-pending');
    card.classList.add('rejected');
    card.dataset.status = 'rejected';
    itemStatus[itemId] = 'rejected';
    delete replacementMap[itemId];
    const status = document.getElementById('status-' + itemId);
    if (status) status.textContent = rt('rv.lg_removed');
    // Hide replacement result if shown
    const rep = document.getElementById('replacement-' + itemId);
    if (rep) { rep.classList.remove('visible'); rep.innerHTML = ''; }
    updateStats();
}

// ---- Replace (AJAX) ----
// The server offers the best place that keeps every rule of the trip in this position
// (review_replace.php); Try Another asks for the next one. Places pending for other stops are
// not offered again. A meal is offered other food places, or a break without a place.
function pendingPlaceIds(exceptItemId) {
    return Object.entries(replacementMap)
        .filter(([id, r]) => Number(id) !== Number(exceptItemId) && Number(r.place_id || 0) > 0)
        .map(([, r]) => Number(r.place_id));
}

async function replacePlace(itemId, next = false) {
    const card = document.getElementById('card-' + itemId);
    const btn  = document.getElementById('btn-replace-' + itemId);
    const rep  = document.getElementById('replacement-' + itemId);
    if (!card || !btn || !rep) return;
    const isMeal = card.dataset.kind === 'meal';
    const offset = next ? (replaceOffsets[itemId] ?? -1) + 1 : 0;

    card.classList.add('replacing');
    card.classList.remove('accepted', 'rejected', 'replacement-pending', 'kept');
    card.dataset.status = 'replacing';
    const status = document.getElementById('status-' + itemId);
    if (status) status.textContent = rt('rv.finding');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> ' + escHtml(rt('rv.finding_btn'));
    rep.innerHTML = '';
    rep.classList.remove('visible');

    const settle = (label) => {
        card.classList.remove('replacing');
        card.classList.add('accepted');
        card.dataset.status = 'accepted';
        if (!isMeal) itemStatus[itemId] = 'accepted';
        if (status) status.textContent = label;
    };

    try {
        const resp = await fetch('review_replace.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                itinerary_id: ITINERARY_ID,
                item_id: itemId,
                offset: offset,
                exclude_place_ids: pendingPlaceIds(itemId).join(','),
            })
        });
        const data = await parseJsonResponse(resp);

        if (data.status === 'success') {
            replaceOffsets[itemId] = offset;
            replacementMap[itemId] = {
                place_id: data.place_id,
                meal: isMeal,
                title: data.new_title,
                cost: data.new_cost,
                state: data.new_state || '',
                district: data.new_district || '',
                category: data.new_category || '',
                item_type: data.new_item_type || '',
            };

            const titleEl = card.querySelector('.place-title');
            if (titleEl) titleEl.textContent = data.new_title;
            card.dataset.placeId = data.place_id;
            card.dataset.title = data.new_title || '';
            if (!isMeal) {
                card.dataset.state = data.new_state || card.dataset.state;
                card.dataset.category = data.new_category || card.dataset.category;
            }
            const timeEl = card.querySelector('.place-time');
            if (timeEl) timeEl.textContent = data.new_start ? rt('rv.planned_reset', { from: data.new_start, to: data.new_end }) : '';

            const metaMain = card.querySelector('.place-meta-main');
            if (metaMain) {
                const location = [data.new_district, data.new_state].filter(Boolean).join(', ');
                const opening = data.new_opening_hours ? ' | ' + rt('iv.hours') + ' ' + data.new_opening_hours : '';
                const costText = isMeal
                    ? (Number(data.place_id) > 0 ? ' | ' + rt('rv.in_meal') : rt('edit.meal_break') + ' | ' + rt('rv.in_meal'))
                    : (parseFloat(data.new_cost || 0) > 0 ? ' | RM ' + parseFloat(data.new_cost).toFixed(2) : ' | ' + rt('iv.free'));
                metaMain.textContent = [location, opening, costText].filter(Boolean).join('');
            }
            if (!isMeal) {
                const badge = card.querySelector('.type-badge');
                if (badge && data.new_item_type) {
                    badge.textContent = data.new_item_label || (data.new_item_type.charAt(0).toUpperCase() + data.new_item_type.slice(1));
                    badge.className = 'type-badge badge-' + data.new_item_type;
                }
                updateCardMatch(card, data);
            }

            const why = Array.isArray(data.reasons) && data.reasons.length ? escHtml(data.reasons.slice(0, 3).join('; ')) : '';
            const matchLine = isMeal ? '' : `
                <div style="margin-top:8px; display:flex; align-items:center; gap:8px;">
                    <span class="match-badge badge-${data.match_color}">${data.match_label}</span>
                    <span style="font-weight:900; font-size:13px;">${data.match_pct}% ${escHtml(rt('rv.match_word'))}</span>
                </div>`;
            rep.innerHTML = `
                <div style="font-weight:800; color:#d97706; margin-bottom:4px;">${escHtml(rt('rv.lg_pending'))}</div>
                <div style="font-weight:700;">${escHtml(data.new_title)}</div>
                <div style="font-size:11px; color:#64748b; margin-top:3px;">
                    ${data.new_start ? escHtml(rt('rv.planned', { from: data.new_start, to: data.new_end })) : ''}
                    ${data.new_state ? ' | ' + escHtml(data.new_state) : ''}
                    ${isMeal ? '' : (data.new_cost > 0 ? ' | RM ' + parseFloat(data.new_cost).toFixed(2) : ' | ' + escHtml(rt('iv.free')))}
                </div>
                ${why ? `<div style="font-size:11px; color:#64748b; margin-top:3px;">${escHtml(rt('rv.why_label'))} ${why}</div>` : ''}
                ${matchLine}
                <span class="pending-change-note">${escHtml(rt('rv.pending_note'))}</span>
                <div style="margin-top:8px; display:flex; gap:8px; flex-wrap:wrap;">
                    <button type="button" class="btn-accept" onclick="acceptPlace(${itemId})">${escHtml(rt('rv.keep_repl'))}</button>
                    ${isMeal ? `<button type="button" class="btn-reject" onclick="undoReplacement(${itemId})">${escHtml(rt('rv.keep_orig'))}</button>`
                             : `<button type="button" class="btn-reject" onclick="rejectPlace(${itemId})">${escHtml(rt('edit.remove'))}</button>`}
                    ${data.more ? `<button type="button" class="btn-replace" onclick="replacePlace(${itemId}, true)">${escHtml(rt('rv.try_another'))}</button>` : ''}
                </div>
                ${data.more ? '' : '<div style="font-size:11px; color:#64748b; margin-top:6px;">' + escHtml(rt(isMeal ? 'rv.last_meal' : 'rv.last_stop')) + '</div>'}
            `;
            rep.classList.add('visible');

            card.classList.remove('replacing');
            card.classList.add('accepted', 'replacement-pending');
            card.dataset.status = 'accepted';
            if (!isMeal) itemStatus[itemId] = 'accepted';
            if (status) status.textContent = rt('rv.lg_pending');
        } else {
            delete replaceOffsets[itemId];
            rep.innerHTML = '<div style="color:#ef4444; font-weight:700;">' + escHtml(rt('rv.warning')) + ' ' + escHtml(data.message || rt('rv.no_repl')) + '</div>';
            rep.classList.add('visible');
            if (replacementMap[itemId]) {
                card.classList.add('replacement-pending');
                settle(rt('rv.lg_pending'));
                rep.innerHTML += '<span class="pending-change-note">' + escHtml(rt('rv.still_pending')) + '</span>';
            } else {
                settle(rt(isMeal ? 'rv.planned_word' : 'rv.kept'));
            }
        }
    } catch(e) {
        rep.innerHTML = '<div style="color:#ef4444; font-weight:700;">' + escHtml(rt('rv.warning')) + ' ' + escHtml(rt('rv.err_network')) + '</div>';
        rep.classList.add('visible');
        settle(replacementMap[itemId] ? rt('rv.lg_pending') : rt(isMeal ? 'rv.planned_word' : 'rv.kept'));
    }

    btn.disabled = false;
    btn.textContent = rt('edit.replace');
    updateStats();
}

/** Back to the card as generated, without its pending replacement. */
function undoReplacement(itemId) {
    const card = document.getElementById('card-' + itemId);
    const original = originalCards[itemId];
    if (!card || !original) return;
    card.innerHTML = original.html;
    card.dataset.state = original.state;
    card.dataset.category = original.category;
    card.dataset.placeId = original.placeId;
    card.classList.remove('rejected', 'replacing', 'replacement-pending', 'kept');
    card.classList.add('accepted');
    card.dataset.status = 'accepted';
    delete replacementMap[itemId];
    delete replaceOffsets[itemId];
    if (card.dataset.kind === 'visit') itemStatus[itemId] = 'accepted';
    updateStats();
}

// ---- Hotel suggestions ----
function currentLastKeptStopForDay(dayNo) {
    const cards = Array.from(document.querySelectorAll(`.place-card[data-kind="visit"][data-day="${dayNo}"]`))
        .filter(card => card.dataset.status !== 'rejected');
    if (cards.length === 0) return null;
    const last = cards[cards.length - 1];
    const itemId = parseInt(last.dataset.itemId || '0', 10);
    if (itemId && replacementMap[itemId] && replacementMap[itemId].place_id) {
        return {
            itemId: 0,
            placeId: parseInt(replacementMap[itemId].place_id || '0', 10),
            title: replacementMap[itemId].title || last.querySelector('.place-title')?.textContent?.trim() || rt('rv.fallback_repl'),
        };
    }
    return {
        itemId,
        placeId: parseInt(last.dataset.placeId || '0', 10),
        title: last.dataset.title || last.querySelector('.place-title')?.textContent?.trim() || rt('rv.fallback_final'),
    };
}

function hotelToggleButton(nightNo) {
    return document.getElementById('hotel-toggle-night-' + nightNo);
}

function setHotelToggleLabel(nightNo, label, disabled = false) {
    const btn = hotelToggleButton(nightNo);
    if (!btn) return;
    btn.textContent = label;
    btn.disabled = disabled;
}

function toggleNightHotels(nightNo, dayNo, allowSelect) {
    const target = document.getElementById('hotel-results-night-' + nightNo);
    if (!target) return;
    const state = hotelResultState[nightNo] || {};
    if (state.loaded && target.classList.contains('open')) {
        target.classList.remove('open');
        setHotelToggleLabel(nightNo, rt('rv.show_results'));
        return;
    }
    if (state.loaded) {
        target.classList.add('open');
        setHotelToggleLabel(nightNo, rt('rv.hide_results'));
        return;
    }
    loadNightHotels(nightNo, dayNo, allowSelect);
}

async function loadNightHotels(nightNo, dayNo, allowSelect) {
    const target = document.getElementById('hotel-results-night-' + nightNo);
    if (!target) return;

    const stop = currentLastKeptStopForDay(dayNo);
    target.classList.add('open');
    setHotelToggleLabel(nightNo, rt('rv.loading_hotels'), true);
    if (!stop || (!stop.itemId && !stop.placeId)) {
        target.innerHTML = '<div class="hotel-message">' + escHtml(rt('rv.no_final_stop')) + '</div>';
        hotelResultState[nightNo] = { loaded: false, dayNo, allowSelect };
        setHotelToggleLabel(nightNo, rt('rv.try_again'));
        return;
    }

    target.innerHTML = '<div class="hotel-message"><span class="spinner" style="border-color:rgba(15,23,42,.22);border-top-color:#0f172a;"></span> ' + escHtml(rt('rv.looking_hotels')) + '</div>';

    try {
        const params = new URLSearchParams({
            itinerary_id: ITINERARY_ID,
            item_id: stop.itemId || '',
            place_id: stop.itemId ? '' : (stop.placeId || ''),
            night: nightNo || '',
            // The run of nights at one base, and the hotels chosen for other nights: the budget mark counts them.
            nights: nightNo ? runOf(nightNo).join('-') : '',
            chosen: Object.entries(hotelSelections).filter(([n]) => Number(n) !== Number(nightNo))
                .map(([n, h]) => n + ':' + Number(h.hotel_id || 0)).join(','),
        });
        const resp = await fetch('review_hotels.php?' + params.toString(), { method: 'GET' });
        const data = await parseJsonResponse(resp);
        renderNightHotels(nightNo, data, allowSelect);
        hotelResultState[nightNo] = { loaded: data.status === 'success', dayNo, allowSelect };
        setHotelToggleLabel(nightNo, data.status === 'success' ? rt('rv.hide_results') : rt('rv.try_again'));
    } catch (e) {
        target.innerHTML = '<div class="hotel-message" style="color:#b91c1c;">' + escHtml(rt('rv.hotels_failed')) + '</div>';
        hotelResultState[nightNo] = { loaded: false, dayNo, allowSelect };
        setHotelToggleLabel(nightNo, rt('rv.try_again'));
    }
    const btn = hotelToggleButton(nightNo);
    if (btn) btn.disabled = false;
}

// Hotels come from the local lodging list (OpenStreetMap data). Their prices are planning
// estimates worked out on the server; the server reads them again when the review is saved.
function renderNightHotels(nightNo, data, allowSelect) {
    const target = document.getElementById('hotel-results-night-' + nightNo);
    if (!target) return;

    const hotels = Array.isArray(data.hotels) ? data.hotels : [];
    if (data.status !== 'success' || hotels.length === 0) {
        target.innerHTML = '<div class="hotel-message">' + escHtml(data.message || rt('rv.no_hotels')) + '</div>';
        return;
    }

    let html = '<div class="hotel-message">' + escHtml(data.message || rt('rv.hotels_loaded')) + '</div><div class="hotel-grid">';
    hotels.forEach((hotel, idx) => {
        const hotelId = Number(hotel.hotel_id || 0);
        const selected = hotelSelections[nightNo] && Number(hotelSelections[nightNo].hotel_id || 0) === hotelId;
        const price = Number(hotel.price_per_night || 0);
        const distance = hotel.distance_km !== undefined && hotel.distance_km !== null ? Number(hotel.distance_km) : null;
        const details = [
            (hotel.kind_label || rt('opt.hotelkind.hotel')) + (hotel.stars ? ', ' + rt('trip.star', { n: Number(hotel.stars) }) : ''),
            hotel.address || '',
            distance !== null && !Number.isNaN(distance) ? rt('trip.km_away', { n: distance.toFixed(1) }) : '',
        ].filter(Boolean).join(' | ');
        const payload = escHtml(JSON.stringify(hotel));

        html += `
            <div class="hotel-result-card ${selected ? 'selected' : ''}" id="hotel-night-${nightNo}-card-${idx}">
                <div class="hotel-name-rv">${escHtml(hotel.name || rt('rv.unnamed_hotel'))}</div>
                <div class="hotel-meta-rv">${escHtml(details)}</div>
                <div class="hotel-price-rv">${escHtml(price > 0 ? rt('rv.price_about', { n: price.toFixed(0) }) : rt('rv.price_none'))}</div>
                <span class="hotel-price-source">${escHtml(rt('rv.price_source'))}</span>
                ${hotel.budget_label ? '<span class="' + (hotel.budget && hotel.budget.within ? 'hotel-budget-ok' : 'hotel-budget-over') + '">' + escHtml(hotel.budget_label) + '</span>' : ''}
                ${hotel.budget_run && !hotel.budget_run.within ? '<span class="hotel-budget-over">' + escHtml(rt('rv.hotel_run_over', { from: runOf(nightNo)[0], to: runOf(nightNo)[1], n: Number(hotel.budget_run.over_by || 0).toFixed(0) })) + '</span>' : ''}
                ${selected ? '<span class="hotel-selected-note">' + escHtml(rt('rv.selected_night')) + '</span>' : ''}
                <div class="hotel-card-actions">
                    ${allowSelect ? `<button type="button" class="btn btn-primary" data-hotel="${payload}" onclick="selectNightHotel(${nightNo}, ${idx}, this.dataset.hotel)">${escHtml(rt('rv.select_night', { n: nightNo }))}</button>` : ''}
                    ${allowSelect && runOf(nightNo)[1] > runOf(nightNo)[0] ? `<button type="button" class="btn btn-ghost" data-hotel="${payload}" onclick="selectRunHotel(${nightNo}, ${idx}, this.dataset.hotel)">${escHtml('Use for nights ' + runOf(nightNo)[0] + ' to ' + runOf(nightNo)[1])}</button>` : ''}
                    ${hotel.map_url ? `<a class="btn btn-ghost" href="${escHtml(hotel.map_url)}" target="_blank" rel="noopener">${escHtml(rt('trip.map'))}</a>` : ''}
                    ${hotel.website ? `<a class="btn btn-ghost" href="${escHtml(hotel.website)}" target="_blank" rel="noopener noreferrer">${escHtml(rt('rv.website'))}</a>` : ''}
                    ${hotel.price_check_url ? `<a class="btn btn-ghost" href="${escHtml(hotel.price_check_url)}" target="_blank" rel="noopener noreferrer" title="${escHtml(rt('trip.check_price_title'))}">${escHtml(rt('trip.check_price'))}</a>` : ''}
                </div>
            </div>
        `;
    });

    target.innerHTML = html + '</div>';
}

function selectNightHotel(nightNo, idx, hotelJson) {
    let hotel = {};
    try {
        hotel = JSON.parse(hotelJson || '{}');
    } catch (e) {
        alert(rt('rv.hotel_read_failed'));
        return;
    }
    hotelSelections[nightNo] = hotel;
    const panel = document.getElementById('hotel-results-night-' + nightNo);
    if (panel) {
        panel.querySelectorAll('.hotel-result-card').forEach(card => card.classList.remove('selected'));
        const selectedCard = document.getElementById('hotel-night-' + nightNo + '-card-' + idx);
        if (selectedCard) {
            selectedCard.classList.add('selected');
            if (!selectedCard.querySelector('.hotel-selected-note')) {
                const note = document.createElement('span');
                note.className = 'hotel-selected-note';
                note.textContent = rt('rv.selected_night');
                selectedCard.appendChild(note);
            }
        }
    }
    updateStats();
}

/** The run of consecutive nights at the same base that a night belongs to: [first, last]. */
function runOf(nightNo) {
    const run = NIGHT_RUNS[nightNo] || NIGHT_RUNS[String(nightNo)];
    return Array.isArray(run) ? run.map(Number) : [nightNo, nightNo];
}

/** One hotel for every night of the run: each of those nights then starts and ends there. */
function selectRunHotel(nightNo, idx, hotelJson) {
    selectNightHotel(nightNo, idx, hotelJson);
    const hotel = hotelSelections[nightNo];
    if (!hotel) return;
    const [first, last] = runOf(nightNo);
    for (let n = first; n <= last; n++) {
        if (n === nightNo) continue;
        hotelSelections[n] = hotel;
        const target = document.getElementById('hotel-results-night-' + n);
        if (target) {
            target.classList.add('open');
            target.innerHTML = '<div class="hotel-message">' + escHtml('Chosen with night ' + nightNo + ': ' + (hotel.name || '')) + '</div>';
        }
        hotelResultState[n] = { loaded: true, dayNo: n, allowSelect: true };
        setHotelToggleLabel(n, rt('rv.hide_results'));
    }
    updateStats();
}

function skipHotelNight(nightNo) {
    delete hotelSelections[nightNo];
    const target = document.getElementById('hotel-results-night-' + nightNo);
    if (target) {
        target.classList.add('open');
        target.innerHTML = '<div class="hotel-message">' + escHtml(rt('rv.hotel_skipped')) + '</div>';
    }
    hotelResultState[nightNo] = { loaded: true, dayNo: nightNo, allowSelect: true };
    setHotelToggleLabel(nightNo, rt('rv.hide_results'));
    updateStats();
}

// ---- Toggle score breakdown ----
function toggleBreakdown(itemId) {
    const el = document.getElementById('breakdown-' + itemId);
    if (el) el.classList.toggle('visible');
}

// ---- Reset all ----
function resetAll() {
    document.querySelectorAll('.place-card[data-item-id]').forEach(card => {
        const itemId = parseInt(card.dataset.itemId);
        if (originalCards[itemId]) {
            card.innerHTML = originalCards[itemId].html;
            card.dataset.state = originalCards[itemId].state;
            card.dataset.category = originalCards[itemId].category;
            card.dataset.placeId = originalCards[itemId].placeId;
        }
        card.classList.remove('rejected', 'replacing', 'replacement-pending', 'kept');
        card.classList.add('accepted');
        card.dataset.status = 'accepted';
        if (card.dataset.kind === 'visit') itemStatus[itemId] = 'accepted';
        const rep = document.getElementById('replacement-' + itemId);
        if (rep) { rep.classList.remove('visible'); rep.innerHTML = ''; }
    });
    Object.keys(replacementMap).forEach(k => delete replacementMap[k]);
    Object.keys(replaceOffsets).forEach(k => delete replaceOffsets[k]);
    Object.keys(hotelSelections).forEach(k => delete hotelSelections[k]);
    Object.keys(hotelResultState).forEach(k => delete hotelResultState[k]);
    document.querySelectorAll('.hotel-results').forEach(el => {
        el.classList.remove('open');
        el.innerHTML = '';
    });
    document.querySelectorAll('[id^="hotel-toggle-night-"]').forEach(btn => {
        btn.disabled = false;
        btn.textContent = btn.id === 'hotel-toggle-night-0' ? rt('rv.browse_hotels') : rt('rv.load_hotels');
    });
    updateStats();
}

// ---- Update stats bar ----
function updateStats() {
    let accepted = 0, rejected = 0;
    let replaced = 0;
    Object.entries(itemStatus).forEach(([id, s]) => {
        if (s === 'accepted') {
            accepted++;
            if (replacementMap[id]) replaced++;
        } else if (s === 'rejected') rejected++;
    });
    Object.entries(replacementMap).forEach(([id, r]) => { if (r.meal) replaced++; });
    document.getElementById('stat-accepted').textContent = accepted;
    document.getElementById('stat-rejected').textContent = rejected;
    const replacedEl = document.getElementById('stat-replaced');
    if (replacedEl) replacedEl.textContent = replaced;
    const hotelCount = Object.keys(hotelSelections).length;
    document.getElementById('stat-hotel').textContent = hotelCount > 0 ? rt('rv.n_selected', { n: hotelCount }) : rt('rv.none');
}

function updateCardMatch(card, data) {
    const colorMap = { success: '#22c55e', primary: '#3b82f6', warning: '#f59e0b', danger: '#ef4444' };
    const color = colorMap[data.match_color] || '#64748b';
    const badge = card.querySelector('.match-score-wrap .match-badge');
    if (badge) {
        badge.className = 'match-badge badge-' + data.match_color;
        badge.textContent = data.match_label;
    }
    const mainPct = card.querySelector('.match-main-pct');
    if (mainPct) {
        mainPct.textContent = data.match_pct + '%';
        mainPct.style.color = color;
    }
    const fill = card.querySelector('.match-overall-fill');
    if (fill) {
        fill.style.width = data.match_pct + '%';
        fill.style.background = color;
    }
    const pct = card.querySelector('.match-overall-pct');
    if (pct) {
        pct.textContent = data.match_pct + '%';
        pct.style.color = color;
    }
}

// ---- Confirm review ----
// Every day keeps at least one stop (the server refuses to empty a day as well). The server
// checks each change against the rules of the trip; one it cannot make is listed with its reason.
async function confirmReview() {
    const btn = document.getElementById('btn-confirm');
    const restore = () => { btn.disabled = false; btn.textContent = rt('rv.confirm_view'); };
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> ' + escHtml(rt('rv.saving'));

    const rejectedIds = Object.entries(itemStatus)
        .filter(([id, s]) => s === 'rejected')
        .map(([id]) => parseInt(id));
    const emptied = [];
    document.querySelectorAll('.day-section').forEach(section => {
        const visits = Array.from(section.querySelectorAll('.place-card[data-kind="visit"]'));
        if (visits.length > 0 && visits.every(card => itemStatus[card.dataset.itemId] === 'rejected')) {
            emptied.push(visits[0].dataset.day);
        }
    });
    if (emptied.length > 0) {
        alert(rt('rv.keep_one', { days: emptied.map(day => rt('trip.day', { n: day })).join(', ') }));
        restore();
        return;
    }
    const replacements = {};
    Object.entries(replacementMap).forEach(([id, r]) => {
        if (itemStatus[id] !== 'rejected') replacements[id] = { place_id: Number(r.place_id || 0) };
    });

    const post = async fields => parseJsonResponse(await fetch('review_confirm_nightly.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams(Object.assign({ itinerary_id: ITINERARY_ID }, fields)),
    }));
    try {
        const data = await post({
            rejected_ids: rejectedIds.join(','),
            replacements_json: JSON.stringify(replacements),
            hotel_selections_json: JSON.stringify(hotelSelections),
        });
        // The hotels would put the trip over the budget: nothing of them was saved. The dialog
        // states the amount; saving them anyway posts the hotels again with the confirmation.
        if (data.status === 'success' && data.hotel_budget) {
            if (window.confirm(data.hotel_budget.message)) {
                const again = await post({ rejected_ids: '', replacements_json: '{}', hotel_selections_json: JSON.stringify(hotelSelections), confirm_over_budget: '1' });
                if (again.status !== 'success') throw new Error(again.message || 'not saved');
                data.refused = (Array.isArray(data.refused) ? data.refused : []).concat(Array.isArray(again.refused) ? again.refused : []);
            } else {
                data.refused = (Array.isArray(data.refused) ? data.refused : []).concat([{ item_id: null, night: null, action: 'hotel', code: 'HOTEL_BUDGET', message: rt('rv.hotel_budget_kept') }]);
            }
        }

        if (data.status === 'success') {
            const refused = Array.isArray(data.refused) ? data.refused : [];
            if (refused.length === 0) {
                window.location.href = 'itinerary_view.php?itinerary_id=' + ITINERARY_ID;
                return;
            }
            const list = document.getElementById('review-refused-list');
            if (list) {
                list.innerHTML = refused.map(r => {
                    const card = r.item_id ? document.getElementById('card-' + r.item_id) : null;
                    const title = card ? (originalCards[r.item_id] ? titleFromHtml(originalCards[r.item_id].html) : card.dataset.title) : rt('rv.a_stop');
                    const what = r.action === 'hotel' ? (r.night ? rt('rv.refused_hotel_night', { n: r.night }) : rt('rv.refused_hotels'))
                        : rt(r.action === 'remove' ? 'rv.refused_remove' : 'rv.refused_replace', { title: title });
                    return '<li><strong>' + escHtml(what) + ':</strong> ' + escHtml(r.message || rt('rv.not_allowed')) + '</li>';
                }).join('');
            }
            const panel = document.getElementById('review-refused');
            if (panel) {
                panel.classList.add('visible');
                panel.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            btn.disabled = true;
            btn.textContent = rt('rv.saved');
        } else {
            alert(rt('rv.error_prefix', { message: data.message || rt('rv.save_failed') }));
            restore();
        }
    } catch(e) {
        alert(rt('rv.err_network'));
        restore();
    }
}

/** The stop's title as the card showed it when the page was loaded. */
function titleFromHtml(html) {
    const box = document.createElement('div');
    box.innerHTML = html;
    const title = box.querySelector('.place-title');
    return title ? title.textContent.trim() : rt('rv.a_stop');
}

// ---- Versions of the plan (api/itinerary_versions.php) ----
const VERSIONS_URL = '../api/itinerary_versions.php';
const VERSIONS_TOKEN = <?php echo json_encode(csrf_token()); ?>;

async function postVersions(fields) {
    const resp = await fetch(VERSIONS_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams(Object.assign({ itinerary_id: ITINERARY_ID, csrf_token: VERSIONS_TOKEN }, fields)),
    });
    return parseJsonResponse(resp);
}

/** Plan the trip again in one style; the page is loaded again to show the comparison. */
async function makeVersion(button) {
    const label = button.dataset.label || '';
    const buttons = document.querySelectorAll('.versions-actions button');
    buttons.forEach(b => { b.dataset.wasDisabled = b.disabled ? '1' : ''; b.disabled = true; });
    const original = button.innerHTML;
    button.innerHTML = '<span class="spinner" style="border-color:rgba(15,23,42,.22);border-top-color:#0f172a;"></span> ' + escHtml(rt('rv.version_making', { style: label }));
    try {
        const data = await postVersions({ action: 'create', style: button.dataset.style || '' });
        if (data.ok) {
            window.location.href = 'itinerary_review.php?itinerary_id=' + ITINERARY_ID + '#versions';
            return;
        }
        alert(rt('rv.version_failed', { reason: data.display || data.message || '' }));
    } catch (e) {
        alert(rt('rv.err_network'));
    }
    button.innerHTML = original;
    buttons.forEach(b => { b.disabled = b.dataset.wasDisabled === '1'; });
}

/** Make one version the itinerary under review; the other versions are removed. */
async function useVersion(button) {
    if (!window.confirm(rt('rv.version_use_confirm', { style: button.dataset.label || '' }))) return;
    const text = button.textContent;
    button.disabled = true;
    button.textContent = rt('rv.version_using');
    try {
        const data = await postVersions({ action: 'choose', version_id: button.dataset.version || '' });
        if (data.ok) {
            window.location.href = 'itinerary_review.php?itinerary_id=' + Number(data.itinerary_id || ITINERARY_ID);
            return;
        }
        alert(rt('rv.version_failed', { reason: data.display || data.message || '' }));
    } catch (e) {
        alert(rt('rv.err_network'));
    }
    button.disabled = false;
    button.textContent = text;
}

// ---- HTML escape helper ----
function escHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function escJs(str) {
    return String(str || '').replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/\n/g, ' ');
}

function toggleAiChat() {
    const panel = document.getElementById('aiChatPanel');
    if (!panel) return;
    panel.classList.toggle('open');
    if (panel.classList.contains('open')) {
        const input = document.getElementById('aiChatInput');
        if (input) setTimeout(() => input.focus(), 80);
    }
}

function addAiMessage(role, text) {
    const body = document.getElementById('aiChatBody');
    if (!body) return null;
    const msg = document.createElement('div');
    msg.className = 'ai-msg ' + (role === 'user' ? 'user' : 'bot');
    msg.textContent = text;
    body.appendChild(msg);
    body.scrollTop = body.scrollHeight;
    return msg;
}

function askAiQuick(text) {
    const input = document.getElementById('aiChatInput');
    if (input) input.value = text;
    sendAiMessage();
}

async function sendAiMessage(event) {
    if (event) event.preventDefault();
    const input = document.getElementById('aiChatInput');
    if (!input) return;
    const text = input.value.trim();
    if (!text) return;
    input.value = '';
    addAiMessage('user', text);
    const loading = addAiMessage('bot', rt('rv.writing'));
    try {
        const resp = await fetch('ai_chat.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ itinerary_id: ITINERARY_ID, message: text }),
        });
        const data = await parseJsonResponse(resp);
        if (loading) loading.textContent = data.answer || data.message || rt('rv.ai_failed');
    } catch (e) {
        if (loading) loading.textContent = rt('rv.err_network');
    }
}

async function parseJsonResponse(resp) {
    const raw = await resp.text();
    try {
        return JSON.parse(raw);
    } catch (e) {
        const start = raw.indexOf('{');
        const end = raw.lastIndexOf('}');
        if (start >= 0 && end > start) {
            return JSON.parse(raw.slice(start, end + 1));
        }
        throw e;
    }
}
</script>
  <script src="../assets/dashboard_shell.js?v=20261119a"></script>
</body>
</html>

