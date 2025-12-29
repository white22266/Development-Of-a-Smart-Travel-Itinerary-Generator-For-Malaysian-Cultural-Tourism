<?php
// itinerary/generate_itinerary.php
session_start();

require_once "../config/db_connect.php";
require_once "../config/api_keys.php"; // expects GOOGLE_MAPS_API_KEY (string)

// ===================== AUTH / BASIC VALIDATION =====================
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
    $_SESSION["form_errors"] = ["Please select a preference first."];
    header("Location: select_preference.php");
    exit;
}

// ===================== OPTIONS FROM POST =====================
$startDate = trim((string)($_POST["start_date"] ?? ""));
$itemsPerDay = (int)($_POST["items_per_day"] ?? 3);
$routeStrategy = trim((string)($_POST["route_strategy"] ?? "google_optimize"));

$allowedItems = [1, 2, 3, 4, 5];
if (!in_array($itemsPerDay, $allowedItems, true)) $itemsPerDay = 3;

if (!in_array($routeStrategy, ["google_optimize", "nearest_next"], true)) {
    $routeStrategy = "google_optimize";
}

// Validate start date format (optional)
$sd = null;
if ($startDate !== "") {
    $dt = DateTime::createFromFormat("Y-m-d", $startDate);
    if ($dt && $dt->format("Y-m-d") === $startDate) {
        $sd = $startDate;
    }
}

// ===================== HELPERS =====================
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

