<?php
// api/weather.php
// The weather forecast for each day of the traveller's own itinerary, at the first stop of
// that day. Forecasts come from Open-Meteo (see WeatherService) and reach 16 days ahead.
//
//   GET ?itinerary_id=<id>  ->  {"status":"ok","credit":"...","days":{"1":{"date","place","forecast"|null,"text","note"}}}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once "../config/db_connect.php";
require_once "../services/WeatherService.php";

header("Content-Type: application/json; charset=utf-8");

function weather_reply(array $payload, int $httpStatus = 200): never
{
    http_response_code($httpStatus);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
    weather_reply(["status" => "error", "message" => "Unauthorized"], 401);
}
$travellerId = (int)($_SESSION["traveller_id"] ?? 0);
session_write_close();

$itineraryId = (int)($_GET["itinerary_id"] ?? 0);
$stmt = $conn->prepare("SELECT start_date, total_days, origin_lat, origin_lng FROM itineraries WHERE itinerary_id = ? AND traveller_id = ? LIMIT 1");
$stmt->bind_param("ii", $itineraryId, $travellerId);
$stmt->execute();
$itinerary = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$itinerary) {
    weather_reply(["status" => "error", "message" => "Itinerary not found"], 404);
}

// The first stop with coordinates on each day says where that day is spent.
$stmt = $conn->prepare("
    SELECT ii.day_no, ii.item_title,
           COALESCE(ii.item_latitude, cp.latitude) AS latitude,
           COALESCE(ii.item_longitude, cp.longitude) AS longitude
    FROM itinerary_items ii
    LEFT JOIN cultural_places cp ON cp.place_id = ii.place_id
    WHERE ii.itinerary_id = ? AND ii.item_type NOT IN ('hotel', 'transport', 'note')
    ORDER BY ii.day_no, ii.sequence_no
");
$stmt->bind_param("i", $itineraryId);
$stmt->execute();
$where = [];
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
    $dayNo = (int)$row["day_no"];
    if (!isset($where[$dayNo]) && $row["latitude"] !== null && $row["longitude"] !== null) {
        $where[$dayNo] = ["place" => (string)$row["item_title"], "lat" => (float)$row["latitude"], "lng" => (float)$row["longitude"]];
    }
}
$stmt->close();

$timezone = new DateTimeZone("Asia/Kuala_Lumpur");
$today = new DateTimeImmutable("today", $timezone);
$start = !empty($itinerary["start_date"]) ? DateTimeImmutable::createFromFormat("!Y-m-d", (string)$itinerary["start_date"], $timezone) : false;

$days = [];
for ($dayNo = 1; $dayNo <= (int)$itinerary["total_days"]; $dayNo++) {
    $entry = ["date" => null, "place" => $where[$dayNo]["place"] ?? null, "forecast" => null, "text" => null, "note" => null];
    if (!$start) {
        $entry["note"] = "Set a start date to see the forecast.";
    } elseif (!isset($where[$dayNo])) {
        $entry["note"] = "No stop with a location on this day.";
    } else {
        $date = $start->modify("+" . ($dayNo - 1) . " days");
        $entry["date"] = $date->format("Y-m-d");
        $daysAhead = (int)$today->diff($date)->format("%r%a");
        if ($daysAhead < 0) {
            $entry["note"] = "This day is in the past.";
        } elseif ($daysAhead > 15) {
            $entry["note"] = "Forecast available from " . $date->modify("-15 days")->format("j M Y") . ".";
        } else {
            $forecast = WeatherService::forDate($where[$dayNo]["lat"], $where[$dayNo]["lng"], $entry["date"]);
            if ($forecast === null) {
                $entry["note"] = "The weather service could not be reached.";
            } else {
                $entry["forecast"] = $forecast;
                $entry["text"] = WeatherService::describe($forecast) . " " . WeatherService::advice($forecast);
            }
        }
    }
    $days[(string)$dayNo] = $entry;
}

weather_reply(["status" => "ok", "credit" => WeatherService::CREDIT, "days" => $days]);
