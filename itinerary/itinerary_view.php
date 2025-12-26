<?php
session_start();
require_once "../config/db_connect.php";
require_once "../config/api_keys.php";

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
  header("Location: ../auth/login.php?role=traveller");
  exit;
}
$travellerId = (int)($_SESSION["traveller_id"] ?? 0);

$itineraryId = (int)($_GET["itinerary_id"] ?? 0);
if ($itineraryId <= 0) {
  header("Location: my_itineraries.php");
  exit;
}

$stmt = $conn->prepare("SELECT * FROM itineraries WHERE itinerary_id=? AND traveller_id=? LIMIT 1");
$stmt->bind_param("ii", $itineraryId, $travellerId);
$stmt->execute();
$it = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$it) {
  header("Location: my_itineraries.php");
  exit;
}

// items + join place coords
$stmt = $conn->prepare("
  SELECT ii.item_id, ii.day_no, ii.sequence_no, ii.item_type, ii.place_id, ii.item_title,
         ii.estimated_cost, ii.distance_km, ii.travel_time_min,
         cp.latitude, cp.longitude, cp.address, cp.category
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
$totalDays = (int)$it["total_days"];
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <title>Itinerary View</title>
  <link rel="stylesheet" href="../assets/dashboard_style.css">
  <style>
    .day-tabs {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
      margin: 12px 0;
    }

    .day-tabs button {
      padding: 8px 10px;
      border-radius: 10px;
      border: 1px solid rgba(15, 23, 42, 0.12);
      background: #fff;
      cursor: pointer;
    }

    .day-tabs button.active {
      font-weight: 900;
    }

    #map {
      width: 100%;
      height: 360px;
      border-radius: 14px;
      border: 1px solid rgba(15, 23, 42, 0.10);
    }

    .badge {
      display: inline-block;
      padding: 4px 8px;
      border-radius: 999px;
      font-size: 12px;
      font-weight: 800;
      border: 1px solid rgba(15, 23, 42, 0.12);
    }
  </style>
</head>

