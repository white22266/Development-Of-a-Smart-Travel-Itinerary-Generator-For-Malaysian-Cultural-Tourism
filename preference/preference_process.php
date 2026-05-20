<?php
// preference/preference_process.php
// Enhanced: saves preferred_districts alongside preferred_states
session_start();
require_once "../config/db_connect.php";

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
    return ($res && $res->num_rows > 0);
}

function pref_column_exists(mysqli $conn, string $table, string $column): bool
{
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return ($res && $res->num_rows > 0);
}

function save_preference_junctions(mysqli $conn, int $preferenceId, array $interests, array $states, string $district): void
{
    if ($preferenceId <= 0) return;

    if (pref_table_exists($conn, "traveller_preference_interests") && pref_table_exists($conn, "travel_interests")) {
        $stmt = $conn->prepare("
            INSERT IGNORE INTO traveller_preference_interests (preference_id, interest_id)
            SELECT ?, interest_id FROM travel_interests WHERE interest_code = ?
        ");
        if ($stmt) {
            foreach (array_unique(array_filter(array_map("trim", $interests))) as $interest) {
                $stmt->bind_param("is", $preferenceId, $interest);
                $stmt->execute();
            }
            $stmt->close();
        }
    }

    if (pref_table_exists($conn, "traveller_preference_states") && pref_table_exists($conn, "malaysia_states")) {
        $stmt = $conn->prepare("
            INSERT IGNORE INTO traveller_preference_states (preference_id, state_id)
            SELECT ?, state_id FROM malaysia_states WHERE state_name = ?
        ");
        if ($stmt) {
            foreach (array_unique(array_filter(array_map("trim", $states))) as $state) {
                $stmt->bind_param("is", $preferenceId, $state);
                $stmt->execute();
            }
            $stmt->close();
        }
    }
}

$errors = [];

$travellerId = (int)($_SESSION["traveller_id"] ?? 0);

$tripDays  = (int)($_POST["trip_days"]      ?? 0);
$budget    = (float)($_POST["budget"]       ?? 0);
$budgetTier = strtolower(trim((string)($_POST["budget_tier"] ?? "normal")));
$transport = strtolower(trim((string)($_POST["transport_type"] ?? "")));
$travellerType = strtolower(trim((string)($_POST["traveller_type"] ?? "solo")));
$travelPace = strtolower(trim((string)($_POST["travel_pace"] ?? "normal")));
$dietaryPreference = strtolower(trim((string)($_POST["dietary_preference"] ?? "none")));
$preferredVisitTime = strtolower(trim((string)($_POST["preferred_visit_time"] ?? "any")));
$accessibilityNeeds = trim((string)($_POST["accessibility_needs"] ?? ""));
$interests = $_POST["interests"]            ?? [];
$states    = $_POST["preferred_states"]     ?? "";
$districts = $_POST["preferred_districts"]  ?? "";

// ---- Validation ----
if ($travellerId <= 0) {
    $errors[] = "Invalid session. Please login again.";
}

if ($tripDays < 1 || $tripDays > 30) {
    $errors[] = "Travel duration must be between 1 and 30 days.";
}

if ($budget <= 0) {
    $errors[] = "Budget must be greater than 0.";
}

$allowedTransport = ["car", "public_transport", "walking", "motorcycle"];
if (!in_array($transport, $allowedTransport, true)) {
    $errors[] = "Invalid transport type.";
}

if (!in_array($budgetTier, ["budget", "normal", "luxury"], true)) {
    $errors[] = "Invalid budget tier.";
}

if (!in_array($travellerType, ["solo", "couple", "family", "group"], true)) {
    $errors[] = "Invalid traveller type.";
}

if (!in_array($travelPace, ["relaxed", "normal", "packed"], true)) {
    $errors[] = "Invalid travel pace.";
}

if (!in_array($dietaryPreference, ["none", "halal", "vegetarian"], true)) {
    $errors[] = "Invalid dietary preference.";
}

if (!in_array($preferredVisitTime, ["any", "morning", "afternoon", "evening"], true)) {
    $errors[] = "Invalid preferred visit time.";
}

if (strlen($accessibilityNeeds) > 120) {
    $errors[] = "Accessibility notes must be 120 characters or fewer.";
}

if (!is_array($interests) || count($interests) < 1) {
    $errors[] = "Please select at least one interest.";
} else {
    $allowedInterests = ["culture","heritage","food","museum","nature","shopping","festival"];
    foreach ($interests as $i) {
        if (!in_array($i, $allowedInterests, true)) {
            $errors[] = "Invalid interest selection.";
            break;
        }
    }
}

// ---- Normalize ----
$interestsStr = implode(",", array_unique(array_filter(array_map("trim", $interests))));

$statesStr = "";
if (is_array($states)) {
    $clean = array_unique(array_filter(array_map("trim", $states)));
    $statesStr = implode(",", $clean);
} else {
    $statesStr = trim((string)$states);
}

$districtsStr = "";
if (is_array($districts)) {
    $clean = array_unique(array_filter(array_map("trim", $districts)));
    $districtsStr = implode(",", $clean);
} else {
    $districtsStr = trim((string)$districts);
}

if (!empty($errors)) {
    $_SESSION["form_errors"] = $errors;
    $_SESSION["old_input"] = [
        "trip_days"           => $tripDays,
        "budget"              => $budget,
        "budget_tier"         => $budgetTier,
        "transport_type"      => $transport,
        "traveller_type"      => $travellerType,
        "travel_pace"         => $travelPace,
        "dietary_preference"  => $dietaryPreference,
        "preferred_visit_time"=> $preferredVisitTime,
        "accessibility_needs" => $accessibilityNeeds,
        "interests"           => $interests,
        "preferred_states"    => $statesStr,
        "preferred_districts" => $districtsStr,
    ];
    header("Location: preference_form.php");
    exit;
}

// ---- Insert to DB ----
// Check optional migrated columns (graceful fallback)
$colCheck = $conn->query("SHOW COLUMNS FROM traveller_preferences LIKE 'preferred_districts'");
$hasDistrictCol = ($colCheck && $colCheck->num_rows > 0);
$hasSupervisorCols = pref_table_exists($conn, "travel_interests")
    && pref_table_exists($conn, "malaysia_states")
    && pref_column_exists($conn, "traveller_preferences", "budget_tier")
    && pref_column_exists($conn, "traveller_preferences", "traveller_type")
    && pref_column_exists($conn, "traveller_preferences", "travel_pace")
    && pref_column_exists($conn, "traveller_preferences", "dietary_preference")
    && pref_column_exists($conn, "traveller_preferences", "preferred_visit_time")
    && pref_column_exists($conn, "traveller_preferences", "accessibility_needs");

if ($hasDistrictCol && $hasSupervisorCols) {
    $stmt = $conn->prepare("
        INSERT INTO traveller_preferences
            (traveller_id, trip_days, budget, budget_tier, transport_type, traveller_type, travel_pace, dietary_preference, preferred_visit_time, accessibility_needs, interests, preferred_states, preferred_districts)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");
    if (!$stmt) {
        $_SESSION["form_errors"] = ["System error: unable to save preferences."];
        header("Location: preference_form.php");
        exit;
    }
    $stmt->bind_param(
        "iidssssssssss",
        $travellerId,
        $tripDays,
        $budget,
        $budgetTier,
        $transport,
        $travellerType,
        $travelPace,
        $dietaryPreference,
        $preferredVisitTime,
        $accessibilityNeeds,
        $interestsStr,
        $statesStr,
        $districtsStr
    );
} elseif ($hasDistrictCol) {
    $stmt = $conn->prepare("
        INSERT INTO traveller_preferences
            (traveller_id, trip_days, budget, transport_type, interests, preferred_states, preferred_districts)
        VALUES (?,?,?,?,?,?,?)
    ");
    if (!$stmt) {
        $_SESSION["form_errors"] = ["System error: unable to save preferences."];
        header("Location: preference_form.php");
        exit;
    }
    $stmt->bind_param("iidssss", $travellerId, $tripDays, $budget, $transport, $interestsStr, $statesStr, $districtsStr);
} else {
    // Fallback: column not yet migrated — save without districts
    $stmt = $conn->prepare("
        INSERT INTO traveller_preferences
            (traveller_id, trip_days, budget, transport_type, interests, preferred_states)
        VALUES (?,?,?,?,?,?)
    ");
    if (!$stmt) {
        $_SESSION["form_errors"] = ["System error: unable to save preferences."];
        header("Location: preference_form.php");
        exit;
    }
    $stmt->bind_param("iidsss", $travellerId, $tripDays, $budget, $transport, $interestsStr, $statesStr);
}

if ($stmt->execute()) {
    $newPrefId = $stmt->insert_id;
    $stmt->close();

    $stateArr = array_filter(array_map("trim", explode(",", $statesStr)));
    save_preference_junctions($conn, $newPrefId, $interests, $stateArr, $districtsStr);

    $_SESSION["last_preference_id"] = $newPrefId;
    $_SESSION["success_message"] = "Preferences saved successfully. You may proceed to itinerary generation.";
    header("Location: preference_form.php");
    exit;
} else {
    $stmt->close();
    $_SESSION["form_errors"] = ["Failed to save preferences. Please try again."];
    header("Location: preference_form.php");
    exit;
}
