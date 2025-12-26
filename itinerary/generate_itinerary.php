<?php
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

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: select_preference.php");
    exit;
}

$preferenceId = (int)($_POST["preference_id"] ?? 0);
if ($preferenceId <= 0) {
    $_SESSION["form_errors"] = ["Please select a preference first."];
    header("Location: select_preference.php");
    exit;
}

// 1) Load preference (must belong to traveller)
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
    $_SESSION["form_errors"] = ["Invalid preference."];
    header("Location: select_preference.php");
    exit;
}

$tripDays = (int)$pref["trip_days"];
$budget = (float)$pref["budget"];
$transport = (string)$pref["transport_type"];
$interestsCsv = trim((string)$pref["interests"]);
$statesCsv = trim((string)$pref["preferred_states"]);

$allowedCategories = ["culture", "heritage", "museum", "food", "festival", "nature", "shopping"];
$categories = $interestsCsv !== "" ? array_values(array_unique(array_filter(array_map("trim", explode(",", $interestsCsv))))) : [];
$categories = array_values(array_intersect($categories, $allowedCategories));
if (empty($categories)) $categories = $allowedCategories;

$states = $statesCsv !== "" ? array_values(array_unique(array_filter(array_map("trim", explode(",", $statesCsv))))) : [];

// 2) Fetch candidate places
$where = "is_active = 1";
$params = [];
$types = "";

$catPH = implode(",", array_fill(0, count($categories), "?"));
$where .= " AND category IN ($catPH)";
$types .= str_repeat("s", count($categories));
$params = array_merge($params, $categories);

if (!empty($states)) {
    $stPH = implode(",", array_fill(0, count($states), "?"));
    $where .= " AND state IN ($stPH)";
    $types .= str_repeat("s", count($states));
    $params = array_merge($params, $states);
}

$sql = "
  SELECT place_id, state, category, name, description, address, latitude, longitude, opening_hours, estimated_cost
  FROM cultural_places
  WHERE $where
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    $_SESSION["form_errors"] = ["System error reading cultural_places."];
    header("Location: select_preference.php");
    exit;
}
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

$places = [];
while ($row = $res->fetch_assoc()) $places[] = $row;
$stmt->close();

if (count($places) === 0) {
    $_SESSION["form_errors"] = ["No cultural places found for the selected preference."];
    header("Location: select_preference.php");
    exit;
}

// 3) Create itinerary record
$titleStates = !empty($states) ? implode(", ", $states) : "Malaysia";
$title = $tripDays . "D Cultural Itinerary - " . $titleStates;

$stmt = $conn->prepare("
  INSERT INTO itineraries (traveller_id, preference_id, title, total_days, total_estimated_cost, status)
  VALUES (?,?,?,?,0.00,'saved')
");
$stmt->bind_param("iisi", $travellerId, $preferenceId, $title, $tripDays);
if (!$stmt->execute()) {
    $_SESSION["form_errors"] = ["Failed to create itinerary."];
    header("Location: select_preference.php");
    exit;
}
$itineraryId = (int)$stmt->insert_id;
$stmt->close();

// 4) Rule-based distribution (规则分配)
// Simple rule: 3 items/day: [morning attraction], [lunch food], [afternoon attraction]
// If not enough, fallback random.
$byState = [];
foreach ($places as $p) {
    $byState[$p["state"]][] = $p;
}
$stateKeys = array_keys($byState);
sort($stateKeys);

$itemsPerDay = 3;
$totalCost = 0.0;
$used = [];

$ins = $conn->prepare("
  INSERT INTO itinerary_items
  (itinerary_id, day_no, sequence_no, item_type, place_id, item_title, estimated_cost, notes)
  VALUES (?,?,?,?,?,?,?,?)
");
if (!$ins) {
    $_SESSION["form_errors"] = ["System error: cannot insert itinerary items."];
    header("Location: select_preference.php");
    exit;
}

for ($day = 1; $day <= $tripDays; $day++) {
    $dayState = $stateKeys[($day - 1) % count($stateKeys)];
    $pool = $byState[$dayState];
    shuffle($pool);

    $selected = [];

    // First pass: pick unique places
    foreach ($pool as $p) {
        $pid = (int)$p["place_id"];
        if (isset($used[$pid])) continue;
        $selected[] = $p;
        $used[$pid] = true;
        if (count($selected) >= $itemsPerDay) break;
    }

    // fallback: from all
    if (count($selected) < $itemsPerDay) {
        $all = $places;
        shuffle($all);
        foreach ($all as $p) {
            $pid = (int)$p["place_id"];
            if (isset($used[$pid])) continue;
            $selected[] = $p;
            $used[$pid] = true;
            if (count($selected) >= $itemsPerDay) break;
        }
    }

    // insert items
    $seq = 1;
    foreach ($selected as $p) {
        $placeId = (int)$p["place_id"];
        $name = (string)$p["name"];
        $fee = $p["estimated_cost"] !== null ? (float)$p["estimated_cost"] : 0.00;
        $cat = (string)$p["category"];

        $itemType = ($cat === "food") ? "food" : (($cat === "festival") ? "festival" : "attraction");
        $notes = "State: " . $p["state"] . " | Category: " . $cat;

        // CHANGED: correct bind types (fee = d)
        $ins->bind_param("iiisisds", $itineraryId, $day, $seq, $itemType, $placeId, $name, $fee, $notes);

        if (!$ins->execute()) {
            // show real error in dev
            die("Insert failed: " . $ins->error);
        }

        $totalCost += $fee;
        $seq++;
    }
}
$ins->close();

// 5) Update total cost
$upd = $conn->prepare("UPDATE itineraries SET total_estimated_cost = ? WHERE itinerary_id = ?");
$upd->bind_param("di", $totalCost, $itineraryId);
$upd->execute();
$upd->close();

// 6) Go to itinerary view (map + route optimize + weather)
header("Location: itinerary_view.php?itinerary_id=" . $itineraryId);
exit;
