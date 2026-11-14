<?php
// api/itinerary_optimise.php
// Puts the stops of every day of the traveller's own itinerary into the order with the least
// travelling, measured on the road for the trip's transport, and times the days again with the
// planner's rules (see ItineraryRouteOptimiser); a stop no order can keep is removed. The
// traveller asks for this on the itinerary page, usually after changing stops or hotels.
//
//   POST itinerary_id=<id>

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once "../config/db_connect.php";
require_once "../services/ItineraryEditService.php";

header("Content-Type: application/json; charset=utf-8");

function itinerary_optimise_reply(array $payload, int $httpStatus = 200): never
{
    http_response_code($httpStatus);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
    itinerary_optimise_reply(["status" => "error", "message" => "Unauthorized"], 401);
}
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    itinerary_optimise_reply(["status" => "error", "message" => "Use POST"], 405);
}
$travellerId = (int)($_SESSION["traveller_id"] ?? 0);
session_write_close();   // routing can take a few seconds: do not hold up the traveller's other requests

$itineraryId = (int)($_POST["itinerary_id"] ?? 0);
$stmt = $conn->prepare("SELECT itinerary_id FROM itineraries WHERE itinerary_id = ? AND traveller_id = ? LIMIT 1");
$stmt->bind_param("ii", $itineraryId, $travellerId);
$stmt->execute();
$found = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$found) {
    itinerary_optimise_reply(["status" => "error", "message" => "Itinerary not found"], 404);
}

// One transaction of its own (ItineraryEditService::optimise()): a failure changes nothing.
try {
    $report = ItineraryEditService::optimise($conn, $itineraryId)["report"];
} catch (Throwable $e) {
    error_log("itinerary_optimise: " . $e->getMessage());
    itinerary_optimise_reply(["status" => "error", "message" => "The route could not be optimised. Nothing was changed."], 500);
}

$days = $report["reordered_days"];
if ($days) {
    $list = count($days) === 1 ? "Day " . $days[0] : "Days " . implode(", ", array_slice($days, 0, -1)) . " and " . end($days);
    $message = $list . (count($days) === 1 ? " is" : " are") . " now in a shorter order"
        . ($report["saved_min"] > 0 ? ", with " . $report["saved_min"] . " min less travelling" : "") . ". Times are up to date.";
} else {
    $message = "Every day is already in its best order. Times are up to date.";
}
if (!empty($report["removed"])) {
    // A stop that no order can keep within the trip's rules (opening hours, day window, distance) is taken out.
    $message .= " Removed because no order keeps it within the trip's rules: " . implode("; ", $report["removed"]) . ".";
}
itinerary_optimise_reply(["status" => "ok", "message" => $message] + $report);
