<?php
// itinerary/route_optimize.php
session_start();
require_once "../config/db_connect.php";
require_once "../config/api_keys.php";

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
    header("Location: ../auth/login.php?role=traveller");
    exit;
}

$travellerId = (int)($_SESSION["traveller_id"] ?? 0);
$itineraryId = (int)($_GET["itinerary_id"] ?? 0);

if ($travellerId <= 0 || $itineraryId <= 0) {
    header("Location: ../dashboard/traveller_dashboard.php");
    exit;
}

/* 1) Ensure itinerary belongs to this traveller */
$stmt = $conn->prepare("SELECT itinerary_id, total_days, title FROM itineraries WHERE itinerary_id=? AND traveller_id=? LIMIT 1");
$stmt->bind_param("ii", $itineraryId, $travellerId);
$stmt->execute();
$it = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$it) {
    header("Location: ../dashboard/traveller_dashboard.php");
    exit;
}

/* 2) Load itinerary items */
$stmt = $conn->prepare("
  SELECT item_id, day_no, sequence_no, place_id, item_title
  FROM itinerary_items
  WHERE itinerary_id = ?
  ORDER BY day_no ASC, sequence_no ASC
");
$stmt->bind_param("i", $itineraryId);
$stmt->execute();
$res = $stmt->get_result();

$itemsByDay = [];
$placeIds = [];
while ($row = $res->fetch_assoc()) {
    $d = (int)$row["day_no"];
    if (!isset($itemsByDay[$d])) $itemsByDay[$d] = [];
    $itemsByDay[$d][] = $row;

    if (!empty($row["place_id"])) $placeIds[(int)$row["place_id"]] = true;
}
$stmt->close();

if (empty($itemsByDay)) {
    $_SESSION["form_errors"] = ["No itinerary items found."];
    header("Location: itinerary_view.php?itinerary_id=" . $itineraryId);
    exit;
}

/* 3) Fetch lat/lng for places used */
$coords = []; // place_id => [lat,lng]
if (!empty($placeIds)) {
    $ids = array_keys($placeIds);
    $ph = implode(",", array_fill(0, count($ids), "?"));
    $types = str_repeat("i", count($ids));

    $sql = "SELECT place_id, latitude, longitude FROM cultural_places WHERE place_id IN ($ph)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $r2 = $stmt->get_result();
    while ($p = $r2->fetch_assoc()) {
        $pid = (int)$p["place_id"];
        $lat = $p["latitude"];
        $lng = $p["longitude"];
        if ($lat !== null && $lng !== null && $lat !== "" && $lng !== "") {
            $coords[$pid] = [(float)$lat, (float)$lng];
        }
    }
    $stmt->close();
}

/* Helper: call Google Distance Matrix */
function distanceMatrix($origLat, $origLng, $destLat, $destLng)
{
    if (!defined("GOOGLE_MAPS_API_KEY") || GOOGLE_MAPS_API_KEY === "" || GOOGLE_MAPS_API_KEY === "AIzaSyBh7DdaR1qECkdYFpJOJhwF6iOXq66TRdo") {
        return null;
    }

    $orig = $origLat . "," . $origLng;
    $dest = $destLat . "," . $destLng;

    $url = "https://maps.googleapis.com/maps/api/distancematrix/json"
        . "?origins=" . urlencode($orig)
        . "&destinations=" . urlencode($dest)
        . "&mode=driving"
        . "&key=" . urlencode(GOOGLE_MAPS_API_KEY);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 12);
    $raw = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $http !== 200) return null;

    $data = json_decode($raw, true);
    if (!is_array($data)) return null;

    $element = $data["rows"][0]["elements"][0] ?? null;
    if (!$element || ($element["status"] ?? "") !== "OK") return null;

    $distM = (float)($element["distance"]["value"] ?? 0);  // meters
    $durS  = (float)($element["duration"]["value"] ?? 0);  // seconds

    return [
        "distance_km" => round($distM / 1000, 2),
        "time_min" => (int)round($durS / 60)
    ];
}

/* 4) Update items: store distance/time to reach each item from previous item (same day) */
$update = $conn->prepare("UPDATE itinerary_items SET distance_km=?, travel_time_min=? WHERE item_id=?");

$updatedCount = 0;
$skippedCount = 0;

foreach ($itemsByDay as $day => $arr) {
    // Need at least 2 points to compute a segment
    for ($i = 1; $i < count($arr); $i++) {
        $prev = $arr[$i - 1];
        $cur  = $arr[$i];

        $prevPid = (int)($prev["place_id"] ?? 0);
        $curPid  = (int)($cur["place_id"] ?? 0);

        if ($prevPid <= 0 || $curPid <= 0) {
            $skippedCount++;
            continue;
        }
        if (!isset($coords[$prevPid]) || !isset($coords[$curPid])) {
            $skippedCount++;
            continue;
        }

        [$olat, $olng] = $coords[$prevPid];
        [$dlat, $dlng] = $coords[$curPid];

        $result = distanceMatrix($olat, $olng, $dlat, $dlng);
        if ($result === null) {
            $skippedCount++;
            continue;
        }

        $dkm = (float)$result["distance_km"];
        $tmin = (int)$result["time_min"];
        $itemIdToUpdate = (int)$cur["item_id"];

        $update->bind_param("dii", $dkm, $tmin, $itemIdToUpdate);
        if ($update->execute()) $updatedCount++;
    }
}
$update->close();

$_SESSION["success_message"] = "Route optimisation completed. Updated: {$updatedCount}, Skipped: {$skippedCount}.";
header("Location: itinerary_view.php?itinerary_id=" . $itineraryId);
exit;
