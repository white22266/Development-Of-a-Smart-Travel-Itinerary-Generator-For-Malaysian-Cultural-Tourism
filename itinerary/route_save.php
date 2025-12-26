<?php
session_start();
require_once "../config/db_connect.php";

header("Content-Type: application/json"); // CHANGE: JSON response

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
    http_response_code(401);
    echo json_encode([
        "status" => "error",
        "message" => "Unauthorized"
    ]);
    exit;
}

$itemId = (int)($_POST["item_id"] ?? 0);
$distanceKm = (float)($_POST["distance_km"] ?? 0);
$timeMin = (int)($_POST["travel_time_min"] ?? 0);

if ($itemId <= 0) {
    http_response_code(400);
    echo json_encode([
        "status" => "error",
        "message" => "Invalid item ID"
    ]);
    exit;
}

$stmt = $conn->prepare("
    UPDATE itinerary_items
    SET distance_km = ?, travel_time_min = ?
    WHERE item_id = ?
");
$stmt->bind_param("dii", $distanceKm, $timeMin, $itemId);
$stmt->execute();
$stmt->close();

// CHANGE: return success info
echo json_encode([
    "status" => "success",
    "item_id" => $itemId,
    "distance_km" => $distanceKm,
    "travel_time_min" => $timeMin,
    "message" => "Route info updated successfully"
]);