function build_itinerary_title(int $tripDays, string $preferredStatesCsv, string $interestsCsv, int $seed): string
{
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

// ---- Region helpers (East vs Peninsular) ----
function canonical_state(string $state): string
{
    $s = strtolower(trim($state));
    if ($s === "pulau pinang") return "penang";
    if ($s === "malacca") return "melaka";
    return $s;
}

function state_group(string $state): string
{
    $s = canonical_state($state);
    $east = ["sabah", "sarawak", "labuan"];
    return in_array($s, $east, true) ? "east" : "peninsular";
}

// ---- Daily distance limit by transport ----
function get_daily_max_km(string $transportType): float
{
    $t = strtolower(trim($transportType));
    if ($t === "walking") return 5.0;
    if ($t === "public" || $t === "bus" || $t === "train") return 25.0;
    if ($t === "car" || $t === "drive") return 45.0;
    return 35.0;
}

// ---- Neighbor map (canonical keys) ----
function state_neighbors_map(): array
{
    return [
        // Peninsular
        "perlis" => ["kedah"],
        "kedah" => ["perlis", "penang", "perak"],
        "penang" => ["kedah", "perak"],
        "perak" => ["kedah", "penang", "kelantan", "pahang", "selangor"],
        "selangor" => ["perak", "pahang", "negeri sembilan", "kuala lumpur", "putrajaya"],
        "kuala lumpur" => ["selangor"],
        "putrajaya" => ["selangor"],
        "negeri sembilan" => ["selangor", "melaka", "pahang", "johor"],
        "melaka" => ["negeri sembilan", "johor"],
        "johor" => ["melaka", "negeri sembilan", "pahang"],
        "pahang" => ["perak", "selangor", "negeri sembilan", "terengganu", "kelantan", "johor"],
        "terengganu" => ["pahang", "kelantan"],
        "kelantan" => ["terengganu", "pahang", "perak"],

        // East Malaysia
        "sabah" => ["sarawak", "labuan"],
        "sarawak" => ["sabah"],
        "labuan" => ["sabah"],
    ];
}

function count_valid_places(array $pool): int
{
    $n = 0;
    foreach ($pool as $p) {
        if (valid_coord($p["latitude"] ?? null, $p["longitude"] ?? null)) $n++;
    }
    return $n;
}

// ---- Pool selection (no reuse by removing from pool) ----
function take_compact_from_pool(array &$pool, int $k, float $maxKm): array
{
    if ($k <= 0) return [];
    if (count_valid_places($pool) < 1) return [];

    // Build index list of valid coordinate items
    $validIdx = [];
    foreach ($pool as $i => $p) {
        if (valid_coord($p["latitude"] ?? null, $p["longitude"] ?? null)) $validIdx[] = $i;
    }
    if (count($validIdx) < 1) return [];

    // We allow fewer than k if pool cannot provide enough (to avoid stopping)
    $target = min($k, count($validIdx));
    if ($target <= 0) return [];

    shuffle($validIdx);
    $attempts = min(40, count($validIdx));

    for ($a = 0; $a < $attempts; $a++) {
        $anchorIdx = $validIdx[$a];
        if (!isset($pool[$anchorIdx])) continue;

        $anchor = $pool[$anchorIdx];
        $anchorLat = (float)$anchor["latitude"];
        $anchorLng = (float)$anchor["longitude"];

        $pickedIdx = [$anchorIdx];

        while (count($pickedIdx) < $target) {
            $last = $pool[$pickedIdx[count($pickedIdx) - 1]];
            $lastLat = (float)$last["latitude"];
            $lastLng = (float)$last["longitude"];

            $bestIdx = null;
            $bestD = PHP_FLOAT_MAX;

            foreach ($validIdx as $candIdx) {
                if (!isset($pool[$candIdx])) continue;
                if (in_array($candIdx, $pickedIdx, true)) continue;

                $cand = $pool[$candIdx];
                $candLat = (float)$cand["latitude"];
                $candLng = (float)$cand["longitude"];

                // keep within anchor radius
                $dAnchor = haversine_km($anchorLat, $anchorLng, $candLat, $candLng);
                if ($dAnchor > $maxKm) continue;

                $dLast = haversine_km($lastLat, $lastLng, $candLat, $candLng);
                if ($dLast < $bestD) {
                    $bestD = $dLast;
                    $bestIdx = $candIdx;
                }
            }

            if ($bestIdx === null) break; // can't find more nearby

            $pickedIdx[] = $bestIdx;
        }

        if (count($pickedIdx) >= 1) {
            // Collect items
            $selected = [];
            foreach ($pickedIdx as $idx) $selected[] = $pool[$idx];

            // Remove selected from pool by place_id
            $pickedIds = array_flip(array_map(fn($x) => (int)$x["place_id"], $selected));
            $newPool = [];
            foreach ($pool as $p) {
                $pid = (int)$p["place_id"];
                if (!isset($pickedIds[$pid])) $newPool[] = $p;
            }
            $pool = $newPool;

            return $selected;
        }
    }

    return [];
}

function take_loose_from_pool(array &$pool, int $k): array
{
    if ($k <= 0 || empty($pool)) return [];

    shuffle($pool);

    $take = min($k, count($pool));
    $selected = array_slice($pool, 0, $take);

    // remove selected (no reuse)
    $pool = array_slice($pool, $take);

    return $selected;
}

// ---- Food priority ----
function desired_food_count(int $itemsPerDay): int
{
    if ($itemsPerDay >= 5) return 2;
    if ($itemsPerDay >= 3) return 1;
    return 0;
}

function count_food(array $selected): int
{
    $c = 0;
    foreach ($selected as $p) {
        if (strtolower((string)($p["category"] ?? "")) === "food") $c++;
    }
    return $c;
}

function ensure_food_priority(array &$selected, array &$pool, int $itemsPerDay, float $maxKm): void
{
    $need = desired_food_count($itemsPerDay);
    if ($need <= 0) return;

    $have = count_food($selected);
    if ($have >= $need) return;

    // Anchor for "nearby": first valid coordinate in selected
    $anchor = null;
    foreach ($selected as $p) {
        if (valid_coord($p["latitude"] ?? null, $p["longitude"] ?? null)) {
            $anchor = $p;
            break;
        }
    }

    while ($have < $need) {
        $bestIdx = null;
        $bestD = PHP_FLOAT_MAX;

        foreach ($pool as $i => $cand) {
            if (strtolower((string)($cand["category"] ?? "")) !== "food") continue;
            if (!valid_coord($cand["latitude"] ?? null, $cand["longitude"] ?? null)) continue;

            if ($anchor !== null) {
                $d = haversine_km(
                    (float)$anchor["latitude"],
                    (float)$anchor["longitude"],
                    (float)$cand["latitude"],
                    (float)$cand["longitude"]
                );
                if ($d > $maxKm) continue;
                if ($d < $bestD) {
                    $bestD = $d;
                    $bestIdx = $i;
                }
            } else {
                $bestIdx = $i;
                break;
            }
        }

        if ($bestIdx === null) break;

        // Replace a non-food
        $replaceIdx = null;
        for ($j = count($selected) - 1; $j >= 0; $j--) {
            if (strtolower((string)($selected[$j]["category"] ?? "")) !== "food") {
                $replaceIdx = $j;
                break;
            }
        }
        if ($replaceIdx === null) break;

        $food = $pool[$bestIdx];
        array_splice($pool, $bestIdx, 1);

        $replaced = $selected[$replaceIdx];
        $selected[$replaceIdx] = $food;

        // Put replaced back into pool for future days
        $pool[] = $replaced;

        $have++;
    }
}

// ---- Google optimize ordering (real) ----
function transport_to_google_mode(string $transportType): string
{
    $t = strtolower(trim($transportType));
    if ($t === "walking") return "walking";
    if ($t === "public" || $t === "bus" || $t === "train") return "transit";
    return "driving";
}

function google_key_available(): bool
{
    return defined("GOOGLE_MAPS_API_KEY") && is_string(GOOGLE_MAPS_API_KEY) && trim(GOOGLE_MAPS_API_KEY) !== "";
}

function http_get_json(string $url, int $timeoutSec = 10): ?array
{
    if (!function_exists("curl_init")) return null;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => $timeoutSec,
        CURLOPT_TIMEOUT => $timeoutSec,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $err !== "" || $code < 200 || $code >= 300) return null;

    $json = json_decode($raw, true);
    return is_array($json) ? $json : null;
}

