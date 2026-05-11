<?php
// itinerary/share_create.php
// Creates or reuses a public share token for a traveller's itinerary.
session_start();
require_once __DIR__ . "/../config/db_connect.php";

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
    header("Location: ../auth/login.php?role=traveller");
    exit;
}

$travellerId = (int)($_SESSION["traveller_id"] ?? 0);
$itineraryId = (int)($_POST["itinerary_id"] ?? 0);

if ($travellerId <= 0 || $itineraryId <= 0) {
    header("Location: my_itineraries.php");
    exit;
}

$own = $conn->prepare("SELECT itinerary_id FROM itineraries WHERE itinerary_id = ? AND traveller_id = ? LIMIT 1");
$own->bind_param("ii", $itineraryId, $travellerId);
$own->execute();
$exists = $own->get_result()->fetch_assoc();
$own->close();

if (!$exists) {
    header("Location: my_itineraries.php");
    exit;
}

$token = "";
$check = $conn->prepare("SELECT share_token FROM shared_itineraries WHERE itinerary_id = ? AND is_active = 1 LIMIT 1");
if ($check) {
    $check->bind_param("i", $itineraryId);
    $check->execute();
    $row = $check->get_result()->fetch_assoc();
    $check->close();
    if ($row) $token = (string)$row["share_token"];
}

if ($token === "") {
    do {
        $token = bin2hex(random_bytes(16));
        $dup = $conn->prepare("SELECT share_id FROM shared_itineraries WHERE share_token = ? LIMIT 1");
        $dup->bind_param("s", $token);
        $dup->execute();
        $hasDup = (bool)$dup->get_result()->fetch_assoc();
        $dup->close();
    } while ($hasDup);

    $ins = $conn->prepare("INSERT INTO shared_itineraries (itinerary_id, share_token, is_active) VALUES (?, ?, 1)");
    $ins->bind_param("is", $itineraryId, $token);
    $ins->execute();
    $ins->close();
}

header("Location: shared_view.php?token=" . urlencode($token));
exit;
