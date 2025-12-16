<?php
// itinerary/preference_process.php
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

$errors = [];

$travellerId = (int)($_SESSION["traveller_id"] ?? 0);

$tripDays = (int)($_POST["trip_days"] ?? 0);
$budget = (float)($_POST["budget"] ?? 0);
$transport = $_POST["transport_type"] ?? "";
$interests = $_POST["interests"] ?? [];
$states = $_POST["preferred_states"] ?? [];

// Validation
if ($travellerId <= 0) {
    $errors[] = "Invalid session. Please login again.";
}

if ($tripDays < 1 || $tripDays > 30) {
    $errors[] = "Travel duration must be between 1 and 30 days.";
}

if ($budget <= 0) {
    $errors[] = "Budget must be greater than 0.";
}

$allowedTransport = ["car","public_transport","walking","motorcycle"];
if (!in_array($transport, $allowedTransport, true)) {
    $errors[] = "Invalid transport type.";
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

// Normalize arrays
$interestsStr = implode(",", array_unique($interests));

$statesStr = "";
if (is_array($states) && count($states) > 0) {
    // basic sanitize list
    $statesClean = [];
    foreach ($states as $s) {
        $s = trim($s);
        if ($s !== "") $statesClean[] = $s;
    }
    $statesStr = implode(",", array_unique($statesClean));
}

if (!empty($errors)) {
    $_SESSION["form_errors"] = $errors;
    $_SESSION["old_input"] = [
        "trip_days" => $tripDays,
        "budget" => $budget,
        "transport_type" => $transport,
        "interests" => $interests,
        "preferred_states" => $states
    ];
    header("Location: preference_form.php");
    exit;
}

// Insert to DB
$stmt = $conn->prepare("
    INSERT INTO traveller_preferences (traveller_id, trip_days, budget, transport_type, interests, preferred_states)
    VALUES (?,?,?,?,?,?)
");

if (!$stmt) {
    $_SESSION["form_errors"] = ["System error: unable to save preferences."];
    header("Location: preference_form.php");
    exit;
}

$stmt->bind_param("iidsss", $travellerId, $tripDays, $budget, $transport, $interestsStr, $statesStr);

if ($stmt->execute()) {
    $newPrefId = $stmt->insert_id;
    $stmt->close();

    // store last preference_id in session (方便下一模块 itinerary generator 直接用)
    $_SESSION["last_preference_id"] = $newPrefId;

    $_SESSION["success_message"] = "Preferences saved successfully. You may proceed to itinerary generation.";
    // 下一步可以 redirect 到 itinerary generator（先放 placeholder）
    header("Location: preference_form.php");
    exit;
} else {
    $stmt->close();
    $_SESSION["form_errors"] = ["Failed to save preferences. Please try again."];
    header("Location: preference_form.php");
    exit;
}
