<?php
// admin/admin_cultural_kb_process.php
session_start();
require_once "../config/db_connect.php";

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "admin") {
    header("Location: ../auth/login.php?role=admin");
    exit;
}

$categoryOptions = ['culture', 'heritage', 'museum', 'food', 'festival', 'nature', 'shopping'];

function back($msg, $isError = false)
{
    if ($isError) $_SESSION["form_errors"] = [$msg];
    else $_SESSION["success_message"] = $msg;
    header("Location: admin_cultural_kb.php");
    exit;
}
$action = strtolower(trim($_POST["action"] ?? $_GET["action"] ?? ""));
/* ================= DELETE ================= */
if ($action === "delete") {
    $placeId = (int)($_GET["place_id"] ?? 0);
    if ($placeId <= 0) back("Invalid place id.", true);

    $stmt = $conn->prepare("DELETE FROM cultural_places WHERE place_id = ?");
    $stmt->bind_param("i", $placeId);
    $stmt->execute();
    $stmt->close();

    back("Place deleted successfully.");
}

// ================= IMAGE UPLOAD =================
$imageUrl = null;
if (!empty($_FILES['image']['name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {

    $uploadDir = "../uploads/places/";
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'webp'];

    if (!in_array($ext, $allowed)) {
        back("Invalid image type. Only JPG, PNG, WEBP allowed.", true);
    }

    $fileName = "place_" . time() . "_" . rand(1000, 9999) . "." . $ext;
    $targetPath = $uploadDir . $fileName;

    if (!move_uploaded_file($_FILES['image']['tmp_name'], $targetPath)) {
        back("Image upload failed.", true);
    }

    // save in db
    $imageUrl = "uploads/places/" . $fileName;
}
// =================================================

/* ======================= CREATE / UPDATE======================= */

if ($action === "create" || $action === "update") {
    $placeId = (int)($_POST["place_id"] ?? 0);

    $name = trim($_POST["name"] ?? "");
    $state = trim($_POST["state"] ?? "");
    $category = trim($_POST["category"] ?? "");

    $description = trim($_POST["description"] ?? "");
    $address = trim($_POST["address"] ?? "");
    $latitude = trim($_POST["latitude"] ?? "");
    $longitude = trim($_POST["longitude"] ?? "");
    $opening = trim($_POST["opening_hours"] ?? "");

    $cost = (float)($_POST["estimated_cost"] ?? 0);
    $isActive = (int)($_POST["is_active"] ?? 1);
    $isActive = ($isActive === 0) ? 0 : 1;
    // --- NEW: handle remove checkbox + pasted URL ---
    $removeImage = (int)($_POST["remove_image"] ?? 0) === 1;
    $imageUrlInput = trim($_POST["image_url"] ?? "");

    // If no upload, allow external URL
    if ($imageUrl === null && $imageUrlInput !== "") {
        if (!preg_match('#^https?://#i', $imageUrlInput)) {
            back("Image URL must start with http:// or https://", true);
        }
        $imageUrl = $imageUrlInput;
    }

    // Normalize lat/lng (allow NULL)
    $latVal = ($latitude === "") ? null : (float)$latitude;
    $lngVal = ($longitude === "") ? null : (float)$longitude;

    // Decide whether image_url should be changed on UPDATE
    $changeImage = ($imageUrl !== null) || $removeImage;     // upload/url OR remove checked
    $newImageVal = ($imageUrl !== null) ? $imageUrl : null;  // if removing => NULL

    if ($name === "" || $state === "" || $category === "") back("Name, state and category are required.", true);
    if (!in_array($category, $categoryOptions, true)) back("Invalid category.", true);
    if ($cost < 0) back("Cost cannot be negative.", true);
    // latitude/longitude optional，但如果填了就要是数字
    if ($latitude !== "" && !is_numeric($latitude)) back("Latitude must be numeric.", true);
    if ($longitude !== "" && !is_numeric($longitude)) back("Longitude must be numeric.", true);
    /* ===== CREATE ===== */
    if ($action === "create") {
        $stmt = $conn->prepare("
      INSERT INTO cultural_places
      (state, name, category, description, address, latitude, longitude, estimated_cost, opening_hours, image_url, is_active)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)
    ");
        $latVal = ($latitude === "") ? null : (float)$latitude;
        $lngVal = ($longitude === "") ? null : (float)$longitude;

        $stmt->bind_param(
            "sssssdddssi",
            $state,
            $name,
            $category,
            $description,
            $address,
            $latitude,
            $longitude,
            $cost,
            $opening,
            $imageUrl,
            $isActive
        );

        // 兼容 NULL：如果你想严格用 NULL，就把 latitude/longitude 改成直接 bind double，并在 empty 时 set null（会更长）
        $stmt->execute();
        $stmt->close();

        back("Place added successfully.");
    }
    /* ===== UPDATE ===== */
    /* ===== UPDATE ===== */
    if ($placeId <= 0) back("Invalid place id for update.", true);

    if ($changeImage) {
        // change image_url (either set new url/path OR set NULL if remove checked)
        $stmt = $conn->prepare("
        UPDATE cultural_places
        SET state=?, name=?, category=?, description=?, address=?,
            latitude=?, longitude=?, estimated_cost=?, opening_hours=?,
            image_url=?, is_active=?
        WHERE place_id=?
    ");
        $stmt->bind_param(
            "sssssdddssii",
            $state,
            $name,
            $category,
            $description,
            $address,
            $latVal,
            $lngVal,
            $cost,
            $opening,
            $newImageVal,  // can be NULL if remove checked
            $isActive,
            $placeId
        );
    } else {
        // keep old image_url
        $stmt = $conn->prepare("
        UPDATE cultural_places
        SET state=?, name=?, category=?, description=?, address=?,
            latitude=?, longitude=?, estimated_cost=?, opening_hours=?, is_active=?
        WHERE place_id=?
    ");
        $stmt->bind_param(
            "sssssdddsii",
            $state,
            $name,
            $category,
            $description,
            $address,
            $latVal,
            $lngVal,
            $cost,
            $opening,
            $isActive,
            $placeId
        );
    }


    $stmt->execute();
    $stmt->close();

    back("Place updated successfully.");
}
back("Invalid action.", true);
