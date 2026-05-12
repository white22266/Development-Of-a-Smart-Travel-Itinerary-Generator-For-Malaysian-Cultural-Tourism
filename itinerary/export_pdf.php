<?php
// itinerary/export_pdf.php
// Complete itinerary report export: trip summary, routed map, cost breakdown,
// hotel recommendations, day schedule, and place details.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../config/db_connect.php";
require_once "../config/api_keys.php";
require_once "../services/CostEstimationService.php";
require_once "../services/HotelRecommendationService.php";

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
    header("Location: ../auth/login.php?role=traveller");
    exit;
}

$travellerId = (int)($_SESSION["traveller_id"] ?? 0);
$travellerName = $_SESSION["traveller_name"] ?? "Traveller";
$itineraryId = (int)($_GET["itinerary_id"] ?? 0);
if ($travellerId <= 0 || $itineraryId <= 0) {
    header("Location: my_itineraries.php");
    exit;
}

function esc($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function fmt_rm($value): string
{
    return "RM " . number_format((float)$value, 2);
}

function fmt_time($time): string
{
    $time = trim((string)$time);
    if ($time === "") return "-";
    $ts = strtotime($time);
    return $ts ? date("g:i A", $ts) : $time;
}

function clean_text($value): string
{
    $value = trim(strip_tags((string)$value));
    $value = preg_replace('/\s+/', ' ', $value) ?? "";
    return $value;
}

function is_http_url($value): bool
{
    return (bool)preg_match('#^https?://#i', (string)$value);
}

function project_abs_path($relativePath): string
{
    $relativePath = trim((string)$relativePath);
    if ($relativePath === "") return "";
    $relativePath = ltrim($relativePath, "/\\");
    $abs = realpath(__DIR__ . "/../" . $relativePath);
    return $abs ? $abs : "";
}

function detect_mime_from_bytes(string $bytes): string
{
    if (substr($bytes, 0, 8) === "\x89PNG\x0D\x0A\x1A\x0A") return "image/png";
    if (substr($bytes, 0, 3) === "\xFF\xD8\xFF") return "image/jpeg";
    if (substr($bytes, 0, 6) === "GIF87a" || substr($bytes, 0, 6) === "GIF89a") return "image/gif";
    if (substr($bytes, 0, 4) === "RIFF" && substr($bytes, 8, 4) === "WEBP") return "image/webp";
    return "application/octet-stream";
}

function curl_fetch_bytes(string $url, int $timeout = 25): string
{
    if (!function_exists("curl_init")) return "";

    $maxBytes = 8 * 1024 * 1024;
    $data = "";
    $downloaded = 0;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 8,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => "SmartTravelPDF/1.0",
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $chunk) use (&$data, &$downloaded, $maxBytes) {
        $len = strlen($chunk);
        $downloaded += $len;
        if ($downloaded > $maxBytes) return 0;
        $data .= $chunk;
        return $len;
    });
    $ok = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ($ok !== false && $code >= 200 && $code < 300) ? $data : "";
}

function webp_to_png_data_uri(string $bytes): string
{
    if (!function_exists("imagecreatefromwebp")) return "";
    $tmp = tempnam(sys_get_temp_dir(), "pdf_webp_");
    if (!$tmp) return "";
    @file_put_contents($tmp, $bytes);
    $im = @imagecreatefromwebp($tmp);
    @unlink($tmp);
    if (!$im) return "";
    ob_start();
    imagepng($im);
    imagedestroy($im);
    $png = ob_get_clean();
    return $png ? "data:image/png;base64," . base64_encode($png) : "";
}

