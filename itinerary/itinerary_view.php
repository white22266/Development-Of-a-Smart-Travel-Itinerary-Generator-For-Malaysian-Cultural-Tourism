<?php
// itinerary/itinerary_view.php
// One itinerary: the route map (assets/itinerary_map.js: Leaflet on OpenStreetMap, road routes
// from api/itinerary_route.php, forecast from api/weather.php), the cost summary and the
// timetable of every day.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once "../config/db_connect.php";
require_once "../config/map_assets.php";
require_once "../services/CostEstimationService.php";
require_once "../services/CalendarExportService.php";
require_once "../config/i18n.php";
require_once "../config/ai_chat_text.php";
require_once "../services/ItineraryEditService.php";

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
    header("Location: ../auth/login.php?role=traveller");
    exit;
}
$travellerName = $_SESSION["traveller_name"] ?? t('common.traveller_default');
$travellerId   = (int)($_SESSION["traveller_id"] ?? 0);

$itineraryId = (int)($_GET["itinerary_id"] ?? 0);
if ($itineraryId <= 0) { header("Location: my_itineraries.php"); exit; }

// Load itinerary header + preference
$partyCheck = $conn->query("SHOW COLUMNS FROM traveller_preferences LIKE 'party_size'");
$partySelect = ($partyCheck && $partyCheck->num_rows > 0) ? ", tp.party_size" : "";
$stmt = $conn->prepare("
    SELECT i.*, tp.transport_type, tp.budget, tp.budget_tier, tp.traveller_type{$partySelect}
    FROM itineraries i
    LEFT JOIN traveller_preferences tp ON tp.preference_id = i.preference_id
    WHERE i.itinerary_id = ? AND i.traveller_id = ?
    LIMIT 1
");
$stmt->bind_param("ii", $itineraryId, $travellerId);
$stmt->execute();
$it = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$it) { header("Location: my_itineraries.php"); exit; }
$originName = trim((string)($it['origin_name'] ?? ''));
// The base of each day (where the night after it is spent), for a trip planned with bases.
$viewBases = ItineraryEditService::dayBases($conn, $itineraryId);

// Load all itinerary items with place details
$dcCheck = $conn->query("SHOW COLUMNS FROM cultural_places LIKE 'district'");
$hasDistrictCol = ($dcCheck && $dcCheck->num_rows > 0);
$districtJoinCol = $hasDistrictCol ? "cp.district" : "NULL AS district";
$itemLatCheck = $conn->query("SHOW COLUMNS FROM itinerary_items LIKE 'item_latitude'");
$itemLngCheck = $conn->query("SHOW COLUMNS FROM itinerary_items LIKE 'item_longitude'");
$hasItemCoords = ($itemLatCheck && $itemLatCheck->num_rows > 0) && ($itemLngCheck && $itemLngCheck->num_rows > 0);
$itemCoordSelect = $hasItemCoords
    ? "COALESCE(ii.item_latitude, cp.latitude) AS latitude, COALESCE(ii.item_longitude, cp.longitude) AS longitude"
    : "cp.latitude, cp.longitude";
// Lunch and dinner rows (migration 20261116_meal_slots.sql)
$mealSlotCheck = $conn->query("SHOW COLUMNS FROM itinerary_items LIKE 'meal_slot'");
$mealSlotSelect = ($mealSlotCheck && $mealSlotCheck->num_rows > 0) ? "ii.meal_slot" : "NULL AS meal_slot";
// The place type (migration 20261119_place_types.sql), for opening hours the planner assumed.
$placeTypeSelect = PlannerRules::hasColumn($conn, 'cultural_places', 'place_type') ? 'cp.place_type' : 'NULL AS place_type';

