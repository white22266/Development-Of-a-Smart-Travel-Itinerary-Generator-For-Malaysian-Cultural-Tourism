<?php
// itinerary/generate_itinerary.php
session_start();
require_once "../config/db_connect.php";

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
    header("Location: ../auth/login.php?role=traveller");
    exit;
}

$travellerId = (int)($_SESSION["traveller_id"] ?? 0);
if ($travellerId <= 0) {
    header("Location: ../auth/login.php?role=traveller");
    exit;
}

// preference_id priority: GET > session
$preferenceId = (int)($_GET["preference_id"] ?? ($_SESSION["last_preference_id"] ?? 0));
if ($preferenceId <= 0) {
    $_SESSION["form_errors"] = ["No preference record found. Please complete the Preference Analyzer first."];
    header("Location: preference_form.php");
    exit;
}

// 1) Load preference (must belong to this traveller)
$stmt = $conn->prepare("
  SELECT preference_id, trip_days, budget, transport_type, interests, preferred_states
  FROM traveller_preferences
  WHERE preference_id = ? AND traveller_id = ?
  LIMIT 1
");
$stmt->bind_param("ii", $preferenceId, $travellerId);
$stmt->execute();
$pref = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$pref) {
    $_SESSION["form_errors"] = ["Invalid preference record. Please try again."];
    header("Location: preference_form.php");
    exit;
}

$tripDays = (int)$pref["trip_days"];
$budget = (float)$pref["budget"];
$transport = $pref["transport_type"];
$interestsCsv = trim((string)$pref["interests"]);
$statesCsv = trim((string)$pref["preferred_states"]);

// interests & states (CSV -> array)
$interests = $interestsCsv !== "" ? array_values(array_unique(array_filter(array_map("trim", explode(",", $interestsCsv))))) : [];
$states = $statesCsv !== "" ? array_values(array_unique(array_filter(array_map("trim", explode(",", $statesCsv))))) : [];

// 2) NEW: category enum is identical to interests enum
// cultural_places.category ENUM('culture','heritage','museum','food','festival','nature','shopping')
$allowedCategories = ["culture", "heritage", "museum", "food", "festival", "nature", "shopping"];

// categories = interests (validated / normalized)
$categories = [];
foreach ($interests as $i) {
    if (in_array($i, $allowedCategories, true)) $categories[] = $i;
}
$categories = array_values(array_unique($categories));

if (empty($categories)) {
    // fallback: allow all categories
    $categories = $allowedCategories;
}

// 3) Fetch candidate places from DB
$where = "is_active = 1";
$params = [];
$types = "";

// categories IN (...)
$catPlaceholders = implode(",", array_fill(0, count($categories), "?"));
$where .= " AND category IN ($catPlaceholders)";
$types .= str_repeat("s", count($categories));
$params = array_merge($params, $categories);

// states filter optional
if (!empty($states)) {
    $statePlaceholders = implode(",", array_fill(0, count($states), "?"));
    $where .= " AND state IN ($statePlaceholders)";
    $types .= str_repeat("s", count($states));
    $params = array_merge($params, $states);
}

$sql = "
  SELECT place_id, state, category, name, estimated_cost
  FROM cultural_places
  WHERE $where
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    $_SESSION["form_errors"] = ["System error: cannot read cultural places. (Check cultural_places table / SQL query)"];
    header("Location: preference_form.php");
    exit;
}

// bind dynamic params
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

$places = [];
while ($row = $res->fetch_assoc()) {
    $places[] = $row;
}
$stmt->close();

