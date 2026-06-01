<?php
// itinerary/itinerary_view.php
// Moovit-style transit panel: MRT/LRT/KTM/Monorail/Bus/Walk step-by-step directions,
// transfer details, estimated times, total journey duration/distance per leg.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once "../config/db_connect.php";
require_once "../config/api_keys.php";
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
$stmt = $conn->prepare("
    SELECT i.*, tp.transport_type, tp.budget, tp.budget_tier, tp.traveller_type
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

// Hotel recommendations are loaded live from Google Places in Trip Summary/AI Assistant.

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
        'dist_km'      => $item['distance_km'] !== null ? (float)$item['distance_km'] : null,
        'travel_min'   => $item['_travel_min'],
        'start_fmt'    => $item['_start_fmt'],
        'end_fmt'      => $item['_end_fmt'],
        'opening_hours'=> $item['opening_hours'] ?? '',
    ], $items);
}

$jsHotels     = [];

$jsDays        = json_encode($jsItineraryData, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '[]';
$jsHotelsJson  = json_encode($jsHotels, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '[]';
$jsColorsJson  = json_encode($dayColors);
$jsTransport   = json_encode($transportType);
$googleMapsKey = defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : '';
$openWeatherKey= defined('OPENWEATHER_API_KEY') ? OPENWEATHER_API_KEY : '';

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
$travellerType = strtolower((string)($it["traveller_type"] ?? "solo"));
$tierDefaults = CostEstimationService::budgetTierDefaults($budgetTier, $budget, $totalDays);
$costService = new CostEstimationService($transportType, $totalDays, $budget, $travellerType);
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
    <link rel="stylesheet" href="../assets/dashboard_style.css">
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

        /* ===== AI assistant chat ===== */
        .ai-chat-fab {
            position: fixed;
            right: 22px;
            bottom: 22px;
            z-index: 60;
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
            z-index: 60;
            width: min(390px, calc(100vw - 32px));
            max-height: 560px;
            display: none;
            flex-direction: column;
            overflow: hidden;
            background: #fff;
            border: 1px solid rgba(15,23,42,0.12);
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
            padding: 9px 11px;
            border-radius: 10px;
            margin-bottom: 9px;
        }
        .ai-msg.user {
            margin-left: auto;
            background: #4f46e5;
            color: #fff;
        }
        .ai-msg.bot {
            margin-right: auto;
            background: #fff;
            color: #334155;
            border: 1px solid rgba(15,23,42,0.08);
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
                <form method="post" action="share_create.php" style="display:inline;">
                    <input type="hidden" name="itinerary_id" value="<?php echo $itineraryId; ?>">
                    <button type="submit" class="btn btn-ghost">Share Link</button>
                </form>
            </div>
        </div>

        <!-- ===== Map + Sidebar Grid ===== -->
        <div class="iv-grid">

            <!-- LEFT: Map Panel -->
            <div>
                <div class="card" style="padding:16px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; margin-bottom:10px;">
                        <h3 style="margin:0;">Route Map - All Days</h3>
                        <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                            <span class="weather-chip" id="weatherChip">Weather: --</span>
                            <button class="btn btn-ghost" style="font-size:12px; padding:6px 12px;" onclick="locateMe()">My Location</button>
                        </div>
                    </div>

                    <!-- Transport mode switcher -->
                    <div class="transport-bar" id="transportBar">
                        <button class="transport-btn <?php echo $transportType==='car'              ? 'active' : ''; ?>" onclick="setTransport('car')">Car</button>
                        <button class="transport-btn <?php echo $transportType==='motorcycle'       ? 'active' : ''; ?>" onclick="setTransport('motorcycle')">Motorcycle</button>
                        <button class="transport-btn <?php echo $transportType==='public_transport' ? 'active' : ''; ?>" onclick="setTransport('public_transport')">Public Transport</button>
                        <button class="transport-btn <?php echo $transportType==='walking'          ? 'active' : ''; ?>" onclick="setTransport('walking')">Walk</button>
                    </div>

                    <!-- Day legend -->
                    <div class="day-legend" id="dayLegend">
                        <?php for ($d = 1; $d <= $totalDays; $d++):
                            $col = $dayColors[$d] ?? '#6366f1'; ?>
                        <span class="legend-item" id="legend-<?php echo $d; ?>"
                              style="color:<?php echo $col; ?>; border-color:<?php echo $col; ?>; background:<?php echo $col; ?>18;"
                              onclick="toggleDay(<?php echo $d; ?>)">
                            <span class="legend-dot" style="background:<?php echo $col; ?>;"></span>
                            Day <?php echo $d; ?>
                        </span>
                        <?php endfor; ?>
                        <span class="legend-item" style="color:#4f46e5; border-color:#4f46e5; background:#4f46e518;">
                            <span class="legend-dot" style="background:#4f46e5;"></span> My Location
                        </span>
                    </div>

                    <div class="map-wrap">
                        <div id="map"></div>
                        <div id="mapErrorPanel" class="map-error-panel" role="alert">
                            <div class="map-error-card">
                                <strong>Google Maps could not load.</strong>
                                <div id="mapErrorText">
                                    Check that the API key enables Maps JavaScript API and allows this localhost URL.
                                </div>
                            </div>
                        </div>
                    </div>
                    <div id="routeNotice" class="map-route-notice"></div>

                    <div class="info-box" style="margin-top:10px;">
                        <strong>How to read this map:</strong>
                        Each coloured line is one day's route. Day 1 starts from your detected location. Day 2+ continues from the previous night's hotel. Click any marker for details. Switch transport mode to re-render routes.
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
                    Transport: <strong><?php echo htmlspecialchars(str_replace('_', ' ', ucfirst($transportType))); ?></strong>
                    &middot; Click <strong>"Show Route"</strong> between stops for step-by-step directions.
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
                <button class="day-tab <?php echo $d === 1 ? 'active' : ''; ?>"
                        id="tab-<?php echo $d; ?>"
                        style="<?php echo $d === 1 ? "background:{$col}; border-color:{$col};" : ''; ?>"
                        onclick="showDay(<?php echo $d; ?>)">
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
                            <?php echo $d === 1 ? 'Starts from your location' : 'Continues from previous night hotel'; ?>
                        </div>
                    </div>
                </div>

                <?php if (empty($schedule)): ?>
                    <p class="meta">No places scheduled for this day.</p>
                <?php else: ?>
                <div class="table-scroll">
                <table class="timetable">
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
                            $showOriginRow = $firstTravelItem
                                && ($d === 1
                                    || (int)($firstTravelItem['_travel_min'] ?? 0) > 0
                                    || ((float)($firstTravelItem['dist_km'] ?? $firstTravelItem['distance_km'] ?? 0) > 0));
                            $displayNo = 0;
                        ?>
                        <?php if ($showOriginRow): ?>
                        <tr>
                            <td>
                                <div style="font-weight:800; font-size:12px;">
                                    <?php echo (int)($firstTravelItem['_travel_min'] ?? 0) > 0
                                        ? minutesToTime((int)$firstTravelItem['_start_min'] - (int)$firstTravelItem['_travel_min'])
                                        : 'Before ' . $firstTravelItem['_start_fmt']; ?>
                                </div>
                                <div style="font-size:10.5px; color:#94a3b8;">depart</div>
                            </td>
                            <td style="font-weight:800; color:#64748b;">Start</td>
                            <td>
                                <div style="font-weight:700;">
                                    <?php echo $d === 1
                                        ? htmlspecialchars($originName !== '' ? $originName : 'Starting location')
                                        : "Previous night's hotel"; ?>
                                </div>
                                <div style="font-size:11px; color:#64748b; margin-top:2px;">
                                    <?php if ((int)($firstTravelItem['_travel_min'] ?? 0) > 0 || (float)($firstTravelItem['dist_km'] ?? $firstTravelItem['distance_km'] ?? 0) > 0): ?>
                                        Travel to <?php echo htmlspecialchars($firstTravelItem['item_title']); ?>
                                        <?php if ((int)$firstTravelItem['_travel_min'] > 0): ?>
                                            &middot; <?php echo (int)$firstTravelItem['_travel_min']; ?> min
                                        <?php endif; ?>
                                        <?php if ((float)($firstTravelItem['dist_km'] ?? $firstTravelItem['distance_km'] ?? 0) > 0): ?>
                                            &middot; <?php echo number_format((float)($firstTravelItem['dist_km'] ?? $firstTravelItem['distance_km'] ?? 0), 1); ?> km
                                        <?php endif; ?>
                                    <?php else: ?>
                                        Route to first stop was not recorded for this itinerary.
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td><span class="type-badge">Origin</span></td>
                            <td><span style="color:#94a3b8;">Free</span></td>
                        </tr>
                        <?php endif; ?>
                        <?php foreach ($schedule as $idx => $item):
                            $typeBadgeClass = 'badge-' . strtolower($item['item_type'] ?? 'attraction');
                            $activity = activitySuggestion($item['item_type'] ?? 'attraction', $item['category'] ?? '');
                            $prevItem = $idx > 0 ? $schedule[$idx - 1] : null;
                            $legId    = "leg-{$d}-{$idx}";
                            $isHotel = strtolower((string)($item['item_type'] ?? '')) === 'hotel';
                            $displayLabel = $isHotel ? 'Stay' : (string)(++$displayNo);
                        ?>

                        <?php if ($idx > 0): ?>
                        <!-- ===== Transit row between stops ===== -->
                        <tr class="transit-row" id="transit-row-<?php echo $legId; ?>">
                            <td colspan="5">
                                <div class="transit-row-inner">
                                    <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                                        <button class="transit-summary-btn"
                                                id="transit-btn-<?php echo $legId; ?>"
                                                onclick="loadTransitLeg('<?php echo $legId; ?>',
                                                    <?php echo (float)($prevItem['latitude'] ?? 0); ?>,
                                                    <?php echo (float)($prevItem['longitude'] ?? 0); ?>,
                                                    <?php echo (float)($item['latitude'] ?? 0); ?>,
                                                    <?php echo (float)($item['longitude'] ?? 0); ?>,
                                                    '<?php echo addslashes(htmlspecialchars($prevItem['item_title'] ?? '')); ?>',
                                                    '<?php echo addslashes(htmlspecialchars($item['item_title'] ?? '')); ?>'
                                                )">
                                            Show Route
                                        </button>
                                        <!-- Pre-computed estimate -->
                                        <span style="font-size:11.5px; color:#64748b;" id="transit-est-<?php echo $legId; ?>">
                                            <?php if ($item['_travel_min'] > 0): ?>
                                                ~<?php echo $item['_travel_min']; ?> min
                                                <?php if ($item['dist_km'] ?? $item['distance_km'] ?? null): ?>
                                                    &middot; <?php echo number_format((float)($item['dist_km'] ?? $item['distance_km'] ?? 0), 1); ?> km
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </span>
                                        <!-- Transit chips (filled by JS) -->
                                        <span class="transit-chips" id="transit-chips-<?php echo $legId; ?>"></span>
                                    </div>
                                    <!-- Detail panel (expanded by JS) -->
                                    <div class="transit-detail" id="transit-detail-<?php echo $legId; ?>"></div>
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>

                        <!-- ===== Place row ===== -->
                        <tr data-item-id="<?php echo (int)$item['item_id']; ?>"
                            data-lat="<?php echo htmlspecialchars((string)($item['latitude'] ?? '')); ?>"
                            data-lng="<?php echo htmlspecialchars((string)($item['longitude'] ?? '')); ?>"
                            onclick="focusPlace(<?php echo (float)($item['latitude'] ?? 0); ?>, <?php echo (float)($item['longitude'] ?? 0); ?>, '<?php echo addslashes(htmlspecialchars($item['item_title'])); ?>')"
                            style="cursor:pointer;">
                            <td>
                                <div style="font-weight:800; font-size:12px;"><?php echo $item['_start_fmt']; ?></div>
                                <div style="font-size:10.5px; color:#94a3b8;">to <?php echo $item['_end_fmt']; ?></div>
                            </td>
                            <td style="font-weight:800; color:<?php echo $isHotel ? '#64748b' : $col; ?>;"><?php echo htmlspecialchars($displayLabel); ?></td>
                            <td>
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
                            <td>
                                <span class="type-badge <?php echo htmlspecialchars($typeBadgeClass); ?>">
                                    <?php echo htmlspecialchars($isHotel ? 'Hotel / Check-in' : ucfirst($item['item_type'] ?? 'attraction')); ?>
                                </span>
                            </td>
                            <td style="font-weight:700;">
                                <?php echo (float)($item['estimated_cost'] ?? 0) > 0
                                    ? 'RM ' . number_format((float)$item['estimated_cost'], 2)
                                    : '<span style="color:#94a3b8;">Free</span>'; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>

                <!-- Day total -->
                <?php $dayTotal = array_sum(array_column($schedule, 'estimated_cost')); ?>
                <div style="margin-top:10px; text-align:right; font-size:13px; font-weight:800; color:#4f46e5;">
                    Day <?php echo $d; ?> Total: RM <?php echo number_format($dayTotal, 2); ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endfor; ?>
        </div>

    </main>
</div>

<button type="button" class="ai-chat-fab" onclick="toggleAiChat()">AI Assistant</button>
<div class="ai-chat-panel" id="aiChatPanel" aria-live="polite">
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

<!-- ===== JavaScript ===== -->
<script>
// ---- Data from PHP ----
const ITINERARY_ID = <?php echo $itineraryId; ?>;
const DAYS_DATA    = <?php echo $jsDays; ?>;
const HOTELS_DATA  = <?php echo $jsHotelsJson; ?>;
const DAY_COLORS   = <?php echo $jsColorsJson; ?>;
const TOTAL_DAYS   = <?php echo $totalDays; ?>;
const WEATHER_KEY  = "<?php echo addslashes($openWeatherKey); ?>";
const MISSING_INFO = <?php echo $missingInfoJson; ?>;

// ---- State ----
let map, infoWindow;
let userLat = null, userLng = null;
let userMarker = null;
let hotelMarkers = [];
let dayRenderers = {};
let dayMarkers   = {};
let dayVisible   = {};
let currentTransport = <?php echo $jsTransport; ?>;
let googleMapLoaded = false;
let ACTIVE_DAY = 1;

document.addEventListener('DOMContentLoaded', () => {
    const initial = document.getElementById('aiInitialMessage');
    if (initial && MISSING_INFO.length) {
        initial.textContent = 'Before this itinerary is complete, I still need: ' + MISSING_INFO.join(', ') + '. Tell me the missing details here, then confirm the detected information.';
        const panel = document.getElementById('aiChatPanel');
        if (panel) panel.classList.add('open');
        const input = document.getElementById('aiChatInput');
        if (input) setTimeout(() => input.focus(), 120);
    }
});

function showMapLoadError(message) {
    const panel = document.getElementById('mapErrorPanel');
    const text = document.getElementById('mapErrorText');
    if (!panel || !text) return;
    text.innerHTML = message;
    panel.classList.add('visible');
}

function hideMapLoadError() {
    const panel = document.getElementById('mapErrorPanel');
    if (panel) panel.classList.remove('visible');
}

window.gm_authFailure = function() {
    showMapLoadError(
        'Google rejected this browser request. In Google Cloud Console, enable <code>Maps JavaScript API</code>, attach billing, and allow <code>http://localhost/*</code> plus <code>http://127.0.0.1/*</code> in the API key HTTP referrer restrictions.'
    );
};

// ---- Init map ----
function initMap() {
    googleMapLoaded = true;
    hideMapLoadError();
    map = new google.maps.Map(document.getElementById('map'), {
        zoom: 10,
        center: { lat: 3.8, lng: 109.0 },
        mapTypeControl: true,
        fullscreenControl: true,
        streetViewControl: false,
    });
    infoWindow = new google.maps.InfoWindow();

    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
            pos => {
                userLat = pos.coords.latitude;
                userLng = pos.coords.longitude;
                placeUserMarker(userLat, userLng);
                renderAllDays();
                loadWeather(userLat, userLng);
            },
            () => renderAllDays(),
            { timeout: 8000, maximumAge: 60000 }
        );
    } else {
        renderAllDays();
    }
    placeHotelMarkers();
}