function image_to_data_uri($imageUrlOrPath): string
{
    $raw = trim((string)$imageUrlOrPath);
    if ($raw === "") return "";
    if (stripos($raw, "data:image/") === 0) return $raw;

    if (is_http_url($raw)) {
        $bytes = curl_fetch_bytes($raw);
        if ($bytes === "") return "";
        $mime = detect_mime_from_bytes($bytes);
        if ($mime === "image/webp") {
            $converted = webp_to_png_data_uri($bytes);
            if ($converted !== "") return $converted;
        }
        if (strpos($mime, "image/") !== 0) $mime = "image/jpeg";
        return "data:" . $mime . ";base64," . base64_encode($bytes);
    }

    $abs = project_abs_path($raw);
    if ($abs === "" || !is_file($abs)) return "";
    $bytes = @file_get_contents($abs);
    if ($bytes === false) return "";
    $mime = detect_mime_from_bytes($bytes);
    if ($mime === "image/webp") {
        $converted = webp_to_png_data_uri($bytes);
        if ($converted !== "") return $converted;
    }
    if (strpos($mime, "image/") !== 0) $mime = "image/jpeg";
    return "data:" . $mime . ";base64," . base64_encode($bytes);
}

function valid_coord($lat, $lng): bool
{
    if ($lat === null || $lng === null || $lat === "" || $lng === "") return false;
    $lat = (float)$lat;
    $lng = (float)$lng;
    return is_finite($lat) && is_finite($lng) && !($lat == 0.0 && $lng == 0.0);
}

function normalize_transport_mode(string $transportType): string
{
    $t = strtolower(trim(str_replace("-", "_", $transportType)));
    $t = preg_replace('/\s+/', '_', $t) ?? $t;
    if (in_array($t, ["public", "public_transport", "publictransit", "public_transit", "transit", "bus", "train"], true)) return "transit";
    if (in_array($t, ["walk", "walking"], true)) return "walking";
    return "driving";
}

function google_directions_polyline(float $fromLat, float $fromLng, float $toLat, float $toLng, string $mode): string
{
    if (!defined("GOOGLE_MAPS_API_KEY") || trim((string)GOOGLE_MAPS_API_KEY) === "") return "";

    $params = [
        "origin" => $fromLat . "," . $fromLng,
        "destination" => $toLat . "," . $toLng,
        "mode" => $mode,
        "key" => GOOGLE_MAPS_API_KEY,
    ];
    if ($mode === "transit") {
        $params["departure_time"] = "now";
    }

    $url = "https://maps.googleapis.com/maps/api/directions/json?" . http_build_query($params);
    $raw = curl_fetch_bytes($url, 15);
    if ($raw === "") return "";
    $json = json_decode($raw, true);
    if (!is_array($json) || ($json["status"] ?? "") !== "OK") return "";

    return (string)($json["routes"][0]["overview_polyline"]["points"] ?? "");
}

function static_route_map_data_uri(array $days, string $transportType): string
{
    if (!defined("GOOGLE_MAPS_API_KEY") || trim((string)GOOGLE_MAPS_API_KEY) === "") return "";

    $markers = [];
    $paths = [];
    $markerIndex = 1;
    $mode = normalize_transport_mode($transportType);
    $colors = ["0xef4444ff", "0x3b82f6ff", "0x22c55eff", "0xf59e0bff", "0x8b5cf6ff", "0xec4899ff", "0x14b8a6ff"];

    ksort($days);
    foreach ($days as $dayNo => $items) {
        $points = [];
        foreach ($items as $item) {
            if (!valid_coord($item["latitude"] ?? null, $item["longitude"] ?? null)) continue;
            $lat = (float)$item["latitude"];
            $lng = (float)$item["longitude"];
            $points[] = [$lat, $lng];
            $label = $markerIndex <= 9 ? (string)$markerIndex : "";
            $markers[] = "markers=" . rawurlencode("color:red|label:" . $label . "|" . $lat . "," . $lng);
            $markerIndex++;
        }

        for ($i = 0; $i < count($points) - 1; $i++) {
            $polyline = google_directions_polyline($points[$i][0], $points[$i][1], $points[$i + 1][0], $points[$i + 1][1], $mode);
            if ($polyline !== "") {
                $color = $colors[((int)$dayNo - 1) % count($colors)];
                $paths[] = "path=" . rawurlencode("color:" . $color . "|weight:5|enc:" . $polyline);
            }
        }
    }

    if (empty($markers)) return "";
    $baseParams = [
        "size" => "640x330",
        "scale" => "2",
        "maptype" => "roadmap",
        "key" => GOOGLE_MAPS_API_KEY,
    ];
    $url = "https://maps.googleapis.com/maps/api/staticmap?" . http_build_query($baseParams);
    if (!empty($paths)) $url .= "&" . implode("&", $paths);
    $url .= "&" . implode("&", $markers);

    return image_to_data_uri($url);
}