if (count($places) < 1) {

    // Create an EMPTY itinerary (draft) so itinerary_view can show structure
    $titleStates = !empty($states) ? implode(", ", $states) : "Malaysia";
    $title = $tripDays . "D Cultural Itinerary - " . $titleStates;

    $stmt = $conn->prepare("
    INSERT INTO itineraries (traveller_id, preference_id, title, total_days, total_estimated_cost, status)
    VALUES (?,?,?,?,0.00,'draft')
  ");
    if (!$stmt) {
        $_SESSION["form_errors"] = ["System error: cannot create itinerary record."];
        header("Location: preference_form.php");
        exit;
    }

    $stmt->bind_param("iisi", $travellerId, $preferenceId, $title, $tripDays);
    $stmt->execute();
    $itineraryId = (int)$stmt->insert_id;
    $stmt->close();

    $_SESSION["itinerary_notice"] = "No matching cultural places found for your selected preferences. A draft itinerary was created with an empty schedule.";

    header("Location: itinerary_view.php?itinerary_id=" . $itineraryId);
    exit;
}


// 4) Group places by state (for day-by-day distribution)
$byState = [];
foreach ($places as $p) {
    $st = $p["state"];
    if (!isset($byState[$st])) $byState[$st] = [];
    $byState[$st][] = $p;
}
$stateKeys = array_keys($byState);
sort($stateKeys);

// 5) Create itinerary record
$titleStates = !empty($states) ? implode(", ", $states) : "Malaysia";
$title = $tripDays . "D Cultural Itinerary - " . $titleStates;

$stmt = $conn->prepare("
  INSERT INTO itineraries (traveller_id, preference_id, title, total_days, total_estimated_cost, status)
  VALUES (?,?,?,?,0.00,'saved')
");
$stmt->bind_param("iisi", $travellerId, $preferenceId, $title, $tripDays);
if (!$stmt->execute()) {
    $stmt->close();
    $_SESSION["form_errors"] = ["Failed to create itinerary record."];
    header("Location: preference_form.php");
    exit;
}
$itineraryId = $stmt->insert_id;
$stmt->close();

// 6) Generate items/day (rule-based, 3 items per day)
$itemsPerDay = 3;
$totalCost = 0.0;

$usedPlaceIds = [];
$insertItem = $conn->prepare("
  INSERT INTO itinerary_items
  (itinerary_id, day_no, sequence_no, item_type, place_id, item_title, estimated_cost, notes)
  VALUES (?,?,?,?,?,?,?,?)
");

if (!$insertItem) {
    $_SESSION["form_errors"] = ["System error: cannot insert itinerary items."];
    header("Location: preference_form.php");
    exit;
}

for ($day = 1; $day <= $tripDays; $day++) {

    // choose state for this day (cycle)
    $stateIndex = ($day - 1) % count($stateKeys);
    $dayState = $stateKeys[$stateIndex];
    $pool = $byState[$dayState];

    shuffle($pool);

    $selected = [];
    foreach ($pool as $p) {
        $pid = (int)$p["place_id"];
        if (isset($usedPlaceIds[$pid])) continue;
        $selected[] = $p;
        $usedPlaceIds[$pid] = true;
        if (count($selected) >= $itemsPerDay) break;
    }

    // fallback if not enough in chosen state: pick from all places
    if (count($selected) < $itemsPerDay) {
        $all = $places;
        shuffle($all);
        foreach ($all as $p) {
            $pid = (int)$p["place_id"];
            if (isset($usedPlaceIds[$pid])) continue;
            $selected[] = $p;
            $usedPlaceIds[$pid] = true;
            if (count($selected) >= $itemsPerDay) break;
        }
    }

    // insert selected
    $seq = 1;
    foreach ($selected as $p) {
        $placeId = (int)$p["place_id"];
        $name = (string)$p["name"];
        $fee = $p["estimated_cost"] !== null ? (float)$p["estimated_cost"] : 0.00;

        $cat = (string)$p["category"];

        // keep item_type simple (still compatible with your itinerary_items ENUM)
        $itemType = ($cat === "food") ? "food" : (($cat === "festival") ? "festival" : "attraction");

        $notes = "State: " . $p["state"] . " | Category: " . $cat;

        $insertItem->bind_param(
            "iiisisss",
            $itineraryId,
            $day,
            $seq,
            $itemType,
            $placeId,
            $name,
            $fee,
            $notes
        );
        $insertItem->execute();

        $totalCost += $fee;
        $seq++;
    }
}

$insertItem->close();

// 7) Update total estimated cost
$upd = $conn->prepare("UPDATE itineraries SET total_estimated_cost = ? WHERE itinerary_id = ?");
$upd->bind_param("di", $totalCost, $itineraryId);
$upd->execute();
$upd->close();

// 8) Redirect to view
header("Location: itinerary_view.php?itinerary_id=" . $itineraryId);
exit;
