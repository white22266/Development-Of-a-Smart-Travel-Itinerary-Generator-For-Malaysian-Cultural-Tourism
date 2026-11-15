<?php
// itinerary/generate_itinerary.php
// Thin controller for the rule-based itinerary planner (services/ItineraryGenerator.php).
session_start();

require_once "../config/db_connect.php";
require_once "../config/i18n.php";
require_once "../services/ItineraryGenerator.php";

if (
    !isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true ||
    ($_SESSION["role"] ?? "") !== "traveller"
) {
    header("Location: ../auth/login.php?role=traveller");
    exit;
}

$travellerId = (int)($_SESSION["traveller_id"] ?? 0);
if ($travellerId <= 0) {
    header("Location: ../auth/login.php?role=traveller");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: select_preference.php");
    exit;
}

$preferenceId = (int)($_POST["preference_id"] ?? 0);
$redirectTarget = "select_preference.php" . ($preferenceId > 0 ? "?preference_id=" . $preferenceId : "");

if ($preferenceId <= 0) {
    $_SESSION["form_errors"] = [t('rv.g_pick_pref')];
    header("Location: " . $redirectTarget);
    exit;
}

$startDate = trim((string)($_POST["start_date"] ?? ""));
$originName = trim((string)($_POST["origin_name"] ?? ""));
$originLat = (float)($_POST["origin_lat"] ?? 0);
$originLng = (float)($_POST["origin_lng"] ?? 0);
// The end point is posted only when the traveller chose to end the trip somewhere else.
$endOther = (string)($_POST["end_mode"] ?? "start") === "other";
$endName = $endOther ? trim((string)($_POST["end_name"] ?? "")) : "";
$endLat = $endOther ? (float)($_POST["end_lat"] ?? 0) : 0.0;
$endLng = $endOther ? (float)($_POST["end_lng"] ?? 0) : 0.0;

$errors = [];
$dt = DateTime::createFromFormat("Y-m-d", $startDate);
if (!$dt || $dt->format("Y-m-d") !== $startDate) {
    $errors[] = t('rv.g_start_date');
}
if ($originName === "" || $originLat == 0.0 || $originLng == 0.0 || !is_finite($originLat) || !is_finite($originLng)) {
    $errors[] = t('rv.g_origin');
}
if ($endOther && ($endName === "" || $endLat == 0.0 || $endLng == 0.0 || !is_finite($endLat) || !is_finite($endLng))) {
    $errors[] = t('rv.g_end');
}

if (!empty($errors)) {
    $_SESSION["form_errors"] = $errors;
    header("Location: " . $redirectTarget);
    exit;
}

try {
    // Plans, orders, measures on the road and stores the whole trip in one transaction:
    // either the complete itinerary is saved or nothing is.
    $trip = [
        "start_date" => $startDate,
        "origin_name" => $originName,
        "origin_lat" => $originLat,
        "origin_lng" => $originLng,
    ];
    if ($endOther) {
        $trip += ["end_name" => $endName, "end_lat" => $endLat, "end_lng" => $endLng];
    }
    // "I am staying at the starting point": the starting point is the base of every night.
    if (!empty($_POST["stay_at_start"])) {
        $trip["stay_at_start"] = true;
    }
    // Versions of earlier plans older than a day that were never chosen are removed now.
    ItineraryEditService::pruneVersions($conn, $travellerId);
    $itineraryId = (new ItineraryGenerator($conn))->generate($travellerId, $preferenceId, $trip);

    if (isset($_SESSION["ai_preference_drafts"][$travellerId][$preferenceId])) {
        unset($_SESSION["ai_preference_drafts"][$travellerId][$preferenceId]);
    }

    header("Location: itinerary_review.php?itinerary_id=" . (int)$itineraryId);
    exit;
} catch (Throwable $e) {
    $_SESSION["form_errors"] = [$e->getMessage()];
    header("Location: " . $redirectTarget);
    exit;
}
