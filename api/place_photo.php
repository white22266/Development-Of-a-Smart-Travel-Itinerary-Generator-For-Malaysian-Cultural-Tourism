<?php
// api/place_photo.php
// Returns an actual place photo from Google Places when image_url is missing.
// Falls back to a neutral SVG, not a map thumbnail.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../config/db_connect.php";
require_once "../config/api_keys.php";

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true) {
    output_placeholder("Login required");
}

$placeId = (int)($_GET["place_id"] ?? 0);
if ($placeId <= 0) {
    output_placeholder("No place photo");
}

$stmt = $conn->prepare("
    SELECT place_id, name, state, district, address, image_url
    FROM cultural_places
    WHERE place_id = ? AND is_active = 1
    LIMIT 1
");
if (!$stmt) output_placeholder("No place photo");
$stmt->bind_param("i", $placeId);
$stmt->execute();
$place = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$place) {
    output_placeholder("No place photo");
}

$existing = trim((string)($place["image_url"] ?? ""));
if ($existing !== "") {
    if (preg_match('#^https?://#i', $existing) || strpos($existing, '//') === 0) {
        header("Location: " . $existing, true, 302);
        exit;
    }
    $local = realpath(__DIR__ . "/../" . ltrim($existing, "/"));
    $root = realpath(__DIR__ . "/..");
    if ($local && $root && str_starts_with($local, $root) && is_file($local)) {
        output_file($local);
    }
}

if (!defined("GOOGLE_MAPS_API_KEY") || GOOGLE_MAPS_API_KEY === "" || !function_exists("curl_init")) {
    output_placeholder("No place photo");
}

$queryParts = array_filter([
    $place["name"] ?? "",
    $place["district"] ?? "",
    $place["state"] ?? "",
    "Malaysia",
]);
$query = implode(", ", $queryParts);

$findUrl = "https://maps.googleapis.com/maps/api/place/findplacefromtext/json?" . http_build_query([
    "input" => $query,
    "inputtype" => "textquery",
    "fields" => "name,place_id,photos",
    "key" => GOOGLE_MAPS_API_KEY,
]);

$json = http_json($findUrl);
$candidate = $json["candidates"][0] ?? null;
$photoRef = $candidate["photos"][0]["photo_reference"] ?? "";
if ($photoRef === "") {
    output_placeholder("No place photo");
}

$photoUrl = "https://maps.googleapis.com/maps/api/place/photo?" . http_build_query([
    "maxwidth" => 900,
    "photo_reference" => $photoRef,
    "key" => GOOGLE_MAPS_API_KEY,
]);

$photo = http_binary($photoUrl);
if (!$photo || stripos($photo["content_type"], "image/") !== 0 || $photo["body"] === "") {
    output_placeholder("No place photo");
}

header("Content-Type: " . $photo["content_type"]);
header("Cache-Control: public, max-age=86400");
echo $photo["body"];
exit;

function http_json(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false || $code < 200 || $code >= 300) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function http_binary(string $url): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 4,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => [
            "User-Agent: SmartTravelItineraryGenerator/1.0",
        ],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    if ($body === false || $code < 200 || $code >= 300) return null;
    return [
        "content_type" => $contentType !== "" ? $contentType : "application/octet-stream",
        "body" => $body,
    ];
}

function output_file(string $path): void
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $type = match ($ext) {
        "jpg", "jpeg" => "image/jpeg",
        "png" => "image/png",
        "webp" => "image/webp",
        "gif" => "image/gif",
        default => "application/octet-stream",
    };
    header("Content-Type: " . $type);
    header("Cache-Control: public, max-age=86400");
    readfile($path);
    exit;
}

function output_placeholder(string $text): void
{
    $safe = htmlspecialchars($text, ENT_QUOTES, "UTF-8");
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="900" height="520" viewBox="0 0 900 520">'
        . '<rect width="900" height="520" fill="#f1f5f9"/>'
        . '<rect x="320" y="150" width="260" height="170" rx="18" fill="#e2e8f0"/>'
        . '<circle cx="395" cy="210" r="28" fill="#cbd5e1"/>'
        . '<path d="M340 300l90-80 65 55 45-42 80 67H340z" fill="#cbd5e1"/>'
        . '<text x="450" y="375" text-anchor="middle" font-family="Arial, sans-serif" font-size="28" font-weight="700" fill="#64748b">' . $safe . '</text>'
        . '</svg>';
    header("Content-Type: image/svg+xml; charset=utf-8");
    header("Cache-Control: public, max-age=3600");
    echo $svg;
    exit;
}
