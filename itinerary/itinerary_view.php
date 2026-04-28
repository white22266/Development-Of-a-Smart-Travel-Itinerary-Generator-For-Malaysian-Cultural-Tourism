<?php
// itinerary/itinerary_view.php
// Moovit-style transit panel: MRT/LRT/KTM/Monorail/Bus/Walk step-by-step directions,
// transfer details, estimated times, total journey duration/distance per leg.
session_start();
require_once "../config/db_connect.php";
require_once "../config/api_keys.php";

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
    SELECT i.*, tp.transport_type, tp.budget
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

// Load all itinerary items with place details
$dcCheck = $conn->query("SHOW COLUMNS FROM cultural_places LIKE 'district'");
$hasDistrictCol = ($dcCheck && $dcCheck->num_rows > 0);
$districtJoinCol = $hasDistrictCol ? "cp.district" : "NULL AS district";

$stmt = $conn->prepare("
    SELECT ii.item_id, ii.day_no, ii.sequence_no, ii.item_type, ii.place_id,
           ii.item_title, ii.estimated_cost, ii.distance_km, ii.travel_time_min,
           ii.start_time, ii.end_time, ii.notes,
           cp.latitude, cp.longitude, cp.address, cp.category, cp.state, {$districtJoinCol},
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

// Load nearby hotels
$statesInItinerary = [];
foreach ($days as $dayItems) {
    foreach ($dayItems as $item) {
        $st = trim((string)($item["state"] ?? ""));
        if ($st !== "") $statesInItinerary[$st] = true;
    }
}
$hotels = [];
if (!empty($statesInItinerary)) {
    $stateList = implode("','", array_map(fn($s) => $conn->real_escape_string($s), array_keys($statesInItinerary)));
    $hRes = $conn->query("
        SELECT hotel_id, name, state, district, latitude, longitude, price_per_night, rating
        FROM hotels WHERE state IN ('$stateList') AND is_active = 1
        ORDER BY rating DESC LIMIT 30
    ");
    if ($hRes) while ($h = $hRes->fetch_assoc()) $hotels[] = $h;
}

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
        if ($i > 0) {
            if ($item["distance_km"] !== null && (float)$item["distance_km"] > 0) {
                $travelMin = (int)ceil(((float)$item["distance_km"] / $speed) * 60);
                if ($transportType === 'public_transport') $travelMin = (int)ceil($travelMin * 1.4);
            } elseif ($item["travel_time_min"] !== null) {
                $travelMin = (int)$item["travel_time_min"];
            } else {
                $travelMin = ($transportType === 'walking') ? 20 : 15;
            }
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

$jsHotels     = array_map(fn($h) => [
    'name'    => $h['name'],
    'state'   => $h['state'],
    'district'=> $h['district'] ?? '',
    'lat'     => (float)$h['latitude'],
    'lng'     => (float)$h['longitude'],
    'price'   => (float)$h['price_per_night'],
    'rating'  => (float)$h['rating'],
], $hotels);

$jsDays        = json_encode($jsItineraryData, JSON_UNESCAPED_UNICODE);
$jsHotelsJson  = json_encode($jsHotels, JSON_UNESCAPED_UNICODE);
$jsColorsJson  = json_encode($dayColors);
$jsTransport   = json_encode($transportType);
$googleMapsKey = defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : '';
$openWeatherKey= defined('OPENWEATHER_API_KEY') ? OPENWEATHER_API_KEY : '';

// Total cost
$totalCost   = 0;
$totalPlaces = 0;
foreach ($days as $dayItems) {
    foreach ($dayItems as $item) {
        $totalCost += (float)($item['estimated_cost'] ?? 0);
        $totalPlaces++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($it["title"]); ?> — Itinerary View</title>
    <link rel="stylesheet" href="../assets/dashboard_style.css">
    <style>
        /* ===== Layout ===== */
        .iv-grid {
            display: grid;
            grid-template-columns: 1fr 360px;
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

        /* ===== Day tabs ===== */
        .day-tabs { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 12px; }
        .day-tab {
            padding: 7px 14px; border-radius: 10px;
            border: 1.5px solid rgba(15,23,42,0.12);
            background: #fff; font-size: 12px; font-weight: 700;
            cursor: pointer; transition: all .15s;
        }
        .day-tab.active { color: #fff; }

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
            font-size: 16px;
            flex-shrink: 0;
            border: 2px solid rgba(255,255,255,0.8);
            box-shadow: 0 1px 4px rgba(15,23,42,0.12);
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
                    <?php echo $totalDays; ?> day<?php echo $totalDays > 1 ? 's' : ''; ?> &nbsp;·&nbsp;
                    <?php if ($startDate): ?>Starting <?php echo date('d M Y', strtotime($startDate)); ?> &nbsp;·&nbsp;<?php endif; ?>
                    <span style="text-transform:capitalize;"><?php echo htmlspecialchars(str_replace('_', ' ', $transportType)); ?></span>
                </p>
            </div>
            <div class="actions">
                <a class="btn btn-ghost" href="my_itineraries.php">Back</a>
                <a class="btn btn-primary" href="trip_summary.php?itinerary_id=<?php echo $itineraryId; ?>">Trip Summary &amp; Cost</a>
                <a class="btn btn-ghost" href="export_pdf.php?itinerary_id=<?php echo $itineraryId; ?>">Export PDF</a>
            </div>
        </div>

        <!-- ===== Map + Sidebar Grid ===== -->
        <div class="iv-grid">

            <!-- LEFT: Map Panel -->
            <div>
                <div class="card" style="padding:16px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; margin-bottom:10px;">
                        <h3 style="margin:0;">Route Map — All Days</h3>
                        <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                            <span class="weather-chip" id="weatherChip">🌤 Weather: —</span>
                            <button class="btn btn-ghost" style="font-size:12px; padding:6px 12px;" onclick="locateMe()">📍 My Location</button>
                        </div>
                    </div>

                    <!-- Transport mode switcher -->
                    <div class="transport-bar" id="transportBar">
                        <button class="transport-btn <?php echo $transportType==='car'              ? 'active' : ''; ?>" onclick="setTransport('car')">🚗 Car</button>
                        <button class="transport-btn <?php echo $transportType==='motorcycle'       ? 'active' : ''; ?>" onclick="setTransport('motorcycle')">🏍️ Motorcycle</button>
                        <button class="transport-btn <?php echo $transportType==='public_transport' ? 'active' : ''; ?>" onclick="setTransport('public_transport')">🚌 Public Transport</button>
                        <button class="transport-btn <?php echo $transportType==='walking'          ? 'active' : ''; ?>" onclick="setTransport('walking')">🚶 Walk</button>
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
                        <span class="legend-item" style="color:#16a34a; border-color:#16a34a; background:#16a34a18;">
                            <span class="legend-dot" style="background:#16a34a;"></span> Hotel
                        </span>
                        <span class="legend-item" style="color:#4f46e5; border-color:#4f46e5; background:#4f46e518;">
                            <span class="legend-dot" style="background:#4f46e5;"></span> My Location
                        </span>
                    </div>

                    <div id="map"></div>

                    <div class="info-box" style="margin-top:10px;">
                        <strong>How to read this map:</strong>
                        Each coloured line is one day's route. Day 1 starts from your detected location. Day 2+ continues from the previous night's hotel. Click any marker for details. Switch transport mode to re-render routes.
                    </div>
                </div>
            </div>

            <!-- RIGHT: Sidebar Panel -->
            <div class="side-panel">

                <!-- Hotels nearby -->
                <?php if (!empty($hotels)): ?>
                <div class="card" style="padding:14px;">
                    <h3 style="margin-bottom:8px; font-size:14px;">🏨 Nearby Hotels</h3>
                    <p class="meta" style="margin-top:0; margin-bottom:10px;">Click to highlight on map.</p>
                    <?php foreach (array_slice($hotels, 0, 6) as $h): ?>
                    <div class="hotel-card" onclick="focusHotel(<?php echo (float)$h['latitude']; ?>, <?php echo (float)$h['longitude']; ?>, '<?php echo addslashes(htmlspecialchars($h['name'])); ?>')">
                        <div>
                            <div class="hotel-name"><?php echo htmlspecialchars($h['name']); ?></div>
                            <div class="hotel-meta"><?php echo htmlspecialchars($h['district'] ? $h['district'] . ', ' . $h['state'] : $h['state']); ?> · ⭐ <?php echo number_format((float)$h['rating'], 1); ?></div>
                        </div>
                        <div class="hotel-price">RM<?php echo number_format((float)$h['price_per_night'], 0); ?>/night</div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- Trip overview -->
                <div class="card" style="padding:14px;">
                    <h3 style="margin-bottom:10px; font-size:14px;">📊 Trip Overview</h3>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                        <div style="background:rgba(99,102,241,0.07); border-radius:10px; padding:10px; text-align:center;">
                            <div style="font-size:20px; font-weight:900; color:#4f46e5;"><?php echo $totalDays; ?></div>
                            <div style="font-size:11px; color:#64748b; margin-top:2px;">Days</div>
                        </div>
                        <div style="background:rgba(34,197,94,0.07); border-radius:10px; padding:10px; text-align:center;">
                            <div style="font-size:20px; font-weight:900; color:#16a34a;"><?php echo $totalPlaces; ?></div>
                            <div style="font-size:11px; color:#64748b; margin-top:2px;">Places</div>
                        </div>
                        <div style="background:rgba(245,158,11,0.07); border-radius:10px; padding:10px; text-align:center; grid-column:1/-1;">
                            <div style="font-size:18px; font-weight:900; color:#d97706;">RM <?php echo number_format($totalCost, 2); ?></div>
                            <div style="font-size:11px; color:#64748b; margin-top:2px;">Estimated Attraction Cost</div>
                        </div>
                    </div>
                </div>

                <!-- Transit legend -->
                <div class="card" style="padding:14px;">
                    <h3 style="margin-bottom:10px; font-size:14px;">🚇 Transit Lines (MY)</h3>
                    <div style="font-size:11.5px; display:flex; flex-direction:column; gap:5px;">
                        <div style="display:flex; align-items:center; gap:6px;"><span style="width:12px;height:12px;border-radius:50%;background:#7B2D8B;display:inline-block;"></span> MRT Putrajaya Line</div>
                        <div style="display:flex; align-items:center; gap:6px;"><span style="width:12px;height:12px;border-radius:50%;background:#1E5CB3;display:inline-block;"></span> MRT Kajang Line</div>
                        <div style="display:flex; align-items:center; gap:6px;"><span style="width:12px;height:12px;border-radius:50%;background:#F5A623;display:inline-block;"></span> LRT Ampang / Sri Petaling</div>
                        <div style="display:flex; align-items:center; gap:6px;"><span style="width:12px;height:12px;border-radius:50%;background:#E8192C;display:inline-block;"></span> LRT Kelana Jaya</div>
                        <div style="display:flex; align-items:center; gap:6px;"><span style="width:12px;height:12px;border-radius:50%;background:#0072BC;display:inline-block;"></span> KTM Komuter / ETS</div>
                        <div style="display:flex; align-items:center; gap:6px;"><span style="width:12px;height:12px;border-radius:50%;background:#E60026;display:inline-block;"></span> KL Monorail</div>
                        <div style="display:flex; align-items:center; gap:6px;"><span style="width:12px;height:12px;border-radius:50%;background:#C8102E;display:inline-block;"></span> ERL / KLIA Ekspres</div>
                        <div style="display:flex; align-items:center; gap:6px;"><span style="width:12px;height:12px;border-radius:50%;background:#4CAF50;display:inline-block;"></span> Rapid KL Bus</div>
                        <div style="display:flex; align-items:center; gap:6px;"><span style="width:12px;height:12px;border-radius:50%;background:#64748b;display:inline-block;"></span> Walk</div>
                    </div>
                </div>
            </div>
        </div><!-- /iv-grid -->

        <!-- ===== Timetable Section ===== -->
        <div class="card" style="margin-top:18px; padding:16px;">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; margin-bottom:14px;">
                <h3 style="margin:0;">📅 Daily Itinerary Schedule</h3>
                <span class="meta" style="font-size:12px;" id="transitModeLabel">
                    Transport: <strong><?php echo htmlspecialchars(str_replace('_', ' ', ucfirst($transportType))); ?></strong>
                    &nbsp;·&nbsp; Click <strong>"Show Route"</strong> between stops for step-by-step directions.
                </span>
            </div>

            <!-- Day tabs -->
            <div class="day-tabs" id="dayTabs">
                <?php for ($d = 1; $d <= $totalDays; $d++):
                    $col = $dayColors[$d] ?? '#6366f1';
                    $dateLabel = '';
                    if ($startDate) {
                        $ts = strtotime($startDate . ' +' . ($d - 1) . ' days');
                        $dateLabel = ' · ' . date('d M', $ts);
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
                <div style="display:flex; align-items:center; gap:10px; margin-bottom:12px; padding:10px 14px; background:<?php echo $col; ?>12; border-radius:10px; border-left:4px solid <?php echo $col; ?>;">
                    <span style="font-size:18px;">📅</span>
                    <div>
                        <div style="font-weight:900; font-size:14px; color:<?php echo $col; ?>;">Day <?php echo $d; ?></div>
                        <?php if ($dateStr): ?><div style="font-size:12px; color:#64748b;"><?php echo $dateStr; ?></div><?php endif; ?>
                        <div style="font-size:11px; color:#64748b; margin-top:2px;">
                            <?php echo $d === 1 ? '🚩 Starts from your location' : '🏨 Continues from previous night\'s hotel'; ?>
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
                        <?php foreach ($schedule as $idx => $item):
                            $typeBadgeClass = 'badge-' . strtolower($item['item_type'] ?? 'attraction');
                            $activity = activitySuggestion($item['item_type'] ?? 'attraction', $item['category'] ?? '');
                            $prevItem = $idx > 0 ? $schedule[$idx - 1] : null;
                            $legId    = "leg-{$d}-{$idx}";
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
                                            ▶ Show Route
                                        </button>
                                        <!-- Pre-computed estimate -->
                                        <span style="font-size:11.5px; color:#64748b;" id="transit-est-<?php echo $legId; ?>">
                                            <?php if ($item['_travel_min'] > 0): ?>
                                                ~<?php echo $item['_travel_min']; ?> min
                                                <?php if ($item['dist_km'] ?? $item['distance_km'] ?? null): ?>
                                                    · <?php echo number_format((float)($item['dist_km'] ?? $item['distance_km'] ?? 0), 1); ?> km
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
                            <td style="font-weight:800; color:<?php echo $col; ?>;"><?php echo $idx + 1; ?></td>
                            <td>
                                <div style="font-weight:700;"><?php echo htmlspecialchars($item['item_title']); ?></div>
                                <div style="font-size:11px; color:#64748b; margin-top:2px;">
                                    <?php echo $activity; ?>
                                    <?php if (!empty($item['address'])): ?>
                                        &nbsp;·&nbsp; <?php echo htmlspecialchars($item['address']); ?>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($item['opening_hours'])): ?>
                                <div style="font-size:10.5px; color:#94a3b8; margin-top:2px;">🕐 <?php echo htmlspecialchars($item['opening_hours']); ?></div>
                                <?php endif; ?>
                                <?php if (!empty($item['state'])): ?>
                                <div style="font-size:10.5px; color:#94a3b8; margin-top:2px;">
                                    📍 <?php echo htmlspecialchars($item['district'] ? $item['district'] . ', ' . $item['state'] : $item['state']); ?>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="type-badge <?php echo htmlspecialchars($typeBadgeClass); ?>">
                                    <?php echo htmlspecialchars(ucfirst($item['item_type'] ?? 'attraction')); ?>
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

<!-- ===== JavaScript ===== -->
<script>
// ---- Data from PHP ----
const DAYS_DATA    = <?php echo $jsDays; ?>;
const HOTELS_DATA  = <?php echo $jsHotelsJson; ?>;
const DAY_COLORS   = <?php echo $jsColorsJson; ?>;
const TOTAL_DAYS   = <?php echo $totalDays; ?>;
const WEATHER_KEY  = "<?php echo addslashes($openWeatherKey); ?>";

// ---- State ----
let map, infoWindow;
let userLat = null, userLng = null;
let userMarker = null;
let hotelMarkers = [];
let dayRenderers = {};
let dayMarkers   = {};
let dayVisible   = {};
let currentTransport = <?php echo $jsTransport; ?>;

// ---- Init map ----
function initMap() {
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
        infoWindow.setContent('<div style="font-weight:800;color:#4f46e5;">📍 My Location</div>');
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
                    <div style="font-weight:800;">🏨 ${h.name}</div>
                    <div style="font-size:11px;color:#64748b;margin-top:3px;">${h.district ? h.district + ', ' : ''}${h.state}</div>
                    <div style="font-size:12px;font-weight:700;color:#4f46e5;margin-top:4px;">RM${h.price.toFixed(0)}/night · ⭐${h.rating.toFixed(1)}</div>
                </div>`
            );
            infoWindow.open(map, m);
        });
        hotelMarkers.push(m);
    });
}

// ---- Render all days ----
function renderAllDays() {
    for (let d = 1; d <= TOTAL_DAYS; d++) {
        dayVisible[d] = true;
        renderDay(d);
    }
    setTimeout(fitAllBounds, 600);
}

// ---- Render one day ----
function renderDay(day) {
    // Clear existing
    if (dayRenderers[day]) {
        if (dayRenderers[day].setMap) dayRenderers[day].setMap(null);
        if (dayRenderers[day]._poly) dayRenderers[day]._poly.setMap(null);
        delete dayRenderers[day];
    }
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
        const typeEmoji = { food:'🍜', hotel:'🏨', festival:'🎉', museum:'🏛️', heritage:'🏯', culture:'🎭', nature:'🌿', attraction:'📍' };
        const emoji = typeEmoji[item.type] || '📍';
        marker.addListener('click', () => {
            infoWindow.setContent(
                `<div style="max-width:230px;">
                    <div style="font-weight:800;font-size:13px;">${emoji} ${escHtml(item.title)}</div>
                    <div style="font-size:11px;color:#64748b;margin-top:4px;">Day ${day} · Stop ${label}</div>
                    ${item.address ? `<div style="font-size:11px;margin-top:4px;">📍 ${escHtml(item.address)}</div>` : ''}
                    ${item.opening_hours ? `<div style="font-size:11px;margin-top:4px;">🕐 ${escHtml(item.opening_hours)}</div>` : ''}
                    <div style="font-size:11px;margin-top:4px;">${item.start_fmt} – ${item.end_fmt}${item.cost > 0 ? ` · RM${item.cost.toFixed(2)}` : ' · Free'}</div>
                </div>`
            );
            infoWindow.open(map, marker);
        });
        dayMarkers[day].push(marker);
    });

    if (allPoints.length >= 2) {
        const ds = new google.maps.DirectionsService();
        const dr = new google.maps.DirectionsRenderer({
            map,
            suppressMarkers: true,
            polylineOptions: { strokeColor: color, strokeWeight: 4, strokeOpacity: 0.85 },
        });
        dayRenderers[day] = dr;

        const origin      = allPoints[0];
        const destination = allPoints[allPoints.length - 1];
        const waypoints   = allPoints.slice(1, -1).map(p => ({ location: p, stopover: true }));
        const travelMode  = getTravelMode(currentTransport);

        ds.route({ origin, destination, waypoints, travelMode, optimizeWaypoints: false },
            (result, status) => {
                if (status === 'OK') {
                    dr.setDirections(result);
                } else {
                    const poly = new google.maps.Polyline({
                        path: allPoints, map,
                        strokeColor: color, strokeWeight: 3, strokeOpacity: 0.7,
                        icons: [{ icon: { path: google.maps.SymbolPath.FORWARD_CLOSED_ARROW, scale: 3 }, offset: '50%' }],
                    });
                    dayRenderers[day] = { setMap: () => {}, _poly: poly };
                }
            }
        );
    }
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
    document.querySelectorAll('.transport-btn').forEach(btn => btn.classList.remove('active'));
    const modeMap = { car: 0, motorcycle: 1, public_transport: 2, walking: 3 };
    const btns = document.querySelectorAll('.transport-btn');
    if (btns[modeMap[mode]]) btns[modeMap[mode]].classList.add('active');
    for (let d = 1; d <= TOTAL_DAYS; d++) renderDay(d);

    // Update mode label
    const labels = { car: 'Car', motorcycle: 'Motorcycle', public_transport: 'Public Transport', walking: 'Walk' };
    const lbl = document.getElementById('transitModeLabel');
    if (lbl) lbl.innerHTML = `Transport: <strong>${labels[mode] || mode}</strong> &nbsp;·&nbsp; Click <strong>"Show Route"</strong> between stops for step-by-step directions.`;
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
    infoWindow.setContent(`<div style="font-weight:800;">🏨 ${escHtml(name)}</div>`);
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
    if (!WEATHER_KEY || WEATHER_KEY.includes('PASTE_')) { chip.textContent = '🌤 Weather: N/A'; return; }
    try {
        const r = await fetch(`https://api.openweathermap.org/data/2.5/weather?lat=${lat}&lon=${lng}&appid=${WEATHER_KEY}&units=metric`);
        const j = await r.json();
        const desc = j.weather?.[0]?.description || 'Unknown';
        const temp = j.main?.temp !== undefined ? Math.round(j.main.temp) + '°C' : '';
        const icon = j.weather?.[0]?.icon ? `<img src="https://openweathermap.org/img/wn/${j.weather[0].icon}.png" style="width:20px;height:20px;vertical-align:middle;">` : '🌤';
        chip.innerHTML = `${icon} ${capitalise(desc)} ${temp}`;
        if (['Rain','Thunderstorm','Drizzle'].includes(j.weather?.[0]?.main)) {
            chip.style.background = '#fef9c3'; chip.style.borderColor = '#fbbf24';
        }
    } catch(e) { chip.textContent = '🌤 Weather: —'; }
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
        if (btnEl) btnEl.innerHTML = '▶ Show Route';
        return;
    }

    if (!oLat || !oLng || !dLat || !dLng) {
        detailEl.innerHTML = '<div class="ts-warning">⚠ Coordinates not available for this leg.</div>';
        detailEl.classList.add('open');
        return;
    }

    // Show loading
    detailEl.innerHTML = '<div class="ts-loading"><span class="spinner"></span> Fetching route details...</div>';
    detailEl.classList.add('open');
    if (btnEl) btnEl.innerHTML = '▼ Hide Route';

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
            data = await resp.json();
            transitCache[cacheKey] = data;
        } catch(e) {
            detailEl.innerHTML = '<div class="ts-warning">⚠ Network error. Could not fetch route.</div>';
            return;
        }
    }

    if (data.status === 'error') {
        detailEl.innerHTML = `<div class="ts-warning">⚠ ${escHtml(data.message || 'Route not available.')}</div>`;
        return;
    }

    // Update estimate
    if (estEl && data.total_duration) {
        estEl.innerHTML = `<strong>${data.total_duration} min</strong> · ${data.total_distance} km`;
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
            chips.push(`<span class="transit-chip transit-chip-walk">🚶 ${step.duration_min}m walk</span>`);
        } else if (step.travel_mode === 'transit') {
            const color = step.line_color || '#4CAF50';
            const icon  = step.vehicle_icon || '🚌';
            const name  = step.line_short || step.line_name || step.type_label || 'Transit';
            chips.push(`<span class="transit-chip" style="background:${escHtml(color)};">${icon} ${escHtml(name)}</span>`);
        } else {
            const modeIcon = { driving: '🚗', walking: '🚶', bicycling: '🚲' };
            chips.push(`<span class="transit-chip transit-chip-walk">${modeIcon[step.travel_mode] || '🚗'} ${step.duration_min}m</span>`);
        }
    });
    return chips.join('<span style="color:#94a3b8;font-size:10px;">›</span>');
}

function buildTransitDetail(data, fromTitle, toTitle) {
    let html = '';

    // Summary bar
    html += `<div class="ts-summary-bar">
        <div class="ts-summary-stat">⏱ <span>${data.total_duration} min total</span></div>
        <div class="ts-summary-stat">📏 <span>${data.total_distance} km</span></div>
        ${data.fare ? `<span class="ts-fare">🎫 ${escHtml(data.fare)}</span>` : ''}
        ${data.summary ? `<span style="color:#64748b;font-size:11px;">${escHtml(data.summary)}</span>` : ''}
    </div>`;

    // Leg departure/arrival times
    if (data.legs && data.legs.length > 0) {
        const leg = data.legs[0];
        if (leg.departure_time || leg.arrival_time) {
            html += `<div style="font-size:11px; color:#64748b; margin-bottom:8px;">
                ${leg.departure_time ? `🕐 Depart: <strong>${escHtml(leg.departure_time)}</strong>` : ''}
                ${leg.arrival_time  ? ` &nbsp;→&nbsp; Arrive: <strong>${escHtml(leg.arrival_time)}</strong>` : ''}
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
            html += `<div class="ts-warning">⚠ ${escHtml(w)}</div>`;
        });
    }

    return html;
}

function buildStepCard(step, idx, total) {
    const isTransit = step.travel_mode === 'transit';
    const isWalk    = step.travel_mode === 'walking';
    const isDrive   = ['driving', 'car', 'motorcycle'].includes(step.travel_mode);

    const iconBg = step.line_color || (isWalk ? '#64748b' : isDrive ? '#374151' : '#4CAF50');
    const icon   = step.vehicle_icon || (isWalk ? '🚶' : isDrive ? '🚗' : '🚌');
    const modeLabel = step.type_label || (isWalk ? 'Walk' : isDrive ? 'Drive' : 'Transit');

    let html = `<div class="ts-step">
        <div class="ts-icon" style="background:${escHtml(iconBg)};">${icon}</div>
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
            html += `<div class="ts-stops">🚉 ${step.num_stops} stop${step.num_stops > 1 ? 's' : ''} · ${step.duration_min} min</div>`;
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
        html += `<div class="ts-stops">${step.duration_min} min · ${step.instruction ? escHtml(step.instruction) : 'Walk to next stop'}</div>`;

        if (step.sub_steps && step.sub_steps.length > 0) {
            html += '<div class="ts-walk-steps">';
            step.sub_steps.forEach(sub => {
                html += `<div class="ts-walk-step">→ ${escHtml(sub.instruction)} (${sub.distance_km > 0 ? sub.distance_km + ' km · ' : ''}${sub.duration_min} min)</div>`;
            });
            html += '</div>';
        }

    } else {
        // Drive step
        html += `<div class="ts-line-name">${escHtml(step.instruction || 'Drive')}</div>`;
        html += `<div class="ts-stops">${step.duration_min} min · ${step.distance_km} km</div>`;
    }

    html += `</div></div>`;
    return html;
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
</script>

<!-- Load Google Maps API -->
<script src="https://maps.googleapis.com/maps/api/js?key=<?php echo htmlspecialchars($googleMapsKey); ?>&callback=initMap&libraries=geometry" async defer></script>
</body>
</html>