// ---- User location marker ----
function placeUserMarker(lat, lng) {
    if (userMarker) userMarker.setMap(null);
    userMarker = new google.maps.Marker({
        position: { lat, lng }, map,
        title: 'My Location',
        icon: {
            path: google.maps.SymbolPath.CIRCLE,
            scale: 10,
            fillColor: '#4f46e5', fillOpacity: 1,
            strokeColor: '#fff', strokeWeight: 3,
        },
        zIndex: 999,
    });
    userMarker.addListener('click', () => {
        infoWindow.setContent('<div style="font-weight:800;color:#4f46e5;">My Location</div>');
        infoWindow.open(map, userMarker);
    });
}

// ---- Hotel markers ----
function placeHotelMarkers() {
    hotelMarkers.forEach(m => m.setMap(null));
    hotelMarkers = [];
    HOTELS_DATA.forEach(h => {
        if (!h.lat || !h.lng) return;
        const m = new google.maps.Marker({
            position: { lat: h.lat, lng: h.lng }, map,
            title: h.name,
            icon: {
                path: google.maps.SymbolPath.CIRCLE,
                scale: 8,
                fillColor: '#16a34a', fillOpacity: 0.9,
                strokeColor: '#fff', strokeWeight: 2,
            },
        });
        m.addListener('click', () => {
            infoWindow.setContent(
                `<div style="max-width:200px;">
                    <div style="font-weight:800;">Hotel: ${h.name}</div>
                    <div style="font-size:11px;color:#64748b;margin-top:3px;">${h.district ? h.district + ', ' : ''}${h.state}</div>
                    <div style="font-size:12px;font-weight:700;color:#4f46e5;margin-top:4px;">RM${h.price.toFixed(0)}/night | Rating ${h.rating.toFixed(1)}</div>
                </div>`
            );
            infoWindow.open(map, m);
        });
        hotelMarkers.push(m);
    });
}

