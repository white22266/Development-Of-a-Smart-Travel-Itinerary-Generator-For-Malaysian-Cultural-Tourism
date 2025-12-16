<?php
// itinerary/itinerary_view.php
session_start();
require_once "../config/db_connect.php";
require_once "../config/api_keys.php";

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
    header("Location: ../auth/login.php?role=traveller");
    exit;
}

$travellerId = (int)($_SESSION["traveller_id"] ?? 0);
$travellerName = $_SESSION["traveller_name"] ?? "Traveller";

$itineraryId = (int)($_GET["itinerary_id"] ?? 0);
if ($itineraryId <= 0) {
    header("Location: ../dashboard/traveller_dashboard.php");
    exit;
}

// Load itinerary (ensure ownership)
$stmt = $conn->prepare("
  SELECT itinerary_id, title, total_days, total_estimated_cost, created_at
  FROM itineraries
  WHERE itinerary_id = ? AND traveller_id = ?
  LIMIT 1
");
$stmt->bind_param("ii", $itineraryId, $travellerId);
$stmt->execute();
$it = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$it) {
    header("Location: ../dashboard/traveller_dashboard.php");
    exit;
}

// Load items
$stmt = $conn->prepare("
SELECT 
  ii.day_no, ii.sequence_no, ii.item_type, ii.item_title, ii.estimated_cost, ii.notes,
  ii.place_id,
  cp.latitude, cp.longitude, cp.state, cp.category
FROM itinerary_items ii
LEFT JOIN cultural_places cp ON cp.place_id = ii.place_id
WHERE ii.itinerary_id = ?
ORDER BY ii.day_no ASC, ii.sequence_no ASC

");
$stmt->bind_param("i", $itineraryId);
$stmt->execute();
$res = $stmt->get_result();

$itemsByDay = [];
while ($row = $res->fetch_assoc()) {
    $d = (int)$row["day_no"];
    if (!isset($itemsByDay[$d])) $itemsByDay[$d] = [];
    $itemsByDay[$d][] = $row;
    $mapPoints[] = [
        "day" => $d,
        "seq" => (int)$row["sequence_no"],
        "title" => (string)$row["item_title"],
        "type" => (string)$row["item_type"],
        "cost" => (float)$row["estimated_cost"],
        "notes" => (string)$row["notes"],
        "lat" => $row["latitude"] !== null ? (float)$row["latitude"] : null,
        "lng" => $row["longitude"] !== null ? (float)$row["longitude"] : null,
        "state" => (string)($row["state"] ?? ""),
        "category" => (string)($row["category"] ?? "")
    ];
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Itinerary Result | Smart Travel Itinerary Generator</title>
    <link rel="stylesheet" href="../assets/itinerary_style.css">
    <link rel="stylesheet" href="../assets/route_style.css">

</head>

<body>
    <div class="app">
        <aside class="sidebar">
            <div class="brand">
                <div class="brand-badge">ST</div>
                <div class="brand-title">
                    <strong>Smart Travel Itinerary Generator</strong>
                    <span>Itinerary Result</span>
                </div>
            </div>

            <nav class="nav" aria-label="Sidebar Navigation">
                <a href="../dashboard/traveller_dashboard.php"><span class="dot"></span> Dashboard</a>
                <a href="preference_form.php"><span class="dot"></span> Traveler Preference Analyzer</a>
                <a class="active" href="generate_itinerary.php"><span class="dot"></span> Smart Itinerary Generator</a>
                <a href="my_itineraries.php"><span class="dot"></span> Cost Estimation and Trip Summary Module</a>
                <a href="../cultural/cultural_guide.php"><span class="dot"></span> Cultural Guide Presentation</a>
                <a href="../auth/logout.php"><span class="dot"></span> Logout</a>
            </nav>

            <div class="sidebar-footer">
                <div class="small">Logged in as:</div>
                <div style="margin-top:6px; font-weight:800;"><?php echo htmlspecialchars($travellerName); ?></div>
                <div class="chip">Role: Traveller</div>
            </div>
        </aside>

        <main class="content">
            <div class="topbar">
                <div class="page-title">
                    <h1><?php echo htmlspecialchars($it["title"]); ?></h1>
                    <p>
                        Generated itinerary with rule-based selection from the cultural database.
                        Created: <?php echo htmlspecialchars($it["created_at"]); ?>
                    </p>
                </div>
                <div class="actions">
                    <a class="btn btn-ghost" href="../dashboard/traveller_dashboard.php">Back to Dashboard</a>
                    <a class="btn btn-primary" href="preference_form.php">Generate Another</a>
                </div>
            </div>

            <section class="grid">
                <div class="card col-12">
                    <h3 class="section-title">Trip Summary</h3>
                    <p class="help">
                        Total Days: <strong><?php echo (int)$it["total_days"]; ?></strong> |
                        Estimated Cost (entry fees): <strong>RM <?php echo number_format((float)$it["total_estimated_cost"], 2); ?></strong>
                    </p>
                    <p class="small-note">
                        Note: Route/time optimisation and weather-aware adjustment will be refined in Module 4 and Weather API integration.
                    </p>
                </div>
                <div class="map-wrap" style="margin-top:12px;">
                    <div style="padding:12px 14px;">
                        <h3 class="section-title" style="margin-bottom:6px;">Route Map (Day-by-Day)</h3>
                        <p class="help" style="margin:0;">
                            Select a day to view markers and route. Click any marker to view place details.
                        </p>

                        <div class="map-toolbar">
                            <label style="font-size:13px; font-weight:800; color:var(--navy);">View:</label>
                            <select id="daySelect">
                                <option value="all">All Days</option>
                                <?php for ($d = 1; $d <= (int)$it["total_days"]; $d++): ?>
                                    <option value="<?php echo $d; ?>">Day <?php echo $d; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>

                    <div id="routeMap"></div>
                </div>

                <div class="col-12">
                    <?php for ($d = 1; $d <= (int)$it["total_days"]; $d++): ?>
                        <div class="day-block">
                            <div class="day-header">
                                <h3>Day <?php echo $d; ?></h3>
                                <span class="pill"><?php echo isset($itemsByDay[$d]) ? count($itemsByDay[$d]) : 0; ?> items</span>
                            </div>

                            <div class="table-wrap" style="box-shadow: var(--shadow); border-radius: var(--radius);">
                                <table class="it-table">
                                    <thead>
                                        <tr>
                                            <th style="width:80px;">No.</th>
                                            <th>Activity / Place</th>
                                            <th style="width:140px;">Type</th>
                                            <th style="width:160px;">Est. Cost (RM)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!isset($itemsByDay[$d])): ?>
                                            <tr>
                                                <td colspan="4">No items generated for this day.</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($itemsByDay[$d] as $row): ?>
                                                <tr>
                                                    <td><?php echo (int)$row["sequence_no"]; ?></td>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($row["item_title"]); ?></strong><br>
                                                        <span style="color:var(--muted); font-size:12px;">
                                                            <?php echo htmlspecialchars($row["notes"]); ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo htmlspecialchars(ucwords(str_replace("_", " ", $row["item_type"]))); ?></td>
                                                    <td><?php echo number_format((float)$row["estimated_cost"], 2); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endfor; ?>
                </div>
            </section>
        </main>
    </div>
    <script>
        const points = <?php echo json_encode($mapPoints, JSON_UNESCAPED_UNICODE); ?>;

        // Filter points with valid coords
        const validPoints = points.filter(p => p.lat !== null && p.lng !== null);

        let map, markers = [],
            polyline, info;

        function groupByDay(list) {
            const m = {};
            list.forEach(p => {
                const d = String(p.day);
                if (!m[d]) m[d] = [];
                m[d].push(p);
            });
            // sort each day by seq
            Object.keys(m).forEach(k => m[k].sort((a, b) => a.seq - b.seq));
            return m;
        }

        const byDay = groupByDay(validPoints);

        function clearMap() {
            markers.forEach(m => m.setMap(null));
            markers = [];
            if (polyline) polyline.setMap(null);
        }

        function fitBounds(list) {
            if (!list.length) return;
            const bounds = new google.maps.LatLngBounds();
            list.forEach(p => bounds.extend({
                lat: p.lat,
                lng: p.lng
            }));
            map.fitBounds(bounds);
        }

        function draw(list) {
            clearMap();
            if (!list.length) {
                // fallback center Malaysia
                map.setCenter({
                    lat: 4.2105,
                    lng: 101.9758
                });
                map.setZoom(6);
                return;
            }

            info = info || new google.maps.InfoWindow();

            // markers
            list.forEach((p, idx) => {
                const marker = new google.maps.Marker({
                    position: {
                        lat: p.lat,
                        lng: p.lng
                    },
                    map: map,
                    label: String(idx + 1)
                });

                marker.addListener("click", () => {
                    const html = `
          <div style="max-width:260px;">
            <div style="font-weight:900; margin-bottom:6px;">Day ${p.day} • #${p.seq}</div>
            <div style="font-weight:800;">${escapeHtml(p.title)}</div>
            <div style="font-size:12px; margin-top:4px; color:#334155;">
              Type: ${escapeHtml(p.type)}<br>
              State: ${escapeHtml(p.state)}<br>
              Est. Cost: RM ${Number(p.cost).toFixed(2)}
            </div>
            <div style="font-size:12px; margin-top:6px; color:#64748B;">
              ${escapeHtml(p.notes || "")}
            </div>
          </div>
        `;
                    info.setContent(html);
                    info.open(map, marker);
                });

                markers.push(marker);
            });

            // polyline (straight connection)
            const path = list.sort((a, b) => a.seq - b.seq).map(p => ({
                lat: p.lat,
                lng: p.lng
            }));
            polyline = new google.maps.Polyline({
                path,
                geodesic: true,
                strokeOpacity: 0.9,
                strokeWeight: 4
            });
            polyline.setMap(map);

            fitBounds(list);
        }

        function escapeHtml(str) {
            return String(str)
                .replaceAll("&", "&amp;")
                .replaceAll("<", "&lt;")
                .replaceAll(">", "&gt;")
                .replaceAll('"', "&quot;")
                .replaceAll("'", "&#039;");
        }

        function initMap() {
            map = new google.maps.Map(document.getElementById("routeMap"), {
                center: {
                    lat: 4.2105,
                    lng: 101.9758
                },
                zoom: 6,
                mapTypeControl: false,
                streetViewControl: false,
                fullscreenControl: true
            });

            // default: all days
            draw(validPoints);

            const sel = document.getElementById("daySelect");
            sel.addEventListener("change", () => {
                const v = sel.value;
                if (v === "all") draw(validPoints);
                else draw(byDay[v] || []);
            });
        }
    </script>

    <script async defer
        src="https://maps.googleapis.com/maps/api/js?key=<?php echo urlencode(GOOGLE_MAPS_API_KEY); ?>&callback=initMap">
    </script>

</body>

</html>