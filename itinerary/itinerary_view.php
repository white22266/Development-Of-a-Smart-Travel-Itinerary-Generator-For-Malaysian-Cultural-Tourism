<?php
// itinerary/itinerary_view.php
// Fully rebuilt: multi-day Google Maps, timetable schedule, hotel markers, 4 transport modes, user location start
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
if ($itineraryId <= 0) {
    header("Location: my_itineraries.php");
    exit;
}

// Load itinerary header + preference (for transport_type, origin)
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
if (!$it) {
    header("Location: my_itineraries.php");
    exit;
}

// Load all itinerary items with place details
$stmt = $conn->prepare("
    SELECT ii.item_id, ii.day_no, ii.sequence_no, ii.item_type, ii.place_id,
           ii.item_title, ii.estimated_cost, ii.distance_km, ii.travel_time_min,
           ii.start_time, ii.end_time, ii.notes,
           cp.latitude, cp.longitude, cp.address, cp.category, cp.state, cp.district,
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
$transportType = $it["transport_type"] ?? "car";
$startDate     = $it["start_date"] ?? null;

// Load nearby hotels per state (for hotel markers on map)
// Collect all unique states from the itinerary
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
        FROM hotels
        WHERE state IN ('$stateList') AND is_active = 1
        ORDER BY rating DESC
        LIMIT 30
    ");
    if ($hRes) {
        while ($h = $hRes->fetch_assoc()) $hotels[] = $h;
    }
}

// Day colors for route lines
$dayColors = [
    1 => '#EF4444', // Red
    2 => '#3B82F6', // Blue
    3 => '#22C55E', // Green
    4 => '#F59E0B', // Amber
    5 => '#8B5CF6', // Purple
    6 => '#EC4899', // Pink
    7 => '#14B8A6', // Teal
];

// Build timetable: assign times if not set
// Start: 9:00 AM, each attraction ~2h, food ~1h, travel buffer from distance
function buildTimetable(array $items, string $transportType): array {
    $schedule = [];
    $cursor   = 9 * 60; // 9:00 AM in minutes

    // Transport speed estimates (km/h)
    $speeds = [
        'car'              => 60,
        'motorcycle'       => 55,
        'public_transport' => 35,
        'walking'          => 5,
    ];
    $speed = $speeds[$transportType] ?? 60;

    foreach ($items as $i => $item) {
        $type = $item["item_type"] ?? "attraction";

        // Travel time from previous stop (if distance known)
        $travelMin = 0;
        if ($i > 0 && $item["distance_km"] !== null && (float)$item["distance_km"] > 0) {
            $travelMin = (int)ceil(((float)$item["distance_km"] / $speed) * 60);
            // Add buffer: traffic / waiting
            if ($transportType === 'public_transport') $travelMin = (int)ceil($travelMin * 1.4);
        } elseif ($i > 0 && $item["travel_time_min"] !== null) {
            $travelMin = (int)$item["travel_time_min"];
        } elseif ($i > 0) {
            // Default travel gap
            $travelMin = ($transportType === 'walking') ? 20 : 15;
        }

        $cursor += $travelMin;

        // Duration by type
        $duration = match ($type) {
            'attraction', 'heritage', 'museum', 'culture' => 120,
            'food'     => 60,
            'festival' => 150,
            'hotel'    => 30,
            default    => 90,
        };

        $startMin = $cursor;
        $endMin   = $cursor + $duration;

        $schedule[] = array_merge($item, [
            '_travel_min' => $travelMin,
            '_start_min'  => $startMin,
            '_end_min'    => $endMin,
            '_start_fmt'  => minutesToTime($startMin),
            '_end_fmt'    => minutesToTime($endMin),
        ]);

        $cursor = $endMin;
    }
    return $schedule;
}

function minutesToTime(int $min): string {
    $h   = intdiv($min, 60) % 24;
    $m   = $min % 60;
    $ampm = $h >= 12 ? 'PM' : 'AM';
    $h12  = $h % 12 ?: 12;
    return sprintf('%d:%02d %s', $h12, $m, $ampm);
}

