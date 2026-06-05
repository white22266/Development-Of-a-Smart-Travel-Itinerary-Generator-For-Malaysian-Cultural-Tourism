<?php
// preference/preference_process.php
// Saves the complete preference profile. Database schema changes must be applied through SQL migrations.
session_start();
require_once "../config/db_connect.php";
require_once "../config/api_keys.php";
require_once "../services/AccessibilityNeedsAnalysisService.php";
require_once "../services/CostEstimationService.php";

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
    header("Location: ../auth/login.php?role=traveller");
    exit;
}
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: preference_form.php");
    exit;
}

function pref_table_exists(mysqli $conn, string $table): bool
{
    $table = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '$table'");
    return $res && $res->num_rows > 0;
}

function pref_column_exists(mysqli $conn, string $table, string $column): bool
{
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $res && $res->num_rows > 0;
}

function save_preference_junctions(mysqli $conn, int $preferenceId, array $interests, array $states): void
{
    if ($preferenceId <= 0) return;
    if (pref_table_exists($conn, "traveller_preference_interests") && pref_table_exists($conn, "travel_interests")) {
        $stmt = $conn->prepare("INSERT IGNORE INTO traveller_preference_interests (preference_id, interest_id) SELECT ?, interest_id FROM travel_interests WHERE interest_code = ?");
        if ($stmt) {
            foreach (array_unique(array_filter(array_map("trim", $interests))) as $interest) {
                $stmt->bind_param("is", $preferenceId, $interest);
                $stmt->execute();
            }
            $stmt->close();
        }
    }
    if (pref_table_exists($conn, "traveller_preference_states") && pref_table_exists($conn, "malaysia_states")) {
        $stmt = $conn->prepare("INSERT IGNORE INTO traveller_preference_states (preference_id, state_id) SELECT ?, state_id FROM malaysia_states WHERE state_name = ?");
        if ($stmt) {
            foreach (array_unique(array_filter(array_map("trim", $states))) as $state) {
                $stmt->bind_param("is", $preferenceId, $state);
                $stmt->execute();
            }
            $stmt->close();
        }
    }
}

$requiredColumns = [
    "budget_tier", "traveller_type", "party_size", "travel_pace", "dietary_preference",
    "preferred_visit_time", "accessibility_needs", "preferred_districts"
];
$missingColumns = [];
foreach ($requiredColumns as $column) {
    if (!pref_column_exists($conn, "traveller_preferences", $column)) $missingColumns[] = $column;
}
if (!empty($missingColumns)) {
    $_SESSION["form_errors"] = [
        "Database schema is not ready. Apply the latest SQL migration before saving preferences. Missing columns: " . implode(", ", $missingColumns)
    ];
    header("Location: preference_form.php");
    exit;
}

$errors = [];
$travellerId = (int)($_SESSION["traveller_id"] ?? 0);
$tripDays = (int)($_POST["trip_days"] ?? 0);
$budget = (float)($_POST["budget"] ?? 0);
$budgetTier = strtolower(trim((string)($_POST["budget_tier"] ?? "normal")));
$transport = strtolower(trim((string)($_POST["transport_type"] ?? "")));
$travellerType = strtolower(trim((string)($_POST["traveller_type"] ?? "solo")));
$postedPartySize = trim((string)($_POST["party_size"] ?? ""));
$travelPace = strtolower(trim((string)($_POST["travel_pace"] ?? "normal")));
$dietaryPreference = strtolower(trim((string)($_POST["dietary_preference"] ?? "none")));
$preferredVisitTime = strtolower(trim((string)($_POST["preferred_visit_time"] ?? "any")));
$accessibilityNeeds = trim((string)($_POST["accessibility_needs"] ?? ""));
$interests = $_POST["interests"] ?? [];
$states = $_POST["preferred_states"] ?? "";
$districts = $_POST["preferred_districts"] ?? "";

if ($travellerId <= 0) $errors[] = "Invalid session. Please login again.";
if ($tripDays < 1 || $tripDays > 30) $errors[] = "Travel duration must be between 1 and 30 days.";
if ($budget <= 0) $errors[] = "Budget must be greater than 0.";
if (!in_array($transport, ["car", "public_transport", "walking", "motorcycle"], true)) $errors[] = "Invalid transport type.";
if (!in_array($budgetTier, ["budget", "normal", "luxury"], true)) $errors[] = "Invalid budget tier.";
if (!in_array($travellerType, ["solo", "couple", "family", "group"], true)) $errors[] = "Invalid traveller type.";