function activity_text(string $type, string $category): string
{
    $key = strtolower($category ?: $type);
    $map = [
        "food" => "Enjoy local cuisine",
        "festival" => "Join cultural festival",
        "museum" => "Explore exhibits",
        "heritage" => "Visit heritage landmark",
        "culture" => "Experience local culture",
        "nature" => "Explore nature and scenery",
        "shopping" => "Shop for local products",
    ];
    return $map[$key] ?? "Visit and explore";
}

/* ------------------------ Load itinerary ------------------------ */

$stmt = $conn->prepare("
    SELECT i.*, tp.budget, tp.budget_tier, tp.transport_type, tp.interests,
           tp.preferred_states, tp.preferred_districts, tp.traveller_type,
           tp.travel_pace, tp.dietary_preference, tp.preferred_visit_time
    FROM itineraries i
    LEFT JOIN traveller_preferences tp ON tp.preference_id = i.preference_id
    WHERE i.itinerary_id = ? AND i.traveller_id = ?
    LIMIT 1
");
$stmt->bind_param("ii", $itineraryId, $travellerId);
$stmt->execute();
$it = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$it) {
    header("Location: my_itineraries.php");
    exit;
}

$districtCheck = $conn->query("SHOW COLUMNS FROM cultural_places LIKE 'district'");
$hasDistrict = ($districtCheck && $districtCheck->num_rows > 0);
$districtSelect = $hasDistrict ? "cp.district" : "NULL AS district";