// Activity suggestions by category
function activitySuggestion(string $type, string $category): string {
    $map = [
        'food'     => 'Enjoy local cuisine',
        'hotel'    => 'Check in & rest',
        'festival' => 'Join cultural festival activities',
        'museum'   => 'Explore exhibits & galleries',
        'heritage' => 'Explore heritage site',
        'culture'  => 'Experience local culture',
        'nature'   => 'Explore nature & scenery',
        'shopping' => 'Browse local markets & shops',
        'attraction' => 'Visit & explore',
    ];
    $key = strtolower($category ?: $type);
    return $map[$key] ?? $map[$type] ?? 'Visit & explore';
}

// Transport icon & label
function transportLabel(string $mode): array {
    return match ($mode) {
        'car'              => ['🚗', 'Drive'],
        'motorcycle'       => ['🏍️', 'Ride'],
        'public_transport' => ['🚌', 'Public Transport'],
        'walking'          => ['🚶', 'Walk'],
        default            => ['🚗', 'Drive'],
    };
}

// Build timetables for all days
$timetables = [];
foreach ($days as $d => $items) {
    $timetables[$d] = buildTimetable($items, $transportType);
}

// Encode all data for JS
$jsItineraryData = [];
foreach ($timetables as $d => $items) {
    $jsItineraryData[$d] = array_map(function($item) {
        return [
            'item_id'     => (int)$item['item_id'],
            'title'       => $item['item_title'],
            'type'        => $item['item_type'],
            'category'    => $item['category'] ?? '',
            'lat'         => $item['latitude']  !== null ? (float)$item['latitude']  : null,
            'lng'         => $item['longitude'] !== null ? (float)$item['longitude'] : null,
            'address'     => $item['address'] ?? '',
            'state'       => $item['state'] ?? '',
            'district'    => $item['district'] ?? '',
            'cost'        => (float)($item['estimated_cost'] ?? 0),
            'dist_km'     => $item['distance_km'] !== null ? (float)$item['distance_km'] : null,
            'travel_min'  => $item['_travel_min'],
            'start_fmt'   => $item['_start_fmt'],
            'end_fmt'     => $item['_end_fmt'],
            'opening_hours' => $item['opening_hours'] ?? '',
        ];
    }, $items);
}

$jsHotels = array_map(function($h) {
    return [
        'name'    => $h['name'],
        'state'   => $h['state'],
        'district'=> $h['district'] ?? '',
        'lat'     => (float)$h['latitude'],
        'lng'     => (float)$h['longitude'],
        'price'   => (float)$h['price_per_night'],
        'rating'  => (float)$h['rating'],
    ];
}, $hotels);

