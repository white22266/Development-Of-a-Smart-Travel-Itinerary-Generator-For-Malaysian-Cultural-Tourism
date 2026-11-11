<?php
// api/road_conditions.php
// What is happening right now on and near the route of one day of the traveller's own
// itinerary: traffic jams, closed and flooded roads, official weather warnings and fires
// seen by satellite (see RoadConditionsService for the sources). The traveller asks for it
// on the itinerary page, typically before setting off.
//
//   GET ?itinerary_id=<id>&day=<n>[&mode=car|motorcycle|public_transport|walking]

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once "../config/db_connect.php";
require_once "../services/ItineraryRouteService.php";
require_once "../services/RoadConditionsService.php";

header("Content-Type: application/json; charset=utf-8");

function road_conditions_reply(array $payload, int $httpStatus = 200): never
{
    http_response_code($httpStatus);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
    road_conditions_reply(["status" => "error", "message" => "Unauthorized"], 401);
}
$travellerId = (int)($_SESSION["traveller_id"] ?? 0);
session_write_close();   // the sources can take a few seconds: do not hold up the traveller's other requests

$itineraryId = (int)($_GET["itinerary_id"] ?? 0);
$dayNo = (int)($_GET["day"] ?? 0);

$stmt = $conn->prepare("
    SELECT i.total_days, tp.transport_type
    FROM itineraries i
    LEFT JOIN traveller_preferences tp ON tp.preference_id = i.preference_id
    WHERE i.itinerary_id = ? AND i.traveller_id = ?
    LIMIT 1
");
$stmt->bind_param("ii", $itineraryId, $travellerId);
$stmt->execute();
$itinerary = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$itinerary) {
    road_conditions_reply(["status" => "error", "message" => "Itinerary not found"], 404);
}
if ($dayNo < 1 || $dayNo > (int)$itinerary["total_days"]) {
    road_conditions_reply(["status" => "error", "message" => "No such day in this itinerary"], 422);
}

$mode = RouteService::normalizeTransportType((string)($_GET["mode"] ?? $itinerary["transport_type"] ?? "car"));
$route = ItineraryRouteService::dayRoute($conn, $itineraryId, $dayNo, $mode);

// The line travelled that day, and its stops.
$line = [];
foreach (($route["road"]["legs"] ?? []) as $leg) {
    $pieces = !empty($leg["transit"]["segments"]) ? array_column($leg["transit"]["segments"], "geometry") : [$leg["geometry"] ?? []];
    foreach ($pieces as $piece) {
        foreach ($piece as $point) {
            $line[] = $point;
        }
    }
}
$stops = array_map(static fn(array $stop): array => [$stop["lat"], $stop["lng"]], $route["stops"]);
if ($route["start"] !== null) {
    array_unshift($stops, [$route["start"]["lat"], $route["start"]["lng"]]);
}

// The states and districts of the day's places, for the weather warnings.
$areaStmt = $conn->prepare("
    SELECT DISTINCT cp.state, COALESCE(cp.district, '') AS district
    FROM itinerary_items ii
    JOIN cultural_places cp ON cp.place_id = ii.place_id
    WHERE ii.itinerary_id = ? AND ii.day_no = ?
");
$areaStmt->bind_param("ii", $itineraryId, $dayNo);
$areaStmt->execute();
$areas = $areaStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$areaStmt->close();

$conditions = RoadConditionsService::along($line, $stops, $areas, $route["date"]);
road_conditions_reply([
    "status" => "ok",
    "day" => $dayNo,
    "date" => $route["date"],
    "is_today" => $route["date"] === date("Y-m-d"),
    "traffic_enabled" => RoadConditionsService::trafficEnabled(),
] + $conditions);