$stmt = $conn->prepare("
    SELECT ii.item_id, ii.day_no, ii.sequence_no, ii.item_type, {$mealSlotSelect}, ii.place_id,
           ii.item_title, ii.estimated_cost, ii.distance_km, ii.travel_time_min,
           ii.start_time, ii.end_time, ii.notes,
           {$itemCoordSelect}, cp.address, cp.category, cp.state, {$districtJoinCol},
           cp.opening_hours, {$placeTypeSelect}
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
$totalDays     = (int)$it["total_days"];
$transportType = strtolower(trim(str_replace("-", "_", (string)($it["transport_type"] ?? "car"))));
$transportType = preg_replace("/\s+/", "_", $transportType) ?? $transportType;
if (in_array($transportType, ["public", "publictransit", "public_transit", "transit", "bus", "train"], true)) {
    $transportType = "public_transport";
} elseif (in_array($transportType, ["drive", "driving"], true)) {
    $transportType = "car";
} elseif ($transportType === "walk") {
    $transportType = "walking";
}
$startDate     = $it["start_date"] ?? null;
$originLat = $it["origin_lat"] ?? null;
$originLng = $it["origin_lng"] ?? null;

// Day colors
$dayColors = [
    1 => '#EF4444', 2 => '#3B82F6', 3 => '#22C55E',
    4 => '#F59E0B', 5 => '#8B5CF6', 6 => '#EC4899', 7 => '#14B8A6',
];

// ---- Timetable builder ----
function buildTimetable(array $items, string $transportType): array
{
    $schedule = [];
    $cursor   = 9 * 60; // 9:00 AM
    $speeds   = ['car' => 60, 'motorcycle' => 55, 'public_transport' => 35, 'walking' => 5];
    $speed    = $speeds[$transportType] ?? 60;

    foreach ($items as $i => $item) {
        $type      = $item["item_type"] ?? "attraction";
        $dbStartMin = sqlTimeToMinutes($item["start_time"] ?? null);
        $dbEndMin   = sqlTimeToMinutes($item["end_time"] ?? null);
        $travelMin = 0;
        if ($item["travel_time_min"] !== null) {
            $travelMin = (int)$item["travel_time_min"];
        } elseif ($item["distance_km"] !== null && (float)$item["distance_km"] > 0) {
            $travelMin = (int)ceil(((float)$item["distance_km"] / $speed) * 60);
            if ($transportType === 'public_transport') $travelMin = (int)ceil($travelMin * 1.4);
        } elseif ($i > 0 && $item["latitude"] !== null) {   // a row without a place (a meal break) has no leg
            $travelMin = ($transportType === 'walking') ? 20 : 15;
        }
        $cursor = $dbStartMin ?? ($cursor + $travelMin);
        $duration = match($type) {
            'attraction', 'heritage', 'museum', 'culture' => 120,
            'food'     => 60,
            'festival' => 150,
            'hotel'    => 30,
            default    => 90,
        };
        $endMin = $dbEndMin ?? ($cursor + $duration);
        $schedule[] = array_merge($item, [
            '_travel_min' => $travelMin,
            '_start_min'  => $cursor,
            '_end_min'    => $endMin,
            '_start_fmt'  => minutesToTime($cursor),
            '_end_fmt'    => minutesToTime($endMin),
        ]);
        $cursor = $endMin;
    }
    return $schedule;
}

function sqlTimeToMinutes(?string $time): ?int
{
    if (!$time || !preg_match('/^(\d{1,2}):(\d{2})/', $time, $m)) return null;
    return ((int)$m[1] * 60) + (int)$m[2];
}

function minutesToTime(int $min): string
{
    $h    = intdiv($min, 60) % 24;
    $m    = $min % 60;
    $ampm = $h >= 12 ? 'PM' : 'AM';
    $h12  = $h % 12 ?: 12;
    return sprintf('%d:%02d %s', $h12, $m, $ampm);
}

function activitySuggestion(string $type, string $category): string
{
    $known = ['food', 'hotel', 'festival', 'museum', 'heritage', 'culture', 'nature', 'shopping', 'attraction'];
    $key = strtolower($category ?: $type);
    if (in_array($key, $known, true)) return t('iv.act_' . $key);
    if (in_array($type, $known, true)) return t('iv.act_' . $type);
    return t('iv.act_attraction');
}

/** Label of an item type on the timetable: a stored type such as attraction or food, else a category. */
function itineraryTypeLabel(string $type): string
{
    $type = strtolower(trim($type));
    if ($type !== '' && t('opt.itype.' . $type) !== 'opt.itype.' . $type) return t('opt.itype.' . $type);
    return t_choice('interest', $type !== '' ? $type : 'attraction');
}

function extractReasonSelected(?string $notes): string
{
    $text = (string)$notes;
    $pos = strpos($text, 'Reason:');
    if ($pos === false) return '';
    return trim(substr($text, $pos + 7));
}

$travellerType = strtolower((string)($it["traveller_type"] ?? "solo"));
$partySize = CostEstimationService::resolvePartySize($travellerType, isset($it["party_size"]) ? (int)$it["party_size"] : null);

// Build timetables
$timetables = [];
foreach ($days as $d => $items) {
    $timetables[$d] = buildTimetable($items, $transportType);
}

// Encode for JS
$jsItineraryData = [];
foreach ($timetables as $d => $items) {
    $jsItineraryData[$d] = array_map(fn($item) => [
        'item_id'      => (int)$item['item_id'],
        'title'        => !empty($item['meal_slot']) && $item['latitude'] !== null ? t_choice('itype', (string)$item['meal_slot']) . ': ' . $item['item_title'] : i18n_item_title((string)$item['item_title']),
        'type'         => $item['item_type'],
        'category'     => $item['category'] ?? '',
        'lat'          => $item['latitude']  !== null ? (float)$item['latitude']  : null,
        'lng'          => $item['longitude'] !== null ? (float)$item['longitude'] : null,
        'address'      => $item['address'] ?? '',
        'state'        => $item['state'] ?? '',
        'district'     => $item['district'] ?? '',
        'cost'         => (float)($item['estimated_cost'] ?? 0),
        'cost_label'   => !empty($item['meal_slot']) ? t('iv.in_meal_estimate') : ((float)($item['estimated_cost'] ?? 0) > 0
            ? i18n_cost_phrase(CostEstimationService::itemCostLabel((string)($item['item_type'] ?? ''), (float)$item['estimated_cost'], $partySize)['whole_label'])
            : t('iv.free')),
        'dist_km'      => $item['distance_km'] !== null ? (float)$item['distance_km'] : null,
        'travel_min'   => $item['_travel_min'],
        'start_fmt'    => $item['_start_fmt'],
        'end_fmt'      => $item['_end_fmt'],
        'opening_hours'=> $item['opening_hours'] ?? '',
    ], $items);
}

$jsDays        = json_encode($jsItineraryData, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_AMP) ?: '[]';
$jsColorsJson  = json_encode($dayColors);
$jsTransport   = json_encode($transportType);

// Total cost
$totalCost   = 0;
$totalPlaces = 0;
$allCostItems = [];
$totalDistanceKm = 0.0;
foreach ($days as $dayItems) {
    foreach ($dayItems as $item) {
        $totalCost += (float)($item['estimated_cost'] ?? 0);
        if (strtolower((string)($item['item_type'] ?? '')) !== 'hotel' && empty($item['meal_slot'])) {
            $totalPlaces++;
        }
        $allCostItems[] = $item;
        $totalDistanceKm += (float)($item['distance_km'] ?? 0);
    }
}
$budget = (float)($it["budget"] ?? 0);
$budgetTier = strtolower((string)($it["budget_tier"] ?? "normal"));
$tierDefaults = CostEstimationService::budgetTierDefaults($budgetTier, $budget, $totalDays, $partySize);
$costService = new CostEstimationService($transportType, $totalDays, $budget, $travellerType, $partySize);
$costBreakdown = $costService->calculate($allCostItems, $totalDistanceKm, $tierDefaults["hotel"], 3, $tierDefaults["meal"]);
$selectedHotelCount = count(array_filter($allCostItems, fn($item) => strtolower((string)($item["item_type"] ?? "")) === "hotel"));
$missingInfo = [];
if (empty($startDate)) {
    $missingInfo[] = t('trip.start_date');
}
if ($originName === "" || $originLat === null || $originLng === null || (float)$originLat == 0.0 || (float)$originLng == 0.0) {
    $missingInfo[] = t('iv.miss_origin');
}
if ($totalDays > 1 && $selectedHotelCount === 0) {
    $missingInfo[] = t('iv.miss_hotel');
}
$missingInfoJson = json_encode(array_values($missingInfo), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: "[]";

// ---- Edit controls (assets/itinerary_edit.js, api/itinerary_edit.php). This page shows only
// the signed-in traveller's own itineraries, so the controls are always the owner's.
$editPref = PlannerRules::loadPreference($conn, (int)$it["preference_id"]) ?? PlannerRules::normalizePreference([]);
$editUsualStart = (int)PlannerRules::profile($editPref)["start"];
$editDayStarts = ItineraryEditService::dayStarts($conn, $itineraryId);
$editHm = static fn(int $m): string => sprintf("%02d:%02d", intdiv($m, 60), $m % 60);
$editCategories = PlannerRules::VALID_INTERESTS;
// The wishes for planning a day again: the categories, then the place types by their group
// (type:<type>, group:<group>; PlannerRules::wishTokens()), with the labels of lang/ (ptype.*).
$editWishGroups = [t('edit.wish_categories') => array_combine($editCategories, array_map(static fn($c) => t_choice('interest', $c), $editCategories))];
foreach (PlaceTypeClassifier::grouped() as $editGroup => $editTypes) {
    $editGroupLabel = t('ptype.group.' . $editGroup);
    $editOptions = ['group:' . $editGroup => t('edit.wish_group_all', ['group' => $editGroupLabel])];
    foreach (array_keys($editTypes) as $editType) $editOptions['type:' . $editType] = t('ptype.' . $editType);
    $editWishGroups[$editGroupLabel] = $editOptions;
}
// Re-plan rainy days: offered when some hour inside a day's window has RAIN_PREFER percent or
// more (the forecast by the hour, ItineraryEditService::rainInWindow()).
$editRainy = array_filter(ItineraryEditService::rainInWindow($conn, $itineraryId), static fn(int $chance): bool => $chance >= ItineraryDayBuilder::RAIN_PREFER);
ksort($editRainy);
// The visits of each day in order (meal, note and hotel rows are not visits): for Move up and down.
$editVisitIds = [];
foreach ($days as $d => $dayItems) {
    foreach ($dayItems as $row) {
        if (!empty($row["meal_slot"]) || $row["place_id"] === null) continue;
        if (!in_array(strtolower((string)$row["item_type"]), ["attraction", "food", "festival"], true)) continue;
        $editVisitIds[$d][] = (int)$row["item_id"];
    }
}
$editConfig = json_encode([
    "itineraryId" => $itineraryId,
    "endpoint" => "../api/itinerary_edit.php",
    "search" => "../api/place_search.php",
    "states" => implode(",", array_map("strval", (array)($editPref["states"] ?? []))),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: "{}";
?>
<!DOCTYPE html>
<html lang="<?php echo i18n_lang(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($it["title"]); ?> - <?php echo te('iv.title'); ?></title>
    <link rel="stylesheet" href="../assets/dashboard_style.css?v=20260617j">
    <link rel="stylesheet" href="../assets/trip_ai_chat.css?v=20261022">
    <?php echo leaflet_tags(); ?>
    <style>
        /* ===== Layout ===== */
        .iv-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 18px;
            align-items: start;
        }
        @media (max-width: 1100px) { .iv-grid { grid-template-columns: 1fr; } }

        /* ===== Map ===== */
        #map {
            width: 100%;
            height: 520px;
            border-radius: 14px;
            border: 1px solid rgba(15,23,42,0.10);
        }
        .map-wrap { position: relative; }
        .map-error-panel {
            position: absolute;
            inset: 0;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 24px;
            text-align: center;
            border-radius: 14px;
            background: rgba(248,250,252,0.96);
            border: 1px solid rgba(239,68,68,0.22);
            z-index: 5;
        }
        .map-error-panel.visible { display: flex; }
        .map-error-card {
            max-width: 560px;
            color: #334155;
            line-height: 1.45;
        }
        .map-error-card strong {
            display: block;
            color: #b91c1c;
            font-size: 16px;
            margin-bottom: 6px;
        }
        .map-error-card code {
            background: #fee2e2;
            color: #991b1b;
            border-radius: 6px;
            padding: 2px 5px;
        }
        .map-route-notice {
            display: none;
            margin-top: 8px;
            padding: 8px 10px;
            border-radius: 8px;
            background: #fff7ed;
            color: #9a3412;
            border: 1px solid #fed7aa;
            font-size: 12px;
            font-weight: 700;
        }
        .map-route-notice.visible { display: block; }
        .route-summary { margin-top: 8px; font-size: 13px; color: #334155; min-height: 18px; }
        /* numbered pins and their popups */
        .iv-pin span {
            display: flex; align-items: center; justify-content: center;
            width: 30px; height: 30px; border-radius: 50%;
            color: #fff; font: 800 12px system-ui, sans-serif;
            border: 2px solid #fff; box-shadow: 0 1px 5px rgba(2,6,23,.45);
        }
        .iv-popup { max-width: 240px; font-size: 12px; line-height: 1.45; }
        .iv-popup strong { display: block; font-size: 13px; }
        .iv-popup-meta { color: #64748b; }
        /* an arrow on each leg, pointing the way of travel */
        .iv-arrow span {
            display: block; width: 0; height: 0;
            border-left: 7px solid transparent; border-right: 7px solid transparent;
            border-bottom: 13px solid #0f172a;
            filter: drop-shadow(0 0 1.5px #fff) drop-shadow(0 0 1.5px #fff);
        }
        /* road conditions: markers on the map and the list under it */
        .iv-alert span {
            display: flex; align-items: center; justify-content: center;
            width: 24px; height: 24px; border-radius: 6px; transform: rotate(45deg);
            border: 2px solid #fff; box-shadow: 0 1px 5px rgba(2,6,23,.45); background: #ca8a04;
        }
        .iv-alert b { transform: rotate(-45deg); color: #fff; font: 800 12px system-ui, sans-serif; }
        .iv-alert.level-2 span { background: #ea580c; }
        .iv-alert.level-3 span { background: #dc2626; }
        .map-tools { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 10px; }
        .map-tools .btn { font-size: 12px; padding: 7px 12px; }
        .map-fullscreen-btn {
            position: absolute; top: 10px; right: 10px; z-index: 500;
            background: #fff; border: 1px solid rgba(15,23,42,.2); border-radius: 8px;
            padding: 6px 10px; font-size: 12px; font-weight: 700; cursor: pointer; color: #0f172a;
            box-shadow: 0 1px 4px rgba(2,6,23,.25);
        }
        .map-wrap.fullscreen { position: fixed; inset: 0; z-index: 3000; background: #fff; }
        .map-wrap.fullscreen #map { height: 100%; border-radius: 0; border: 0; }
        .map-legend { display: flex; gap: 14px; flex-wrap: wrap; margin-top: 8px; font-size: 11.5px; color: #475569; }
        .map-legend i { display: inline-block; width: 26px; height: 0; vertical-align: middle; margin-right: 5px; border-top: 4px solid #475569; }
        .map-legend i.walk { border-top-style: dotted; }
        .map-legend i.estimate { border-top-style: dashed; }
        .map-legend i.problem { border-top-color: #dc2626; }
        .road-conditions { margin-top: 10px; display: none; }
        .road-conditions.visible { display: block; }
        .rc-item { display: flex; gap: 10px; align-items: flex-start; padding: 8px 10px; border-radius: 8px; border: 1px solid #e2e8f0; margin-top: 6px; font-size: 12.5px; background: #fff; }
        .rc-item[data-rc-index] { cursor: pointer; }
        .rc-item.level-2 { border-color: #fed7aa; background: #fff7ed; }
        .rc-item.level-3 { border-color: #fecaca; background: #fef2f2; }
        .rc-badge { flex: none; font-weight: 800; font-size: 10.5px; padding: 2px 7px; border-radius: 999px; color: #fff; background: #ca8a04; white-space: nowrap; }
        .rc-item.level-2 .rc-badge { background: #ea580c; }
        .rc-item.level-3 .rc-badge { background: #dc2626; }
        .rc-detail { color: #475569; margin-top: 2px; }
        .rc-foot { margin-top: 8px; font-size: 11px; color: #64748b; line-height: 1.5; }
        .live-bus-status { margin-top: 8px; font-size: 11.5px; color: #64748b; line-height: 1.5; }
        .live-bus-status:empty { display: none; }
        /* a phone shows the map nearly full height: this page is read on the road */
        @media (max-width: 700px) { #map { height: 62vh; min-height: 320px; } }
        .weather-chip.wet { background: #fef9c3; border-color: #fbbf24; }

        /* ===== Transport bar ===== */
        .transport-bar {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin: 12px 0 8px;
        }
        .transport-btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 7px 14px;
            border-radius: 999px;
            border: 1.5px solid rgba(15,23,42,0.12);
            background: #fff;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: all .15s;
        }
        .transport-btn.active { background: #4f46e5; color: #fff; border-color: #4f46e5; }
        .transport-btn:hover:not(.active) { border-color: #4f46e5; color: #4f46e5; }

        /* ===== Day legend ===== */
        .day-legend { display: flex; gap: 8px; flex-wrap: wrap; margin: 8px 0 4px; }
        .legend-item {
            display: inline-flex; align-items: center; gap: 6px;
            font-size: 12px; font-weight: 700;
            padding: 4px 10px; border-radius: 999px; border: 1.5px solid;
            cursor: pointer; transition: opacity .15s;
        }
        .legend-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
            margin-top: 14px;
        }
        .summary-card {
            background: #f8fafc;
            border: 1px solid rgba(15,23,42,0.08);
            border-radius: 12px;
            padding: 12px 14px;
        }
        .summary-label {
            color: #64748b;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .04em;
        }
        .summary-value {
            color: #0f172a;
            font-size: 18px;
            font-weight: 900;
            margin-top: 4px;
        }
        .cost-breakdown {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 10px;
            margin-top: 12px;
        }
        .cost-mini {
            border: 1px solid rgba(15,23,42,0.08);
            border-radius: 10px;
            padding: 10px 12px;
            background: #fff;
        }
        .cost-mini strong {
            display: block;
            font-size: 13px;
            color: #0f172a;
            margin-bottom: 4px;
        }
        .cost-mini span {
            display: block;
            font-size: 11px;
            color: #64748b;
            line-height: 1.4;
        }
        .budget-ok { color:#166534; }
        .budget-over { color:#b91c1c; }
        @media (max-width: 1000px) {
            .summary-grid, .cost-breakdown { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 640px) {
            .summary-grid, .cost-breakdown { grid-template-columns: 1fr; }
        }

        /* ===== Day tabs ===== */
        .day-tabs { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 12px; }
        .day-tab {
            padding: 7px 14px; border-radius: 10px;
            border: 1.5px solid rgba(15,23,42,0.12);
            background: #fff; font-size: 12px; font-weight: 700;
            cursor: pointer; transition: all .15s;
        }
        .day-tab.active { color: #fff; }

        .day-box-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
            padding: 12px 14px;
            border-radius: 10px;
            border: 1px solid rgba(15,23,42,0.08);
            background: #f8fafc;
        }
        .day-box-number {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            flex: 0 0 42px;
            color: #fff;
            font-size: 18px;
            font-weight: 900;
            line-height: 1;
        }
        .day-box-title {
            font-weight: 900;
            font-size: 14px;
            color: #0f172a;
        }
        .day-box-date {
            font-size: 12px;
            color: #64748b;
            margin-top: 2px;
        }
        .day-box-note {
            font-size: 11px;
            color: #64748b;
            margin-top: 2px;
        }

        /* ===== Timetable ===== */
        .timetable { width: 100%; border-collapse: collapse; font-size: 12.5px; }
        .timetable th {
            background: rgba(15,23,42,0.04); padding: 8px 10px;
            text-align: left; font-weight: 800; font-size: 11px;
            text-transform: uppercase; letter-spacing: .04em;
            border-bottom: 1px solid rgba(15,23,42,0.08);
        }
        .timetable td {
            padding: 9px 10px;
            border-bottom: 1px solid rgba(15,23,42,0.05);
            vertical-align: top;
        }
        .timetable tr:last-child td { border-bottom: none; }
        .timetable tr:hover td { background: rgba(99,102,241,0.04); }

        /* ===== Transit row (between stops) ===== */
        .transit-row td {
            background: rgba(15,23,42,0.02);
            padding: 0 !important;
        }
        .transit-row-inner {
            padding: 6px 10px;
        }
        .transit-summary-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 12px;
            font-weight: 700;
            color: #4f46e5;
            padding: 0;
        }
        .transit-summary-btn:hover { text-decoration: underline; }

        /* ===== Transit detail panel ===== */
        .transit-detail {
            text-align: left;
            display: none;
            border-top: 1px solid rgba(15,23,42,0.06);
            padding: 10px 14px;
            background: #f8fafc;
        }
        .transit-detail.open { display: block; }

        .ts-stops { font-size: 11px; color: #64748b; margin-top: 2px; }
        .ts-walk-steps {
            margin-top: 4px;
            padding-left: 10px;
            border-left: 2px solid #e2e8f0;
        }
        .ts-walk-step {
            font-size: 11px;
            color: #475569;
            padding: 2px 0;
        }
        .ts-summary-bar {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            padding: 8px 10px;
            background: rgba(99,102,241,0.06);
            border-radius: 8px;
            margin-bottom: 10px;
            font-size: 12px;
        }
        .ts-summary-stat {
            display: flex;
            align-items: center;
            gap: 4px;
            font-weight: 700;
        }
        .ts-warning {
            font-size: 11px;
            color: #92400e;
            background: #fef3c7;
            border-radius: 6px;
            padding: 4px 8px;
            margin-top: 6px;
        }
        .ts-loading {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            color: #64748b;
            padding: 8px 0;
        }
        .spinner {
            width: 14px; height: 14px;
            border: 2px solid rgba(99,102,241,0.3);
            border-top-color: #6366f1;
            border-radius: 50%;
            animation: spin .6s linear infinite;
            flex-shrink: 0;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* ===== Type badges ===== */
        .type-badge {
            display: inline-block; padding: 2px 8px;
            border-radius: 999px; font-size: 10.5px; font-weight: 800; white-space: nowrap;
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


        /* ===== Weather chip ===== */
        .weather-chip {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 5px 12px; border-radius: 999px;
            border: 1px solid rgba(15,23,42,0.10);
            font-size: 12px; font-weight: 700; background: #fff;
        }

        /* ===== Info box ===== */
        .info-box {
            background: rgba(99,102,241,0.06);
            border: 1px solid rgba(99,102,241,0.18);
            border-radius: 10px; padding: 10px 14px;
            font-size: 12px; color: #475569;
        }

        .table-scroll { overflow-x: auto; }

        /* ===== Edit controls (assets/itinerary_edit.js) ===== */
        .ed-actions { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; align-items: center; cursor: default; }
        .ed-btn {
            font-size: 11px; font-weight: 700; padding: 4px 9px; min-height: 28px;
            border-radius: 8px; border: 1px solid rgba(15,23,42,0.15); background: #fff; color: #334155; cursor: pointer;
        }
        .ed-btn:hover:not(:disabled) { border-color: #4f46e5; color: #4f46e5; }
        .ed-btn:disabled { opacity: .4; cursor: default; }
        .ed-btn.ed-primary { background: #4f46e5; border-color: #4f46e5; color: #fff; }
        .ed-btn.ed-primary:hover:not(:disabled) { background: #4338ca; color: #fff; }
        .ed-btn.ed-danger:hover:not(:disabled) { border-color: #dc2626; color: #dc2626; }
        .ed-panel { flex-basis: 100%; border: 1px solid #e2e8f0; border-radius: 10px; background: #f8fafc; padding: 8px 10px; font-size: 12px; }
        .ed-panel[hidden] { display: none; }
        .ed-option { display: flex; gap: 8px; justify-content: space-between; align-items: center; padding: 6px 0; border-top: 1px solid #e2e8f0; }
        .ed-option:first-child { border-top: 0; }
        .ed-option-meta { font-size: 11px; color: #64748b; }
        .ed-row { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        .ed-msg { flex-basis: 100%; font-size: 11.5px; color: #475569; }
        .ed-msg:empty { display: none; }
        .ed-msg.err { color: #b91c1c; }
        .ed-msg.ok { color: #166534; }
        .day-tools { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: -4px 0 12px; font-size: 12px; }
        .day-tools label, .ed-panel label { font-weight: 700; color: #334155; display: inline-flex; gap: 6px; align-items: center; }
        .day-tools input[type=time], .ed-panel select, .ed-panel input[type=search] {
            font-size: 12px; padding: 4px 6px; min-height: 28px; border: 1px solid #cbd5e1; border-radius: 8px; background: #fff;
        }
        .ed-notice { margin-bottom: 12px; padding: 8px 12px; border-radius: 10px; background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; font-size: 12.5px; }
        .ed-notice[hidden] { display: none; }
        .trip-tools { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 12px; font-size: 12px; color: #475569; }
        @media (max-width: 640px) {
            .ed-btn { min-height: 36px; padding: 6px 11px; }
            .day-tools input[type=time], .ed-panel select, .ed-panel input[type=search] { min-height: 36px; }
            .ed-panel input[type=search] { flex: 1 1 100%; }
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
                <span><?php echo te('iv.title'); ?></span>
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
    <main class="content" style="padding:20px 24px;">

        <!-- Topbar -->
        <div class="topbar">
            <div class="page-title">
                <h1><?php echo htmlspecialchars($it["title"]); ?></h1>
                <p class="meta">
                    <?php echo $totalDays > 1 ? te('iv.days_many', ['n' => $totalDays]) : te('iv.days_one'); ?> &middot;
                    <?php if ($startDate): ?><?php echo te('iv.starting', ['date' => i18n_date((string)$startDate, 'short')]); ?> &middot;<?php endif; ?>
                    <span><?php echo htmlspecialchars(t_choice('transport', $transportType)); ?></span>
                </p>
            </div>
            <div class="actions">
                <a class="btn btn-ghost" href="my_itineraries.php"><?php echo te('common.back'); ?></a>
                <a class="btn btn-ghost" href="export_pdf.php?itinerary_id=<?php echo $itineraryId; ?>"><?php echo te('trip.export_pdf'); ?></a>
                <a class="btn btn-ghost" href="export_ics.php?itinerary_id=<?php echo $itineraryId; ?>"><?php echo te('iv.add_calendar'); ?></a>
                <a class="btn btn-primary" href="trip_summary.php?itinerary_id=<?php echo $itineraryId; ?>"><?php echo te('trip.title'); ?></a>
            </div>
        </div>

        <!-- ===== Map + Sidebar Grid ===== -->
        <div class="iv-grid">

            <!-- LEFT: Map Panel -->
            <div>
                <div class="card" style="padding:16px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; margin-bottom:10px;">
                        <h3 style="margin:0;"><?php echo te('iv.route_map'); ?></h3>
                        <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                            <span class="weather-chip" id="weatherChip"><?php echo te('im.wx_none'); ?></span>
                            <button type="button" class="btn btn-ghost" style="font-size:12px; padding:6px 12px;" id="locateButton"><?php echo te('iv.my_location'); ?></button>
                        </div>
                    </div>

                    <!-- Transport mode switcher -->
                    <div class="transport-bar" id="transportBar">
                        <button type="button" class="transport-btn <?php echo $transportType==='car'              ? 'active' : ''; ?>" data-mode="car"><?php echo te('opt.transport.car'); ?></button>
                        <button type="button" class="transport-btn <?php echo $transportType==='motorcycle'       ? 'active' : ''; ?>" data-mode="motorcycle"><?php echo te('opt.transport.motorcycle'); ?></button>
                        <button type="button" class="transport-btn <?php echo $transportType==='public_transport' ? 'active' : ''; ?>" data-mode="public_transport"><?php echo te('opt.transport.public_transport'); ?></button>
                        <button type="button" class="transport-btn <?php echo $transportType==='walking'          ? 'active' : ''; ?>" data-mode="walking"><?php echo te('iv.walk'); ?></button>
                    </div>

                    <!-- Day legend -->
                    <div class="day-legend" id="dayLegend">
                        <?php for ($d = 1; $d <= $totalDays; $d++):
                            $col = $dayColors[$d] ?? '#6366f1'; ?>
                        <span class="legend-item" id="legend-<?php echo $d; ?>"
                              style="color:<?php echo $col; ?>; border-color:<?php echo $col; ?>; background:<?php echo $col; ?>18;"
                              data-map-day="<?php echo $d; ?>">
                            <span class="legend-dot" style="background:<?php echo $col; ?>;"></span>
                            <?php echo te('trip.day', ['n' => $d]); ?>
                        </span>
                        <?php endfor; ?>
                        <?php if ($totalDays > 1): ?>
                        <span class="legend-item" data-map-day="0" style="color:#0f172a; border-color:#0f172a; background:#0f172a10;">
                            <span class="legend-dot" style="background:#0f172a;"></span> <?php echo te('iv.all_days'); ?>
                        </span>
                        <?php endif; ?>
                    </div>

                    <div class="map-wrap">
                        <div id="map"></div>
                        <button type="button" class="map-fullscreen-btn" id="mapFullscreenButton"><?php echo te('iv.fullscreen'); ?></button>
                        <div id="mapErrorPanel" class="map-error-panel" role="alert">
                            <div class="map-error-card">
                                <strong><?php echo te('iv.map_error_title'); ?></strong>
                                <div id="mapErrorText">
                                    <?php echo te('iv.map_error_text'); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div id="routeNotice" class="map-route-notice"></div>
                    <div id="routeSummary" class="route-summary"></div>
                    <div class="map-legend" aria-label="<?php echo te('iv.legend_aria'); ?>">
                        <span><i></i><?php echo te('iv.lg_road'); ?></span>
                        <span><i class="walk"></i><?php echo te('iv.lg_walk'); ?></span>
                        <span><i class="estimate"></i><?php echo te('iv.lg_estimate'); ?></span>
                        <span><i class="problem"></i><?php echo te('iv.lg_problem'); ?></span>
                    </div>
                    <div class="map-tools">
                        <button type="button" class="btn btn-ghost" id="optimiseButton"
                                title="<?php echo te('iv.optimise_title'); ?>"><?php echo te('iv.optimise'); ?></button>
                        <button type="button" class="btn btn-ghost" id="conditionsButton"
                                title="<?php echo te('iv.conditions_title'); ?>"><?php echo te('iv.conditions'); ?></button>
                        <button type="button" class="btn btn-ghost" id="busesButton"
                                title="<?php echo te('iv.buses_title'); ?>"><?php echo te('iv.buses'); ?></button>
                    </div>
                    <div id="liveBusStatus" class="live-bus-status" aria-live="polite"></div>
                    <div id="roadConditions" class="road-conditions" aria-live="polite"></div>

                    <div class="info-box" style="margin-top:10px;">
                        <strong><?php echo te('iv.map_help_head'); ?></strong>
                        <?php echo te('iv.map_help'); ?>
                        <?php echo te('iv.map_credit'); ?>
                    </div>
                </div>

                <div class="card" style="padding:16px; margin-top:18px;">
                    <div style="display:flex; justify-content:space-between; gap:10px; align-items:flex-start; flex-wrap:wrap;">
                        <div>
                            <h3 style="margin:0;"><?php echo te('iv.summary_cost'); ?></h3>
                            <p class="meta" style="margin:4px 0 0;"><?php echo te('iv.summary_note'); ?></p>
                        </div>
                        <?php if ($budget > 0): ?>
                            <strong class="<?php echo $costBreakdown["within_budget"] ? "budget-ok" : "budget-over"; ?>">
                                <?php echo te($costBreakdown["within_budget"] ? 'trip.within' : 'trip.over'); ?>
                                <?php echo " RM " . number_format(abs((float)$costBreakdown["budget_difference"]), 2); ?>
                            </strong>
                        <?php endif; ?>
                    </div>

                    <div class="summary-grid">
                        <div class="summary-card">
                            <div class="summary-label"><?php echo te('iv.s_days'); ?></div>
                            <div class="summary-value"><?php echo $totalDays; ?></div>
                        </div>
                        <div class="summary-card">
                            <div class="summary-label"><?php echo te('iv.s_places'); ?></div>
                            <div class="summary-value"><?php echo $totalPlaces; ?></div>
                        </div>
                        <div class="summary-card">
                            <div class="summary-label"><?php echo te('iv.s_distance'); ?></div>
                            <div class="summary-value"><?php echo number_format($totalDistanceKm, 1); ?> km</div>
                        </div>
                        <div class="summary-card">
                            <div class="summary-label"><?php echo te('pdf.m_total'); ?></div>
                            <div class="summary-value">RM <?php echo number_format((float)$costBreakdown["total_cost"], 2); ?></div>
                        </div>
                    </div>

                    <div class="cost-breakdown">
                        <?php foreach ($costBreakdown["breakdown"] as $row): ?>
                            <div class="cost-mini">
                                <strong>RM <?php echo number_format((float)$row["amount"], 2); ?></strong>
                                <span><?php echo htmlspecialchars(i18n_cost_label((string)$row["label"], $transportType)); ?></span>
                                <span><?php echo htmlspecialchars(i18n_cost_phrase((string)$row["note"])); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div><!-- /iv-grid -->

        <!-- ===== Timetable Section ===== -->
        <div class="card" style="margin-top:18px; padding:16px;">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; margin-bottom:14px;">
                <h3 style="margin:0;"><?php echo te('pdf.h_daily'); ?></h3>
                <span class="meta" style="font-size:12px;" id="transitModeLabel">
                    <?php echo te('iv.transport_prefix'); ?> <strong id="transitModeName"><?php echo htmlspecialchars($transportType === 'walking' ? t('iv.walk') : t_choice('transport', $transportType)); ?></strong>
                    &middot; <?php echo strtr(te('iv.route_hint'), ['{button}' => '<strong>&quot;' . te('iv.show_route') . '&quot;</strong>']); ?>
                </span>
            </div>

            <div class="ed-notice" id="editNotice" role="status" hidden></div>
            <?php if ($editRainy): ?>
            <div class="trip-tools ed-actions" data-ed-trip>
                <button type="button" class="ed-btn ed-primary" data-ed="replan_rain"><?php echo te('edit.replan_rain'); ?></button>
                <span><?php echo te('edit.rain_hint', ['days' => implode(', ', array_map(static fn($d, $c) => t('edit.rain_day_chance', ['day' => $d, 'chance' => $c]), array_keys($editRainy), $editRainy))]); ?></span>
                <div class="ed-msg" aria-live="polite"></div>
            </div>
            <?php endif; ?>

            <!-- Day tabs -->
            <div class="day-tabs" id="dayTabs">
                <?php for ($d = 1; $d <= $totalDays; $d++):
                    $col = $dayColors[$d] ?? '#6366f1';
                    $dateLabel = '';
                    if ($startDate) {
                        $ts = strtotime($startDate . ' +' . ($d - 1) . ' days');
                        $dateLabel = ' | ' . i18n_date(date('Y-m-d', $ts), 'short');
                    }
                ?>
                <button type="button" class="day-tab <?php echo $d === 1 ? 'active' : ''; ?>"
                        id="tab-<?php echo $d; ?>"
                        style="<?php echo $d === 1 ? "background:{$col}; border-color:{$col};" : ''; ?>"
                        data-day-tab="<?php echo $d; ?>">
                    <?php echo te('trip.day', ['n' => $d]); ?><?php echo htmlspecialchars($dateLabel); ?>
                </button>
                <?php endfor; ?>
            </div>

            <!-- Day schedule boxes -->
            <?php for ($d = 1; $d <= $totalDays; $d++):
                $col = $dayColors[$d] ?? '#6366f1';
                $dateStr = '';
                if ($startDate) {
                    $ts = strtotime($startDate . ' +' . ($d - 1) . ' days');
                    $dateStr = i18n_date(date('Y-m-d', $ts), 'long');
                }
                $schedule = $timetables[$d] ?? [];
            ?>
            <div class="day-box" id="day-<?php echo $d; ?>" style="<?php echo $d !== 1 ? 'display:none;' : ''; ?>">

                <!-- Day header -->
                <div class="day-box-header" style="border-left:4px solid <?php echo $col; ?>; background:<?php echo $col; ?>10;">
                    <div class="day-box-number" style="background:<?php echo $col; ?>;">
                        <?php echo $d; ?>
                    </div>
                    <div>
                        <div class="day-box-title"><?php echo te('trip.day', ['n' => $d]); ?></div>
                        <?php if ($dateStr): ?><div class="day-box-date"><?php echo $dateStr; ?></div><?php endif; ?>
                        <div class="day-box-note">
                            <?php
                                // A hotel night booked for the day before: the day starts from the hotel.
                                $startHotel = null;
                                if ($d > 1) {
                                    foreach (array_reverse($timetables[$d - 1] ?? []) as $prevRow) {
                                        if (strtolower((string)($prevRow['item_type'] ?? '')) === 'hotel') { $startHotel = (string)$prevRow['item_title']; break; }
                                    }
                                }
                                // Without a hotel, a trip planned with bases starts the day at the base of the night before.
                                if ($startHotel !== null && $startHotel !== '') {
                                    echo htmlspecialchars(t('iv.day_starts_hotel', ['name' => $startHotel]));
                                } elseif ($d === 1 && $originName !== '' && $viewBases) {
                                    echo htmlspecialchars('Starts at ' . $originName . '.');
                                } elseif ($d > 1 && isset($viewBases[$d - 1])) {
                                    echo htmlspecialchars('Starts at ' . $viewBases[$d - 1]['name'] . ', where you spent the night.');
                                } else {
                                    echo te($d === 1 ? 'iv.day_starts_origin' : 'iv.day_starts_prev');
                                }
                            ?>
                        </div>
                        <?php
                            // Where the night after this day is spent: its hotel, else the base of the day.
                            $nightAt = null;
                            if ($d < $totalDays) {
                                foreach (array_reverse($timetables[$d] ?? []) as $row) {
                                    if (strtolower((string)($row['item_type'] ?? '')) === 'hotel') { $nightAt = (string)$row['item_title']; break; }
                                }
                                $nightAt ??= $viewBases[$d]['name'] ?? null;
                            }
                        ?>
                        <?php if ($nightAt !== null && $nightAt !== ''): ?>
                        <div class="day-box-note"><?php echo htmlspecialchars('Night at ' . $nightAt . '.'); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($viewBases[$d]['note'])): ?>
                        <div class="day-box-note"><?php echo htmlspecialchars((string)$viewBases[$d]['note']); ?></div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Edit this day: its start, a new place, planned again -->
                <?php $dayStartValue = $editHm($editDayStarts[$d] ?? $editUsualStart); ?>
                <div class="day-tools ed-actions" data-ed-day="<?php echo $d; ?>">
                    <label><?php echo te('edit.day_start'); ?>
                        <input type="time" step="300" value="<?php echo htmlspecialchars($dayStartValue); ?>" data-ed-start>
                    </label>
                    <button type="button" class="ed-btn" data-ed="set_day_start"><?php echo te('edit.day_start_set'); ?></button>
                    <?php if (isset($editDayStarts[$d])): ?>
                    <button type="button" class="ed-btn" data-ed="clear_day_start"><?php echo te('edit.day_start_usual', ['time' => $editHm($editUsualStart)]); ?></button>
                    <?php endif; ?>
                    <button type="button" class="ed-btn" data-ed="add_open"><?php echo te('edit.add_place'); ?></button>
                    <button type="button" class="ed-btn" data-ed="regen_open"><?php echo te('edit.regenerate'); ?></button>
                    <div class="ed-panel" data-ed-panel="add" hidden>
                        <div class="ed-row">
                            <input type="search" maxlength="80" autocomplete="off" placeholder="<?php echo te('edit.search_placeholder'); ?>" aria-label="<?php echo te('edit.search_placeholder'); ?>" data-ed-search>
                        </div>
                        <div data-ed-results></div>
                    </div>
                    <div class="ed-panel" data-ed-panel="regen" hidden>
                        <div class="ed-row">
                            <?php foreach (['more' => te('edit.more_of'), 'less' => te('edit.less_of')] as $editWish => $editWishLabel): ?>
                            <label><?php echo $editWishLabel; ?>
                                <select data-ed-<?php echo $editWish; ?>>
                                    <option value=""><?php echo te('edit.no_choice'); ?></option>
                                    <?php foreach ($editWishGroups as $editGroupLabel => $editOptions): ?>
                                    <optgroup label="<?php echo htmlspecialchars($editGroupLabel); ?>">
                                        <?php foreach ($editOptions as $editValue => $editLabel): ?>
                                        <option value="<?php echo htmlspecialchars($editValue); ?>"><?php echo htmlspecialchars($editLabel); ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <?php endforeach; ?>
                            <button type="button" class="ed-btn ed-primary" data-ed="regenerate_day"><?php echo te('edit.regenerate_go'); ?></button>
                        </div>
                    </div>
                    <div class="ed-msg" aria-live="polite"></div>
                </div>

                <?php if (empty($schedule)): ?>
                    <p class="meta"><?php echo te('iv.no_places'); ?></p>
                <?php else: ?>
                <div class="table-scroll mobile-card-table-wrap">
                <table class="timetable mobile-card-table">
                    <thead>
                        <tr>
                            <th style="width:90px;"><?php echo te('trip.col_time'); ?></th>
                            <th style="width:30px;">#</th>
                            <th><?php echo te('pdf.col_place_act'); ?></th>
                            <th style="width:80px;"><?php echo te('trip.col_type'); ?></th>
                            <th style="width:80px;"><?php echo te('trip.col_cost'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                            $firstTravelItem = null;
                            foreach ($schedule as $candidate) {
                                if (strtolower((string)($candidate['item_type'] ?? '')) !== 'hotel') {
                                    $firstTravelItem = $candidate;
                                    break;
                                }
                            }
                            $dayStartName = $originName !== '' ? $originName : t('iv.starting_location');
                            if ($d > 1) {
                                $dayStartName = t('iv.prev_ended');
                                $before = array_filter($timetables[$d - 1] ?? [], static fn($row) => $row['latitude'] !== null && $row['longitude'] !== null);
                                $hotelsBefore = array_filter($before, static fn($row) => strtolower((string)$row['item_type']) === 'hotel');
                                $endedAt = $hotelsBefore ? end($hotelsBefore) : ($before ? end($before) : null);
                                if ($endedAt) $dayStartName = (string)$endedAt['item_title'];
                            }
                            $showOriginRow = $firstTravelItem
                                && ($d === 1
                                    || (int)($firstTravelItem['_travel_min'] ?? 0) > 0
                                    || ((float)($firstTravelItem['dist_km'] ?? $firstTravelItem['distance_km'] ?? 0) > 0));
                            $displayNo = 0;
                        ?>
                        <?php if ($showOriginRow): ?>
                        <tr data-leg-to="<?php echo (int)$firstTravelItem['item_id']; ?>">
                            <td data-label="<?php echo te('trip.col_time'); ?>">
                                <div style="font-weight:800; font-size:12px;">
                                    <?php echo (int)($firstTravelItem['_travel_min'] ?? 0) > 0
                                        ? minutesToTime((int)$firstTravelItem['_start_min'] - (int)$firstTravelItem['_travel_min'])
                                        : t('trip.before', ['time' => $firstTravelItem['_start_fmt']]); ?>
                                </div>
                                <div style="font-size:10.5px; color:#94a3b8;"><?php echo te('iv.depart'); ?></div>
                            </td>
                            <td data-label="#" style="font-weight:800; color:#64748b;"><?php echo te('trip.start'); ?></td>
                            <td data-label="<?php echo te('pdf.col_place_act'); ?>">
                                <div style="font-weight:700;">
                                    <?php echo htmlspecialchars($dayStartName); ?>
                                </div>
                                <div style="font-size:11px; color:#64748b; margin-top:2px; display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                                    <span><?php echo te('trip.travel_to', ['name' => (string)$firstTravelItem['item_title']]); ?></span>
                                    <!-- The planner's estimate; the map script replaces it with the road figures -->
                                    <span class="leg-est">
                                        <?php if ((int)$firstTravelItem['_travel_min'] > 0): ?>
                                            ~<?php echo te('trip.min', ['n' => (int)$firstTravelItem['_travel_min']]); ?>
                                        <?php endif; ?>
                                        <?php if ((float)($firstTravelItem['distance_km'] ?? 0) > 0): ?>
                                            &middot; <?php echo number_format((float)$firstTravelItem['distance_km'], 1); ?> km
                                        <?php endif; ?>
                                    </span>
                                    <button type="button" class="transit-summary-btn" data-leg-toggle="<?php echo (int)$firstTravelItem['item_id']; ?>"><?php echo te('iv.show_route'); ?></button>
                                </div>
                                <div class="transit-detail"></div>
                            </td>
                            <td data-label="<?php echo te('trip.col_type'); ?>"><span class="type-badge"><?php echo te('trip.origin'); ?></span></td>
                            <td data-label="<?php echo te('trip.col_cost'); ?>"><span style="color:#94a3b8;"><?php echo te('iv.free'); ?></span></td>
                        </tr>
                        <?php endif; ?>
                        <?php foreach ($schedule as $idx => $item):
                            $typeBadgeClass = 'badge-' . strtolower($item['item_type'] ?? 'attraction');
                            $activity = activitySuggestion($item['item_type'] ?? 'attraction', $item['category'] ?? '');
                            $isHotel = strtolower((string)($item['item_type'] ?? '')) === 'hotel';
                            $mealSlot = (string)($item['meal_slot'] ?? '');
                            $hasPoint = $item['latitude'] !== null && $item['longitude'] !== null;
                            if ($mealSlot !== '' && !$hasPoint) $activity = t('pdf.meal_free', ['slot' => mb_strtolower(t_choice('itype', $mealSlot))]);
                            // Numbered like the map's markers: a row without a place has none.
                            $displayLabel = $isHotel ? t('trip.stay') : ($hasPoint ? (string)(++$displayNo) : '-');
                        ?>

                        <?php if ($idx > 0 && $hasPoint): ?>
                        <!-- ===== The leg from the stop before ===== -->
                        <tr class="transit-row" data-leg-to="<?php echo (int)$item['item_id']; ?>">
                            <td class="mobile-empty" colspan="5">
                                <div class="transit-row-inner">
                                    <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                                        <button type="button" class="transit-summary-btn" data-leg-toggle="<?php echo (int)$item['item_id']; ?>"><?php echo te('iv.show_route'); ?></button>
                                        <!-- The planner's estimate; the map script replaces it with the road figures -->
                                        <span class="leg-est" style="font-size:11.5px; color:#64748b;">
                                            <?php if ($item['_travel_min'] > 0): ?>
                                                ~<?php echo te('trip.min', ['n' => (int)$item['_travel_min']]); ?>
                                                <?php if ((float)($item['distance_km'] ?? 0) > 0): ?>
                                                    &middot; <?php echo number_format((float)$item['distance_km'], 1); ?> km
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    <div class="transit-detail"></div>
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>

                        <!-- ===== Place row ===== -->
                        <tr data-item-id="<?php echo (int)$item['item_id']; ?>"
                            data-lat="<?php echo htmlspecialchars((string)($item['latitude'] ?? '')); ?>"
                            data-lng="<?php echo htmlspecialchars((string)($item['longitude'] ?? '')); ?>"
                            style="cursor:pointer;">
                            <td data-label="<?php echo te('trip.col_time'); ?>">
                                <div style="font-weight:800; font-size:12px;"><?php echo $item['_start_fmt']; ?></div>
                                <div style="font-size:10.5px; color:#94a3b8;"><?php echo te('iv.to_time', ['time' => $item['_end_fmt']]); ?></div>
                            </td>
                            <td data-label="#" style="font-weight:800; color:<?php echo $isHotel ? '#64748b' : $col; ?>;"><?php echo htmlspecialchars($displayLabel); ?></td>
                            <td data-label="<?php echo te('pdf.col_place_act'); ?>">
                                <div style="font-weight:700;"><?php echo htmlspecialchars(i18n_item_title((string)$item['item_title'])); ?></div>
                                <div style="font-size:11px; color:#64748b; margin-top:2px;">
                                    <?php echo $activity; ?>
                                    <?php if (!empty($item['address'])): ?>
                                        &middot; <?php echo htmlspecialchars($item['address']); ?>
                                    <?php endif; ?>
                                </div>
                                <?php $mapLinks = CalendarExportService::mapLinks($item['latitude'] ?? null, $item['longitude'] ?? null, (string)$item['item_title']); ?>
                                <?php if ($mapLinks): ?>
                                <div style="font-size:10.5px; margin-top:3px; display:flex; gap:10px; flex-wrap:wrap;">
                                    <?php foreach ($mapLinks as $linkLabel => $link): ?>
                                    <a href="<?php echo htmlspecialchars($link['href']); ?>"<?php echo $link['external'] ? ' target="_blank" rel="noopener"' : ''; ?> onclick="event.stopPropagation();"><?php echo htmlspecialchars($linkLabel === 'Open in maps app' ? t('iv.open_maps_app') : $linkLabel); ?></a>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($item['opening_hours'])): ?>
                                <div style="font-size:10.5px; color:#94a3b8; margin-top:2px;"><?php echo te('iv.hours'); ?> <?php echo htmlspecialchars($item['opening_hours']); ?></div>
                                <?php endif; ?>
                                <?php if (!empty($item['state'])): ?>
                                <div style="font-size:10.5px; color:#94a3b8; margin-top:2px;">
                                    <?php echo te('iv.location'); ?> <?php echo htmlspecialchars($item['district'] ? $item['district'] . ', ' . $item['state'] : $item['state']); ?>
                                </div>
                                <?php endif; ?>
                                <?php $reasonSelected = extractReasonSelected($item['notes'] ?? ''); ?>
                                <?php if ($reasonSelected !== '' && !$isHotel): ?>
                                <div style="font-size:10.5px; color:#475569; margin-top:4px;">
                                    <strong><?php echo te('trip.reason'); ?></strong> <?php echo htmlspecialchars(i18n_reason_text($reasonSelected)); ?>
                                </div>
                                <?php endif; ?>
                                <?php
                                    // Replace, Remove, Move up and down for a visit; Replace only for a meal; nothing for notes and hotels.
                                    $editPos = array_search((int)$item['item_id'], $editVisitIds[$d] ?? [], true);
                                    $editKind = $mealSlot !== '' ? 'meal' : ($editPos !== false ? 'visit' : null);
                                ?>
                                <?php if ($editKind !== null): ?>
                                <div class="ed-actions" data-ed-item="<?php echo (int)$item['item_id']; ?>" data-ed-day="<?php echo $d; ?>"
                                     data-ed-pos="<?php echo $editPos !== false ? $editPos + 1 : 0; ?>" data-ed-title="<?php echo htmlspecialchars((string)$item['item_title']); ?>">
                                    <button type="button" class="ed-btn" data-ed="replace_open"><?php echo te('edit.replace'); ?></button>
                                    <?php if ($editKind === 'visit'): ?>
                                    <button type="button" class="ed-btn ed-danger" data-ed="remove"><?php echo te('edit.remove'); ?></button>
                                    <button type="button" class="ed-btn" data-ed="move_up"<?php echo $editPos === 0 ? ' disabled' : ''; ?>><?php echo te('edit.move_up'); ?></button>
                                    <button type="button" class="ed-btn" data-ed="move_down"<?php echo $editPos === count($editVisitIds[$d]) - 1 ? ' disabled' : ''; ?>><?php echo te('edit.move_down'); ?></button>
                                    <?php endif; ?>
                                    <div class="ed-panel" data-ed-panel="replace" hidden></div>
                                    <div class="ed-msg" aria-live="polite"></div>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td data-label="<?php echo te('trip.col_type'); ?>">
                                <span class="type-badge <?php echo htmlspecialchars($typeBadgeClass); ?>">
                                    <?php echo htmlspecialchars($isHotel ? t('trip.hotel_checkin') : ($mealSlot !== '' ? t_choice('itype', $mealSlot) : itineraryTypeLabel((string)($item['item_type'] ?? 'attraction')))); ?>
                                </span>
                            </td>
                            <td data-label="<?php echo te('trip.col_cost'); ?>" style="font-weight:700;">
                                <?php if ($mealSlot !== ''): ?>
                                    <span style="color:#94a3b8; font-weight:400;"><?php echo te('iv.in_meal_estimate'); ?></span>
                                <?php elseif ((float)($item['estimated_cost'] ?? 0) > 0): ?>
                                    <?php $costLabel = CostEstimationService::itemCostLabel((string)($item['item_type'] ?? ''), (float)$item['estimated_cost'], $partySize); ?>
                                    <div><?php echo htmlspecialchars(i18n_cost_phrase((string)$costLabel['whole_label'])); ?></div>
                                    <div style="font-size:10.5px; color:#64748b; font-weight:400; margin-top:2px;"><?php echo htmlspecialchars(i18n_cost_phrase((string)$costLabel['unit_label'])); ?></div>
                                <?php else: ?>
                                    <span style="color:#94a3b8;"><?php echo te('iv.free'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>

                <!-- Day total -->
                <?php
                $dayTotal = 0.0;
                foreach ($schedule as $costItem) {
                    $dayTotal += CostEstimationService::wholePartyItemCost((string)($costItem['item_type'] ?? ''), (float)($costItem['estimated_cost'] ?? 0), $partySize);
                }
                ?>
                <div style="margin-top:10px; text-align:right; font-size:13px; font-weight:800; color:#4f46e5;">
                    <?php echo te('iv.day_total', ['day' => $d, 'amount' => number_format($dayTotal, 2)]); ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endfor; ?>
        </div>

    </main>
</div>

<button type="button" class="ai-chat-fab" onclick="toggleAiChat()"><?php echo te('trip.ai_fab'); ?></button>
<div class="ai-chat-panel" id="aiChatPanel" aria-live="polite" data-itinerary-id="<?php echo (int)$itineraryId; ?>" data-missing-info="<?php echo htmlspecialchars($missingInfoJson, ENT_QUOTES); ?>">
    <div class="ai-chat-header">
        <div>
            <div class="ai-chat-title"><?php echo te('iv.ai_title'); ?></div>
            <div class="ai-chat-subtitle"><?php echo te('iv.ai_sub'); ?></div>
        </div>
        <button type="button" class="ai-chat-close" onclick="toggleAiChat()" aria-label="<?php echo te('trip.ai_close'); ?>">&times;</button>
    </div>
    <div class="ai-chat-body" id="aiChatBody">
        <div class="ai-msg bot" id="aiInitialMessage"><?php echo te('iv.ai_hello'); ?></div>
    </div>
    <form class="ai-chat-form" onsubmit="sendAiMessage(event)">
        <input id="aiChatInput" type="text" maxlength="700" autocomplete="off" placeholder="<?php echo te('trip.ai_placeholder'); ?>">
        <button type="submit"><?php echo te('trip.ai_send'); ?></button>
    </form>
</div>

<script>
// What assets/trip_ai_chat.js needs: the chat's language with its card labels, and its own texts.
const AI_CHAT_BOOT = <?php echo json_encode(['lang' => i18n_lang(), 'ui' => ai_ui_labels(i18n_lang())], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '{}'; ?>;
const AI_CHAT_T = <?php echo i18n_js(['iv.ai_missing', 'iv.ai_sep']); ?>;
</script>
<script src="../assets/trip_ai_chat.js?v=20261118a"></script>
<script>
// What assets/itinerary_edit.js needs: the endpoints and its texts in the page's language.
const ITINERARY_EDIT = <?php echo $editConfig; ?>;
const ITINERARY_EDIT_T = <?php echo i18n_js([
    'edit.loading', 'edit.suggest_none', 'edit.more_options', 'edit.choose', 'edit.planned_at', 'edit.saving', 'edit.reloading',
    'edit.confirm_remove', 'edit.confirm_regenerate', 'edit.confirm_replan', 'edit.err_network', 'edit.search_hint', 'edit.no_results',
    'edit.add', 'edit.err_time', 'edit.meal_break',
]); ?>;
</script>
<script src="../assets/itinerary_edit.js?v=20261119a"></script>
<script>
// What assets/itinerary_map.js draws.
const ITINERARY_ID = <?php echo $itineraryId; ?>;
const TOTAL_DAYS = <?php echo $totalDays; ?>;
const DAYS_DATA = <?php echo $jsDays; ?>;
const DAY_COLORS = <?php echo $jsColorsJson; ?>;
const START_TRANSPORT = <?php echo $jsTransport; ?>;
const START_DATE = <?php echo json_encode($startDate ?: null); ?>;
// The texts the map script and the live bus layer write, in the page's language.
const ITINERARY_MAP_T = <?php echo i18n_js([
    'trip.min', 'trip.day', 'opt.transport.car', 'opt.transport.motorcycle', 'opt.transport.public_transport', 'iv.walk', 'iv.free',
    'iv.starting_location', 'iv.my_location', 'iv.show_route', 'iv.optimise', 'iv.optimising', 'iv.fullscreen', 'iv.fullscreen_close',
    'iv.buses', 'iv.buses_hide',
    'im.by_car', 'im.by_motorcycle', 'im.by_public', 'im.on_foot', 'im.h_min', 'im.hotel', 'im.stop',
    'im.k_bus', 'im.k_train', 'im.k_ferry', 'im.k_cable_car', 'im.k_ride',
    'im.leg_transit', 'im.about', 'im.estimate', 'im.start_hotel', 'im.start_prev', 'im.loading_route', 'im.all_n_days', 'im.road_total',
    'im.time_estimated', 'im.route_missing', 'im.distance', 'im.time', 'im.via', 'im.walk_to', 'im.from_to', 'im.arrives', 'im.typical',
    'im.timetables', 'im.no_timetable', 'im.short_walk', 'im.open_osm', 'im.hide_route', 'im.loading_leg', 'im.leg_missing',
    'im.wx_none', 'im.wx_prefix', 'im.wx_no_forecast', 'im.wx_chip', 'im.wx_chip_rain', 'im.wx_describe', 'im.wx_describe_rain',
    'im.wx_adv_wet', 'im.wx_adv_hot', 'im.wx_adv_good', 'im.wx_credit', 'im.wx_n_start', 'im.wx_n_nostop', 'im.wx_n_past', 'im.wx_n_down', 'im.wx_n_from',
    'im.wx_clear', 'im.wx_mainly_clear', 'im.wx_partly_cloudy', 'im.wx_overcast', 'im.wx_fog', 'im.wx_light_drizzle', 'im.wx_drizzle',
    'im.wx_heavy_drizzle', 'im.wx_freezing_drizzle', 'im.wx_light_rain', 'im.wx_rain', 'im.wx_heavy_rain', 'im.wx_freezing_rain',
    'im.wx_light_snow', 'im.wx_snow', 'im.wx_heavy_snow', 'im.wx_snow_grains', 'im.wx_light_showers', 'im.wx_showers', 'im.wx_heavy_showers',
    'im.wx_snow_showers', 'im.wx_thunderstorm', 'im.wx_thunderstorm_hail', 'im.wx_unknown',
    'im.geo_unsupported', 'im.geo_denied', 'im.list_sep', 'im.list_and', 'im.opt_saved', 'im.opt_one', 'im.opt_many', 'im.opt_best',
    'im.opt_removed', 'im.opt_failed',
    'im.rc_loading', 'im.rc_failed', 'im.rc_now', 'im.rc_none', 'im.rc_checked', 'im.rc_not_forecast', 'im.rc_area', 'im.rc_state',
    'im.rc_route', 'im.rc_away', 'im.rc_flood',
]); ?>;
const LIVE_BUSES_T = <?php echo i18n_js([
    'lb.ago_s', 'lb.ago_min', 'lb.towards', 'lb.reported', 'lb.show_route', 'lb.route_shown', 'lb.no_line', 'lb.in_view_one', 'lb.in_view_many',
    'lb.failed', 'lb.looking', 'lb.n_zoom', 'lb.n_none_here', 'lb.n_empty', 'lb.n_nomap',
]); ?>;
</script>
<script src="../assets/live_buses.js?v=20261118a"></script>
<script src="../assets/itinerary_map.js?v=20261118a"></script>
  <script src="../assets/dashboard_shell.js?v=20261119a"></script>
</body>
</html>

