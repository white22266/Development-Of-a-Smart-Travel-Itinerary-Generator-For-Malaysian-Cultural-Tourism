<?php
// itinerary/export_ics.php
// Calendar (.ics) download of one itinerary. Only the traveller who owns it may export it.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../config/db_connect.php";
require_once "../services/CalendarExportService.php";

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
    header("Location: ../auth/login.php?role=traveller");
    exit;
}

$travellerId = (int)($_SESSION["traveller_id"] ?? 0);
$itineraryId = (int)($_GET["itinerary_id"] ?? 0);
if ($travellerId <= 0 || $itineraryId <= 0) {
    header("Location: my_itineraries.php");
    exit;
}

$stmt = $conn->prepare("SELECT title FROM itineraries WHERE itinerary_id = ? AND traveller_id = ? LIMIT 1");
$stmt->bind_param("ii", $itineraryId, $travellerId);
$stmt->execute();
$it = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$it) {
    header("Location: my_itineraries.php");
    exit;
}

$ics = CalendarExportService::buildForItinerary($conn, $itineraryId);
$fileName = CalendarExportService::fileStem((string)($it["title"] ?? ""), $itineraryId) . ".ics";

header("Content-Type: text/calendar; charset=utf-8");
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header("Content-Length: " . strlen($ics));
header("Cache-Control: private, no-store");
echo $ics;