function order_google_optimize(array $selected, string $transportType): ?array
{
    if (!google_key_available()) return null;

    $with = [];
    $without = [];

    foreach ($selected as $p) {
        if (valid_coord($p["latitude"] ?? null, $p["longitude"] ?? null)) $with[] = $p;
        else $without[] = $p;
    }

    if (count($with) <= 2) return array_merge($with, $without);

    $mode = transport_to_google_mode($transportType);

    $origin = (float)$with[0]["latitude"] . "," . (float)$with[0]["longitude"];
    $destination = (float)$with[count($with) - 1]["latitude"] . "," . (float)$with[count($with) - 1]["longitude"];

    $intermediates = array_slice($with, 1, count($with) - 2);
    if (empty($intermediates)) return array_merge($with, $without);

    $wps = [];
    foreach ($intermediates as $p) {
        $wps[] = (float)$p["latitude"] . "," . (float)$p["longitude"];
    }

    $waypoints = "optimize:true|" . implode("|", $wps);

    $url = "https://maps.googleapis.com/maps/api/directions/json"
        . "?origin=" . urlencode($origin)
        . "&destination=" . urlencode($destination)
        . "&waypoints=" . urlencode($waypoints)
        . "&mode=" . urlencode($mode)
        . "&key=" . urlencode(GOOGLE_MAPS_API_KEY);

    $json = http_get_json($url, 10);
    if (!$json) return null;
    if (($json["status"] ?? "") !== "OK") return null;

    $routes = $json["routes"] ?? [];
    if (empty($routes)) return null;

    $order = $routes[0]["waypoint_order"] ?? null;
    if (!is_array($order)) return null;

    $optimized = [];
    $optimized[] = $with[0];

    foreach ($order as $idx) {
        $idx = (int)$idx;
        if ($idx >= 0 && $idx < count($intermediates)) {
            $optimized[] = $intermediates[$idx];
        }
    }

    $optimized[] = $with[count($with) - 1];

    return array_merge($optimized, $without);
}

// ---- Day state candidates: same state -> neighbors -> others ----
function build_day_state_candidates(?string $currentState, array $byState): array
{
    $keys = array_keys($byState);
    if ($currentState === null || !isset($byState[$currentState])) return $keys;

    $cands = [];
    $cands[] = $currentState;

    $neighbors = state_neighbors_map();
    $curCanon = canonical_state($currentState);
    $nlist = $neighbors[$curCanon] ?? [];

    foreach ($nlist as $canon) {
        foreach ($keys as $k) {
            if (canonical_state($k) === $canon && !in_array($k, $cands, true)) {
                $cands[] = $k;
                break;
            }
        }
    }

    foreach ($keys as $k) {
        if (!in_array($k, $cands, true)) $cands[] = $k;
    }

    return $cands;
}

