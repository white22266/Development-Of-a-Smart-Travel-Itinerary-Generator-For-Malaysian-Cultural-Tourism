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

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
    header("Location: ../auth/login.php?role=traveller");
    exit;
}
$travellerName = $_SESSION["traveller_name"] ?? "Traveller";
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

$stmt = $conn->prepare("
    SELECT ii.item_id, ii.day_no, ii.sequence_no, ii.item_type, ii.place_id,
           ii.item_title, ii.estimated_cost, ii.distance_km, ii.travel_time_min,
           ii.start_time, ii.end_time, ii.notes,
           {$itemCoordSelect}, cp.address, cp.category, cp.state, {$districtJoinCol},
           cp.opening_hours
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
        } elseif ($i > 0) {
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
    $map = [
        'food' => 'Enjoy local cuisine', 'hotel' => 'Check in & rest',
        'festival' => 'Join cultural festival', 'museum' => 'Explore exhibits',
        'heritage' => 'Explore heritage site', 'culture' => 'Experience local culture',
        'nature' => 'Explore nature & scenery', 'shopping' => 'Browse local markets',
        'attraction' => 'Visit & explore',
    ];
    $key = strtolower($category ?: $type);
    return $map[$key] ?? $map[$type] ?? 'Visit & explore';
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
        'title'        => $item['item_title'],
        'type'         => $item['item_type'],
        'category'     => $item['category'] ?? '',
        'lat'          => $item['latitude']  !== null ? (float)$item['latitude']  : null,
        'lng'          => $item['longitude'] !== null ? (float)$item['longitude'] : null,
        'address'      => $item['address'] ?? '',
        'state'        => $item['state'] ?? '',
        'district'     => $item['district'] ?? '',
        'cost'         => (float)($item['estimated_cost'] ?? 0),
        'cost_label'   => (float)($item['estimated_cost'] ?? 0) > 0
            ? CostEstimationService::itemCostLabel((string)($item['item_type'] ?? ''), (float)$item['estimated_cost'], $partySize)['whole_label']
            : 'Free',
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
        if (strtolower((string)($item['item_type'] ?? '')) !== 'hotel') {
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
    $missingInfo[] = "Start Date";
}
if ($originName === "" || $originLat === null || $originLng === null || (float)$originLat == 0.0 || (float)$originLng == 0.0) {
    $missingInfo[] = "Starting Location";
}
if ($totalDays > 1 && $selectedHotelCount === 0) {
    $missingInfo[] = "Confirmed Hotel";
}
$missingInfoJson = json_encode(array_values($missingInfo), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: "[]";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($it["title"]); ?> - Itinerary View</title>
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

        /* ===== Side panel ===== */
        .side-panel { display: flex; flex-direction: column; gap: 14px; }
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
        .transit-chips {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            flex-wrap: wrap;
            margin-left: 8px;
        }
        .transit-chip {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 10.5px;
            font-weight: 800;
            color: #fff;
            white-space: nowrap;
        }
        .transit-chip-walk {
            background: #64748b;
        }

        /* ===== Transit detail panel ===== */
        .transit-detail {
            display: none;
            border-top: 1px solid rgba(15,23,42,0.06);
            padding: 10px 14px;
            background: #f8fafc;
        }
        .transit-detail.open { display: block; }

        /* ===== Transit step card ===== */
        .ts-step {
            display: flex;
            gap: 10px;
            margin-bottom: 10px;
            position: relative;
        }
        .ts-step:last-child { margin-bottom: 0; }
        .ts-step::before {
            content: '';
            position: absolute;
            left: 17px;
            top: 32px;
            bottom: -10px;
            width: 2px;
            background: rgba(15,23,42,0.10);
        }
        .ts-step:last-child::before { display: none; }
        .ts-icon {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            font-weight: 900;
            line-height: 1;
            text-align: center;
            overflow: hidden;
            flex-shrink: 0;
            border: 2px solid rgba(255,255,255,0.8);
            box-shadow: 0 1px 4px rgba(15,23,42,0.12);
        }
        .ts-icon span {
            display: block;
            max-width: 30px;
            overflow: hidden;
            text-overflow: clip;
            white-space: nowrap;
            color: rgba(255,255,255,0.96);
            font-size: 10px;
            line-height: 1;
        }
        .ts-icon .ts-icon-emoji {
            font-size: 18px;
            line-height: 1;
        }
        .ts-body { flex: 1; }
        .ts-mode-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 900;
            color: #fff;
            margin-bottom: 3px;
        }
        .ts-line-name { font-weight: 800; font-size: 12.5px; }
        .ts-stops { font-size: 11px; color: #64748b; margin-top: 2px; }
        .ts-stop-row {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 11.5px;
            margin-top: 4px;
        }
        .ts-stop-dot {
            width: 8px; height: 8px;
            border-radius: 50%;
            border: 2px solid;
            flex-shrink: 0;
        }
        .ts-time-badge {
            display: inline-block;
            background: rgba(15,23,42,0.06);
            border-radius: 6px;
            padding: 2px 6px;
            font-size: 10.5px;
            font-weight: 700;
        }
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
        .ts-fare {
            background: #fef9c3;
            color: #854d0e;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 800;
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

        /* ===== Hotel card ===== */
        .hotel-card {
            display: flex; justify-content: space-between; align-items: center;
            padding: 8px 10px; border-radius: 10px;
            border: 1px solid rgba(15,23,42,0.08);
            margin-bottom: 6px; font-size: 12px; cursor: pointer;
            transition: border-color .15s;
        }
        .hotel-card:hover { border-color: #6366f1; }
        .hotel-card .hotel-name { font-weight: 700; }
        .hotel-card .hotel-meta { color: #64748b; font-size: 11px; margin-top: 2px; }
        .hotel-card .hotel-price { font-weight: 800; color: #4f46e5; white-space: nowrap; }

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
                <span>Itinerary View</span>
            </div>
        </div>
        <nav class="nav">
            <a href="../traveller/traveller_dashboard.php"><span class="dot"></span> Dashboard</a>
            <a href="../preference/preference_form.php"><span class="dot"></span> Traveller Preference Analyzer</a>
            <a href="../itinerary/select_preference.php"><span class="dot"></span> Smart Itinerary Generator</a>
            <a class="active" href="../itinerary/my_itineraries.php"><span class="dot"></span> Cost Estimation and Trip Summary</a>
            <a href="../cultural/cultural_guide.php"><span class="dot"></span> Cultural Guide Presentation</a>
            <a href="../auth/profile/profile.php"><span class="dot"></span> Profile</a>
            <a href="../auth/logout.php"><span class="dot"></span> Logout</a>
        </nav>
        <div class="sidebar-footer">
            <div class="small">Logged in as:</div>
            <div style="margin-top:6px; font-weight:800;"><?php echo htmlspecialchars($travellerName); ?></div>
            <div class="chip">Role: Traveller</div>
        </div>
    </aside>

    <!-- ===== Main Content ===== -->
    <main class="content" style="padding:20px 24px;">

        <!-- Topbar -->
        <div class="topbar">
            <div class="page-title">
                <h1><?php echo htmlspecialchars($it["title"]); ?></h1>
                <p class="meta">
                    <?php echo $totalDays; ?> day<?php echo $totalDays > 1 ? 's' : ''; ?> &middot;
                    <?php if ($startDate): ?>Starting <?php echo date('d M Y', strtotime($startDate)); ?> &middot;<?php endif; ?>
                    <span style="text-transform:capitalize;"><?php echo htmlspecialchars(str_replace('_', ' ', $transportType)); ?></span>
                </p>
            </div>
            <div class="actions">
                <a class="btn btn-ghost" href="my_itineraries.php">Back</a>
                <a class="btn btn-ghost" href="export_pdf.php?itinerary_id=<?php echo $itineraryId; ?>">Export PDF</a>
                <a class="btn btn-primary" href="trip_summary.php?itinerary_id=<?php echo $itineraryId; ?>">Trip Summary</a>
            </div>
        </div>

        <!-- ===== Map + Sidebar Grid ===== -->
        <div class="iv-grid">

            <!-- LEFT: Map Panel -->
            <div>
                <div class="card" style="padding:16px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; margin-bottom:10px;">
                        <h3 style="margin:0;">Route Map</h3>
                        <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                            <span class="weather-chip" id="weatherChip">Weather: --</span>
                            <button type="button" class="btn btn-ghost" style="font-size:12px; padding:6px 12px;" id="locateButton">My Location</button>
                        </div>
                    </div>

                    <!-- Transport mode switcher -->
                    <div class="transport-bar" id="transportBar">
                        <button type="button" class="transport-btn <?php echo $transportType==='car'              ? 'active' : ''; ?>" data-mode="car">Car</button>
                        <button type="button" class="transport-btn <?php echo $transportType==='motorcycle'       ? 'active' : ''; ?>" data-mode="motorcycle">Motorcycle</button>
                        <button type="button" class="transport-btn <?php echo $transportType==='public_transport' ? 'active' : ''; ?>" data-mode="public_transport">Public Transport</button>
                        <button type="button" class="transport-btn <?php echo $transportType==='walking'          ? 'active' : ''; ?>" data-mode="walking">Walk</button>
                    </div>

                    <!-- Day legend -->
                    <div class="day-legend" id="dayLegend">
                        <?php for ($d = 1; $d <= $totalDays; $d++):
                            $col = $dayColors[$d] ?? '#6366f1'; ?>
                        <span class="legend-item" id="legend-<?php echo $d; ?>"
                              style="color:<?php echo $col; ?>; border-color:<?php echo $col; ?>; background:<?php echo $col; ?>18;"
                              data-map-day="<?php echo $d; ?>">
                            <span class="legend-dot" style="background:<?php echo $col; ?>;"></span>
                            Day <?php echo $d; ?>
                        </span>
                        <?php endfor; ?>
                        <?php if ($totalDays > 1): ?>
                        <span class="legend-item" data-map-day="0" style="color:#0f172a; border-color:#0f172a; background:#0f172a10;">
                            <span class="legend-dot" style="background:#0f172a;"></span> All days
                        </span>
                        <?php endif; ?>
                    </div>

                    <div class="map-wrap">
                        <div id="map"></div>
                        <div id="mapErrorPanel" class="map-error-panel" role="alert">
                            <div class="map-error-card">
                                <strong>The map could not be loaded.</strong>
                                <div id="mapErrorText">
                                    The map library did not arrive. Check the internet connection and reload the page.
                                    The timetable below is complete without the map.
                                </div>
                            </div>
                        </div>
                    </div>
                    <div id="routeNotice" class="map-route-notice"></div>
                    <div id="routeSummary" class="route-summary"></div>

                    <div class="info-box" style="margin-top:10px;">
                        <strong>How to read this map:</strong>
                        The numbered pins are the day's stops in order, "S" is where the day starts and "H" is a hotel.
                        Day 1 starts from your starting location; a later day starts where the day before ended.
                        The line follows the roads. Choosing another way of travelling redraws it without changing the saved itinerary.
                        Map and routes: OpenStreetMap contributors. Weather data by Open-Meteo.com.
                    </div>
                </div>

                <div class="card" style="padding:16px; margin-top:18px;">
                    <div style="display:flex; justify-content:space-between; gap:10px; align-items:flex-start; flex-wrap:wrap;">
                        <div>
                            <h3 style="margin:0;">Trip Summary &amp; Cost</h3>
                            <p class="meta" style="margin:4px 0 0;">Map, schedule, and full budget estimate are combined on this page.</p>
                        </div>
                        <?php if ($budget > 0): ?>
                            <strong class="<?php echo $costBreakdown["within_budget"] ? "budget-ok" : "budget-over"; ?>">
                                <?php echo $costBreakdown["within_budget"] ? "Within Budget" : "Over Budget"; ?>
                                <?php echo " RM " . number_format(abs((float)$costBreakdown["budget_difference"]), 2); ?>
                            </strong>
                        <?php endif; ?>
                    </div>

                    <div class="summary-grid">
                        <div class="summary-card">
                            <div class="summary-label">Days</div>
                            <div class="summary-value"><?php echo $totalDays; ?></div>
                        </div>
                        <div class="summary-card">
                            <div class="summary-label">Places</div>
                            <div class="summary-value"><?php echo $totalPlaces; ?></div>
                        </div>
                        <div class="summary-card">
                            <div class="summary-label">Distance</div>
                            <div class="summary-value"><?php echo number_format($totalDistanceKm, 1); ?> km</div>
                        </div>
                        <div class="summary-card">
                            <div class="summary-label">Total Estimate</div>
                            <div class="summary-value">RM <?php echo number_format((float)$costBreakdown["total_cost"], 2); ?></div>
                        </div>
                    </div>

                    <div class="cost-breakdown">
                        <?php foreach ($costBreakdown["breakdown"] as $row): ?>
                            <div class="cost-mini">
                                <strong>RM <?php echo number_format((float)$row["amount"], 2); ?></strong>
                                <span><?php echo htmlspecialchars($row["label"]); ?></span>
                                <span><?php echo htmlspecialchars($row["note"]); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div><!-- /iv-grid -->

        <!-- ===== Timetable Section ===== -->
        <div class="card" style="margin-top:18px; padding:16px;">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; margin-bottom:14px;">
                <h3 style="margin:0;">Daily Itinerary Schedule</h3>
                <span class="meta" style="font-size:12px;" id="transitModeLabel">
                    Transport: <strong id="transitModeName"><?php echo htmlspecialchars(str_replace('_', ' ', ucfirst($transportType))); ?></strong>
                    &middot; Click <strong>"Show Route"</strong> between stops for the road distance, time and directions.
                </span>
            </div>

            <!-- Day tabs -->
            <div class="day-tabs" id="dayTabs">
                <?php for ($d = 1; $d <= $totalDays; $d++):
                    $col = $dayColors[$d] ?? '#6366f1';
                    $dateLabel = '';
                    if ($startDate) {
                        $ts = strtotime($startDate . ' +' . ($d - 1) . ' days');
                        $dateLabel = ' | ' . date('d M', $ts);
                    }
                ?>
                <button type="button" class="day-tab <?php echo $d === 1 ? 'active' : ''; ?>"
                        id="tab-<?php echo $d; ?>"
                        style="<?php echo $d === 1 ? "background:{$col}; border-color:{$col};" : ''; ?>"
                        data-day-tab="<?php echo $d; ?>">
                    Day <?php echo $d; ?><?php echo $dateLabel; ?>
                </button>
                <?php endfor; ?>
            </div>

            <!-- Day schedule boxes -->
            <?php for ($d = 1; $d <= $totalDays; $d++):
                $col = $dayColors[$d] ?? '#6366f1';
                $dateStr = '';
                if ($startDate) {
                    $ts = strtotime($startDate . ' +' . ($d - 1) . ' days');
                    $dateStr = date('l, d F Y', $ts);
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
                        <div class="day-box-title">Day <?php echo $d; ?></div>
                        <?php if ($dateStr): ?><div class="day-box-date"><?php echo $dateStr; ?></div><?php endif; ?>
                        <div class="day-box-note">
                            <?php echo $d === 1 ? 'Starts from your starting location' : 'Starts where the day before ended'; ?>
                        </div>
                    </div>
                </div>

                <?php if (empty($schedule)): ?>
                    <p class="meta">No places scheduled for this day.</p>
                <?php else: ?>
                <div class="table-scroll mobile-card-table-wrap">
                <table class="timetable mobile-card-table">
                    <thead>
                        <tr>
                            <th style="width:90px;">Time</th>
                            <th style="width:30px;">#</th>
                            <th>Place &amp; Activity</th>
                            <th style="width:80px;">Type</th>
                            <th style="width:80px;">Cost (RM)</th>
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
                            $dayStartName = $originName !== '' ? $originName : 'Starting location';
                            if ($d > 1) {
                                $dayStartName = 'Where the day before ended';
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
                            <td data-label="Time">
                                <div style="font-weight:800; font-size:12px;">
                                    <?php echo (int)($firstTravelItem['_travel_min'] ?? 0) > 0
                                        ? minutesToTime((int)$firstTravelItem['_start_min'] - (int)$firstTravelItem['_travel_min'])
                                        : 'Before ' . $firstTravelItem['_start_fmt']; ?>
                                </div>
                                <div style="font-size:10.5px; color:#94a3b8;">depart</div>
                            </td>
                            <td data-label="#" style="font-weight:800; color:#64748b;">Start</td>
                            <td data-label="Place & Activity">
                                <div style="font-weight:700;">
                                    <?php echo htmlspecialchars($dayStartName); ?>
                                </div>
                                <div style="font-size:11px; color:#64748b; margin-top:2px; display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                                    <span>Travel to <?php echo htmlspecialchars($firstTravelItem['item_title']); ?></span>
                                    <!-- The planner's estimate; the map script replaces it with the road figures -->
                                    <span class="leg-est">
                                        <?php if ((int)$firstTravelItem['_travel_min'] > 0): ?>
                                            ~<?php echo (int)$firstTravelItem['_travel_min']; ?> min
                                        <?php endif; ?>
                                        <?php if ((float)($firstTravelItem['distance_km'] ?? 0) > 0): ?>
                                            &middot; <?php echo number_format((float)$firstTravelItem['distance_km'], 1); ?> km
                                        <?php endif; ?>
                                    </span>
                                    <button type="button" class="transit-summary-btn" data-leg-toggle="<?php echo (int)$firstTravelItem['item_id']; ?>">Show Route</button>
                                </div>
                                <div class="transit-detail"></div>
                            </td>
                            <td data-label="Type"><span class="type-badge">Origin</span></td>
                            <td data-label="Cost (RM)"><span style="color:#94a3b8;">Free</span></td>
                        </tr>
                        <?php endif; ?>
                        <?php foreach ($schedule as $idx => $item):
                            $typeBadgeClass = 'badge-' . strtolower($item['item_type'] ?? 'attraction');
                            $activity = activitySuggestion($item['item_type'] ?? 'attraction', $item['category'] ?? '');
                            $isHotel = strtolower((string)($item['item_type'] ?? '')) === 'hotel';
                            $displayLabel = $isHotel ? 'Stay' : (string)(++$displayNo);
                        ?>

                        <?php if ($idx > 0): ?>
                        <!-- ===== The leg from the stop before ===== -->
                        <tr class="transit-row" data-leg-to="<?php echo (int)$item['item_id']; ?>">
                            <td class="mobile-empty" colspan="5">
                                <div class="transit-row-inner">
                                    <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                                        <button type="button" class="transit-summary-btn" data-leg-toggle="<?php echo (int)$item['item_id']; ?>">Show Route</button>
                                        <!-- The planner's estimate; the map script replaces it with the road figures -->
                                        <span class="leg-est" style="font-size:11.5px; color:#64748b;">
                                            <?php if ($item['_travel_min'] > 0): ?>
                                                ~<?php echo (int)$item['_travel_min']; ?> min
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
                            <td data-label="Time">
                                <div style="font-weight:800; font-size:12px;"><?php echo $item['_start_fmt']; ?></div>
                                <div style="font-size:10.5px; color:#94a3b8;">to <?php echo $item['_end_fmt']; ?></div>
                            </td>
                            <td data-label="#" style="font-weight:800; color:<?php echo $isHotel ? '#64748b' : $col; ?>;"><?php echo htmlspecialchars($displayLabel); ?></td>
                            <td data-label="Place & Activity">
                                <div style="font-weight:700;"><?php echo htmlspecialchars($item['item_title']); ?></div>
                                <div style="font-size:11px; color:#64748b; margin-top:2px;">
                                    <?php echo $activity; ?>
                                    <?php if (!empty($item['address'])): ?>
                                        &middot; <?php echo htmlspecialchars($item['address']); ?>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($item['opening_hours'])): ?>
                                <div style="font-size:10.5px; color:#94a3b8; margin-top:2px;">Hours: <?php echo htmlspecialchars($item['opening_hours']); ?></div>
                                <?php endif; ?>
                                <?php if (!empty($item['state'])): ?>
                                <div style="font-size:10.5px; color:#94a3b8; margin-top:2px;">
                                    Location: <?php echo htmlspecialchars($item['district'] ? $item['district'] . ', ' . $item['state'] : $item['state']); ?>
                                </div>
                                <?php endif; ?>
                                <?php $reasonSelected = extractReasonSelected($item['notes'] ?? ''); ?>
                                <?php if ($reasonSelected !== '' && !$isHotel): ?>
                                <div style="font-size:10.5px; color:#475569; margin-top:4px;">
                                    <strong>Reason Selected:</strong> <?php echo htmlspecialchars($reasonSelected); ?>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td data-label="Type">
                                <span class="type-badge <?php echo htmlspecialchars($typeBadgeClass); ?>">
                                    <?php echo htmlspecialchars($isHotel ? 'Hotel / Check-in' : ucfirst($item['item_type'] ?? 'attraction')); ?>
                                </span>
                            </td>
                            <td data-label="Cost (RM)" style="font-weight:700;">
                                <?php if ((float)($item['estimated_cost'] ?? 0) > 0): ?>
                                    <?php $costLabel = CostEstimationService::itemCostLabel((string)($item['item_type'] ?? ''), (float)$item['estimated_cost'], $partySize); ?>
                                    <div><?php echo htmlspecialchars($costLabel['whole_label']); ?></div>
                                    <div style="font-size:10.5px; color:#64748b; font-weight:400; margin-top:2px;"><?php echo htmlspecialchars($costLabel['unit_label']); ?></div>
                                <?php else: ?>
                                    <span style="color:#94a3b8;">Free</span>
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
                    Day <?php echo $d; ?> scheduled places, whole party: RM <?php echo number_format($dayTotal, 2); ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endfor; ?>
        </div>

    </main>
</div>

<button type="button" class="ai-chat-fab" onclick="toggleAiChat()">AI Assistant</button>
<div class="ai-chat-panel" id="aiChatPanel" aria-live="polite" data-itinerary-id="<?php echo (int)$itineraryId; ?>" data-missing-info="<?php echo htmlspecialchars($missingInfoJson, ENT_QUOTES); ?>">
    <div class="ai-chat-header">
        <div>
            <div class="ai-chat-title">AI Travel Assistant</div>
            <div class="ai-chat-subtitle">Chat about weather, hotels, dates, route changes, cost, or trip details.</div>
        </div>
        <button type="button" class="ai-chat-close" onclick="toggleAiChat()" aria-label="Close AI chat">&times;</button>
    </div>
    <div class="ai-chat-body" id="aiChatBody">
        <div class="ai-msg bot" id="aiInitialMessage">Hi, tell me what you need for this trip. I can discuss hotels, weather, dates, route changes, cost, and place suggestions. I will only save changes after you click a confirmation button.</div>
    </div>
    <form class="ai-chat-form" onsubmit="sendAiMessage(event)">
        <input id="aiChatInput" type="text" maxlength="700" autocomplete="off" placeholder="Type your message to the AI chatbot...">
        <button type="submit">Send</button>
    </form>
</div>

<script src="../assets/trip_ai_chat.js?v=20261112b"></script>
<script>
// What assets/itinerary_map.js draws.
const ITINERARY_ID = <?php echo $itineraryId; ?>;
const TOTAL_DAYS = <?php echo $totalDays; ?>;
const DAYS_DATA = <?php echo $jsDays; ?>;
const DAY_COLORS = <?php echo $jsColorsJson; ?>;
const START_TRANSPORT = <?php echo $jsTransport; ?>;
</script>
<script src="../assets/itinerary_map.js?v=20261112c"></script>
  <script src="../assets/dashboard_shell.js?v=20260617c"></script>
</body>
</html>