$stmt = $conn->prepare("
    SELECT
        ii.item_id, ii.day_no, ii.sequence_no, ii.item_type, ii.place_id,
        ii.item_title, ii.start_time, ii.end_time, ii.estimated_cost,
        ii.notes, ii.distance_km, ii.travel_time_min,
        cp.latitude, cp.longitude, cp.address, cp.opening_hours,
        cp.category, cp.state, {$districtSelect},
        cp.description, cp.visit_duration_min, cp.halal_status,
        cp.best_time_to_visit, cp.dress_code_required,
        COALESCE(cp.image_path, cp.image_url) AS image_src
    FROM itinerary_items ii
    LEFT JOIN cultural_places cp ON cp.place_id = ii.place_id
    WHERE ii.itinerary_id = ?
    ORDER BY ii.day_no ASC, ii.sequence_no ASC
");
$stmt->bind_param("i", $itineraryId);
$stmt->execute();
$res = $stmt->get_result();
$stmt->close();

$days = [];
$allItems = [];
$lastPlace = null;
$totalDistanceKm = 0.0;
while ($row = $res->fetch_assoc()) {
    $d = (int)$row["day_no"];
    if (!isset($days[$d])) $days[$d] = [];
    $days[$d][] = $row;
    $allItems[] = $row;
    $totalDistanceKm += (float)($row["distance_km"] ?? 0);
    if (valid_coord($row["latitude"] ?? null, $row["longitude"] ?? null)) {
        $lastPlace = $row;
    }
}
ksort($days);

$tripDays = max(1, (int)($it["total_days"] ?? count($days)));
$budget = (float)($it["budget"] ?? 0);
$transportType = (string)($it["transport_type"] ?? "car");
$budgetTier = strtolower((string)($it["budget_tier"] ?? "normal"));
$tierDefaults = match ($budgetTier) {
    "budget" => ["hotel" => 90.0, "meal" => 12.0],
    "luxury" => ["hotel" => 280.0, "meal" => 35.0],
    default => ["hotel" => 150.0, "meal" => 20.0],
};

$costService = new CostEstimationService($transportType, $tripDays, $budget);
$costBreakdown = $costService->calculate($allItems, $totalDistanceKm, $tierDefaults["hotel"], 3, $tierDefaults["meal"]);
$perDayBreakdown = $costService->perDayBreakdown($days);

$hotelRecommendations = [];
$hotelBudget = ($budget > 0) ? ($budget * 0.3 / max(1, $tripDays - 1)) : $tierDefaults["hotel"];
if ($lastPlace && valid_coord($lastPlace["latitude"] ?? null, $lastPlace["longitude"] ?? null)) {
    $hotelService = new HotelRecommendationService($conn);
    $hotelRecommendations = $hotelService->recommend((float)$lastPlace["latitude"], (float)$lastPlace["longitude"], $hotelBudget, 25.0, 3);
    if (empty($hotelRecommendations) && !empty($lastPlace["state"])) {
        $hotelRecommendations = $hotelService->recommendByState((string)$lastPlace["state"], $hotelBudget, 3);
    }
}

$totalPlaces = count($allItems);
$mapUri = static_route_map_data_uri($days, $transportType);
$generatedAt = date("d M Y, g:i A");

/* ------------------------ Build report HTML ------------------------ */

$css = "
<style>
  @page { margin: 24px 24px 30px 24px; }
  body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 10.5px; color: #0f172a; line-height: 1.35; }
  h1, h2, h3, p { margin-top: 0; }
  .cover { border-bottom: 3px solid #4f46e5; padding-bottom: 12px; margin-bottom: 14px; }
  .eyebrow { color: #4f46e5; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: .08em; margin-bottom: 5px; }
  h1 { font-size: 21px; margin-bottom: 6px; }
  h2 { font-size: 14px; margin: 16px 0 8px; color: #111827; }
  h3 { font-size: 12px; margin: 10px 0 6px; }
  .muted { color: #64748b; }
  .grid4 { width: 100%; border-collapse: separate; border-spacing: 6px; margin: 8px 0 12px; }
  .metric { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 9px; }
  .metric-label { font-size: 8.5px; color: #64748b; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; }
  .metric-value { font-size: 14px; font-weight: 900; margin-top: 3px; color: #111827; }
  .section { page-break-inside: avoid; margin-bottom: 12px; }
  .box { border: 1px solid #e2e8f0; border-radius: 9px; padding: 10px; background: #fff; margin-bottom: 9px; }
  .route-map { width: 100%; border: 1px solid #cbd5e1; border-radius: 9px; margin-top: 6px; }
  table { width: 100%; border-collapse: collapse; }
  th { background: #f1f5f9; color: #334155; font-size: 8.5px; text-transform: uppercase; text-align: left; padding: 6px; border: 1px solid #e2e8f0; }
  td { padding: 6px; border: 1px solid #e2e8f0; vertical-align: top; }
  .cost-total { background: #111827; color: #fff; font-weight: 900; }
  .ok { color: #166534; font-weight: 900; }
  .over { color: #b91c1c; font-weight: 900; }
  .day-title { background: #eef2ff; color: #3730a3; padding: 8px 10px; border-radius: 8px; font-weight: 900; margin: 14px 0 8px; }
  .place-card { page-break-inside: avoid; border: 1px solid #e2e8f0; border-radius: 9px; padding: 9px; margin-bottom: 8px; }
  .place-title { font-weight: 900; font-size: 12px; margin-bottom: 5px; }
  .place-img { width: 172px; max-height: 105px; object-fit: cover; border-radius: 7px; border: 1px solid #e2e8f0; }
  .place-desc { color: #334155; font-size: 9.7px; }
  .tag { display: inline-block; background: #eef2ff; color: #3730a3; border-radius: 999px; padding: 2px 7px; font-size: 8.5px; font-weight: 800; }
  .note { background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 8px; color: #92400e; }
  .footer { color: #64748b; font-size: 9px; margin-top: 10px; border-top: 1px solid #e2e8f0; padding-top: 8px; }
</style>
";

$html = $css;
$html .= "<div class='cover'>";
$html .= "<div class='eyebrow'>Smart Travel Itinerary Generator</div>";
$html .= "<h1>" . esc($it["title"] ?: "Travel Itinerary Report") . "</h1>";
$html .= "<p class='muted'>Prepared for " . esc($travellerName) . " | Generated " . esc($generatedAt) . "</p>";
$html .= "<p class='muted'>This report combines route map, daily schedule, place information, hotel recommendations, and full cost summary.</p>";
$html .= "</div>";

$html .= "<table class='grid4'><tr>";
$metrics = [
    ["Trip Days", $tripDays . " day" . ($tripDays > 1 ? "s" : "")],
    ["Places", $totalPlaces],
    ["Transport", ucfirst(str_replace("_", " ", $transportType))],
    ["Total Estimate", fmt_rm($costBreakdown["total_cost"] ?? 0)],
];
foreach ($metrics as $m) {
    $html .= "<td class='metric'><div class='metric-label'>" . esc($m[0]) . "</div><div class='metric-value'>" . esc($m[1]) . "</div></td>";
}
$html .= "</tr></table>";

$html .= "<div class='section box'>";
$html .= "<h2>Trip Summary</h2>";
$html .= "<table>";
$html .= "<tr><th>Start Date</th><td>" . esc(!empty($it["start_date"]) ? date("d M Y", strtotime($it["start_date"])) : "-") . "</td><th>Budget</th><td>" . esc($budget > 0 ? fmt_rm($budget) : "-") . "</td></tr>";
$html .= "<tr><th>Destination</th><td>" . esc($it["preferred_states"] ?: "Malaysia") . "</td><th>District</th><td>" . esc($it["preferred_districts"] ?: "-") . "</td></tr>";
$html .= "<tr><th>Interests</th><td>" . esc($it["interests"] ?: "-") . "</td><th>Travel Style</th><td>" . esc(ucfirst($it["budget_tier"] ?: "Normal") . " / " . ucfirst($it["travel_pace"] ?: "Normal")) . "</td></tr>";
$html .= "</table>";
$html .= "</div>";

$html .= "<div class='section box'>";
$html .= "<h2>Planner Recommendations</h2>";
$html .= "<ul>";
if ($budget > 0) {
    $html .= !empty($costBreakdown["within_budget"])
        ? "<li>The estimated total cost is within the traveller budget. Keep hotel and meal choices close to the suggested range to maintain this budget.</li>"
        : "<li>The estimated total cost is above the traveller budget. Reduce hotel cost, meal budget, or paid attractions before final travel.</li>";
}
$html .= "<li>Use the routed map and daily schedule together: visit stops in the listed sequence to reduce unnecessary backtracking.</li>";
if (stripos((string)$it["interests"], "festival") !== false) {
    $html .= "<li>Festival places should only be used when the festival date matches the travel date. Check the event date before travelling.</li>";
}
$html .= "<li>Check live traffic, public transport availability, weather, and opening hours on the travel day because these can change after the itinerary is generated.</li>";
$html .= "</ul>";
$html .= "</div>";

$html .= "<div class='section box'>";
$html .= "<h2>Route Map</h2>";
if ($mapUri !== "") {
    $html .= "<img class='route-map' src='" . esc($mapUri) . "' alt='Route map'>";
    $html .= "<p class='muted'>Route lines are generated from Google Directions API segment polylines for the selected transport mode. If Google returns no route for a segment, that segment is omitted instead of drawing a misleading straight line.</p>";
} else {
    $html .= "<div class='note'>Route map is unavailable. Check Google Maps API key, Static Maps API, Directions API, billing, and coordinate data.</div>";
}
$html .= "</div>";

$html .= "<div class='section box'>";
$html .= "<h2>Cost Breakdown</h2>";
$html .= "<table>";
$html .= "<tr><th>Component</th><th>Amount</th><th>Basis</th></tr>";
foreach (($costBreakdown["breakdown"] ?? []) as $row) {
    $html .= "<tr><td>" . esc($row["label"] ?? "") . "</td><td>" . esc(fmt_rm($row["amount"] ?? 0)) . "</td><td>" . esc($row["note"] ?? "") . "</td></tr>";
}
$html .= "<tr class='cost-total'><td>Total Estimated Cost</td><td>" . esc(fmt_rm($costBreakdown["total_cost"] ?? 0)) . "</td><td>";
if ($budget > 0) {
    $class = !empty($costBreakdown["within_budget"]) ? "ok" : "over";
    $status = !empty($costBreakdown["within_budget"]) ? "Within budget" : "Over budget";
    $html .= "<span class='" . $class . "'>" . esc($status . " by " . fmt_rm(abs((float)($costBreakdown["budget_difference"] ?? 0)))) . "</span>";
} else {
    $html .= "No budget entered";
}
$html .= "</td></tr></table></div>";

$html .= "<div class='section box'>";
$html .= "<h2>Hotel Recommendations</h2>";
if (!empty($hotelRecommendations)) {
    $html .= "<table><tr><th>Hotel</th><th>Location</th><th>Rating</th><th>Price / Night</th><th>Why Recommended</th></tr>";
    foreach ($hotelRecommendations as $hotel) {
        $distance = isset($hotel["distance_km"]) ? number_format((float)$hotel["distance_km"], 1) . " km from route area" : "Same destination state";
        $html .= "<tr><td>" . esc($hotel["name"] ?? "") . "</td><td>" . esc(trim(($hotel["district"] ?? "") . ", " . ($hotel["state"] ?? ""), " ,")) . "</td><td>" . esc(number_format((float)($hotel["rating"] ?? 0), 1)) . "</td><td>" . esc(fmt_rm($hotel["price_per_night"] ?? 0)) . "</td><td>" . esc($distance . "; fits the estimated accommodation budget range.") . "</td></tr>";
    }
    $html .= "</table>";
} else {
    $html .= "<div class='note'>No hotel recommendation is available for this route. Add active hotel records near the itinerary destination to improve this section.</div>";
}
$html .= "</div>";

$html .= "<div class='section'>";
$html .= "<h2>Daily Itinerary Schedule</h2>";
foreach ($days as $dayNo => $items) {
    $dayDate = "";
    if (!empty($it["start_date"])) {
        $dt = DateTime::createFromFormat("Y-m-d", (string)$it["start_date"]);
        if ($dt) {
            $dt->modify("+" . ((int)$dayNo - 1) . " days");
            $dayDate = $dt->format("d M Y");
        }
    }
    $dayCost = $perDayBreakdown[$dayNo]["attraction_cost"] ?? 0;
    $html .= "<div class='day-title'>Day " . (int)$dayNo . ($dayDate ? " | " . esc($dayDate) : "") . " | Day Item Cost " . esc(fmt_rm($dayCost)) . "</div>";
    $html .= "<table><tr><th style='width:70px;'>Time</th><th style='width:25px;'>#</th><th>Place & Activity</th><th style='width:70px;'>Type</th><th style='width:60px;'>Cost</th><th style='width:72px;'>Travel</th></tr>";
    foreach ($items as $item) {
        $time = fmt_time($item["start_time"] ?? "") . " - " . fmt_time($item["end_time"] ?? "");
        $category = strtolower((string)($item["category"] ?: $item["item_type"]));
        $travel = "-";
        if ($item["travel_time_min"] !== null && $item["travel_time_min"] !== "") {
            $travel = (int)$item["travel_time_min"] . " min";
            if ($item["distance_km"] !== null && $item["distance_km"] !== "") $travel .= " / " . number_format((float)$item["distance_km"], 1) . " km";
        }
        $html .= "<tr>";
        $html .= "<td>" . esc($time) . "</td>";
        $html .= "<td>" . (int)$item["sequence_no"] . "</td>";
        $html .= "<td><strong>" . esc($item["item_title"]) . "</strong><br><span class='muted'>" . esc(activity_text((string)$item["item_type"], $category)) . "</span>";
        if (!empty($item["address"])) $html .= "<br><span class='muted'>" . esc($item["address"]) . "</span>";
        $html .= "</td>";
        $html .= "<td><span class='tag'>" . esc(ucfirst($category)) . "</span></td>";
        $html .= "<td>" . esc(fmt_rm($item["estimated_cost"] ?? 0)) . "</td>";
        $html .= "<td>" . esc($travel) . "</td>";
        $html .= "</tr>";
    }
    $html .= "</table>";
}
$html .= "</div>";

$html .= "<div class='section'>";
$html .= "<h2>Place Details</h2>";
foreach ($days as $dayNo => $items) {
    foreach ($items as $item) {
        $img = image_to_data_uri($item["image_src"] ?? "");
        $category = strtolower((string)($item["category"] ?: $item["item_type"]));
        $html .= "<div class='place-card'>";
        $html .= "<div class='place-title'>Day " . (int)$dayNo . " - " . esc($item["item_title"]) . "</div>";
        $html .= "<table><tr>";
        $html .= "<td style='width:185px; border:0; padding:0 9px 0 0;'>";
        if ($img !== "") $html .= "<img class='place-img' src='" . esc($img) . "' alt='Place image'>";
        else $html .= "<div class='muted'>No image available</div>";
        $html .= "</td><td style='border:0; padding:0;'>";
        $html .= "<div><span class='tag'>" . esc(ucfirst($category)) . "</span></div>";
        if (!empty($item["opening_hours"])) $html .= "<p><strong>Opening Hours:</strong> " . esc($item["opening_hours"]) . "</p>";
        if (!empty($item["address"])) $html .= "<p><strong>Address:</strong> " . esc($item["address"]) . "</p>";
        if (!empty($item["best_time_to_visit"])) $html .= "<p><strong>Best Time:</strong> " . esc($item["best_time_to_visit"]) . "</p>";
        if ((int)($item["dress_code_required"] ?? 0) === 1) $html .= "<p><strong>Etiquette:</strong> Dress code required. Cover shoulders/knees where appropriate.</p>";
        if ($item["halal_status"] !== null && $category === "food") $html .= "<p><strong>Halal:</strong> " . ((int)$item["halal_status"] === 1 ? "Available" : "Not certified / unknown") . "</p>";
        $desc = clean_text($item["description"] ?? "");
        if ($desc !== "") {
            if (function_exists("mb_substr")) $desc = mb_substr($desc, 0, 360);
            else $desc = substr($desc, 0, 360);
            $html .= "<p class='place-desc'>" . esc($desc) . "</p>";
        }
        $html .= "</td></tr></table>";
        $html .= "</div>";
    }
}
$html .= "</div>";

$html .= "<div class='footer'>Report generated by Smart Travel Itinerary Generator. Route and cost values are estimates and should be checked against live maps, traffic, opening hours, and current hotel prices before travel.</div>";

/* ------------------------ Render PDF ------------------------ */

$autoload = __DIR__ . "/../vendor/autoload.php";
if (!file_exists($autoload)) {
    echo "<!doctype html><html><head><meta charset='utf-8'><title>Itinerary Report</title></head><body>{$html}</body></html>";
    exit;
}

require_once $autoload;

use Dompdf\Dompdf;
use Dompdf\Options;

$options = new Options();
$options->set("isHtml5ParserEnabled", true);
$options->set("isRemoteEnabled", true);
$options->set("defaultFont", "DejaVu Sans");

$dompdf = new Dompdf($options);
$dompdf->loadHtml("<!doctype html><html><head><meta charset='utf-8'></head><body>{$html}</body></html>");
$dompdf->setPaper("A4", "portrait");
$dompdf->render();

$filename = "itinerary_report_" . $itineraryId . ".pdf";
$dompdf->stream($filename, ["Attachment" => true]);
exit;