function remove_used_from_pool(array &$pool, array &$usedPlaceIds): void
{
    if (empty($pool) || empty($usedPlaceIds)) return;
    $new = [];
    foreach ($pool as $p) {
        $pid = (int)$p["place_id"];
        if (!isset($usedPlaceIds[$pid])) $new[] = $p;
    }
    $pool = $new;
}

function pick_start_state_best(array $byState): ?string
{
    $best = null;
    $bestCount = -1;
    foreach ($byState as $st => $pool) {
        $c = count($pool);
        if ($c > $bestCount) {
            $bestCount = $c;
            $best = $st;
        }
    }
    return $best;
}

// ===================== 1) LOAD PREFERENCE =====================
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
$transportType = trim((string)($pref["transport_type"] ?? ""));
$interestsCsv = trim((string)($pref["interests"] ?? ""));
$statesCsv = trim((string)($pref["preferred_states"] ?? ""));

// For title only
$titleStatesCsv = ($statesCsv === "") ? "Malaysia" : $statesCsv;

// Build state filter list
$states = $statesCsv !== "" ? array_values(array_unique(array_filter(array_map("trim", explode(",", $statesCsv))))) : [];
$statesLower = array_map("strtolower", $states);

// If empty or contains "Malaysia", do NOT filter by state
if ($statesCsv === "" || in_array("malaysia", $statesLower, true)) {
    $states = [];
}

$allowedCategories = ["culture", "heritage", "museum", "food", "festival", "nature", "shopping"];
$categories = $interestsCsv !== "" ? array_values(array_unique(array_filter(array_map("trim", explode(",", $interestsCsv))))) : [];
$categories = array_values(array_intersect($categories, $allowedCategories));
if (empty($categories)) $categories = $allowedCategories;

// Determine if user explicitly selected states (not Malaysia)
$statesCsvLowerList = array_map("strtolower", normalize_list($statesCsv));
$userSelectedStates = (!empty($statesCsv) && !in_array("malaysia", $statesCsvLowerList, true));
$preferredStatesForOrder = $userSelectedStates ? normalize_list($statesCsv) : [];

// ===================== 2) FETCH CANDIDATE PLACES =====================
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
call_user_func_array([$stmt, "bind_param"], $bind);

$stmt->execute();
$res = $stmt->get_result();

$places = [];
while ($row = $res->fetch_assoc()) $places[] = $row;
$stmt->close();

// ===================== 3) CREATE ITINERARY (ALWAYS CREATE, NO ROLLBACK) =====================
$seed = crc32($travellerId . "|" . $preferenceId . "|" . date("Y-m-d H:i:s"));
$title = build_itinerary_title($tripDays, $titleStatesCsv, $interestsCsv, $seed);