$partySize = CostEstimationService::defaultPartySize($travellerType);
if ($travellerType === "solo") {
    $partySize = 1;
} elseif ($travellerType === "couple") {
    $partySize = 2;
} elseif ($postedPartySize === "" || !ctype_digit($postedPartySize)) {
    $errors[] = "Party size must be a positive whole number.";
} else {
    $partySize = (int)$postedPartySize;
    if ($partySize < 1 || $partySize > 1000) $errors[] = "Party size must be between 1 and 1000.";
}

if (!in_array($travelPace, ["relaxed", "normal", "packed"], true)) $errors[] = "Invalid travel pace.";
if (!in_array($dietaryPreference, ["none", "halal", "vegetarian"], true)) $errors[] = "Invalid dietary preference.";
if (!in_array($preferredVisitTime, ["any", "morning", "afternoon", "evening"], true)) $errors[] = "Invalid preferred visit time.";
if (strlen($accessibilityNeeds) > 120) $errors[] = "Accessibility notes must be 120 characters or fewer.";

$allowedInterests = ["culture", "heritage", "food", "museum", "nature", "shopping", "festival"];
if (!is_array($interests) || count($interests) < 1) {
    $errors[] = "Please select at least one interest.";
} else {
    foreach ($interests as $interest) {
        if (!in_array($interest, $allowedInterests, true)) {
            $errors[] = "Invalid interest selection.";
            break;
        }
    }
}

$interestsStr = implode(",", array_unique(array_filter(array_map("trim", is_array($interests) ? $interests : []))));
$statesStr = is_array($states) ? implode(",", array_unique(array_filter(array_map("trim", $states)))) : trim((string)$states);
$districtsStr = is_array($districts) ? implode(",", array_unique(array_filter(array_map("trim", $districts)))) : trim((string)$districts);

if (!empty($errors)) {
    $_SESSION["form_errors"] = $errors;
    $_SESSION["old_input"] = [
        "trip_days" => $tripDays, "budget" => $budget, "budget_tier" => $budgetTier,
        "transport_type" => $transport, "traveller_type" => $travellerType, "party_size" => $partySize,
        "travel_pace" => $travelPace, "dietary_preference" => $dietaryPreference,
        "preferred_visit_time" => $preferredVisitTime, "accessibility_needs" => $accessibilityNeeds,
        "interests" => $interests, "preferred_states" => $statesStr, "preferred_districts" => $districtsStr,
    ];
    header("Location: preference_form.php");
    exit;
}

if ($accessibilityNeeds !== "") {
    $analysis = (new AccessibilityNeedsAnalysisService())->analyze($accessibilityNeeds, $travellerType);
    $accessibilityNeeds = trim((string)($analysis["stored_text"] ?? $accessibilityNeeds));
}

$stmt = $conn->prepare("
    INSERT INTO traveller_preferences
        (traveller_id, trip_days, budget, budget_tier, transport_type, traveller_type, party_size, travel_pace,
         dietary_preference, preferred_visit_time, accessibility_needs, interests, preferred_states, preferred_districts)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
");
if (!$stmt) {
    $_SESSION["form_errors"] = ["System error: unable to save the complete preference profile."];
    header("Location: preference_form.php");
    exit;
}
$stmt->bind_param(
    "iidsssisssssss",
    $travellerId, $tripDays, $budget, $budgetTier, $transport, $travellerType, $partySize, $travelPace,
    $dietaryPreference, $preferredVisitTime, $accessibilityNeeds, $interestsStr, $statesStr, $districtsStr
);

if (!$stmt->execute()) {
    $stmt->close();
    $_SESSION["form_errors"] = ["Failed to save the complete preference profile. Please contact the administrator if the database migration has not been applied."];
    header("Location: preference_form.php");
    exit;
}

$newPrefId = (int)$stmt->insert_id;
$stmt->close();
save_preference_junctions($conn, $newPrefId, is_array($interests) ? $interests : [], array_filter(array_map("trim", explode(",", $statesStr))));
$_SESSION["last_preference_id"] = $newPrefId;
$_SESSION["success_message"] = "Preferences saved successfully. You may proceed to itinerary generation.";
header("Location: preference_form.php");
exit;
