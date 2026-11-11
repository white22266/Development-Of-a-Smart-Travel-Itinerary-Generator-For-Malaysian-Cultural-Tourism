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

function normalize_optional_date(string $value): ?string
{
    $value = trim($value);
    if ($value === "") return null;
    $dt = DateTime::createFromFormat("Y-m-d", $value);
    if (!$dt || $dt->format("Y-m-d") !== $value) return null;
    return $value;
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
$festivalStartDate = normalize_optional_date((string)($_POST["festival_start_date"] ?? ""));
$festivalEndDate = normalize_optional_date((string)($_POST["festival_end_date"] ?? ""));

if ($name === "" || $state === "" || $category === "" || $description === "") back("Please fill in all required fields.", true);
if (!in_array($category, $categoryOptions, true)) back("Invalid category.", true);
if (!in_array($state, $stateOptions, true)) back("Invalid state.", true);
if ($cost < 0) back("Estimated cost cannot be negative.", true);
if ($category === "festival") {
    if ($festivalStartDate === null || $festivalEndDate === null) {
        back("Festival start date and end date are required for festival suggestions.", true);
    }
    if ($festivalEndDate < $festivalStartDate) {
        back("Festival end date cannot be earlier than festival start date.", true);
    }
} else {
    $festivalStartDate = null;
    $festivalEndDate = null;
}

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
        if (!mkdir($uploadDir, 0775, true)) back("Cannot create upload directory.", true);
    }

    // The file must really be a picture, whatever its name says, and the stored name is ours.
    $imageInfo = @getimagesize($_FILES["image"]["tmp_name"]);
    $ext = [IMAGETYPE_JPEG => "jpg", IMAGETYPE_PNG => "png", IMAGETYPE_WEBP => "webp"][$imageInfo[2] ?? 0] ?? null;
    if ($ext === null) back("Invalid image type. Only JPG/PNG/WEBP allowed.", true);
    if (($_FILES["image"]["size"] ?? 0) > 5 * 1024 * 1024) back("The image is too large. The limit is 5 MB.", true);

    $fileName = "suggest_" . time() . "_" . bin2hex(random_bytes(4)) . "." . $ext;
    $targetPath = $uploadDir . $fileName;

    if (!move_uploaded_file($_FILES["image"]["tmp_name"], $targetPath)) {
        back("Image upload failed.", true);
    }

    // CHANGE: Save relative web path into DB (varchar)
    $imageUrl = "uploads/places/" . $fileName;
}
// ===================================================================

$districtOrNull = ($district === "") ? null : $district;

$stmt = $conn->prepare("
  INSERT INTO cultural_place_suggestions
  (traveller_id, state, district, category, name, description, address, latitude, longitude, opening_hours, festival_start_date, festival_end_date, estimated_cost, image_url, status)
  VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,'pending')
");
if (!$stmt) back("System error: cannot submit suggestion. (" . $conn->error . ")", true);

$stmt->bind_param(
    "issssssddsssds",
    $travellerId,
    $state,
    $districtOrNull,
    $category,
    $name,
    $description,
    $address,
    $latitude,
    $longitude,
    $opening,
    $festivalStartDate,
    $festivalEndDate,
    $cost,
    $imageUrl
);

if (!$stmt->execute()) {
    $err = $stmt->error;
    $stmt->close();
    back("Submit failed: " . $err, true);
}
$stmt->close();

back("Suggestion submitted. Waiting for admin approval.");