// ---- Render all days ----
function renderAllDays() {
    clearRouteNotice();
    for (let d = 1; d <= TOTAL_DAYS; d++) {
        dayVisible[d] = true;
        renderDay(d);
    }
    setTimeout(fitAllBounds, 600);
}

// ---- Render one day ----
function renderDay(day) {
    clearDayRoutes(day);
    if (dayMarkers[day]) {
        dayMarkers[day].forEach(m => m.setMap(null));
        delete dayMarkers[day];
    }
    if (!dayVisible[day]) return;

    const items = DAYS_DATA[day] || [];
    const color = DAY_COLORS[day] || '#6366f1';

    let originLatLng = null;
    if (day === 1 && userLat !== null) {
        originLatLng = { lat: userLat, lng: userLng };
    } else if (day > 1) {
        const prevItems = DAYS_DATA[day - 1] || [];
        for (let i = prevItems.length - 1; i >= 0; i--) {
            if (prevItems[i].lat && prevItems[i].lng) {
                originLatLng = { lat: prevItems[i].lat, lng: prevItems[i].lng };
                break;
            }
        }
    }

    const validItems = items.filter(it => it.lat && it.lng);
    if (validItems.length === 0) return;

    dayMarkers[day] = [];
    const allPoints = [];
    if (originLatLng && day === 1) allPoints.push(originLatLng);

    validItems.forEach((item, idx) => {
        const pos = { lat: item.lat, lng: item.lng };
        allPoints.push(pos);
        const label = String.fromCharCode(65 + idx);
        const marker = new google.maps.Marker({
            position: pos, map,
            label: { text: label, color: '#fff', fontWeight: 'bold', fontSize: '12px' },
            title: item.title,
            icon: {
                path: google.maps.SymbolPath.CIRCLE,
                scale: 14,
                fillColor: color, fillOpacity: 0.95,
                strokeColor: '#fff', strokeWeight: 2,
            },
        });
        const typeLabel = { food: 'Food', hotel: 'Hotel', festival: 'Festival', museum: 'Museum', heritage: 'Heritage', culture: 'Culture', nature: 'Nature', attraction: 'Place' };
        const markerLabel = typeLabel[item.type] || 'Place';
        marker.addListener('click', () => {
            infoWindow.setContent(
                `<div style="max-width:230px;">
                    <div style="font-weight:800;font-size:13px;">${markerLabel}: ${escHtml(item.title)}</div>
                    <div style="font-size:11px;color:#64748b;margin-top:4px;">Day ${day} | Stop ${label}</div>
                    ${item.address ? `<div style="font-size:11px;margin-top:4px;">Address: ${escHtml(item.address)}</div>` : ''}
                    ${item.opening_hours ? `<div style="font-size:11px;margin-top:4px;">Hours: ${escHtml(item.opening_hours)}</div>` : ''}
                    <div style="font-size:11px;margin-top:4px;">${item.start_fmt} - ${item.end_fmt}${item.cost > 0 ? ` | RM${item.cost.toFixed(2)}` : ' | Free'}</div>
                </div>`
            );
            infoWindow.open(map, marker);
        });
        dayMarkers[day].push(marker);
    });

    if (allPoints.length >= 2) {
        const travelMode  = getTravelMode(currentTransport);
        if (currentTransport === 'public_transport') {
            renderSegmentedTransitRoute(day, allPoints, color);
        } else {
            renderWaypointRoute(day, allPoints, color, travelMode);
        }
    }
}

