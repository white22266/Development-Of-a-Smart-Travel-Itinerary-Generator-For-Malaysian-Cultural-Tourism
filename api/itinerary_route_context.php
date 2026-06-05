<?php
// api/itinerary_route_context.php
// Returns the confirmed official origin plus whole-party cost context used by the itinerary page.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once "../config/db_connect.php";
require_once "../services/CostEstimationService.php";

header("Content-Type: application/json; charset=utf-8");

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "Unauthorized."]);
    exit;
}

$travellerId = (int)($_SESSION["traveller_id"] ?? 0);
$itineraryId = (int)($_GET["itinerary_id"] ?? 0);
if ($travellerId <= 0 || $itineraryId <= 0) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Invalid itinerary request."]);
    exit;
}

$partySelect = "";
$partyCheck = $conn->query("SHOW COLUMNS FROM traveller_preferences LIKE 'party_size'");
if ($partyCheck && $partyCheck->num_rows > 0) {
    $partySelect = ", tp.party_size";
}

$stmt = $conn->prepare("
    SELECT i.itinerary_id, i.origin_name, i.origin_lat, i.origin_lng,
           tp.traveller_type{$partySelect}
    FROM itineraries i
    LEFT JOIN traveller_preferences tp ON tp.preference_id = i.preference_id
    WHERE i.itinerary_id = ? AND i.traveller_id = ?
    LIMIT 1
");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Unable to load route context."]);
    exit;
}
$stmt->bind_param("ii", $itineraryId, $travellerId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    http_response_code(404);
    echo json_encode(["status" => "error", "message" => "Itinerary not found."]);
    exit;
}

$lat = $row["origin_lat"] !== null ? (float)$row["origin_lat"] : null;
$lng = $row["origin_lng"] !== null ? (float)$row["origin_lng"] : null;
$valid = $lat !== null && $lng !== null && is_finite($lat) && is_finite($lng)
    && !($lat == 0.0 && $lng == 0.0)
    && $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180;

$travellerType = (string)($row["traveller_type"] ?? "solo");
$partySize = CostEstimationService::resolvePartySize($travellerType, isset($row["party_size"]) ? (int)$row["party_size"] : null);
$roomCount = CostEstimationService::roomCount($partySize);

echo json_encode([
    "status" => "success",
    "origin" => $valid ? [
        "name" => trim((string)($row["origin_name"] ?? "Starting Location")),
        "lat" => $lat,
        "lng" => $lng,
    ] : null,
    "party_size" => $partySize,
    "room_count" => $roomCount,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