<body>
  <div class="app">
    <main class="content" style="padding:24px;">
      <div class="topbar">
        <div class="page-title">
          <h1><?php echo htmlspecialchars($it["title"]); ?></h1>
          <p class="meta">Route optimization uses Google Maps.</p>
        </div>
        <div class="actions">
          <a class="btn btn-ghost" href="my_itineraries.php">Back</a>
          <a class="btn btn-primary" href="trip_summary.php?itinerary_id=<?php echo $itineraryId; ?>">Trip Summary</a>
        </div>
      </div>

      <div class="card">
        <h3>Map & Route</h3>
        <div id="map"></div>

        <div style="margin-top:12px; display:flex; gap:10px; flex-wrap:wrap;">
          <button class="btn btn-primary" type="button" onclick="optimizeRoute()">Optimize Route (Day)</button>
          <span class="badge" id="weatherBadge">Weather: —</span>
        </div>

        <p class="meta" style="margin-top:10px;">
          Notes: The system will calculate A → B → C route distance/time and save into database.
        </p>
      </div>

      <div class="card" style="margin-top:14px;">
        <h3>Daily Schedule</h3>

        <div class="day-tabs">
          <?php for ($d = 1; $d <= $totalDays; $d++): ?>
            <button type="button" class="<?php echo $d === 1 ? 'active' : ''; ?>" onclick="showDay(<?php echo $d; ?>)">
              Day <?php echo $d; ?>
            </button>
          <?php endfor; ?>
        </div>

        <?php for ($d = 1; $d <= $totalDays; $d++): ?>
          <div class="day-box" id="day-<?php echo $d; ?>" style="<?php echo $d === 1 ? '' : 'display:none;'; ?>">
            <h4>Day <?php echo $d; ?></h4>
            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Title</th>
                    <th>Type</th>
                    <th>Cost (RM)</th>
                    <th>Distance (km)</th>
                    <th>Time (min)</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (!empty($days[$d])): ?>
                    <?php foreach ($days[$d] as $row): ?>
                      <tr data-item-id="<?php echo (int)$row["item_id"]; ?>"
                        data-lat="<?php echo htmlspecialchars($row["latitude"] ?? ""); ?>"
                        data-lng="<?php echo htmlspecialchars($row["longitude"] ?? ""); ?>"
                        data-title="<?php echo htmlspecialchars($row["item_title"]); ?>"
                        data-category="<?php echo htmlspecialchars($row["category"] ?? ""); ?>">
                        <td><?php echo (int)$row["sequence_no"]; ?></td>
                        <td><strong><?php echo htmlspecialchars($row["item_title"]); ?></strong></td>
                        <td><?php echo htmlspecialchars($row["item_type"]); ?></td>
                        <td><?php echo number_format((float)$row["estimated_cost"], 2); ?></td>
                        <td class="dist"><?php echo $row["distance_km"] !== null ? number_format((float)$row["distance_km"], 2) : "-"; ?></td>
                        <td class="tmin"><?php echo $row["travel_time_min"] !== null ? (int)$row["travel_time_min"] : "-"; ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <tr>
                      <td colspan="6">No items.</td>
                    </tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        <?php endfor; ?>
      </div>

    </main>
  </div>

  <script>
    let currentDay = 1;

    function showDay(d) {
      currentDay = d;
      document.querySelectorAll(".day-box").forEach(el => el.style.display = "none");
      document.getElementById("day-" + d).style.display = "block";
      document.querySelectorAll(".day-tabs button").forEach((b, i) => b.classList.toggle("active", (i + 1) === d));
      renderMapForDay(d);
      loadWeatherForDay(d);
    }

    let map, directionsService, directionsRenderer, markers = [];

    function initMap() {
      map = new google.maps.Map(document.getElementById("map"), {
        zoom: 10,
        center: {
          lat: 1.5,
          lng: 103.7
        }
      });
      directionsService = new google.maps.DirectionsService();
      directionsRenderer = new google.maps.DirectionsRenderer({
        map
      });
      renderMapForDay(1);
      loadWeatherForDay(1);
    }

    function clearMarkers() {
      markers.forEach(m => m.setMap(null));
      markers = [];
    }

    function getDayRows(day) {
      return Array.from(document.querySelectorAll("#day-" + day + " tbody tr")).filter(tr => tr.dataset.lat && tr.dataset.lng);
    }

    function renderMapForDay(day) {
      clearMarkers();
      directionsRenderer.set("directions", null);

      const rows = getDayRows(day);
      if (rows.length === 0) return;

      const points = rows.map(r => ({
        lat: parseFloat(r.dataset.lat),
        lng: parseFloat(r.dataset.lng),
        title: r.dataset.title
      }));
      map.setCenter(points[0]);

      // markers with labels A, B, C...
      points.forEach((p, idx) => {
        const label = String.fromCharCode(65 + idx); // A B C...
        const marker = new google.maps.Marker({
          position: p,
          map,
          label,
          title: p.title
        });
        markers.push(marker);
      });

      if (points.length >= 2) {
        const origin = points[0];
        const destination = points[points.length - 1];
        const waypoints = points.slice(1, -1).map(p => ({
          location: p,
          stopover: true
        }));

        directionsService.route({
          origin,
          destination,
          waypoints,
          travelMode: google.maps.TravelMode.DRIVING
        }, (result, status) => {
          if (status === "OK") {
            directionsRenderer.setDirections(result);
          }
        });
      }
    }

    async function optimizeRoute() {
      const rows = getDayRows(currentDay);
      if (rows.length < 2) {
        alert("Need at least 2 places to optimize route.");
        return;
      }

      const points = rows.map(r => ({
        lat: parseFloat(r.dataset.lat),
        lng: parseFloat(r.dataset.lng)
      }));
      const origin = points[0];
      const destination = points[points.length - 1];
      const waypoints = points.slice(1, -1).map(p => ({
        location: p,
        stopover: true
      }));

      directionsService.route({
        origin,
        destination,
        waypoints,
        travelMode: google.maps.TravelMode.DRIVING,
        optimizeWaypoints: true
      }, async (result, status) => {
        if (status !== "OK") {
          alert("Route optimization failed: " + status);
          return;
        }
        directionsRenderer.setDirections(result);

        // compute legs distance/time, save back to DB
        const legs = result.routes[0].legs;
        // legs[i] is segment from i -> i+1
        // We'll save distance/time into the NEXT item row (sequence 2..n)
        for (let i = 0; i < legs.length; i++) {
          const distKm = legs[i].distance.value / 1000.0;
          const timeMin = Math.round(legs[i].duration.value / 60.0);

          const targetRow = rows[i + 1]; // save into B,C,D...
          targetRow.querySelector(".dist").textContent = distKm.toFixed(2);
          targetRow.querySelector(".tmin").textContent = String(timeMin);

          await fetch("route_save.php", {
            method: "POST",
            headers: {
              "Content-Type": "application/x-www-form-urlencoded"
            },
            body: new URLSearchParams({
              item_id: targetRow.dataset.itemId,
              distance_km: distKm.toFixed(2),
              travel_time_min: timeMin
            })
          });
        }

        alert("Route saved to database.");
      });
    }

    async function loadWeatherForDay(day) {
      const rows = getDayRows(day);
      const badge = document.getElementById("weatherBadge");
      if (rows.length === 0) {
        badge.textContent = "Weather: —";
        return;
      }

      const lat = rows[0].dataset.lat;
      const lng = rows[0].dataset.lng;

      // simple current weather check (client-side)
      const key = "<?php echo htmlspecialchars(OPENWEATHER_API_KEY); ?>";
      if (!key || key.includes("PASTE_")) {
        badge.textContent = "Weather: API key missing";
        return;
      }

      try {
        const url = `https://api.openweathermap.org/data/2.5/weather?lat=${lat}&lon=${lng}&appid=${key}`;
        const r = await fetch(url);
        const j = await r.json();
        const main = j.weather?.[0]?.main || "Unknown";
        badge.textContent = "Weather: " + main;

        // if rainy, warn for outdoor category
        if (["Rain", "Thunderstorm", "Drizzle"].includes(main)) {
          alert("Weather warning: Rain detected. Outdoor items may be affected.");
        }
      } catch (e) {
        badge.textContent = "Weather: error";
      }
    }

    showDay(1);
  </script>

  <script src="https://maps.googleapis.com/maps/api/js?key=<?php echo htmlspecialchars(GOOGLE_MAPS_API_KEY); ?>&callback=initMap" async defer></script>
</body>

</html>