function clearDayRoutes(day) {
    const routes = dayRenderers[day];
    if (!routes) return;
    const list = Array.isArray(routes) ? routes : [routes];
    list.forEach(r => {
        if (!r) return;
        if (r.setMap) r.setMap(null);
        if (r._poly) r._poly.setMap(null);
    });
    delete dayRenderers[day];
}

function addDayRoute(day, routeObj) {
    if (!dayRenderers[day]) dayRenderers[day] = [];
    dayRenderers[day].push(routeObj);
}

function renderWaypointRoute(day, allPoints, color, travelMode) {
    const ds = new google.maps.DirectionsService();
    const dr = new google.maps.DirectionsRenderer({
        map,
        suppressMarkers: true,
        polylineOptions: { strokeColor: color, strokeWeight: 4, strokeOpacity: 0.85 },
    });
    addDayRoute(day, dr);

    const origin = allPoints[0];
    const destination = allPoints[allPoints.length - 1];
    const waypoints = allPoints.slice(1, -1).map(p => ({ location: p, stopover: true }));

    ds.route({ origin, destination, waypoints, travelMode, optimizeWaypoints: false },
        (result, status) => {
            if (status === 'OK') {
                dr.setDirections(result);
            } else {
                dr.setMap(null);
                drawStraightFallback(day, allPoints, color, 'Google could not route this travel mode for one day, so a straight fallback line is shown.');
            }
        }
    );
}

function renderSegmentedTransitRoute(day, allPoints, color) {
    const ds = new google.maps.DirectionsService();
    for (let i = 0; i < allPoints.length - 1; i++) {
        const origin = allPoints[i];
        const destination = allPoints[i + 1];
        const dr = new google.maps.DirectionsRenderer({
            map,
            suppressMarkers: true,
            preserveViewport: true,
            polylineOptions: { strokeColor: color, strokeWeight: 5, strokeOpacity: 0.88 },
        });
        addDayRoute(day, dr);

        ds.route({ origin, destination, travelMode: google.maps.TravelMode.TRANSIT },
            (result, status) => {
                if (status === 'OK') {
                    dr.setDirections(result);
                } else {
                    dr.setMap(null);
                    drawStraightFallback(day, [origin, destination], color, 'Google Transit has no bus/train route for one or more stops, so that leg is shown as a straight fallback line.');
                }
            }
        );
    }
}

function drawStraightFallback(day, points, color, message) {
    const poly = new google.maps.Polyline({
        path: points,
        map,
        strokeColor: color,
        strokeWeight: 3,
        strokeOpacity: 0.65,
        strokePattern: 'dashed',
        icons: [{ icon: { path: google.maps.SymbolPath.FORWARD_CLOSED_ARROW, scale: 3 }, offset: '50%' }],
    });
    addDayRoute(day, { _poly: poly });
    setRouteNotice(message);
}

function setRouteNotice(message) {
    const el = document.getElementById('routeNotice');
    if (!el) return;
    el.textContent = message;
    el.classList.add('visible');
}

function clearRouteNotice() {
    const el = document.getElementById('routeNotice');
    if (!el) return;
    el.textContent = '';
    el.classList.remove('visible');
}

function getTravelMode(mode) {
    mode = normalizeTransportMode(mode);
    const m = {
        car: google.maps.TravelMode.DRIVING,
        motorcycle: google.maps.TravelMode.DRIVING,
        public_transport: google.maps.TravelMode.TRANSIT,
        walking: google.maps.TravelMode.WALKING,
    };
    return m[mode] || google.maps.TravelMode.DRIVING;
}

function fitAllBounds() {
    const bounds = new google.maps.LatLngBounds();
    let has = false;
    if (userLat !== null) { bounds.extend({ lat: userLat, lng: userLng }); has = true; }
    for (let d = 1; d <= TOTAL_DAYS; d++) {
        (DAYS_DATA[d] || []).forEach(it => { if (it.lat && it.lng) { bounds.extend({ lat: it.lat, lng: it.lng }); has = true; } });
    }
    HOTELS_DATA.forEach(h => { if (h.lat && h.lng) bounds.extend({ lat: h.lat, lng: h.lng }); });
    if (has) map.fitBounds(bounds, { padding: 60 });
}

function toggleDay(day) {
    dayVisible[day] = !dayVisible[day];
    const legend = document.getElementById('legend-' + day);
    if (legend) legend.style.opacity = dayVisible[day] ? '1' : '0.35';
    renderDay(day);
}

function showDay(d) {
    ACTIVE_DAY = d;
    document.querySelectorAll('.day-box').forEach(el => el.style.display = 'none');
    const box = document.getElementById('day-' + d);
    if (box) box.style.display = 'block';
    const col = DAY_COLORS[d] || '#6366f1';
    document.querySelectorAll('.day-tab').forEach((btn, i) => {
        const isActive = (i + 1) === d;
        btn.classList.toggle('active', isActive);
        btn.style.background  = isActive ? col : '';
        btn.style.borderColor = isActive ? col : '';
        btn.style.color       = isActive ? '#fff' : '';
    });
    const items = (DAYS_DATA[d] || []).filter(it => it.lat && it.lng);
    if (items.length > 0 && map) { map.panTo({ lat: items[0].lat, lng: items[0].lng }); map.setZoom(12); }
}

function setTransport(mode) {
    mode = normalizeTransportMode(mode);
    currentTransport = mode;
    clearRouteNotice();
    document.querySelectorAll('.transport-btn').forEach(btn => btn.classList.remove('active'));
    const modeMap = { car: 0, motorcycle: 1, public_transport: 2, walking: 3 };
    const btns = document.querySelectorAll('.transport-btn');
    if (btns[modeMap[mode]]) btns[modeMap[mode]].classList.add('active');
    for (let d = 1; d <= TOTAL_DAYS; d++) renderDay(d);

    // Update mode label
    const labels = { car: 'Car', motorcycle: 'Motorcycle', public_transport: 'Public Transport', walking: 'Walk' };
    const lbl = document.getElementById('transitModeLabel');
    if (lbl) {
        lbl.innerHTML = `Transport: <strong>${labels[mode] || mode}</strong> &middot; Click <strong>"Show Route"</strong> between stops for step-by-step directions.`;
    }
}

function normalizeTransportMode(mode) {
    const m = String(mode || 'car').toLowerCase().trim().replace(/[-\s]+/g, '_');
    if (['public', 'public_transport', 'publictransit', 'public_transit', 'transit', 'bus', 'train'].includes(m)) return 'public_transport';
    if (['drive', 'driving'].includes(m)) return 'car';
    if (m === 'walk') return 'walking';
    if (m === 'motorbike' || m === 'bike') return 'motorcycle';
    return ['car', 'motorcycle', 'public_transport', 'walking'].includes(m) ? m : 'car';
}

function focusPlace(lat, lng, title) {
    if (!map || !lat || !lng) return;
    map.panTo({ lat, lng }); map.setZoom(15);
}

