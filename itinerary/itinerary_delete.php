<?php
// itinerary/itinerary_delete.php
session_start();
require_once "../config/db_connect.php";

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
    header("Location: ../auth/login.php?role=traveller");
    exit;
}

$travellerId = (int)($_SESSION["traveller_id"] ?? 0);
$itineraryId = (int)($_POST["itinerary_id"] ?? 0);

if ($travellerId <= 0 || $itineraryId <= 0) {
    $_SESSION["form_errors"] = ["Invalid request."];
    header("Location: my_itineraries.php");
    exit;
}

// Ensure the itinerary belongs to the logged-in traveller
$check = $conn->prepare("SELECT itinerary_id FROM itineraries WHERE itinerary_id = ? AND traveller_id = ? LIMIT 1");
$check->bind_param("ii", $itineraryId, $travellerId);
$check->execute();
$exists = $check->get_result()->num_rows > 0;
$check->close();

if (!$exists) {
    $_SESSION["form_errors"] = ["Itinerary not found or you do not have permission to delete it."];
    header("Location: my_itineraries.php");
    exit;
}

// Delete itinerary (itinerary_items will be deleted via ON DELETE CASCADE)
$del = $conn->prepare("DELETE FROM itineraries WHERE itinerary_id = ? AND traveller_id = ? LIMIT 1");
$del->bind_param("ii", $itineraryId, $travellerId);
$ok = $del->execute();
$del->close();

if ($ok) {
    $_SESSION["success_message"] = "Itinerary deleted successfully.";
} else {
    $_SESSION["form_errors"] = ["Failed to delete itinerary. Please try again."];
}

header("Location: my_itineraries.php");
exit;
