<?php
// cultural/suggest_place_process.php
session_start();
require_once "../config/db_connect.php";
require_once "../services/DuplicatePlaceService.php";

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
    header("Location: ../auth/login.php?role=traveller");
    exit;
}

$travellerId = (int)($_SESSION["traveller_id"] ?? 0);
if ($travellerId <= 0) {
    header("Location: ../auth/login.php?role=traveller");
    exit;
}

$categoryOptions = ['culture', 'heritage', 'museum', 'food', 'festival', 'nature', 'shopping'];
$stateOptions = [
    "Johor",
    "Kedah",
    "Kelantan",
    "Melaka",
    "Negeri Sembilan",
    "Pahang",
    "Penang",
    "Perak",
    "Perlis",
    "Sabah",
    "Sarawak",
    "Selangor",
    "Terengganu",
    "Kuala Lumpur",
    "Putrajaya",
    "Labuan"
];

function back($msg, $isError = false)
{
    if ($isError) $_SESSION["form_errors"] = [$msg];
    else $_SESSION["success_message"] = $msg;
    header("Location: suggest_place.php");
    exit;
}

function table_has_column(mysqli $conn, string $table, string $column): bool
{
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return ($res && $res->num_rows > 0);
}

function download_remote_image_to_uploads(string $url): ?string
{
    if (!preg_match('#^https?://#i', $url)) return null;

    $uploadDir = "../uploads/places/";
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0777, true)) return null;

    $body = false;
    $contentType = "";

    if (function_exists("curl_init")) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => "Mozilla/5.0 SmartTravelImageFetcher/1.0",
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        if ($status < 200 || $status >= 300) $body = false;
    } else {
        $context = stream_context_create([
            "http" => [
                "method" => "GET",
                "timeout" => 20,
                "header" => "User-Agent: Mozilla/5.0 SmartTravelImageFetcher/1.0\r\n",
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $header) {
                if (stripos($header, "Content-Type:") === 0) {
                    $contentType = trim(substr($header, 13));
                    break;
                }
            }
        }
    }

    if ($body === false || strlen((string)$body) < 200) return null;

    $info = @getimagesizefromstring((string)$body);
    if (!$info || empty($info["mime"]) || strpos($info["mime"], "image/") !== 0) return null;

    $mime = strtolower((string)$info["mime"]);
    $extMap = [
        "image/jpeg" => "jpg",
        "image/png" => "png",
        "image/webp" => "webp",
        "image/gif" => "gif",
    ];
    $ext = $extMap[$mime] ?? "";
    if (!in_array($ext, ["jpg", "png", "webp"], true)) return null;

    $fileName = "suggest_google_" . time() . "_" . rand(1000, 9999) . "." . $ext;
    $targetPath = $uploadDir . $fileName;
    if (file_put_contents($targetPath, $body) === false) return null;

    return "uploads/places/" . $fileName;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: suggest_place.php");
    exit;
}

$name = trim($_POST["name"] ?? "");
$state = trim($_POST["state"] ?? "");
$district = trim($_POST["district"] ?? "");
$category = trim($_POST["category"] ?? "");
$description = trim($_POST["description"] ?? "");

$address = trim($_POST["address"] ?? "");
$latitude = trim($_POST["latitude"] ?? "");
$longitude = trim($_POST["longitude"] ?? "");
$opening = trim($_POST["opening_hours"] ?? "");
$cost = (float)($_POST["estimated_cost"] ?? 0);

if ($name === "" || $state === "" || $category === "" || $description === "") back("Please fill in all required fields.", true);
if (!in_array($category, $categoryOptions, true)) back("Invalid category.", true);
if (!in_array($state, $stateOptions, true)) back("Invalid state.", true);
if ($cost < 0) back("Estimated cost cannot be negative.", true);

if ($latitude === "" || $longitude === "") back("Latitude and Longitude are required.", true);
if ($latitude !== "" && !is_numeric($latitude)) back("Latitude must be numeric.", true);
if ($longitude !== "" && !is_numeric($longitude)) back("Longitude must be numeric.", true);
if ($latitude < -90 || $latitude > 90) back("Latitude must be between -90 and 90.", true);
if ($longitude < -180 || $longitude > 180) back("Longitude must be between -180 and 180.", true);

$dupeService = new DuplicatePlaceService($conn);
$candidate = [
    "name" => $name,
    "state" => $state,
    "category" => $category,
    "latitude" => $latitude,
    "longitude" => $longitude,
];
if ($dupeService->hasHighConfidenceDuplicate($candidate)) {
    back("This place looks like it already exists in the cultural guide. Please check the duplicate warning before submitting a new place.", true);
}
// ================= IMAGE UPLOAD (store URL string) =================
$imageUrl = null;

if (!empty($_FILES["image"]["name"]) && ($_FILES["image"]["error"] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {

    // CHANGE: Use uploads/places for traveller submissions
    $uploadDir = "../uploads/places/";
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0777, true)) back("Cannot create upload directory.", true);
    }

    $ext = strtolower(pathinfo($_FILES["image"]["name"], PATHINFO_EXTENSION));
    $allowed = ["jpg", "jpeg", "png", "webp"];
    if (!in_array($ext, $allowed, true)) back("Invalid image type. Only JPG/PNG/WEBP allowed.", true);

    $fileName = "suggest_" . time() . "_" . rand(1000, 9999) . "." . $ext;
    $targetPath = $uploadDir . $fileName;

    if (!move_uploaded_file($_FILES["image"]["tmp_name"], $targetPath)) {
        back("Image upload failed.", true);
    }

    // CHANGE: Save relative web path into DB (varchar)
    $imageUrl = "uploads/places/" . $fileName;
}
if ($imageUrl === null) {
    $imageUrlInput = trim((string)($_POST["image_url"] ?? ""));
    if ($imageUrlInput !== "") {
        if (!preg_match('#^https?://#i', $imageUrlInput)) {
            back("Image URL must start with http:// or https://", true);
        }
        $downloadedImage = download_remote_image_to_uploads($imageUrlInput);
        $imageUrl = $downloadedImage ?: (strlen($imageUrlInput) <= 950 ? $imageUrlInput : null);
    }
}
// ===================================================================

$hasSuggestionDistrict = table_has_column($conn, "cultural_place_suggestions", "district");

if ($hasSuggestionDistrict) {
    $stmt = $conn->prepare("
      INSERT INTO cultural_place_suggestions
      (traveller_id, state, district, category, name, description, address, latitude, longitude, opening_hours, estimated_cost, image_url, status)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'pending')
    ");
} else {
    $stmt = $conn->prepare("
      INSERT INTO cultural_place_suggestions
      (traveller_id, state, category, name, description, address, latitude, longitude, opening_hours, estimated_cost, image_url, status)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,'pending')
    ");
}

if (!$stmt) back("System error: cannot submit suggestion. (" . $conn->error . ")", true);

if ($hasSuggestionDistrict) {
    $stmt->bind_param(
        "issssssddsds",
        $travellerId,
        $state,
        $district,
        $category,
        $name,
        $description,
        $address,
        $latitude,
        $longitude,
        $opening,
        $cost,
        $imageUrl
    );
} else {
    $stmt->bind_param(
        "isssssddsds",
        $travellerId,
        $state,
        $category,
        $name,
        $description,
        $address,
        $latitude,
        $longitude,
        $opening,
        $cost,
        $imageUrl
    );
}

if (!$stmt->execute()) {
    $err = $stmt->error;
    $stmt->close();
    back("Submit failed: " . $err, true);
}
$stmt->close();

back("Suggestion submitted. Waiting for admin approval.");