function focusHotel(lat, lng, name) {
    if (!map || !lat || !lng) return;
    map.panTo({ lat, lng }); map.setZoom(15);
    infoWindow.setContent(`<div style="font-weight:800;">Hotel: ${escHtml(name)}</div>`);
    const hm = hotelMarkers.find(m => {
        const p = m.getPosition();
        return Math.abs(p.lat() - lat) < 0.0001 && Math.abs(p.lng() - lng) < 0.0001;
    });
    if (hm) infoWindow.open(map, hm);
}

function locateMe() {
    if (!navigator.geolocation) { alert('Geolocation not supported.'); return; }
    navigator.geolocation.getCurrentPosition(
        pos => {
            userLat = pos.coords.latitude;
            userLng = pos.coords.longitude;
            placeUserMarker(userLat, userLng);
            renderDay(1);
            loadWeather(userLat, userLng);
        },
        () => alert('Could not get your location. Please allow location access.')
    );
}

// ---- Weather ----
async function loadWeather(lat, lng) {
    const chip = document.getElementById('weatherChip');
    if (!WEATHER_KEY || WEATHER_KEY.includes('PASTE_')) { chip.textContent = 'Weather: N/A'; return; }
    try {
        const r = await fetch(`https://api.openweathermap.org/data/2.5/weather?lat=${lat}&lon=${lng}&appid=${WEATHER_KEY}&units=metric`);
        const j = await r.json();
        const desc = j.weather?.[0]?.description || 'Unknown';
        const temp = j.main?.temp !== undefined ? Math.round(j.main.temp) + ' C' : '';
        const icon = j.weather?.[0]?.icon ? `<img src="https://openweathermap.org/img/wn/${j.weather[0].icon}.png" style="width:20px;height:20px;vertical-align:middle;">` : '';
        chip.innerHTML = `${icon} ${capitalise(desc)} ${temp}`;
        if (['Rain','Thunderstorm','Drizzle'].includes(j.weather?.[0]?.main)) {
            chip.style.background = '#fef9c3'; chip.style.borderColor = '#fbbf24';
        }
    } catch(e) { chip.textContent = 'Weather: --'; }
}

function capitalise(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : s; }

// =========================================================
// ===== MOOVIT-STYLE TRANSIT PANEL =====
// =========================================================

// Cache to avoid re-fetching same leg
const transitCache = {};

async function loadTransitLeg(legId, oLat, oLng, dLat, dLng, fromTitle, toTitle) {
    const detailEl = document.getElementById('transit-detail-' + legId);
    const btnEl    = document.getElementById('transit-btn-' + legId);
    const chipsEl  = document.getElementById('transit-chips-' + legId);
    const estEl    = document.getElementById('transit-est-' + legId);

    if (!detailEl) return;

    // Toggle: if already open, close it
    if (detailEl.classList.contains('open')) {
        detailEl.classList.remove('open');
        detailEl.innerHTML = '';
        if (btnEl) btnEl.innerHTML = 'Show Route';
        return;
    }

    if (!oLat || !oLng || !dLat || !dLng) {
        detailEl.innerHTML = '<div class="ts-warning">Warning: Coordinates not available for this leg.</div>';
        detailEl.classList.add('open');
        return;
    }

    // Show loading
    detailEl.innerHTML = '<div class="ts-loading"><span class="spinner"></span> Fetching route details...</div>';
    detailEl.classList.add('open');
    if (btnEl) btnEl.innerHTML = 'Hide Route';

    const cacheKey = `${legId}|${currentTransport}`;
    let data = transitCache[cacheKey];

    if (!data) {
        try {
            const resp = await fetch('transit_route.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    o_lat: oLat, o_lng: oLng,
                    d_lat: dLat, d_lng: dLng,
                    mode:  currentTransport,
                    depart: 'now',
                })
            });
            data = await parseJsonResponse(resp);
            transitCache[cacheKey] = data;
        } catch(e) {
            detailEl.innerHTML = '<div class="ts-warning">Warning: Network error. Could not fetch route.</div>';
            return;
        }
    }

    if (data.status === 'error') {
        detailEl.innerHTML = `<div class="ts-warning">Warning: ${escHtml(data.message || 'Route not available.')}</div>`;
        return;
    }

    // Update estimate
    if (estEl && data.total_duration) {
        estEl.innerHTML = `<strong>${data.total_duration} min</strong> | ${data.total_distance} km`;
    }

    // Build transit chips
    if (chipsEl) {
        chipsEl.innerHTML = buildTransitChips(data.steps);
    }

    // Build full detail panel
    detailEl.innerHTML = buildTransitDetail(data, fromTitle, toTitle);
}

function buildTransitChips(steps) {
    if (!steps || steps.length === 0) return '';
    const chips = [];
    steps.forEach(step => {
        if (step.travel_mode === 'walking') {
            chips.push(`<span class="transit-chip transit-chip-walk">${step.duration_min}m walk</span>`);
        } else if (step.travel_mode === 'transit') {
            const color = step.line_color || '#4CAF50';
            const icon  = step.vehicle_icon || 'Bus';
            const name  = step.line_short || step.line_name || step.type_label || 'Transit';
            chips.push(`<span class="transit-chip" style="background:${escHtml(color)};">${icon} ${escHtml(name)}</span>`);
        } else {
            const modeIcon = { driving: 'Drive', walking: 'Walk', bicycling: 'Bike' };
            chips.push(`<span class="transit-chip transit-chip-walk">${modeIcon[step.travel_mode] || 'Drive'} ${step.duration_min}m</span>`);
        }
    });
    return chips.join('<span style="color:#94a3b8;font-size:10px;"> &gt; </span>');
}

function buildTransitDetail(data, fromTitle, toTitle) {
    let html = '';

    // Summary bar
    html += `<div class="ts-summary-bar">
        <div class="ts-summary-stat">Time <span>${data.total_duration} min total</span></div>
        <div class="ts-summary-stat">Distance <span>${data.total_distance} km</span></div>
        ${data.fare ? `<span class="ts-fare">Fare ${escHtml(data.fare)}</span>` : ''}
        ${data.summary ? `<span style="color:#64748b;font-size:11px;">${escHtml(data.summary)}</span>` : ''}
    </div>`;

    // Leg departure/arrival times
    if (data.legs && data.legs.length > 0) {
        const leg = data.legs[0];
        if (leg.departure_time || leg.arrival_time) {
            html += `<div style="font-size:11px; color:#64748b; margin-bottom:8px;">
                ${leg.departure_time ? `Depart: <strong>${escHtml(leg.departure_time)}</strong>` : ''}
                ${leg.arrival_time  ? ` &nbsp;-&gt;&nbsp; Arrive: <strong>${escHtml(leg.arrival_time)}</strong>` : ''}
            </div>`;
        }
    }

    // Steps
    if (!data.steps || data.steps.length === 0) {
        html += '<div style="font-size:12px;color:#64748b;">No detailed steps available.</div>';
    } else {
        html += '<div>';
        data.steps.forEach((step, idx) => {
            html += buildStepCard(step, idx, data.steps.length);
        });
        html += '</div>';
    }

    // Warnings
    if (data.warnings && data.warnings.length > 0) {
        data.warnings.forEach(w => {
            if (isMinorRouteWarning(w)) return;
            html += `<div class="ts-warning">Warning: ${escHtml(w)}</div>`;
        });
    }

    return html;
}

