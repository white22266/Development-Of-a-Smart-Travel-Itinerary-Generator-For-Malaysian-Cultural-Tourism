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
//rule-based $title
// ===== Read generator options from POST (MUST be before INSERT) =====
$startDate = trim((string)($_POST["start_date"] ?? ""));   // may be empty
$itemsPerDay = (int)($_POST["items_per_day"] ?? 3);
$allowedItems = [1, 2, 3, 4, 5];
if (!in_array($itemsPerDay, $allowedItems, true)) $itemsPerDay = 3;

$routeStrategy = trim((string)($_POST["route_strategy"] ?? "google_optimize"));
if (!in_array($routeStrategy, ["google_optimize", "nearest_next"], true)) {
    $routeStrategy = "google_optimize";
}

// Validate start date format (optional but safer)
$sd = null;
if ($startDate !== "") {
    $dt = DateTime::createFromFormat("Y-m-d", $startDate);
    if ($dt && $dt->format("Y-m-d") === $startDate) {
        $sd = $startDate; // valid date
    }
}


function normalize_list(string $csv): array
{
    $csv = trim($csv);
    if ($csv === "") return [];
    $parts = array_map("trim", explode(",", $csv));
    $parts = array_values(array_filter($parts, fn($x) => $x !== ""));
    return $parts;
}

function map_category_label(string $cat): string
{
    $map = [
        "culture" => "Culture",
        "heritage" => "Heritage",
        "museum" => "Museums",
        "food" => "Food",
        "festival" => "Festivals",
        "nature" => "Nature",
        "shopping" => "Shopping"
    ];
    $cat = strtolower(trim($cat));
    return $map[$cat] ?? ucfirst($cat);
}

function pick_top(array $items, int $max): array
{
    $out = [];
    foreach ($items as $x) {
        if (!in_array($x, $out, true)) $out[] = $x;
        if (count($out) >= $max) break;
    }
    return $out;
}

function build_itinerary_title(
    int $tripDays,
    string $preferredStatesCsv,
    string $interestsCsv,
    int $seed
): string {
    $states = normalize_list($preferredStatesCsv);
    $catsRaw = normalize_list($interestsCsv);
    $cats = array_map("map_category_label", $catsRaw);

    $statesTop = pick_top($states, 2);
    $catsTop = pick_top($cats, 2);

    $statesText = "Malaysia";
    if (count($statesTop) === 1) $statesText = $statesTop[0];
    if (count($statesTop) === 2) $statesText = $statesTop[0] . " & " . $statesTop[1];
    if (count($states) > 2) $statesText .= " + More";

    $themeText = "Cultural";
    if (count($catsTop) === 1) $themeText = $catsTop[0];
    if (count($catsTop) === 2) $themeText = $catsTop[0] . " & " . $catsTop[1];

    $templates = [
        "%dD %s Trail — %s",
        "%dD %s Escape: %s",
        "%dD %s Highlights | %s",
        "%dD %s Explorer Route — %s",
        "%dD %s Journey: %s",
        "%dD %s Getaway — %s"
    ];

    $idx = $seed % count($templates);
    return sprintf($templates[$idx], $tripDays, $themeText, $statesText);
}

function haversine_km($lat1, $lon1, $lat2, $lon2): float
{
    $R = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) * sin($dLat / 2) +
        cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
        sin($dLon / 2) * sin($dLon / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $R * $c;
}

function valid_coord($lat, $lng): bool
{
    if ($lat === null || $lng === null) return false;
    $lat = (float)$lat;
    $lng = (float)$lng;
    return is_finite($lat) && is_finite($lng) && !($lat == 0.0 && $lng == 0.0);
}

function order_nearest_next(array $selected): array
{
    $with = [];
    $without = [];

    foreach ($selected as $p) {
        if (valid_coord($p["latitude"] ?? null, $p["longitude"] ?? null)) $with[] = $p;
        else $without[] = $p;
    }

    if (count($with) <= 2) return array_merge($with, $without);

    $ordered = [];
    $ordered[] = array_shift($with);

    while (!empty($with)) {
        $last = $ordered[count($ordered) - 1];

        $bestIdx = 0;
        $bestD = PHP_FLOAT_MAX;

        foreach ($with as $i => $cand) {
            $d = haversine_km(
                (float)$last["latitude"],
                (float)$last["longitude"],
                (float)$cand["latitude"],
                (float)$cand["longitude"]
            );
            if ($d < $bestD) {
                $bestD = $d;
                $bestIdx = $i;
            }
        }

        $ordered[] = $with[$bestIdx];
        array_splice($with, $bestIdx, 1);
    }

    return array_merge($ordered, $without);
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

$statesCsv = trim((string)($pref["preferred_states"] ?? ""));

// For title display only
$titleStatesCsv = ($statesCsv === "") ? "Malaysia" : $statesCsv;

// Build states filter list
$states = $statesCsv !== "" ? array_values(array_unique(array_filter(array_map("trim", explode(",", $statesCsv))))) : [];

// If user saved "Malaysia" (or empty), do NOT filter by state
$statesLower = array_map("strtolower", $states);
if ($statesCsv === "" || in_array("malaysia", $statesLower, true)) {
    $states = []; // no state filter => all states
}


$allowedCategories = ["culture", "heritage", "museum", "food", "festival", "nature", "shopping"];
$categories = $interestsCsv !== "" ? array_values(array_unique(array_filter(array_map("trim", explode(",", $interestsCsv))))) : [];
$categories = array_values(array_intersect($categories, $allowedCategories));
if (empty($categories)) $categories = $allowedCategories;



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
// bind_param needs references
$bind = [];
$bind[] = $types;
for ($i = 0; $i < count($params); $i++) {
    $bind[] = &$params[$i];
}
call_user_func_array([$stmt, 'bind_param'], $bind);

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
$seed = crc32($travellerId . "|" . $preferenceId . "|" . date("Y-m-d H:i:s"));
$title = build_itinerary_title($tripDays, $titlestatesCsv, $interestsCsv, $seed);

$stmt = $conn->prepare("
  INSERT INTO itineraries (traveller_id, preference_id, title, start_date, total_days, items_per_day, total_estimated_cost, status)
  VALUES (?,?,?,?,?,?,0.00,'saved')
");

$stmt->bind_param("iissii", $travellerId, $preferenceId, $title, $sd, $tripDays, $itemsPerDay);

if (!$stmt->execute()) {
    $_SESSION["form_errors"] = ["Failed to create itinerary. " . $stmt->error];
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
    // APPLY ROUTE STRATEGY HERE
    if ($routeStrategy === "nearest_next") {
        $selected = order_nearest_next($selected);
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
