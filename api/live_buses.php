<?php
// api/live_buses.php
// Where the buses and trains are right now, for the live layer of the maps (see
// BusTrackingService: open data from the operators through data.gov.my).
//
//   GET ?bbox=<west>,<south>,<east>,<north>          the vehicles in that part of the map
//   GET ?feed=<id>&trip=<trip id>&route=<route id>   the line that vehicle's route follows

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once "../config/db_connect.php";
require_once "../services/BusTrackingService.php";

header("Content-Type: application/json; charset=utf-8");

function live_buses_reply(array $payload, int $httpStatus = 200): never
{
    http_response_code($httpStatus);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// Signed-in travellers only: this is not a public relay for somebody else's data.
if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
    live_buses_reply(["status" => "error", "message" => "Unauthorized"], 401);
}
session_write_close();   // the feeds can take a few seconds: do not hold up the traveller's other requests

if (isset($_GET["feed"])) {
    $line = BusTrackingService::routeLine(
        substr((string)$_GET["feed"], 0, 40),
        substr((string)($_GET["trip"] ?? ""), 0, 80),
        substr((string)($_GET["route"] ?? ""), 0, 80)
    );
    if ($line === null) {
        live_buses_reply(["status" => "empty", "message" => "The line of this route is not published."]);
    }
    live_buses_reply(["status" => "ok"] + $line);
}

$box = array_map("floatval", explode(",", (string)($_GET["bbox"] ?? "")));
if (count($box) !== 4) {
    live_buses_reply(["status" => "error", "message" => "bbox must be west,south,east,north"], 422);
}
live_buses_reply(["status" => "ok"] + BusTrackingService::vehiclesIn($box));