function isMinorRouteWarning(message) {
    const text = String(message || '').toLowerCase();
    return text.includes('walking directions are in beta')
        || text.includes('missing sidewalks')
        || text.includes('pedestrian paths');
}

function buildStepCard(step, idx, total) {
    const isTransit = step.travel_mode === 'transit';
    const isWalk    = step.travel_mode === 'walking';
    const isDrive   = ['driving', 'car', 'motorcycle'].includes(step.travel_mode);

    const iconBg = step.line_color || (isWalk ? '#64748b' : isDrive ? '#374151' : '#4CAF50');
    const icon   = step.vehicle_icon || (isWalk ? 'Walk' : isDrive ? 'Drive' : 'Bus');
    const modeLabel = step.type_label || (isWalk ? 'Walk' : isDrive ? 'Drive' : 'Transit');
    const iconText = escHtml(icon);
    const iconClass = /[\uD800-\uDBFF][\uDC00-\uDFFF]|[\u2600-\u27BF]/.test(String(icon)) ? 'ts-icon-emoji' : '';

    let html = `<div class="ts-step">
        <div class="ts-icon" style="background:${escHtml(iconBg)};"><span class="${iconClass}">${iconText}</span></div>
        <div class="ts-body">
            <span class="ts-mode-badge" style="background:${escHtml(iconBg)};">${escHtml(modeLabel)}</span>`;

    if (isTransit) {
        // Transit step: show line name, depart/arrive stop, num stops, headsign, times
        const lineName = step.line_name || step.line_short || modeLabel;
        html += `<div class="ts-line-name">${escHtml(lineName)}</div>`;

        if (step.headsign) {
            html += `<div style="font-size:11px;color:#64748b;">Direction: ${escHtml(step.headsign)}</div>`;
        }

        if (step.depart_stop) {
            html += `<div class="ts-stop-row">
                <span class="ts-stop-dot" style="border-color:${escHtml(iconBg)};background:${escHtml(iconBg)};"></span>
                <span style="font-weight:700;">${escHtml(step.depart_stop)}</span>
                ${step.departure_time ? `<span class="ts-time-badge">${escHtml(step.departure_time)}</span>` : ''}
            </div>`;
        }

        if (step.num_stops > 0) {
            html += `<div class="ts-stops">${step.num_stops} stop${step.num_stops > 1 ? 's' : ''} | ${step.duration_min} min</div>`;
        }

        if (step.arrive_stop) {
            html += `<div class="ts-stop-row">
                <span class="ts-stop-dot" style="border-color:${escHtml(iconBg)};"></span>
                <span style="font-weight:700;">${escHtml(step.arrive_stop)}</span>
                ${step.arrival_time ? `<span class="ts-time-badge">${escHtml(step.arrival_time)}</span>` : ''}
            </div>`;
        }

        if (step.agency) {
            html += `<div style="font-size:10.5px;color:#94a3b8;margin-top:3px;">Operated by: ${escHtml(step.agency)}</div>`;
        }

    } else if (isWalk) {
        // Walk step
        html += `<div class="ts-line-name">Walk ${step.distance_km > 0 ? step.distance_km + ' km' : ''}</div>`;
        html += `<div class="ts-stops">${step.duration_min} min | ${step.instruction ? escHtml(step.instruction) : 'Walk to next stop'}</div>`;

        if (step.sub_steps && step.sub_steps.length > 0) {
            html += '<div class="ts-walk-steps">';
            step.sub_steps.forEach(sub => {
                html += `<div class="ts-walk-step">-&gt; ${escHtml(sub.instruction)} (${sub.distance_km > 0 ? sub.distance_km + ' km | ' : ''}${sub.duration_min} min)</div>`;
            });
            html += '</div>';
        }

    } else {
        // Drive step
        html += `<div class="ts-line-name">${escHtml(step.instruction || 'Drive')}</div>`;
        html += `<div class="ts-stops">${step.duration_min} min | ${step.distance_km} km</div>`;
    }

    html += `</div></div>`;
    return html;
}

// ---- AI assistant chat ----
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

function clearAiPendingCards() {
    document.querySelectorAll('.ai-pending-card').forEach(card => card.remove());
}

async function sendAiMessage(event) {
    if (event) event.preventDefault();
    const input = document.getElementById('aiChatInput');
    if (!input) return;

    const text = input.value.trim();
    if (!text) return;
    input.value = '';

    addAiMessage('user', text);
    clearAiPendingCards();
    const loading = addAiMessage('bot', 'Writing answer...');

    try {
        const originIntent = /\b(starting\s+location|start\s+location|starting\s+point|start\s+point|origin|start\s+from|starting\s+from|depart\s+from|leave\s+from)\b/i.test(text);
        const dateIntent = /\b(start\s+date|travel\s+date|trip\s+date|travel\s+on|go\s+on|visit\s+on|arrive\s+on|depart\s+on|date)\b|\b\d{4}[\/\-.]\d{1,2}[\/\-.]\d{1,2}\b|\b\d{1,2}[\/\-.]\d{1,2}[\/\-.]\d{4}\b|\b\d{1,2}\s*[a-zA-Z]+\s*\d{4}\b|\b[a-zA-Z]+\s*\d{1,2}\s*\d{4}\b/i.test(text);
        const weatherIntent = /\b(weather|forecast|rain|raining|temperature|hot|humid|storm|umbrella)\b|天气|下雨|热/i.test(text);
        const smartHotelIntent = /\b(hotel|hotels|accommodation|stay|stays|room|rooms|sleep|overnight|check\s*in|nearby\s+hotel|budget\s+hotel|cheap\s+hotel|luxury\s+hotel|place\s+to\s+stay)\b|住宿|酒店|旅馆|旅店|民宿/i.test(text);
        const smartEditIntent = !originIntent && !dateIntent && !weatherIntent && /\b(replace|change|swap|modify|regenerate|replan|reroute|alternative|alternatives|better\s+stop|change\s+stop|arrange|empty|add|extra|fill|more\s+places?|another\s+place|new\s+place|remove|delete|skip|dislike|don't\s+want|do\s+not\s+want|too\s+far|too\s+expensive|nearest|nearby|improve|suggest\s+place|recommend\s+place|reduce\s+cost|cheaper|lower\s+cost|save\s+money|budget\s+friendly|day\s*\d+|day\d+)\b|更改|替换|换掉|换|改行程|重新推荐|重新安排|省钱|便宜|安排|添加|加|空|没有|补/i.test(text);
        const endpoint = smartHotelIntent ? '../api/ai_hotel_assistant.php' : (smartEditIntent ? '../api/ai_itinerary_editor.php' : '../api/ai_travel_assistant.php');
        const resp = await fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams((smartHotelIntent || smartEditIntent) ? {
                action: 'recommend',
                itinerary_id: ITINERARY_ID,
                message: text,
            } : {
                action: 'chat',
                itinerary_id: ITINERARY_ID,
                message: text,
            }),
        });
        const data = await parseJsonResponse(resp);
        if (loading) {
            loading.textContent = cleanAiText(data.answer || data.message || 'AI assistant could not answer this request.');
        }
        if (data.pending_actions && data.pending_actions.length) {
            const firstAction = data.pending_actions[0];
            if (data.pending_actions.length > 1) firstAction.next_action = data.pending_actions[1];
            renderPendingAction(loading, firstAction);
        } else if (data.pending_action) {
            renderPendingAction(loading, data.pending_action);
        }
        if (smartHotelIntent && data.hotels && data.hotels.length) {
            renderHotelCards(loading, data.hotels);
        }
        if (smartEditIntent && data.proposals && data.proposals.length) {
            renderChangeCards(loading, data.proposals);
        }
    } catch (e) {
        if (loading) loading.textContent = 'Network error. Please try again.';
    }
}