$jsColors = $dayColors;
$jsDays   = json_encode($jsItineraryData, JSON_UNESCAPED_UNICODE);
$jsHotelsJson = json_encode($jsHotels, JSON_UNESCAPED_UNICODE);
$jsColorsJson = json_encode($jsColors);
$jsTransport  = json_encode($transportType);
$googleMapsKey = defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : '';
$openWeatherKey = defined('OPENWEATHER_API_KEY') ? OPENWEATHER_API_KEY : '';
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
            grid-template-columns: 1fr 380px;
            gap: 18px;
            align-items: start;
        }
        @media (max-width: 1100px) {
            .iv-grid { grid-template-columns: 1fr; }
        }

        /* ===== Map panel ===== */
        #map {
            width: 100%;
            height: 520px;
            border-radius: 14px;
            border: 1px solid rgba(15,23,42,0.10);
        }

        /* ===== Transport mode switcher ===== */
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
        .transport-btn.active {
            background: #4f46e5;
            color: #fff;
            border-color: #4f46e5;
        }
        .transport-btn:hover:not(.active) {
            border-color: #4f46e5;
            color: #4f46e5;
        }

        /* ===== Day legend ===== */
        .day-legend {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin: 8px 0 4px;
        }
        .legend-item {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 999px;
            border: 1.5px solid;
            cursor: pointer;
            transition: opacity .15s;
        }
        .legend-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            flex-shrink: 0;
        }

        /* ===== Sidebar panel ===== */
        .side-panel {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        /* ===== Day tabs ===== */
        .day-tabs {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }
        .day-tab {
            padding: 7px 14px;
            border-radius: 10px;
            border: 1.5px solid rgba(15,23,42,0.12);
            background: #fff;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: all .15s;
        }
        .day-tab.active {
            color: #fff;
        }

        /* ===== Timetable ===== */
        .timetable {
            width: 100%;
            border-collapse: collapse;
            font-size: 12.5px;
        }
        .timetable th {
            background: rgba(15,23,42,0.04);
            padding: 8px 10px;
            text-align: left;
            font-weight: 800;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .04em;
            border-bottom: 1px solid rgba(15,23,42,0.08);
        }
        .timetable td {
            padding: 9px 10px;
            border-bottom: 1px solid rgba(15,23,42,0.05);
            vertical-align: top;
        }
        .timetable tr:last-child td { border-bottom: none; }
        .timetable tr:hover td { background: rgba(99,102,241,0.04); }

        /* Travel row (between stops) */
        .travel-row td {
            background: rgba(15,23,42,0.02);
            color: #64748b;
            font-size: 11.5px;
            padding: 5px 10px;
        }

        /* Type badges */
        .type-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 10.5px;
            font-weight: 800;
            white-space: nowrap;
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

        /* ===== Weather badge ===== */
        .weather-chip {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 12px;
            border-radius: 999px;
            border: 1px solid rgba(15,23,42,0.10);
            font-size: 12px;
            font-weight: 700;
            background: #fff;
        }

        /* ===== Hotel card in sidebar ===== */
        .hotel-card {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 10px;
            border-radius: 10px;
            border: 1px solid rgba(15,23,42,0.08);
            margin-bottom: 6px;
            font-size: 12px;
            cursor: pointer;
            transition: border-color .15s;
        }
        .hotel-card:hover { border-color: #6366f1; }
        .hotel-card .hotel-name { font-weight: 700; }
        .hotel-card .hotel-meta { color: #64748b; font-size: 11px; margin-top: 2px; }
        .hotel-card .hotel-price { font-weight: 800; color: #4f46e5; white-space: nowrap; }

        /* ===== User location dot ===== */
        .my-location-label {
            background: #4f46e5;
            color: #fff;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 800;
        }

        /* ===== Responsive table wrapper ===== */
        .table-scroll { overflow-x: auto; }

        /* ===== Info box ===== */
        .info-box {
            background: rgba(99,102,241,0.06);
            border: 1px solid rgba(99,102,241,0.18);
            border-radius: 10px;
            padding: 10px 14px;
            font-size: 12px;
            color: #475569;
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
                <span>Itinerary View</span>
            </div>
        </div>
        <nav class="nav" aria-label="Sidebar Navigation">
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
                    <?php if ($startDate): ?>
                        Starting <?php echo date('d M Y', strtotime($startDate)); ?> &nbsp;·&nbsp;
                    <?php endif; ?>
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
                        <button class="transport-btn <?php echo $transportType==='car' ? 'active' : ''; ?>" onclick="setTransport('car')">🚗 Car</button>
                        <button class="transport-btn <?php echo $transportType==='motorcycle' ? 'active' : ''; ?>" onclick="setTransport('motorcycle')">🏍️ Motorcycle</button>
                        <button class="transport-btn <?php echo $transportType==='public_transport' ? 'active' : ''; ?>" onclick="setTransport('public_transport')">🚌 Public Transport</button>
                        <button class="transport-btn <?php echo $transportType==='walking' ? 'active' : ''; ?>" onclick="setTransport('walking')">🚶 Walk</button>
                    </div>

                    <!-- Day legend / toggles -->
                    <div class="day-legend" id="dayLegend">
                        <?php for ($d = 1; $d <= $totalDays; $d++):
                            $col = $dayColors[$d] ?? '#6366f1';
                        ?>
                        <span class="legend-item" id="legend-<?php echo $d; ?>"
                              style="color:<?php echo $col; ?>; border-color:<?php echo $col; ?>; background:<?php echo $col; ?>18;"
                              onclick="toggleDay(<?php echo $d; ?>)">
                            <span class="legend-dot" style="background:<?php echo $col; ?>;"></span>
                            Day <?php echo $d; ?>
                        </span>
                        <?php endfor; ?>
                        <span class="legend-item" style="color:#16a34a; border-color:#16a34a; background:#16a34a18;">
                            <span class="legend-dot" style="background:#16a34a;"></span>
                            Hotel
                        </span>
                        <span class="legend-item" style="color:#4f46e5; border-color:#4f46e5; background:#4f46e518;">
                            <span class="legend-dot" style="background:#4f46e5;"></span>
                            My Location
                        </span>
                    </div>

                    <!-- Map -->
                    <div id="map"></div>

                    <div class="info-box" style="margin-top:10px;">
                        <strong>How to read this map:</strong>
                        Each coloured line represents one day's route. Day 1 starts from your detected location (or the first place if location is unavailable). Each subsequent day continues from the previous night's hotel. Click any marker for details.
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

                <!-- Quick stats -->
                <div class="card" style="padding:14px;">
                    <h3 style="margin-bottom:10px; font-size:14px;">📊 Trip Overview</h3>
                    <?php
                    $totalCost = 0;
                    $totalPlaces = 0;
                    foreach ($days as $dayItems) {
                        foreach ($dayItems as $item) {
                            $totalCost += (float)($item['estimated_cost'] ?? 0);
                            $totalPlaces++;
                        }
                    }
                    ?>
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

            </div>
        </div><!-- /iv-grid -->

        <!-- ===== Timetable Section ===== -->
        <div class="card" style="margin-top:18px; padding:16px;">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; margin-bottom:14px;">
                <h3 style="margin:0;">📅 Daily Itinerary Schedule</h3>
                <span class="meta" style="font-size:12px;">Times are estimated based on transport mode and distance.</span>
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
                        <?php if ($dateStr): ?>
                        <div style="font-size:12px; color:#64748b;"><?php echo $dateStr; ?></div>
                        <?php endif; ?>
                        <?php if ($d === 1): ?>
                        <div style="font-size:11px; color:#64748b; margin-top:2px;">🚩 Starts from your location</div>
                        <?php else: ?>
                        <div style="font-size:11px; color:#64748b; margin-top:2px;">🏨 Continues from previous night's hotel</div>
                        <?php endif; ?>
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
                            <th style="width:36px;">#</th>
                            <th>Place &amp; Activity</th>
                            <th style="width:80px;">Type</th>
                            <th style="width:100px;">Transport</th>
                            <th style="width:80px;">Cost (RM)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        [$tIcon, $tLabel] = transportLabel($transportType);
                        foreach ($schedule as $idx => $item):
                            $typeBadgeClass = 'badge-' . strtolower($item['item_type'] ?? 'attraction');
                            $activity = activitySuggestion($item['item_type'] ?? 'attraction', $item['category'] ?? '');
                        ?>
                        <?php if ($idx > 0 && $item['_travel_min'] > 0): ?>
                        <!-- Travel row -->
                        <tr class="travel-row">
                            <td colspan="6">
                                <?php echo $tIcon; ?> Travel via <?php echo $tLabel; ?>
                                &nbsp;·&nbsp;
                                <?php if ($item['dist_km'] ?? $item['distance_km'] ?? null): ?>
                                    <?php $dk = $item['dist_km'] ?? (float)($item['distance_km'] ?? 0); ?>
                                    <?php echo number_format($dk, 1); ?> km
                                    &nbsp;·&nbsp;
                                <?php endif; ?>
                                ~<?php echo $item['_travel_min']; ?> min
                            </td>
                        </tr>
                        <?php endif; ?>
                        <!-- Place row -->
                        <tr data-item-id="<?php echo (int)$item['item_id']; ?>"
                            data-lat="<?php echo htmlspecialchars((string)($item['latitude'] ?? '')); ?>"
                            data-lng="<?php echo htmlspecialchars((string)($item['longitude'] ?? '')); ?>"
                            data-title="<?php echo htmlspecialchars($item['item_title']); ?>"
                            data-day="<?php echo $d; ?>"
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
                            </td>
                            <td>
                                <span class="type-badge <?php echo htmlspecialchars($typeBadgeClass); ?>">
                                    <?php echo htmlspecialchars(ucfirst($item['item_type'] ?? 'attraction')); ?>
                                </span>
                            </td>
                            <td style="font-size:11.5px; color:#475569;">
                                <?php echo $tIcon; ?> <?php echo $tLabel; ?>
                            </td>
                            <td style="font-weight:700;">
                                <?php echo $item['estimated_cost'] > 0 ? 'RM ' . number_format((float)$item['estimated_cost'], 2) : '<span style="color:#94a3b8;">Free</span>'; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>

                <!-- Day total -->
                <?php
                $dayTotal = array_sum(array_column($schedule, 'estimated_cost'));
                ?>
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
let dayRenderers = {};   // day -> DirectionsRenderer
let dayMarkers   = {};   // day -> [Marker, ...]
let dayVisible   = {};   // day -> bool
let currentDay   = 1;
let currentTransport = <?php echo $jsTransport; ?>;

// ---- Travel mode map ----
const TRAVEL_MODES = {
    car:              google.maps ? google.maps.TravelMode?.DRIVING   : 'DRIVING',
    motorcycle:       google.maps ? google.maps.TravelMode?.DRIVING   : 'DRIVING',
    public_transport: google.maps ? google.maps.TravelMode?.TRANSIT   : 'TRANSIT',
    walking:          google.maps ? google.maps.TravelMode?.WALKING   : 'WALKING',
};

// ---- Init map ----
function initMap() {
    map = new google.maps.Map(document.getElementById('map'), {
        zoom: 10,
        center: { lat: 3.8, lng: 109.0 }, // Malaysia center
        mapTypeControl: true,
        fullscreenControl: true,
        streetViewControl: false,
    });
    infoWindow = new google.maps.InfoWindow();

    // Try to get user location
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
            pos => {
                userLat = pos.coords.latitude;
                userLng = pos.coords.longitude;
                placeUserMarker(userLat, userLng);
                renderAllDays();
                loadWeather(userLat, userLng);
            },
            () => {
                // No location — render without origin
                renderAllDays();
            },
            { timeout: 8000, maximumAge: 60000 }
        );
    } else {
        renderAllDays();
    }

    // Place hotel markers
    placeHotelMarkers();
}

// ---- User location marker ----
function placeUserMarker(lat, lng) {
    if (userMarker) userMarker.setMap(null);
    userMarker = new google.maps.Marker({
        position: { lat, lng },
        map,
        title: 'My Location',
        icon: {
            path: google.maps.SymbolPath.CIRCLE,
            scale: 10,
            fillColor: '#4f46e5',
            fillOpacity: 1,
            strokeColor: '#fff',
            strokeWeight: 3,
        },
        zIndex: 999,
    });
    userMarker.addListener('click', () => {
        infoWindow.setContent('<div style="font-weight:800; color:#4f46e5;">📍 My Location</div>');
        infoWindow.open(map, userMarker);
    });
    map.setCenter({ lat, lng });
    map.setZoom(11);
}

// ---- Hotel markers ----
function placeHotelMarkers() {
    hotelMarkers.forEach(m => m.setMap(null));
    hotelMarkers = [];
    HOTELS_DATA.forEach(h => {
        if (!h.lat || !h.lng) return;
        const marker = new google.maps.Marker({
            position: { lat: h.lat, lng: h.lng },
            map,
            title: h.name,
            icon: {
                url: 'https://maps.google.com/mapfiles/ms/icons/green-dot.png',
            },
        });
        marker.addListener('click', () => {
            infoWindow.setContent(
                `<div style="max-width:200px;">
                    <div style="font-weight:800;">🏨 ${h.name}</div>
                    <div style="font-size:12px; color:#64748b; margin-top:4px;">${h.district ? h.district + ', ' : ''}${h.state}</div>
                    <div style="font-size:12px; margin-top:4px;">⭐ ${h.rating} &nbsp;·&nbsp; <strong>RM${h.price}/night</strong></div>
                </div>`
            );
            infoWindow.open(map, marker);
        });
        hotelMarkers.push(marker);
    });
}

// ---- Render all days ----
function renderAllDays() {
    for (let d = 1; d <= TOTAL_DAYS; d++) {
        dayVisible[d] = true;
        renderDay(d);
    }
    fitAllBounds();
}

// ---- Render a single day's route ----
function renderDay(day) {
    // Clear existing renderer + markers for this day
    if (dayRenderers[day]) {
        dayRenderers[day].setMap(null);
        dayRenderers[day] = null;
    }
    if (dayMarkers[day]) {
        dayMarkers[day].forEach(m => m.setMap(null));
        dayMarkers[day] = [];
    }

    if (!dayVisible[day]) return;

    const items = DAYS_DATA[day] || [];
    const color  = DAY_COLORS[day] || '#6366f1';

    // Build waypoints: for day 1 prepend user location; for day 2+ prepend last hotel of prev day
    let originLatLng = null;

    if (day === 1 && userLat !== null) {
        originLatLng = { lat: userLat, lng: userLng };
    } else if (day > 1) {
        // Find last item of previous day that has coords (ideally a hotel)
        const prevItems = DAYS_DATA[day - 1] || [];
        for (let i = prevItems.length - 1; i >= 0; i--) {
            if (prevItems[i].lat && prevItems[i].lng) {
                originLatLng = { lat: prevItems[i].lat, lng: prevItems[i].lng };
                break;
            }
        }
    }

    // Filter items with valid coords
    const validItems = items.filter(it => it.lat && it.lng);
    if (validItems.length === 0) return;

    // Place numbered markers
    dayMarkers[day] = [];
    const allPoints = [];

    if (originLatLng && day === 1) {
        allPoints.push(originLatLng);
    }

    validItems.forEach((item, idx) => {
        const pos = { lat: item.lat, lng: item.lng };
        allPoints.push(pos);

        const label = String.fromCharCode(65 + idx); // A, B, C...
        const marker = new google.maps.Marker({
            position: pos,
            map,
            label: {
                text: label,
                color: '#fff',
                fontWeight: 'bold',
                fontSize: '12px',
            },
            title: item.title,
            icon: {
                path: google.maps.SymbolPath.CIRCLE,
                scale: 14,
                fillColor: color,
                fillOpacity: 0.95,
                strokeColor: '#fff',
                strokeWeight: 2,
            },
        });

        marker.addListener('click', () => {
            const typeEmoji = {
                food: '🍜', hotel: '🏨', festival: '🎉', museum: '🏛️',
                heritage: '🏯', culture: '🎭', nature: '🌿', attraction: '📍',
            };
            const emoji = typeEmoji[item.type] || '📍';
            infoWindow.setContent(
                `<div style="max-width:220px;">
                    <div style="font-weight:800; font-size:13px;">${emoji} ${item.title}</div>
                    <div style="font-size:11px; color:#64748b; margin-top:4px;">Day ${day} · Stop ${label}</div>
                    ${item.address ? `<div style="font-size:11px; margin-top:4px;">📍 ${item.address}</div>` : ''}
                    ${item.opening_hours ? `<div style="font-size:11px; margin-top:4px;">🕐 ${item.opening_hours}</div>` : ''}
                    <div style="font-size:11px; margin-top:4px;">
                        ${item.start_fmt} – ${item.end_fmt}
                        ${item.cost > 0 ? ` · RM${item.cost.toFixed(2)}` : ' · Free'}
                    </div>
                </div>`
            );
            infoWindow.open(map, marker);
        });

        dayMarkers[day].push(marker);
    });

    // Draw route via Directions API
    if (allPoints.length >= 2) {
        const directionsService  = new google.maps.DirectionsService();
        const directionsRenderer = new google.maps.DirectionsRenderer({
            map,
            suppressMarkers: true,
            polylineOptions: {
                strokeColor: color,
                strokeWeight: 4,
                strokeOpacity: 0.85,
            },
        });
        dayRenderers[day] = directionsRenderer;

        const origin      = allPoints[0];
        const destination = allPoints[allPoints.length - 1];
        const waypoints   = allPoints.slice(1, -1).map(p => ({ location: p, stopover: true }));

        const travelMode = getTravelMode(currentTransport);

        directionsService.route({
            origin,
            destination,
            waypoints,
            travelMode,
            optimizeWaypoints: false,
        }, (result, status) => {
            if (status === 'OK') {
                directionsRenderer.setDirections(result);
            } else {
                // Fallback: draw polyline manually
                const polyline = new google.maps.Polyline({
                    path: allPoints,
                    map,
                    strokeColor: color,
                    strokeWeight: 3,
                    strokeOpacity: 0.7,
                    icons: [{
                        icon: { path: google.maps.SymbolPath.FORWARD_CLOSED_ARROW, scale: 3 },
                        offset: '50%',
                    }],
                });
                if (!dayRenderers[day]) dayRenderers[day] = { setMap: () => {}, _poly: polyline };
                else dayRenderers[day]._poly = polyline;
            }
        });
    }
}

// ---- Get Google Maps TravelMode ----
function getTravelMode(mode) {
    const m = {
        car:              google.maps.TravelMode.DRIVING,
        motorcycle:       google.maps.TravelMode.DRIVING,
        public_transport: google.maps.TravelMode.TRANSIT,
        walking:          google.maps.TravelMode.WALKING,
    };
    return m[mode] || google.maps.TravelMode.DRIVING;
}

// ---- Fit map to show all points ----
function fitAllBounds() {
    const bounds = new google.maps.LatLngBounds();
    let hasPoints = false;

    if (userLat !== null) {
        bounds.extend({ lat: userLat, lng: userLng });
        hasPoints = true;
    }
    for (let d = 1; d <= TOTAL_DAYS; d++) {
        (DAYS_DATA[d] || []).forEach(item => {
            if (item.lat && item.lng) {
                bounds.extend({ lat: item.lat, lng: item.lng });
                hasPoints = true;
            }
        });
    }
    HOTELS_DATA.forEach(h => {
        if (h.lat && h.lng) {
            bounds.extend({ lat: h.lat, lng: h.lng });
        }
    });
    if (hasPoints) map.fitBounds(bounds, { padding: 60 });
}

// ---- Toggle day visibility ----
function toggleDay(day) {
    dayVisible[day] = !dayVisible[day];
    const legend = document.getElementById('legend-' + day);
    if (legend) legend.style.opacity = dayVisible[day] ? '1' : '0.35';
    renderDay(day);
}

// ---- Show day timetable ----
function showDay(d) {
    currentDay = d;
    document.querySelectorAll('.day-box').forEach(el => el.style.display = 'none');
    const box = document.getElementById('day-' + d);
    if (box) box.style.display = 'block';

    const col = DAY_COLORS[d] || '#6366f1';
    document.querySelectorAll('.day-tab').forEach((btn, i) => {
        const isActive = (i + 1) === d;
        btn.classList.toggle('active', isActive);
        btn.style.background    = isActive ? col : '';
        btn.style.borderColor   = isActive ? col : '';
        btn.style.color         = isActive ? '#fff' : '';
    });

    // Focus map on this day's first place
    const items = (DAYS_DATA[d] || []).filter(it => it.lat && it.lng);
    if (items.length > 0 && map) {
        map.panTo({ lat: items[0].lat, lng: items[0].lng });
        map.setZoom(12);
    }
}

// ---- Set transport mode ----
function setTransport(mode) {
    currentTransport = mode;
    document.querySelectorAll('.transport-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    const modeMap = { car: 0, motorcycle: 1, public_transport: 2, walking: 3 };
    const btns = document.querySelectorAll('.transport-btn');
    if (btns[modeMap[mode]]) btns[modeMap[mode]].classList.add('active');

    // Re-render all day routes with new travel mode
    for (let d = 1; d <= TOTAL_DAYS; d++) {
        renderDay(d);
    }
}

// ---- Focus on a place ----
function focusPlace(lat, lng, title) {
    if (!map || !lat || !lng) return;
    map.panTo({ lat, lng });
    map.setZoom(15);
}

// ---- Focus on a hotel ----
function focusHotel(lat, lng, name) {
    if (!map || !lat || !lng) return;
    map.panTo({ lat, lng });
    map.setZoom(15);
    infoWindow.setContent(`<div style="font-weight:800;">🏨 ${name}</div>`);
    // Find and open on the hotel marker
    const hm = hotelMarkers.find(m => {
        const p = m.getPosition();
        return Math.abs(p.lat() - lat) < 0.0001 && Math.abs(p.lng() - lng) < 0.0001;
    });
    if (hm) infoWindow.open(map, hm);
}

// ---- Locate me button ----
function locateMe() {
    if (!navigator.geolocation) { alert('Geolocation not supported.'); return; }
    navigator.geolocation.getCurrentPosition(
        pos => {
            userLat = pos.coords.latitude;
            userLng = pos.coords.longitude;
            placeUserMarker(userLat, userLng);
            // Re-render day 1 with new origin
            renderDay(1);
            loadWeather(userLat, userLng);
        },
        () => alert('Could not get your location. Please allow location access.')
    );
}

// ---- Weather ----
async function loadWeather(lat, lng) {
    const chip = document.getElementById('weatherChip');
    if (!WEATHER_KEY || WEATHER_KEY.includes('PASTE_')) {
        chip.textContent = '🌤 Weather: N/A';
        return;
    }
    try {
        const r = await fetch(`https://api.openweathermap.org/data/2.5/weather?lat=${lat}&lon=${lng}&appid=${WEATHER_KEY}&units=metric`);
        const j = await r.json();
        const desc = j.weather?.[0]?.description || 'Unknown';
        const temp = j.main?.temp !== undefined ? Math.round(j.main.temp) + '°C' : '';
        const icon = j.weather?.[0]?.icon ? `<img src="https://openweathermap.org/img/wn/${j.weather[0].icon}.png" style="width:20px;height:20px;vertical-align:middle;">` : '🌤';
        chip.innerHTML = `${icon} ${capitalise(desc)} ${temp}`;
        if (['Rain','Thunderstorm','Drizzle'].includes(j.weather?.[0]?.main)) {
            chip.style.background = '#fef9c3';
            chip.style.borderColor = '#fbbf24';
        }
    } catch(e) {
        chip.textContent = '🌤 Weather: —';
    }
}

function capitalise(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : s; }
</script>

<!-- Load Google Maps API -->
<script src="https://maps.googleapis.com/maps/api/js?key=<?php echo htmlspecialchars($googleMapsKey); ?>&callback=initMap&libraries=geometry" async defer></script>
</body>
</html>
