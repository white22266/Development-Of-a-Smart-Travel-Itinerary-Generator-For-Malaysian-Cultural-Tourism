<?php
// itinerary/geocode_origin.php
// Server-side geocoding proxy using Google Maps Geocoding API.
// Supports:
//   Forward:  ?q=City+Name     -> {"lat":float, "lng":float, "address":"..."}
//   Reverse:  ?lat=x&lng=y     -> {"lat":float, "lng":float, "address":"..."}

header("Content-Type: application/json");
require_once "../config/api_keys.php";

if (!defined("GOOGLE_MAPS_API_KEY") || trim(GOOGLE_MAPS_API_KEY) === "") {
    echo json_encode(["error" => "No API key configured"]);
    exit;
}

$lat = trim((string)($_GET["lat"] ?? ""));
$lng = trim((string)($_GET["lng"] ?? ""));
$q   = trim((string)($_GET["q"]   ?? ""));

// ---- Reverse geocoding (lat + lng provided) ----
if ($lat !== "" && $lng !== "") {
    $url = "https://maps.googleapis.com/maps/api/geocode/json?"
         . http_build_query([
               "latlng"      => $lat . "," . $lng,
               "key"         => GOOGLE_MAPS_API_KEY,
               "result_type" => "locality|sublocality|administrative_area_level_2",
           ]);
    $body = gmaps_fetch($url);
    if ($body === false) { echo json_encode(["error" => "Request failed"]); exit; }
    $data = json_decode($body, true);
    if (!is_array($data) || ($data["status"] ?? "") !== "OK") {
        echo json_encode([
            "lat"     => (float)$lat,
            "lng"     => (float)$lng,
            "address" => "GPS: " . $lat . ", " . $lng,
        ]);
        exit;
    }
    $shortName = extract_locality($data["results"]);
    $formatted = $data["results"][0]["formatted_address"] ?? ($lat . ", " . $lng);
    echo json_encode([
        "lat"     => (float)$lat,
        "lng"     => (float)$lng,
        "address" => $shortName ?: $formatted,
    ]);
    exit;
}

// ---- Forward geocoding (address string provided) ----
if ($q === "") {
    echo json_encode(["error" => "No query provided"]);
    exit;
}

$url = "https://maps.googleapis.com/maps/api/geocode/json?"
     . http_build_query([
           "address" => $q,
           "key"     => GOOGLE_MAPS_API_KEY,
           "region"  => "MY",
       ]);
$body = gmaps_fetch($url);
if ($body === false) { echo json_encode(["error" => "Request failed"]); exit; }
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
    "lat"     => (float)$loc["lat"],
    "lng"     => (float)$loc["lng"],
    "address" => $data["results"][0]["formatted_address"] ?? $q,
]);

// ---- Helpers ----
function gmaps_fetch(string $url): string|false {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $body = curl_exec($ch);
    $err  = curl_errno($ch);
    curl_close($ch);
    return ($err || $body === false) ? false : $body;
}

function extract_locality(array $results): string {
    foreach ($results as $r) {
        foreach (($r["address_components"] ?? []) as $comp) {
            $types = $comp["types"] ?? [];
            if (in_array("locality", $types) || in_array("sublocality", $types)) {
                return $comp["long_name"];
            }
        }
    }
    return "";
}