function cleanAiText(text) {
    return String(text || '')
        .replace(/\*{1,3}([^*]+)\*{1,3}/g, '$1')
        .replace(/^\s{0,3}#{1,6}\s*/gm, '')
        .replace(/[ \t]+\n/g, '\n')
        .trim();
}

function renderPendingAction(container, action) {
    if (!container || !action || (action.type !== 'update_start_date' && action.type !== 'update_origin')) return;
    const card = document.createElement('div');
    card.className = 'ai-pending-card';
    card.style.marginTop = '8px';
    card.style.padding = '9px';
    card.style.border = '1px solid rgba(79,70,229,.22)';
    card.style.borderRadius = '9px';
    card.style.background = '#eef2ff';

    const title = document.createElement('strong');
    title.style.display = 'block';
    title.style.color = '#0f172a';
    title.style.marginBottom = '4px';
    title.textContent = action.type === 'update_origin' ? 'Confirm starting location' : 'Confirm trip date';

    const meta = document.createElement('span');
    meta.style.display = 'block';
    meta.style.fontSize = '11px';
    meta.style.color = '#475569';
    meta.style.marginBottom = '8px';
    meta.textContent = action.summary || (action.type === 'update_origin'
        ? ('Update starting location to ' + (action.label || action.origin_name))
        : ('Update itinerary start date to ' + action.label));

    const button = document.createElement('button');
    button.type = 'button';
    button.style.border = '0';
    button.style.borderRadius = '8px';
    button.style.background = '#4f46e5';
    button.style.color = '#fff';
    button.style.padding = '6px 9px';
    button.style.fontSize = '11px';
    button.style.fontWeight = '900';
    button.style.cursor = 'pointer';
    button.textContent = action.type === 'update_origin' ? 'Confirm Location' : 'Confirm Date';
    button.addEventListener('click', () => {
        if (action.type === 'update_origin') {
            confirmTripOrigin(action.origin_name || action.label || '', button);
            return;
        }
        confirmTripDate(action.start_date, button, action.next_action || null);
    });

    card.appendChild(title);
    card.appendChild(meta);
    card.appendChild(button);
    container.appendChild(card);
    const body = document.getElementById('aiChatBody');
    if (body) body.scrollTop = body.scrollHeight;
}

function renderHotelCards(container, hotels) {
    if (!container) return;
    hotels.slice(0, 1).forEach((hotel) => {
        const card = document.createElement('div');
        card.className = 'ai-pending-card';
        card.style.marginTop = '8px';
        card.style.padding = '9px';
        card.style.border = '1px solid rgba(15,23,42,.10)';
        card.style.borderRadius = '9px';
        card.style.background = '#f8fafc';

        const name = document.createElement('strong');
        name.style.display = 'block';
        name.style.color = '#0f172a';
        name.style.marginBottom = '4px';
        name.textContent = hotel.name || 'Hotel';

        const meta = document.createElement('span');
        meta.style.display = 'block';
        meta.style.fontSize = '11px';
        meta.style.color = '#64748b';
        meta.style.marginBottom = '7px';
        const price = Number(hotel.price_per_night || 0);
        const rating = Number(hotel.rating || 0);
        meta.textContent = 'Estimated RM ' + price.toFixed(0) + '/night'
            + (rating ? ' - Google rating ' + rating.toFixed(1) : '')
            + (hotel.distance_km ? ' - ' + Number(hotel.distance_km).toFixed(1) + ' km away' : '')
            + (hotel.address ? ' - ' + hotel.address : '');

        const button = document.createElement('button');
        button.type = 'button';
        button.style.border = '0';
        button.style.borderRadius = '8px';
        button.style.background = '#f59e0b';
        button.style.color = '#0f172a';
        button.style.padding = '6px 9px';
        button.style.fontSize = '11px';
        button.style.fontWeight = '900';
        button.style.cursor = 'pointer';
        button.textContent = 'Confirm Hotel';
        button.addEventListener('click', () => confirmHotelByPlaceId(String(hotel.google_place_id || ''), button));

        card.appendChild(name);
        card.appendChild(meta);
        card.appendChild(button);
        container.appendChild(card);
    });
    const body = document.getElementById('aiChatBody');
    if (body) body.scrollTop = body.scrollHeight;
}

function renderChangeCards(container, proposals) {
    if (!container) return;
    proposals.slice(0, 1).forEach((proposal) => {
        const place = proposal.new_place || {};
        const isAdd = proposal.proposal_type === 'add' || !Number(proposal.item_id || 0);
        const card = document.createElement('div');
        card.className = 'ai-pending-card';
        card.style.marginTop = '8px';
        card.style.padding = '9px';
        card.style.border = '1px solid rgba(15,23,42,.10)';
        card.style.borderRadius = '9px';
        card.style.background = isAdd ? '#f0fdf4' : '#fff7ed';

        const title = document.createElement('strong');
        title.style.display = 'block';
        title.style.color = '#0f172a';
        title.style.marginBottom = '3px';
        title.textContent = isAdd
            ? 'Day ' + proposal.day_no + ': add new stop'
            : 'Day ' + proposal.day_no + ' stop ' + proposal.sequence_no + ': ' + (proposal.current_title || 'Current stop');

        const meta = document.createElement('span');
        meta.style.display = 'block';
        meta.style.fontSize = '11px';
        meta.style.color = '#64748b';
        meta.style.marginBottom = '6px';
        const cost = Number(place.estimated_cost || 0);
        meta.textContent = (isAdd ? 'Add ' : 'Replace with ') + (place.name || 'new place') + ' - ' + (place.category || 'place') + ' - RM ' + cost.toFixed(2);

        const reason = document.createElement('span');
        reason.style.display = 'block';
        reason.style.fontSize = '11px';
        reason.style.color = '#64748b';
        reason.style.marginBottom = '7px';
        reason.textContent = proposal.reason || 'Suggested from current itinerary database';

        const button = document.createElement('button');
        button.type = 'button';
        button.style.border = '0';
        button.style.borderRadius = '8px';
        button.style.background = isAdd ? '#16a34a' : '#f59e0b';
        button.style.color = isAdd ? '#fff' : '#0f172a';
        button.style.padding = '6px 9px';
        button.style.fontSize = '11px';
        button.style.fontWeight = '900';
        button.style.cursor = 'pointer';
        button.textContent = isAdd ? 'Confirm Add' : 'Confirm Change';
        button.addEventListener('click', () => {
            if (isAdd) confirmItineraryAdd(Number(proposal.day_no || 0), Number(place.place_id || 0), button);
            else confirmItineraryChange(Number(proposal.item_id || 0), Number(place.place_id || 0), button);
        });

        card.appendChild(title);
        card.appendChild(meta);
        card.appendChild(reason);
        card.appendChild(button);
        container.appendChild(card);
    });
    const body = document.getElementById('aiChatBody');
    if (body) body.scrollTop = body.scrollHeight;
}

async function confirmTripDate(startDate, button, nextAction = null) {
    if (!startDate) return;
    if (button) button.disabled = true;
    const loading = addAiMessage('bot', 'Saving confirmed trip date...');
    try {
        const resp = await fetch('../api/ai_travel_assistant.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'confirm_date',
                itinerary_id: ITINERARY_ID,
                start_date: String(startDate)
            }),
        });
        const data = await parseJsonResponse(resp);
        if (data.status === 'success') {
            if (loading) loading.textContent = cleanAiText(data.answer || 'Trip date saved. Reloading...');
            clearAiPendingCards();
            if (nextAction) {
                if (loading) renderPendingAction(loading, nextAction);
                return;
            }
            setTimeout(() => window.location.reload(), 700);
        } else {
            if (button) button.disabled = false;
            if (loading) loading.textContent = cleanAiText(data.answer || data.message || 'Could not save this trip date.');
        }
    } catch (e) {
        if (button) button.disabled = false;
        if (loading) loading.textContent = 'Network error while saving trip date. Please try again.';
    }
}