$stmt = $conn->prepare("
  INSERT INTO itineraries
    (traveller_id, preference_id, title, start_date, total_days, items_per_day, total_estimated_cost, status)
  VALUES
    (?,?,?,?,?,?,0.00,'saved')
");
$stmt->bind_param("iissii", $travellerId, $preferenceId, $title, $sd, $tripDays, $itemsPerDay);

if (!$stmt->execute()) {
    // If itinerary itself cannot be created, we must return (cannot continue).
    $_SESSION["form_errors"] = ["Failed to create itinerary. " . $stmt->error];
    header("Location: select_preference.php");
    exit;
}

$itineraryId = (int)$stmt->insert_id;
$stmt->close();

// If no places at all, still redirect to view (no error page)
if (count($places) === 0) {
    $_SESSION["success_message"] = "Itinerary created, but no cultural places matched your filters.";
    header("Location: itinerary_view.php?itinerary_id=" . $itineraryId);
    exit;
}

// ===================== 4) GROUP BY STATE =====================
$byState = [];
foreach ($places as $p) {
    $st = (string)($p["state"] ?? "");
    if ($st === "") $st = "Unknown";
    $byState[$st][] = $p;
}

// Shuffle each pool once
foreach ($byState as $st => $pool) {
    shuffle($pool);
    $byState[$st] = $pool;
}

// ===================== 5) PREPARE INSERT STATEMENT =====================
$ins = $conn->prepare("
  INSERT INTO itinerary_items
    (itinerary_id, day_no, sequence_no, item_type, place_id, item_title, estimated_cost, notes)
  VALUES
    (?,?,?,?,?,?,?,?)
");
if (!$ins) {
    // Do not block: redirect to view with message
    $_SESSION["success_message"] = "Itinerary created, but cannot insert items right now.";
    header("Location: itinerary_view.php?itinerary_id=" . $itineraryId);
    exit;
}

$totalCost = 0.0;
$maxDayKm = get_daily_max_km($transportType);

// Used place ids (rule #1)
$usedPlaceIds = []; // place_id => true

// Pick start state:
// - If user selected states: pick first preferred that exists
// - Else: pick best pool
$currentState = null;
if ($userSelectedStates) {
    foreach ($preferredStatesForOrder as $s) {
        foreach (array_keys($byState) as $k) {
            if (strcasecmp($k, $s) === 0) {
                $currentState = $k;
                break 2;
            }
        }
    }
}
if ($currentState === null) {
    $currentState = pick_start_state_best($byState);
}

// ===================== 6) GENERATE (NO STOP, NO ROLLBACK) =====================
for ($dayNo = 1; $dayNo <= $tripDays; $dayNo++) {

    // Remove used items from every pool (safety)
    foreach ($byState as $st => $pool) {
        remove_used_from_pool($pool, $usedPlaceIds);
        $byState[$st] = $pool;
    }

    // Candidates: same state -> neighbors -> others (rule #3)
    $candidates = build_day_state_candidates($currentState, $byState);

    $chosenState = null;
    $selected = [];

    foreach ($candidates as $st) {
        if (!isset($byState[$st]) || empty($byState[$st])) continue;

        // Try compact first (nearby), then loose (still no reuse)
        $try = take_compact_from_pool($byState[$st], $itemsPerDay, $maxDayKm);
        if (empty($try)) {
            $try = take_loose_from_pool($byState[$st], $itemsPerDay);
        }

        if (!empty($try)) {
            $chosenState = $st;
            $selected = $try;
            break;
        }
    }

    if (!empty($selected) && $chosenState !== null) {

        // Food priority within the SAME day state (rule #4)
        ensure_food_priority($selected, $byState[$chosenState], $itemsPerDay, $maxDayKm);

        // Route ordering mode (both have real function)
        if ($routeStrategy === "google_optimize") {
            $opt = order_google_optimize($selected, $transportType);
            if ($opt !== null) $selected = $opt;
            else $selected = order_nearest_next($selected); // fallback
        } elseif ($routeStrategy === "nearest_next") {
            $selected = order_nearest_next($selected);
        }

        // Insert items for this day (ONE state/day => no East/West mixing in same day)
        $seq = 1;
        foreach ($selected as $p) {
            $placeId = (int)$p["place_id"];
            if ($placeId <= 0) continue;
            if (isset($usedPlaceIds[$placeId])) continue; // hard rule #1

            $name = (string)$p["name"];
            $fee = ($p["estimated_cost"] !== null) ? (float)$p["estimated_cost"] : 0.00;
            $cat = strtolower((string)$p["category"]);

            $itemType = ($cat === "food") ? "food" : (($cat === "festival") ? "festival" : "attraction");
            $notes = "State: " . (string)$p["state"] . " | Category: " . $cat;

            $ins->bind_param(
                "iiisisds",
                $itineraryId,
                $dayNo,
                $seq,
                $itemType,
                $placeId,
                $name,
                $fee,
                $notes
            );

            // Never die; if fail, skip
            if ($ins->execute()) {
                $totalCost += $fee;
                $usedPlaceIds[$placeId] = true;
                $seq++;
            }
        }

        $currentState = $chosenState;
    } else {
        // Nothing available for this day: keep day empty, keep going (no stop)
        // Try to move to any other state with remaining places
        $currentState = pick_start_state_best($byState) ?? $currentState;
    }
}

$ins->close();

// ===================== 7) UPDATE ITINERARY TOTALS (KEEP total_days = tripDays) =====================
$upd = $conn->prepare("UPDATE itineraries SET total_estimated_cost = ?, total_days = ? WHERE itinerary_id = ?");
$upd->bind_param("dii", $totalCost, $tripDays, $itineraryId);
$upd->execute();
$upd->close();

// ===================== 8) REDIRECT =====================
header("Location: itinerary_view.php?itinerary_id=" . $itineraryId);
exit;
