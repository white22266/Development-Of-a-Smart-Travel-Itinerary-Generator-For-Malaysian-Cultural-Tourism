<?php
// api/itinerary_route.php
// The road route of one day of the traveller's own itinerary, for the map on the itinerary
// page: the starting point, the stops, and for every leg its line, distance, time, main roads
// and turn-by-turn steps. Routing comes from OpenStreetMap data (see RouteService).
//
//   GET ?itinerary_id=<id>&day=<n>[&mode=car|motorcycle|public_transport|walking]

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once "../config/db_connect.php";
require_once "../services/ItineraryRouteService.php";

header("Content-Type: application/json; charset=utf-8");

function itinerary_route_reply(array $payload, int $httpStatus = 200): never
{
    http_response_code($httpStatus);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
    itinerary_route_reply(["status" => "error", "message" => "Unauthorized"], 401);
}
$travellerId = (int)($_SESSION["traveller_id"] ?? 0);
session_write_close();   // routing can take a second: do not hold up the traveller's other requests

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
    itinerary_route_reply(["status" => "error", "message" => "Itinerary not found"], 404);
}
if ($dayNo < 1 || $dayNo > (int)$itinerary["total_days"]) {
    itinerary_route_reply(["status" => "error", "message" => "No such day in this itinerary"], 422);
}

// The map can show the same stops for another way of travelling. Nothing is saved.
$mode = RouteService::normalizeTransportType((string)($_GET["mode"] ?? $itinerary["transport_type"] ?? "car"));

$route = ItineraryRouteService::dayRoute($conn, $itineraryId, $dayNo, $mode);
itinerary_route_reply(["status" => "ok", "mode" => $mode] + $route);
