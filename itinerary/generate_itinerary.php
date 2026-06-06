<?php
// itinerary/generate_itinerary.php
// Thin controller for the unified Rule-Based Itinerary Planner.
session_start();

require_once "../config/db_connect.php";
require_once "../config/api_keys.php";
require_once "../services/RuleBasedItineraryPlannerService.php";
require_once "../services/PlannerIntegrityService.php";
require_once "../services/ItineraryTargetRefillService.php";

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
if ($preferenceId <= 0) {
    $_SESSION["form_errors"] = ["Please select a saved preference first."];
    header("Location: select_preference.php");
    exit;
}

$startDate = trim((string)($_POST["start_date"] ?? ""));
$originName = trim((string)($_POST["origin_name"] ?? ""));
$originLat = (float)($_POST["origin_lat"] ?? 0);
$originLng = (float)($_POST["origin_lng"] ?? 0);

$errors = [];
$dt = DateTime::createFromFormat("Y-m-d", $startDate);
if (!$dt || $dt->format("Y-m-d") !== $startDate) {
    $errors[] = "Please confirm a valid Start Date before generating the itinerary.";
}
if ($originName === "" || $originLat == 0.0 || $originLng == 0.0 || !is_finite($originLat) || !is_finite($originLng)) {
    $errors[] = "Please confirm a Starting Location with map coordinates before generating the itinerary.";
}

if (!empty($errors)) {
    $_SESSION["form_errors"] = $errors;
    header("Location: select_preference.php");
    exit;
}

try {
    $googleMapsKey = defined("GOOGLE_MAPS_API_KEY") ? trim((string)GOOGLE_MAPS_API_KEY) : "";
    $planner = new RuleBasedItineraryPlannerService($conn, $googleMapsKey);
    $itineraryId = $planner->generate($travellerId, $preferenceId, [
        "start_date" => $startDate,
        "origin_name" => $originName,
        "origin_lat" => $originLat,
        "origin_lng" => $originLng,
    ]);

    // 1) Remove paid places that exceed the cumulative attraction budget where possible.
    PlannerIntegrityService::enforceCumulativeAttractionBudget($conn, $travellerId, $itineraryId);

    // 2) After optimisation and budget pruning, refill each day toward the travel-pace target:
    // Relaxed = 3, Normal = 4, Packed = 5. This uses the same saved preference, state boundary,
    // district/interest fallback order, opening hours, dietary and accessibility checks.
    ItineraryTargetRefillService::refill($conn, $travellerId, $itineraryId, $googleMapsKey);

    // 3) Re-apply budget integrity after refill so added paid places cannot silently exceed budget.
    PlannerIntegrityService::enforceCumulativeAttractionBudget($conn, $travellerId, $itineraryId);

    // 4) Final refill prioritises free/low-cost valid places if budget pruning removed anything.
    ItineraryTargetRefillService::refill($conn, $travellerId, $itineraryId, $googleMapsKey);

    if (isset($_SESSION["ai_preference_drafts"][$travellerId][$preferenceId])) {
        unset($_SESSION["ai_preference_drafts"][$travellerId][$preferenceId]);
    }

    header("Location: itinerary_review.php?itinerary_id=" . (int)$itineraryId);
    exit;
} catch (Throwable $e) {
    $_SESSION["form_errors"] = [$e->getMessage()];
    header("Location: select_preference.php");
    exit;
}
