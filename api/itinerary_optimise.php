<?php
// api/itinerary_optimise.php
// Puts the stops of every day of the traveller's own itinerary into the order with the least
// travelling, measured on the road for the trip's transport, and times the days again with the
// planner's rules (see ItineraryEditService::reorderTrip()). A stop no order can keep gives way
// to the places the day search would take next, inside the budget rule; a must-see place is never
// dropped for a better order. The traveller asks for this on the itinerary page, usually after
// changing stops or hotels.
//
//   POST itinerary_id=<id>
//
// The answer: status ("ok"), message (what was done, in the page's language: the days in a
// shorter order, the stops replaced and those removed without a replacement, a day kept as it
// was, the amount over the budget), report (ItineraryEditService::reorderTrip()), over_by, total
// and budget.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once "../config/db_connect.php";
require_once "../config/i18n.php";
require_once "../services/ItineraryEditService.php";

header("Content-Type: application/json; charset=utf-8");

function itinerary_optimise_reply(array $payload, int $httpStatus = 200): never
{
    http_response_code($httpStatus);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
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
    $result = ItineraryEditService::optimise($conn, $itineraryId);
} catch (Throwable $e) {
    error_log("itinerary_optimise: " . $e->getMessage());
    itinerary_optimise_reply(["status" => "error", "message" => t("optm.failed")], 500);
}

// The report goes under "report", so the page shows "message" as it is (assets/itinerary_map.js
// words its own sentence only when the answer has reordered_days at the top).
$report = $result["report"];
$message = ItineraryEditService::optimiseSentence($report, static fn(string $key, array $vars = []): string => t($key, $vars));
itinerary_optimise_reply([
    "status" => "ok",
    "message" => $message,
    "report" => $report,
    "over_by" => (float)($report["over_by"] ?? 0),
    "total" => (float)($report["total"] ?? 0),
    "budget" => (float)($report["budget"] ?? 0),
]);
