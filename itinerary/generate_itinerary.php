<?php
// itinerary/generate_itinerary.php
// Thin controller for the unified Rule-Based Itinerary Planner.
session_start();

require_once "../config/db_connect.php";
require_once "../services/StrictFestivalCandidatePoolService.php";

// Planning must never see a festival without valid dates. For this request the place table is
// shadowed by a pool without them; the permanent place records are not touched.
StrictFestivalCandidatePoolService::install($conn);
require_once "../services/RuleBasedItineraryPlannerService.php";
require_once "../services/PlannerIntegrityService.php";
require_once "../services/ItineraryTargetRefillService.php";
require_once "../services/ItineraryTargetRebuildService.php";
require_once "../services/ItineraryRouteOptimiser.php";

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
    $_SESSION["form_errors"] = ["Please select a saved preference first."];
    header("Location: " . $redirectTarget);
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
    header("Location: " . $redirectTarget);
    exit;
}

try {
    // Planning uses distance estimates, which keeps it fast and repeatable. The finished
    // plan is measured on the road at the end.
    $planner = new RuleBasedItineraryPlannerService($conn);
    $itineraryId = $planner->generate($travellerId, $preferenceId, [
        "start_date" => $startDate,
        "origin_name" => $originName,
        "origin_lat" => $originLat,
        "origin_lng" => $originLng,
    ]);

    // Remove paid places that exceed the cumulative attraction budget where possible.
    PlannerIntegrityService::enforceCumulativeAttractionBudget($conn, $travellerId, $itineraryId);

    // Append valid places first. If this cannot meet the pace target because the day is already late,
    // rebuild the short day from its official start point using a full-day planning window.
    ItineraryTargetRefillService::refill($conn, $travellerId, $itineraryId);
    ItineraryTargetRebuildService::rebuildShortDays($conn, $travellerId, $itineraryId);

    // Re-apply budget integrity after rebuild/refill so added paid places cannot silently exceed budget.
    PlannerIntegrityService::enforceCumulativeAttractionBudget($conn, $travellerId, $itineraryId);

    // Measure the finished plan on the road for the chosen transport, put the stops of each
    // day into the order with the least travelling and time the days again. When the routing
    // server does not answer, the plan keeps its estimates.
    $conn->begin_transaction();
    try {
        ItineraryRouteOptimiser::optimise($conn, $itineraryId);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        error_log("generate_itinerary: route optimisation skipped for itinerary {$itineraryId}: " . $e->getMessage());
    }

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
