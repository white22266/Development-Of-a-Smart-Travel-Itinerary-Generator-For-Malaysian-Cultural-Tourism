<?php
// preference/preference_process.php
// Saves the complete preference profile. Database schema changes must be applied through SQL migrations.
session_start();
require_once "../config/db_connect.php";
require_once "../config/i18n.php";
require_once "../services/AccessibilityNeedsAnalysisService.php";
require_once "../services/CostEstimationService.php";
require_once __DIR__ . "/preference_inputs.php";

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

function save_preference_junctions(mysqli $conn, int $preferenceId, array $interests, array $states, array $weights = []): void
{
    if ($preferenceId <= 0) return;
    if (pref_table_exists($conn, "traveller_preference_interests") && pref_table_exists($conn, "travel_interests")) {
        $stmt = $conn->prepare("INSERT IGNORE INTO traveller_preference_interests (preference_id, interest_id, weight) SELECT ?, interest_id, ? FROM travel_interests WHERE interest_code = ?");
        if ($stmt) {
            foreach (array_unique(array_filter(array_map("trim", $interests))) as $interest) {
                $weight = (int)($weights[$interest] ?? 2);
                $stmt->bind_param("iis", $preferenceId, $weight, $interest);
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
    "preferred_visit_time", "accessibility_needs", "preferred_districts",
    "day_start_time", "day_end_time", "stops_per_day", "adults", "children", "seniors", "accessibility_tags"
];
$missingColumns = [];
foreach ($requiredColumns as $column) {
    if (!pref_column_exists($conn, "traveller_preferences", $column)) $missingColumns[] = $column;
}
if (!pref_column_exists($conn, "traveller_preference_interests", "weight")) $missingColumns[] = "traveller_preference_interests.weight";
if (!pref_table_exists($conn, "traveller_preference_places")) $missingColumns[] = "table traveller_preference_places";
if (!empty($missingColumns)) {
    $_SESSION["form_errors"] = [
        t('pref.err_schema', ['columns' => implode(", ", $missingColumns)])
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

if ($travellerId <= 0) $errors[] = t('pref.err_session');
if ($tripDays < 1 || $tripDays > 30) $errors[] = t('pref.err_days');
if ($budget <= 0) $errors[] = t('pref.err_budget');
if (!in_array($transport, ["car", "public_transport", "walking", "motorcycle"], true)) $errors[] = t('pref.err_transport');
if (!in_array($budgetTier, ["budget", "normal", "luxury"], true)) $errors[] = t('pref.err_tier');
if (!in_array($travellerType, ["solo", "couple", "family", "group"], true)) $errors[] = t('pref.err_type');

[$party, $partyErrors] = pref_parse_party(
    (string)($_POST["adults"] ?? ""), (string)($_POST["children"] ?? ""), (string)($_POST["seniors"] ?? "")
);
$errors = array_merge($errors, $partyErrors);

$partySize = CostEstimationService::defaultPartySize($travellerType);
if ($party !== null) {
    $partySize = $party["total"];   // the mix of adults, children and seniors decides
} elseif (!empty($partyErrors)) {
    // the party mix was rejected above; keep the default size
} elseif ($travellerType === "solo") {
    $partySize = 1;
} elseif ($travellerType === "couple") {
    $partySize = 2;
} elseif ($postedPartySize === "" || !ctype_digit($postedPartySize)) {
    $errors[] = t('pref.err_party_number');
} else {
    $partySize = (int)$postedPartySize;
    if ($partySize < 1 || $partySize > 1000) $errors[] = t('pref.err_party_range');
}

if (!in_array($travelPace, ["relaxed", "normal", "packed"], true)) $errors[] = t('pref.err_pace');
if (!in_array($dietaryPreference, ["none", "halal", "vegetarian"], true)) $errors[] = t('pref.err_diet');
if (!in_array($preferredVisitTime, ["any", "morning", "afternoon", "evening"], true)) $errors[] = t('pref.err_visit');
if (strlen($accessibilityNeeds) > 120) $errors[] = t('pref.err_access_len');

[$dayStart, $dayEnd, $dayErrors] = pref_parse_day_window((string)($_POST["day_start_time"] ?? ""), (string)($_POST["day_end_time"] ?? ""));
[$stopsPerDay, $stopsErrors] = pref_parse_stops((string)($_POST["stops_per_day"] ?? ""));
$errors = array_merge($errors, $dayErrors, $stopsErrors);

$tickedTags = $_POST["access_tags"] ?? [];
if (!is_array($tickedTags)) $tickedTags = [];
foreach ($tickedTags as $tag) {
    if (!is_string($tag) || !in_array($tag, PREF_ACCESS_TAGS, true)) {
        $errors[] = t('pref.err_access_tag');
        break;
    }
}

[$mustIds, $avoidIds, $placeErrors] = pref_check_place_lists($_POST["must_places"] ?? [], $_POST["avoid_places"] ?? []);
$errors = array_merge($errors, $placeErrors);
if (empty($placeErrors) && ($mustIds || $avoidIds)) {
    $allIds = array_merge($mustIds, $avoidIds);
    $marks = implode(",", array_fill(0, count($allIds), "?"));
    $check = $conn->prepare("SELECT place_id FROM cultural_places WHERE is_active = 1 AND place_id IN ($marks)");
    $check->bind_param(str_repeat("i", count($allIds)), ...$allIds);
    $check->execute();
    $found = [];
    foreach ($check->get_result()->fetch_all(MYSQLI_ASSOC) as $row) $found[(int)$row["place_id"]] = true;
    $check->close();
    foreach ($allIds as $id) {
        if (!isset($found[$id])) {
            $errors[] = t('pref.err_place_missing');
            break;
        }
    }
}

$allowedInterests = ["culture", "heritage", "food", "museum", "nature", "shopping", "festival"];
if (!is_array($interests) || count($interests) < 1) {
    $errors[] = t('pref.err_interest_none');
} else {
    foreach ($interests as $interest) {
        if (!in_array($interest, $allowedInterests, true)) {
            $errors[] = t('pref.err_interest_invalid');
            break;
        }
    }
}

$interestWeights = [];
$postedWeights = $_POST["interest_weight"] ?? [];
if (!is_array($postedWeights)) $postedWeights = [];
foreach (is_array($interests) ? $interests : [] as $interest) {
    if (!is_string($interest) || !in_array($interest, $allowedInterests, true)) continue;
    $w = pref_parse_weight($postedWeights[$interest] ?? "");
    if ($w === null) {
        $errors[] = t('pref.err_interest_level');
        break;
    }
    $interestWeights[$interest] = $w;
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
        "day_start_time" => (string)($_POST["day_start_time"] ?? ""), "day_end_time" => (string)($_POST["day_end_time"] ?? ""),
        "stops_per_day" => (string)($_POST["stops_per_day"] ?? ""),
        "adults" => (string)($_POST["adults"] ?? ""), "children" => (string)($_POST["children"] ?? ""),
        "seniors" => (string)($_POST["seniors"] ?? ""),
        "interest_weight" => $interestWeights, "access_tags" => array_values($tickedTags),
        "must_places" => $mustIds, "avoid_places" => $avoidIds,
    ];
    header("Location: preference_form.php");
    exit;
}

$derivedTags = [];
if ($accessibilityNeeds !== "") {
    $analysis = (new AccessibilityNeedsAnalysisService())->analyze($accessibilityNeeds, $travellerType);
    $accessibilityNeeds = trim((string)($analysis["stored_text"] ?? $accessibilityNeeds));
    $derivedTags = is_array($analysis["tags"] ?? null) ? $analysis["tags"] : [];
}
$accessibilityTags = pref_accessibility_tag_list($tickedTags, $derivedTags);
$adultsN = $party["adults"] ?? null;
$childrenN = $party["children"] ?? null;
$seniorsN = $party["seniors"] ?? null;

$conn->begin_transaction();
try {
    $stmt = $conn->prepare("
        INSERT INTO traveller_preferences
            (traveller_id, trip_days, budget, budget_tier, transport_type, traveller_type, party_size, travel_pace,
             dietary_preference, preferred_visit_time, accessibility_needs, interests, preferred_states, preferred_districts,
             day_start_time, day_end_time, stops_per_day, adults, children, seniors, accessibility_tags)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");
    $stmt->bind_param(
        "iidsssisssssssssiiiis",
        $travellerId, $tripDays, $budget, $budgetTier, $transport, $travellerType, $partySize, $travelPace,
        $dietaryPreference, $preferredVisitTime, $accessibilityNeeds, $interestsStr, $statesStr, $districtsStr,
        $dayStart, $dayEnd, $stopsPerDay, $adultsN, $childrenN, $seniorsN, $accessibilityTags
    );
    $stmt->execute();
    $newPrefId = (int)$stmt->insert_id;
    $stmt->close();

    save_preference_junctions($conn, $newPrefId, is_array($interests) ? $interests : [], array_filter(array_map("trim", explode(",", $statesStr))), $interestWeights);

    $placeStmt = $conn->prepare("INSERT INTO traveller_preference_places (preference_id, place_id, wish) VALUES (?,?,?)");
    foreach (["must" => $mustIds, "avoid" => $avoidIds] as $wish => $ids) {
        foreach ($ids as $placeId) {
            $placeStmt->bind_param("iis", $newPrefId, $placeId, $wish);
            $placeStmt->execute();
        }
    }
    $placeStmt->close();

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    error_log("preference_process: " . $e->getMessage());
    $_SESSION["form_errors"] = [t('pref.err_save')];
    header("Location: preference_form.php");
    exit;
}

$_SESSION["last_preference_id"] = $newPrefId;
$_SESSION["success_message"] = t('pref.saved');
header("Location: ../itinerary/select_preference.php?preference_id=" . $newPrefId . "&from=preference_saved");
exit;
