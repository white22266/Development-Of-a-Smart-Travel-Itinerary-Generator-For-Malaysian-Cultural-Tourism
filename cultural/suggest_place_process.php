<?php
// cultural/suggest_place_process.php
session_start();
require_once "../config/db_connect.php";
require_once "../config/i18n.php";
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

if ($name === "" || $state === "" || $category === "" || $description === "") back(t('sug.err_required'), true);
if (!in_array($category, $categoryOptions, true)) back(t('sug.err_category'), true);
if (!in_array($state, $stateOptions, true)) back(t('sug.err_state'), true);
if ($cost < 0) back(t('sug.err_cost'), true);
if ($category === "festival") {
    if ($festivalStartDate === null || $festivalEndDate === null) {
        back(t('sug.err_festival_dates'), true);
    }
    if ($festivalEndDate < $festivalStartDate) {
        back(t('sug.err_festival_order'), true);
    }
} else {
    $festivalStartDate = null;
    $festivalEndDate = null;
}

if ($latitude === "" || $longitude === "") back(t('sug.err_latlng_required'), true);
if ($latitude !== "" && !is_numeric($latitude)) back(t('sug.err_lat_numeric'), true);
if ($longitude !== "" && !is_numeric($longitude)) back(t('sug.err_lng_numeric'), true);
if ($latitude < -90 || $latitude > 90) back(t('sug.err_lat_range'), true);
if ($longitude < -180 || $longitude > 180) back(t('sug.err_lng_range'), true);

$dupeService = new DuplicatePlaceService($conn);
$candidate = [
    "name" => $name,
    "state" => $state,
    "category" => $category,
    "latitude" => $latitude,
    "longitude" => $longitude,
];
if ($dupeService->hasHighConfidenceDuplicate($candidate)) {
    back(t('sug.err_duplicate'), true);
}
// ================= IMAGE UPLOAD (store URL string) =================
$imageUrl = null;

if (!empty($_FILES["image"]["name"]) && ($_FILES["image"]["error"] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {

    // CHANGE: Use uploads/places for traveller submissions
    $uploadDir = "../uploads/places/";
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0775, true)) back(t('sug.err_upload_dir'), true);
    }

    // The file must really be a picture, whatever its name says, and the stored name is ours.
    $imageInfo = @getimagesize($_FILES["image"]["tmp_name"]);
    $ext = [IMAGETYPE_JPEG => "jpg", IMAGETYPE_PNG => "png", IMAGETYPE_WEBP => "webp"][$imageInfo[2] ?? 0] ?? null;
    if ($ext === null) back(t('sug.err_image_type'), true);
    if (($_FILES["image"]["size"] ?? 0) > 5 * 1024 * 1024) back(t('sug.err_image_size'), true);

    $fileName = "suggest_" . time() . "_" . bin2hex(random_bytes(4)) . "." . $ext;
    $targetPath = $uploadDir . $fileName;

    if (!move_uploaded_file($_FILES["image"]["tmp_name"], $targetPath)) {
        back(t('sug.err_image_upload'), true);
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
if (!$stmt) back(t('sug.err_system', ['error' => $conn->error]), true);

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
    back(t('sug.err_submit', ['error' => $err]), true);
}
$stmt->close();

back(t('sug.submitted'));
