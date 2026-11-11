<?php
// api/geo_search.php
// Place and address search for the forms and the travel assistant ("where do you start
// from?", "where is this place?"). It answers from OpenStreetMap data through
// GeocodingService: this server caches the results and keeps to the search services' limits,
// and no API key is involved.
//
//   GET ?q=<text>             -> {"status":"ok","results":[{"name","detail","label","lat","lng"}]}
//   GET ?lat=<lat>&lng=<lng>  -> {"status":"ok","address":"...","state_iso":"MY-04","district":"..."}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once "../config/env_loader.php";
require_once "../services/GeocodingService.php";

header("Content-Type: application/json; charset=utf-8");

function geo_search_reply(array $payload, int $httpStatus = 200): never
{
    http_response_code($httpStatus);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true) {
    geo_search_reply(["status" => "error", "message" => "Unauthorized"], 401);
}

// A signed-in user searches while typing, so many requests are normal. Not without limit, though.
$now = time();
$recent = array_values(array_filter((array)($_SESSION["geo_search_times"] ?? []), static fn($t) => (int)$t > $now - 60));
if (count($recent) >= 40) {
    geo_search_reply(["status" => "error", "message" => "Too many searches. Please wait a moment."], 429);
}
$recent[] = $now;
$_SESSION["geo_search_times"] = $recent;
session_write_close();   // a lookup can take a second: do not hold up the user's other requests

if (isset($_GET["lat"], $_GET["lng"])) {
    $lat = (float)$_GET["lat"];
    $lng = (float)$_GET["lng"];
    // Roughly Malaysia: this app has no use for addresses elsewhere.
    if ($lat < 0.5 || $lat > 7.8 || $lng < 99.0 || $lng > 120.0) {
        geo_search_reply(["status" => "ok", "address" => null, "state_iso" => null]);
    }
    $where = GeocodingService::reverse($lat, $lng);
    geo_search_reply([
        "status" => "ok",
        "address" => $where["address"] ?? null,
        "state_iso" => $where["state_iso"] ?? null,
        "district" => $where["district"] ?? null,
    ]);
}

$results = GeocodingService::search((string)($_GET["q"] ?? ""), 6);
foreach ($results as &$result) {
    $result["label"] = $result["detail"] !== "" ? $result["name"] . ", " . $result["detail"] : $result["name"];
}
unset($result);
geo_search_reply(["status" => "ok", "results" => $results]);
