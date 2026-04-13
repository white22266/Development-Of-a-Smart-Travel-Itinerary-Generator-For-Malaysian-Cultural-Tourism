<?php
// itinerary/geocode_origin.php
// Server-side geocoding proxy using Google Maps Geocoding API.
// Returns JSON: {"lat": float, "lng": float} or {"error": "message"}
header("Content-Type: application/json");

require_once "../config/api_keys.php";

$q = trim((string)($_GET["q"] ?? ""));

if ($q === "") {
    echo json_encode(["error" => "No query"]);
    exit;
}

if (!defined("GOOGLE_MAPS_API_KEY") || trim(GOOGLE_MAPS_API_KEY) === "") {
    echo json_encode(["error" => "No API key"]);
    exit;
}

$url = "https://maps.googleapis.com/maps/api/geocode/json?"
     . http_build_query([
         "address" => $q,
         "key"     => GOOGLE_MAPS_API_KEY,
         "region"  => "MY",
     ]);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 8,
    CURLOPT_SSL_VERIFYPEER => false,
]);
$body = curl_exec($ch);
$err  = curl_errno($ch);
curl_close($ch);

if ($err || $body === false) {
    echo json_encode(["error" => "Request failed"]);
    exit;
}

$data = json_decode($body, true);
if (!is_array($data) || ($data["status"] ?? "") !== "OK") {
    echo json_encode(["error" => "Geocoding failed: " . ($data["status"] ?? "unknown")]);
    exit;
}

$loc = $data["results"][0]["geometry"]["location"] ?? null;
if (!$loc) {
    echo json_encode(["error" => "No result"]);
    exit;
}

echo json_encode([
    "lat"             => (float)$loc["lat"],
    "lng"             => (float)$loc["lng"],
    "formatted_address" => $data["results"][0]["formatted_address"] ?? $q,
]);