async function confirmTripOrigin(originName, button) {
    originName = String(originName || '').trim();
    if (!originName) return;
    if (button) {
        button.disabled = true;
        button.textContent = 'Checking...';
    }
    const loading = addAiMessage('bot', 'Confirming starting location coordinates...');
    try {
        if (!window.google || !google.maps || !google.maps.Geocoder) {
            throw new Error('Google Maps is not ready.');
        }
        const geocoder = new google.maps.Geocoder();
        const result = await new Promise((resolve, reject) => {
            geocoder.geocode({ address: originName, componentRestrictions: { country: 'MY' } }, (results, status) => {
                if (status === 'OK' && results && results[0] && results[0].geometry) resolve(results[0]);
                else reject(new Error(status || 'Geocode failed'));
            });
        });
        const loc = result.geometry.location;
        const resp = await fetch('../api/ai_travel_assistant.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'confirm_origin',
                itinerary_id: ITINERARY_ID,
                origin_name: result.formatted_address || originName,
                origin_lat: String(loc.lat()),
                origin_lng: String(loc.lng())
            }),
        });
        const data = await parseJsonResponse(resp);
        if (data.status === 'success') {
            if (loading) loading.textContent = cleanAiText(data.answer || 'Starting location saved. Reloading...');
            clearAiPendingCards();
            setTimeout(() => window.location.reload(), 700);
        } else {
            if (button) {
                button.disabled = false;
                button.textContent = 'Confirm Location';
            }
            if (loading) loading.textContent = cleanAiText(data.answer || data.message || 'Could not save this starting location.');
        }
    } catch (e) {
        if (button) {
            button.disabled = false;
            button.textContent = 'Confirm Location';
        }
        if (loading) loading.textContent = 'Could not confirm coordinates for that location. Try a clearer city, hotel, landmark, or address.';
    }
}

async function confirmItineraryChange(itemId, placeId, button) {
    if (!itemId || !placeId) return;
    if (button) button.disabled = true;
    const loading = addAiMessage('bot', 'Applying selected itinerary change and recalculating route/cost...');
    try {
        const resp = await fetch('../api/ai_itinerary_editor.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'confirm',
                itinerary_id: ITINERARY_ID,
                item_id: String(itemId),
                place_id: String(placeId)
            }),
        });
        const data = await parseJsonResponse(resp);
        if (data.status === 'success') {
            if (loading) loading.textContent = cleanAiText(data.answer || 'Itinerary changed. Reloading...');
            clearAiPendingCards();
            setTimeout(() => window.location.reload(), 700);
        } else {
            if (button) button.disabled = false;
            if (loading) loading.textContent = cleanAiText(data.answer || data.message || 'Could not apply this itinerary change.');
        }
    } catch (e) {
        if (button) button.disabled = false;
        if (loading) loading.textContent = 'Network error while saving itinerary change. Please try again.';
    }
}

async function confirmItineraryAdd(dayNo, placeId, button) {
    if (!dayNo || !placeId) return;
    if (button) button.disabled = true;
    const loading = addAiMessage('bot', 'Adding selected place and recalculating route/cost...');
    try {
        const resp = await fetch('../api/ai_itinerary_editor.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'confirm_add',
                itinerary_id: ITINERARY_ID,
                day_no: String(dayNo),
                place_id: String(placeId)
            }),
        });
        const data = await parseJsonResponse(resp);
        if (data.status === 'success') {
            if (loading) loading.textContent = cleanAiText(data.answer || 'Place added. Reloading...');
            clearAiPendingCards();
            setTimeout(() => window.location.reload(), 700);
        } else {
            if (button) button.disabled = false;
            if (loading) loading.textContent = cleanAiText(data.answer || data.message || 'Could not add this place.');
        }
    } catch (e) {
        if (button) button.disabled = false;
        if (loading) loading.textContent = 'Network error while adding place. Please try again.';
    }
}

async function confirmHotelByPlaceId(placeId, button) {
    if (!placeId) return;
    if (button) button.disabled = true;
    const loading = addAiMessage('bot', 'Saving selected hotel into your itinerary and cost summary...');
    try {
        const resp = await fetch('review_replace.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'confirm',
                itinerary_id: ITINERARY_ID,
                rejected_ids: '',
                replacements_json: '{}',
                hotel_place_id: placeId
            }),
        });
        const data = await parseJsonResponse(resp);
        if (data.status === 'success') {
            if (loading) loading.textContent = cleanAiText(data.answer || data.message || 'Hotel confirmed. Reloading...');
            clearAiPendingCards();
            setTimeout(() => window.location.reload(), 700);
        } else {
            if (button) button.disabled = false;
            if (loading) loading.textContent = cleanAiText(data.answer || data.message || 'Could not confirm this hotel.');
        }
    } catch (e) {
        if (button) button.disabled = false;
        if (loading) loading.textContent = 'Network error while saving hotel. Please try again.';
    }
}

// ---- HTML escape ----
function escHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
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

<!-- Load Google Maps API -->
<?php if (trim($googleMapsKey) !== ''): ?>
<script>
setTimeout(() => {
    if (!googleMapLoaded) {
        showMapLoadError(
            'Google Maps did not finish loading. Check the browser console for the exact Google Maps API error. For localhost testing, the API key must allow <code>http://localhost/*</code> and the Maps JavaScript API must be enabled.'
        );
    }
}, 7000);
</script>
<script src="https://maps.googleapis.com/maps/api/js?key=<?php echo htmlspecialchars($googleMapsKey); ?>&callback=initMap&libraries=geometry" async defer></script>
<?php else: ?>
<script>
showMapLoadError('Google Maps API key is missing in <code>config/api_keys.php</code>.');
</script>
<?php endif; ?>
</body>
</html>
