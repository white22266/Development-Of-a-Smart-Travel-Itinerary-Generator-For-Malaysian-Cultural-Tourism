<?php
// itinerary/generate_itinerary.php
// Enhanced: RouteService integration, origin-aware routing, distance/time per item
session_start();

require_once "../config/db_connect.php";
require_once "../config/api_keys.php";
require_once "../services/RouteService.php";
require_once "../services/CostEstimationService.php";

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
$startDate     = trim((string)($_POST["start_date"]     ?? ""));
$routeStrategy = trim((string)($_POST["route_strategy"] ?? "google_optimize"));

// Origin-aware routing: user's starting location
$originLat  = (float)($_POST["origin_lat"]  ?? 0);
$originLng  = (float)($_POST["origin_lng"]  ?? 0);
$originName = trim((string)($_POST["origin_name"] ?? ""));
$hasOrigin  = ($originLat !== 0.0 && $originLng !== 0.0
               && is_finite($originLat) && is_finite($originLng));

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
        "%dD %s Trail - %s",
        "%dD %s Escape: %s",
        "%dD %s Highlights | %s",
        "%dD %s Explorer Route - %s",
        "%dD %s Journey: %s",
        "%dD %s Getaway - %s"
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

function has_accessibility_keyword(string $accessibilityNeeds, array $keywords): bool
{
    $access = strtolower($accessibilityNeeds);
    foreach ($keywords as $keyword) {
        if ($keyword !== "" && str_contains($access, strtolower($keyword))) return true;
    }
    return false;
}

function needs_low_walking_plan(string $travellerType, string $accessibilityNeeds): bool
{
    $type = strtolower(trim($travellerType));
    if (in_array($type, ["family", "elderly"], true)) return true;
    return has_accessibility_keyword($accessibilityNeeds, [
        "elderly",
        "wheelchair",
        "disabled",
        "avoid stairs",
        "avoid_stairs",
        "low walking",
        "low_walking",
        "less walking",
        "avoid long walk",
        "mobility",
        "step_free",
    ]);
}

function prefers_indoor_accessibility(string $accessibilityNeeds): bool
{
    return has_accessibility_keyword($accessibilityNeeds, [
        "indoor",
        "indoor_preferred",
        "avoid heat",
        "avoid_heat",
        "too hot",
        "hot weather",
    ]);
}

// ---- Daily distance limit by transport and travel pace ----
function get_daily_max_km(string $transportType, string $travelPace = "normal", string $travellerType = "solo", string $accessibilityNeeds = ""): float
{
    $t = strtolower(trim(str_replace("-", "_", $transportType)));
    $t = preg_replace("/\s+/", "_", $t) ?? $t;
    $pace = strtolower(trim($travelPace));

    if ($t === "walking") {
        $limit = match ($pace) {
            "relaxed" => 3.0,
            "packed" => 7.0,
            default => 5.0,
        };
    } elseif (in_array($t, ["public", "public_transport", "publictransit", "public_transit", "transit", "bus", "train"], true)) {
        $limit = match ($pace) {
            "relaxed" => 15.0,
            "packed" => 35.0,
            default => 25.0,
        };
    } elseif (in_array($t, ["car", "drive", "driving", "motorcycle"], true)) {
        $limit = match ($pace) {
            "relaxed" => 30.0,
            "packed" => 60.0,
            default => 45.0,
        };
    } else {
        $limit = 35.0;
    }

    if (needs_low_walking_plan($travellerType, $accessibilityNeeds)) {
        $limit *= 0.75;
    }
    return max(2.0, $limit);
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
function take_compact_from_pool(array &$pool, int $k, float $maxKm, bool $budgetAware = false): array
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

    if ($budgetAware) {
        usort($validIdx, fn($a, $b) => place_entrance_fee($pool[$a]) <=> place_entrance_fee($pool[$b]));
    } else {
        shuffle($validIdx);
    }
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

            // Keep the pool intact until an item is actually inserted. This prevents
            // a day becoming empty when a preselected item is later skipped by
            // opening-hour or festival-date checks.
            return $selected;
        }
    }

    return [];
}

function take_loose_from_pool(array &$pool, int $k, bool $budgetAware = false): array
{
    if ($k <= 0 || empty($pool)) return [];

    if ($budgetAware) {
        usort($pool, fn($a, $b) => place_entrance_fee($a) <=> place_entrance_fee($b));
    } else {
        shuffle($pool);
    }

    $take = min($k, count($pool));
    $selected = array_slice($pool, 0, $take);

    // Keep the pool intact until insert succeeds; usedPlaceIds removes real inserts.
    return $selected;
}

function pace_buffer_minutes(string $travelPace, string $travellerType = "solo", string $accessibilityNeeds = ""): int
{
    $buffer = match (strtolower(trim($travelPace))) {
        "relaxed" => 45,
        "packed" => 15,
        default => 30,
    };
    $type = strtolower(trim($travellerType));
    if ($type === "family") $buffer += 15;
    if ($type === "group") $buffer += 10;
    if (needs_low_walking_plan($travellerType, $accessibilityNeeds)) $buffer += 20;
    return min(80, $buffer);
}

function is_food_place(array $place): bool
{
    return strtolower((string)($place["category"] ?? "")) === "food";
}

function daily_activity_quota(string $travelPace): int
{
    return match (strtolower(trim($travelPace))) {
        "relaxed" => 3,
        "packed" => 5,
        default => 4,
    };
}

function daily_food_hunter_quota(string $travelPace): int
{
    return match (strtolower(trim($travelPace))) {
        "relaxed" => 4,
        "packed" => 6,
        default => 5,
    };
}

function adjusted_activity_quota(int $baseQuota, string $travellerType, string $accessibilityNeeds, bool $foodHunterMode): int
{
    $quota = $baseQuota;
    $type = strtolower(trim($travellerType));

    if (!$foodHunterMode && $type === "family") $quota--;
    if (!$foodHunterMode && needs_low_walking_plan($travellerType, $accessibilityNeeds)) $quota--;
    if ($foodHunterMode && $type === "group") $quota++;

    $min = $foodHunterMode ? 4 : 2;
    $max = $foodHunterMode ? 6 : 5;
    return max($min, min($max, $quota));
}

function traveller_category_priority(array $place, string $travellerType, string $accessibilityNeeds): int
{
    $category = strtolower((string)($place["category"] ?? ""));
    $duration = (int)($place["visit_duration_min"] ?? 90);
    $type = strtolower(trim($travellerType));

    if (needs_low_walking_plan($travellerType, $accessibilityNeeds)) {
        if (in_array($category, ["museum", "culture", "shopping", "food"], true)) return 0;
        if ($category === "heritage" && $duration <= 90) return 1;
        if (in_array($category, ["nature", "festival"], true)) return 4;
        return 2;
    }

    if (prefers_indoor_accessibility($accessibilityNeeds)) {
        if (in_array($category, ["museum", "shopping", "culture", "food"], true)) return 0;
        if (in_array($category, ["nature", "festival"], true)) return 3;
        return 1;
    }

    if ($type === "couple") {
        if (in_array($category, ["nature", "culture", "heritage", "food"], true)) return 0;
        return 1;
    }

    if ($type === "family") {
        if (in_array($category, ["nature", "museum", "shopping", "food"], true) && $duration <= 150) return 0;
        if (in_array($category, ["culture", "heritage"], true)) return 1;
        return 2;
    }

    if ($type === "group") {
        if (in_array($category, ["food", "shopping", "culture", "nature"], true)) return 0;
        return 1;
    }

    return 1;
}

function sort_pool_by_preference(array &$pool, string $travellerType, string $accessibilityNeeds, bool $budgetAware): void
{
    usort($pool, function ($a, $b) use ($travellerType, $accessibilityNeeds, $budgetAware) {
        $pa = traveller_category_priority($a, $travellerType, $accessibilityNeeds);
        $pb = traveller_category_priority($b, $travellerType, $accessibilityNeeds);
        if ($pa !== $pb) return $pa <=> $pb;
        if ($budgetAware) return place_entrance_fee($a) <=> place_entrance_fee($b);
        return strcmp((string)($a["name"] ?? ""), (string)($b["name"] ?? ""));
    });
}

function reason_selected_text(array $place, array $preferredCategories, float $budget, ?float $distanceKm, string $travellerType, string $accessibilityNeeds, string $transportType): string
{
    $category = strtolower((string)($place["category"] ?? ""));
    $bits = [];
    if (in_array($category, $preferredCategories, true)) {
        $bits[] = "matches " . $category . " interest";
    } elseif ($category === "food") {
        $bits[] = "adds meal stop";
    }
    if (!empty($place["district"])) {
        $bits[] = "within " . (string)$place["district"];
    } elseif (!empty($place["state"])) {
        $bits[] = "within " . (string)$place["state"];
    }
    if ($budget > 0) {
        $bits[] = "fits budget";
    }
    if ($distanceKm !== null) {
        $bits[] = "near previous stop";
    } else {
        $bits[] = "first suitable stop";
    }
    if (needs_low_walking_plan($travellerType, $accessibilityNeeds)) {
        $bits[] = "low-walking plan";
    } elseif (prefers_indoor_accessibility($accessibilityNeeds)) {
        $bits[] = "indoor/heat-aware plan";
    } elseif (strtolower(trim($travellerType)) === "couple") {
        $bits[] = "scenic/relaxed preference";
    } elseif (strtolower(trim($travellerType)) === "group") {
        $bits[] = "group-friendly category";
    }
    $bits[] = ucwords(str_replace("_", " ", $transportType)) . " route";
    return implode("; ", array_slice($bits, 0, 5));
}

function category_mode_allows(array $place, string $mode): bool
{
    if ($mode === "food") return is_food_place($place);
    if ($mode === "activity") return !is_food_place($place);
    return true;
}

function food_slot_target_minutes(string $slot): int
{
    return match ($slot) {
        "Breakfast" => 8 * 60,
        "Brunch" => 10 * 60 + 30,
        "Lunch" => 12 * 60 + 30,
        "Tea" => 15 * 60 + 30,
        "Dinner" => 18 * 60 + 30,
        "Supper" => 20 * 60 + 30,
        default => 0,
    };
}

function food_hunter_slots(int $count): array
{
    if ($count >= 6) return ["Breakfast", "Brunch", "Lunch", "Tea", "Dinner", "Supper"];
    if ($count >= 5) return ["Breakfast", "Brunch", "Lunch", "Tea", "Dinner"];
    return ["Breakfast", "Lunch", "Tea", "Dinner"];
}

function mark_food_slot(array $place, string $slot): array
{
    $place["_meal_slot"] = $slot;
    $place["_slot_target_min"] = food_slot_target_minutes($slot);
    return $place;
}

function nearest_food_for_slot(
    array $byState,
    array $stateOrder,
    array $reservedIds,
    ?array $anchor,
    string $slot,
    ?string $dayDate,
    float $maxKm
): ?array {
    $targetMin = food_slot_target_minutes($slot);
    $best = null;
    $bestScore = PHP_FLOAT_MAX;

    foreach ($stateOrder as $stateRank => $state) {
        if (!isset($byState[$state])) continue;

        foreach ($byState[$state] as $place) {
            $placeId = (int)($place["place_id"] ?? 0);
            if ($placeId <= 0 || isset($reservedIds[$placeId])) continue;
            if (!is_food_place($place)) continue;
            if (!valid_coord($place["latitude"] ?? null, $place["longitude"] ?? null)) continue;
            if (!festival_available_on_date($place, $dayDate)) continue;
            if (!opening_hours_allows($place["opening_hours"] ?? null, $targetMin)) continue;

            $distance = 0.0;
            if ($anchor !== null && valid_coord($anchor["latitude"] ?? null, $anchor["longitude"] ?? null)) {
                $distance = haversine_km(
                    (float)$anchor["latitude"],
                    (float)$anchor["longitude"],
                    (float)$place["latitude"],
                    (float)$place["longitude"]
                );
                if ($distance > $maxKm) continue;
            }

            $score = ($stateRank * 1000) + $distance;
            if ($best === null || $score < $bestScore) {
                $best = $place;
                $bestScore = $score;
            }
        }
    }

    return $best === null ? null : mark_food_slot($best, $slot);
}

function pick_standard_meal_stops(
    array $byState,
    array $stateOrder,
    array $activities,
    array $usedPlaceIds,
    ?string $dayDate,
    float $maxKm
): array {
    if (empty($activities)) return [];

    $anchors = [
        "Breakfast" => $activities[0],
        "Lunch" => $activities[(int)floor((count($activities) - 1) / 2)],
        "Dinner" => $activities[count($activities) - 1],
    ];

    $meals = [];
    $reservedIds = $usedPlaceIds;
    foreach (["Breakfast", "Lunch", "Dinner"] as $slot) {
        $meal = nearest_food_for_slot($byState, $stateOrder, $reservedIds, $anchors[$slot], $slot, $dayDate, $maxKm);
        if ($meal === null) continue;
        $meals[$slot] = $meal;
        $reservedIds[(int)$meal["place_id"]] = true;
    }

    return $meals;
}

function compose_standard_day_plan(array $activities, array $meals): array
{
    $plan = [];
    if (isset($meals["Breakfast"])) $plan[] = $meals["Breakfast"];

    $activityCount = count($activities);
    $beforeLunch = min(1, $activityCount);

    foreach (array_slice($activities, 0, $beforeLunch) as $activity) $plan[] = $activity;
    if (isset($meals["Lunch"])) $plan[] = $meals["Lunch"];
    foreach (array_slice($activities, $beforeLunch) as $activity) $plan[] = $activity;
    if (isset($meals["Dinner"])) $plan[] = $meals["Dinner"];

    return $plan;
}

function mark_food_hunter_plan(array $foods): array
{
    $slots = food_hunter_slots(count($foods));
    $plan = [];
    foreach ($foods as $idx => $food) {
        $plan[] = mark_food_slot($food, $slots[$idx] ?? ("Food Stop " . ($idx + 1)));
    }
    return $plan;
}

// ---- Google optimize ordering (real) ----
function transport_to_google_mode(string $transportType): string
{
    $t = strtolower(trim(str_replace("-", "_", $transportType)));
    $t = preg_replace("/\s+/", "_", $t) ?? $t;
    if ($t === "walking") return "walking";
    if (in_array($t, ["public", "public_transport", "publictransit", "public_transit", "transit", "bus", "train"], true)) return "transit";
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

function table_has_column(mysqli $conn, string $table, string $column): bool
{
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return ($res && $res->num_rows > 0);
}

function table_exists(mysqli $conn, string $table): bool
{
    $table = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '$table'");
    return ($res && $res->num_rows > 0);
}

function preference_interest_csv(mysqli $conn, int $preferenceId, string $fallbackCsv): string
{
    $values = [];
    if (table_exists($conn, "traveller_preference_interests") && table_exists($conn, "travel_interests")) {
        $stmt = $conn->prepare("
            SELECT ti.interest_code
            FROM traveller_preference_interests tpi
            JOIN travel_interests ti ON ti.interest_id = tpi.interest_id
            WHERE tpi.preference_id = ?
            ORDER BY ti.interest_code
        ");
        if ($stmt) {
            $stmt->bind_param("i", $preferenceId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) $values[] = (string)$row["interest_code"];
            $stmt->close();
        }
    }
    return empty($values) ? trim($fallbackCsv) : implode(",", array_values(array_unique($values)));
}

function preference_state_csv(mysqli $conn, int $preferenceId, string $fallbackCsv): string
{
    $values = [];
    if (table_exists($conn, "traveller_preference_states") && table_exists($conn, "malaysia_states")) {
        $stmt = $conn->prepare("
            SELECT ms.state_name
            FROM traveller_preference_states tps
            JOIN malaysia_states ms ON ms.state_id = tps.state_id
            WHERE tps.preference_id = ?
            ORDER BY ms.state_name
        ");
        if ($stmt) {
            $stmt->bind_param("i", $preferenceId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) $values[] = (string)$row["state_name"];
            $stmt->close();
        }
    }
    return empty($values) ? trim($fallbackCsv) : implode(",", array_values(array_unique($values)));
}

function daily_start_minutes(string $preferredVisitTime): int
{
    return match (strtolower(trim($preferredVisitTime))) {
        "morning" => 8 * 60,
        "afternoon" => 12 * 60 + 30,
        "evening" => 16 * 60,
        default => 8 * 60,
    };
}

function projected_trip_total_cost(
    float $attractionCost,
    float $scheduledFoodCost,
    float $distanceKm,
    string $transportType,
    int $tripDays,
    float $hotelRate,
    float $mealPrice,
    string $travellerType = "solo"
): float {
    $nights = max(0, $tripDays - 1);
    $foodMinimum = $tripDays * 3 * $mealPrice * CostEstimationService::travellerMultiplier($travellerType);
    $transportCost = $distanceKm * CostEstimationService::getTransportRate($transportType);
    return round($attractionCost + max($foodMinimum, $scheduledFoodCost) + ($nights * $hotelRate) + $transportCost, 2);
}

function sql_time_from_minutes(int $minutes): string
{
    $minutes = max(0, min(23 * 60 + 59, $minutes));
    return sprintf("%02d:%02d:00", intdiv($minutes, 60), $minutes % 60);
}

function parse_clock_to_minutes(string $raw): ?int
{
    $raw = strtolower(trim($raw));
    if ($raw === "") return null;

    if (!preg_match('/(\d{1,2})(?::?(\d{2}))?\s*(am|pm)?/', $raw, $m)) {
        return null;
    }

    $hour = (int)$m[1];
    $minute = isset($m[2]) && $m[2] !== "" ? (int)$m[2] : 0;
    $ampm = $m[3] ?? "";

    if ($ampm === "pm" && $hour < 12) $hour += 12;
    if ($ampm === "am" && $hour === 12) $hour = 0;
    if ($hour > 23 || $minute > 59) return null;

    return $hour * 60 + $minute;
}

function opening_hours_allows(?string $openingHours, int $startMin): bool
{
    $text = strtolower(trim((string)$openingHours));
    if ($text === "" || strpos($text, "24 hour") !== false) return true;
    if ($text === "closed" || $text === "temporarily closed" || $text === "permanently closed") return false;

    $normalized = str_replace(["–", "—", "to"], "-", $text);
    if (!preg_match('/([0-9]{1,2}(?::?[0-9]{2})?\s*(?:am|pm)?)\s*-\s*([0-9]{1,2}(?::?[0-9]{2})?\s*(?:am|pm)?)/i', $normalized, $m)) {
        return true; // Do not reject records with non-standard opening text.
    }

    $open = parse_clock_to_minutes($m[1]);
    $close = parse_clock_to_minutes($m[2]);
    if ($open === null || $close === null) return true;

    if ($close < $open) {
        return $startMin >= $open || $startMin <= $close; // Overnight range.
    }

    return $startMin >= $open && $startMin <= $close;
}

function place_visit_duration_min(array $place): int
{
    $category = strtolower((string)($place["category"] ?? ""));
    if ($category === "food") return 60;

    $configured = (int)($place["visit_duration_min"] ?? 0);
    if ($configured > 0) return max(30, min(360, $configured));

    return match ($category) {
        "festival" => 150,
        "museum", "heritage", "culture", "nature" => 120,
        "shopping" => 90,
        default => 90,
    };
}

function place_entrance_fee(array $place): float
{
    if (array_key_exists("entrance_fee", $place) && $place["entrance_fee"] !== null) {
        return max(0.0, (float)$place["entrance_fee"]);
    }
    return max(0.0, (float)($place["estimated_cost"] ?? 0));
}

function trip_end_date(?string $startDate, int $tripDays): ?string
{
    if ($startDate === null || $startDate === "") return null;
    $dt = DateTime::createFromFormat("Y-m-d", $startDate);
    if (!$dt) return null;
    $dt->modify("+" . max(0, $tripDays - 1) . " days");
    return $dt->format("Y-m-d");
}

function itinerary_day_date(?string $startDate, int $dayNo): ?string
{
    if ($startDate === null || $startDate === "") return null;
    $dt = DateTime::createFromFormat("Y-m-d", $startDate);
    if (!$dt) return null;
    $dt->modify("+" . max(0, $dayNo - 1) . " days");
    return $dt->format("Y-m-d");
}

function festival_available_on_date(array $place, ?string $date): bool
{
    if (strtolower((string)($place["category"] ?? "")) !== "festival") return true;
    if ($date === null || $date === "") return false;

    $start = trim((string)($place["festival_start_date"] ?? ""));
    $end = trim((string)($place["festival_end_date"] ?? ""));
    if ($start === "" || $end === "") return false;

    return $start <= $date && $end >= $date;
}

function remove_unavailable_festivals_for_day(array &$pool, ?string $dayDate): void
{
    $filtered = [];
    foreach ($pool as $place) {
        if (festival_available_on_date($place, $dayDate)) $filtered[] = $place;
    }
    $pool = $filtered;
}

function filter_and_top_up_day_selection(
    array $selected,
    array $byState,
    array $stateOrder,
    int $activityQuota,
    array $usedPlaceIds,
    ?string $dayDate,
    int $dayStartMin,
    string $categoryMode = "all"
): array {
    $result = [];
    $selectedIds = [];

    $appendIfUsable = function (array $place) use (&$result, &$selectedIds, $activityQuota, $usedPlaceIds, $dayDate, $dayStartMin, $categoryMode): bool {
        if (count($result) >= $activityQuota) return false;

        $placeId = (int)($place["place_id"] ?? 0);
        if ($placeId <= 0) return false;
        if (isset($usedPlaceIds[$placeId]) || isset($selectedIds[$placeId])) return false;
        if (!category_mode_allows($place, $categoryMode)) return false;
        if (!valid_coord($place["latitude"] ?? null, $place["longitude"] ?? null)) return false;
        if (!festival_available_on_date($place, $dayDate)) return false;
        if ($categoryMode !== "food" && !opening_hours_allows($place["opening_hours"] ?? null, $dayStartMin)) return false;

        $result[] = $place;
        $selectedIds[$placeId] = true;
        return true;
    };

    foreach ($selected as $place) {
        $appendIfUsable($place);
    }

    if (count($result) >= $activityQuota) return $result;

    foreach ($stateOrder as $state) {
        if (!isset($byState[$state])) continue;
        foreach ($byState[$state] as $place) {
            $appendIfUsable($place);
            if (count($result) >= $activityQuota) return $result;
        }
    }

    return $result;
}

// ===================== 1) LOAD PREFERENCE =====================
// Check if preferred_districts column exists (graceful fallback)
$colCheck = $conn->query("SHOW COLUMNS FROM traveller_preferences LIKE 'preferred_districts'");
$hasDistrictCol = ($colCheck && $colCheck->num_rows > 0);

$prefCols = "*";

$stmt = $conn->prepare("
  SELECT $prefCols
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

$tripDays      = (int)$pref["trip_days"];
$budget        = (float)($pref["budget"] ?? 0);
$budgetTier    = (string)($pref["budget_tier"] ?? "normal");
$travelPace    = (string)($pref["travel_pace"] ?? "normal");
$travellerType = strtolower(trim((string)($pref["traveller_type"] ?? "solo")));
$accessibilityNeeds = strtolower(trim((string)($pref["accessibility_needs"] ?? "")));
$dietaryPreference = (string)($pref["dietary_preference"] ?? "none");
$preferredVisitTime = (string)($pref["preferred_visit_time"] ?? "any");
$transportType = RouteService::normalizeTransportType((string)($pref["transport_type"] ?? "car"));
$interestsCsv  = preference_interest_csv($conn, $preferenceId, (string)($pref["interests"] ?? ""));
$statesCsv     = preference_state_csv($conn, $preferenceId, (string)($pref["preferred_states"] ?? ""));
$districtsCsv  = trim((string)($pref["preferred_districts"] ?? ""));
$tripEndDate = trip_end_date($sd, $tripDays);

// The daily quota is for cultural/activity stops. Meal stops are added around
// those places. A food-only preference becomes a denser food trail instead.
$activityQuota = daily_activity_quota($travelPace);

// For title only
$titleStatesCsv = ($statesCsv === "") ? "Malaysia" : $statesCsv;

// Build state filter list
$states = $statesCsv !== "" ? array_values(array_unique(array_filter(array_map("trim", explode(",", $statesCsv))))) : [];
$statesLower = array_map("strtolower", $states);

// If empty or contains "Malaysia", do NOT filter by state
if ($statesCsv === "" || in_array("malaysia", $statesLower, true)) {
    $states = [];
}

// Build district filter list (only used when districts are explicitly selected)
$districts = $districtsCsv !== "" ? array_values(array_unique(array_filter(array_map("trim", explode(",", $districtsCsv))))) : [];
$userSelectedDistricts = !empty($districts);

$allowedCategories = ["culture", "heritage", "museum", "food", "festival", "nature", "shopping"];
$preferredCategories = $interestsCsv !== "" ? array_values(array_unique(array_filter(array_map("trim", explode(",", $interestsCsv))))) : [];
$preferredCategories = array_values(array_intersect($preferredCategories, $allowedCategories));
$foodHunterMode = count($preferredCategories) === 1 && $preferredCategories[0] === "food";
$categories = empty($preferredCategories) ? $allowedCategories : $preferredCategories;
if (!$foodHunterMode && !in_array("food", $categories, true)) {
    $categories[] = "food";
}
if ($foodHunterMode) {
    $activityQuota = daily_food_hunter_quota($travelPace);
}
$activityQuota = adjusted_activity_quota($activityQuota, $travellerType, $accessibilityNeeds, $foodHunterMode);

// Determine if user explicitly selected states (not Malaysia)
$statesCsvLowerList = array_map("strtolower", normalize_list($statesCsv));
$userSelectedStates = (!empty($statesCsv) && !in_array("malaysia", $statesCsvLowerList, true));
$preferredStatesForOrder = $userSelectedStates ? normalize_list($statesCsv) : [];

// ===================== 2) FETCH CANDIDATE PLACES =====================
// Check if district column exists in cultural_places
$hasDistrictPlaceCol = table_has_column($conn, "cultural_places", "district");
$hasEntranceFeeCol = table_has_column($conn, "cultural_places", "entrance_fee");
$hasVisitDurationCol = table_has_column($conn, "cultural_places", "visit_duration_min");
$hasHalalCol = table_has_column($conn, "cultural_places", "halal_status");
$hasFestivalDateCols = table_has_column($conn, "cultural_places", "festival_start_date")
    && table_has_column($conn, "cultural_places", "festival_end_date");

$where  = "is_active = 1";
$params = [];
$types  = "";

$catPH = implode(",", array_fill(0, count($categories), "?"));
$where .= " AND category IN ($catPH)";
$types .= str_repeat("s", count($categories));
$params = array_merge($params, $categories);

// District filter takes priority over state filter (more specific)
if ($userSelectedDistricts && $hasDistrictPlaceCol) {
    $dtPH   = implode(",", array_fill(0, count($districts), "?"));
    $where .= " AND district IN ($dtPH)";
    $types .= str_repeat("s", count($districts));
    $params = array_merge($params, $districts);
} elseif (!empty($states)) {
    $stPH   = implode(",", array_fill(0, count($states), "?"));
    $where .= " AND state IN ($stPH)";
    $types .= str_repeat("s", count($states));
    $params = array_merge($params, $states);
}

if ($dietaryPreference === "halal" && $hasHalalCol) {
    $where .= " AND (category <> 'food' OR halal_status = 1)";
}

// Festival records are date-specific events. If the trip has no start date, or
// the event does not overlap the trip window, do not suggest it as a normal place.
if ($hasFestivalDateCols) {
    if ($sd !== null && $tripEndDate !== null) {
        $where .= " AND (category <> 'festival' OR (festival_start_date IS NOT NULL AND festival_end_date IS NOT NULL AND festival_start_date <= ? AND festival_end_date >= ?))";
        $types .= "ss";
        $params[] = $tripEndDate;
        $params[] = $sd;
    } else {
        $where .= " AND category <> 'festival'";
    }
} elseif (in_array("festival", $categories, true)) {
    $where .= " AND category <> 'festival'";
}

$districtSelectCol = $hasDistrictPlaceCol ? ", district" : "";
$entranceFeeSelectCol = $hasEntranceFeeCol ? ", entrance_fee, is_free" : "";
$visitDurationSelectCol = $hasVisitDurationCol ? ", visit_duration_min" : "";
$festivalDateSelectCol = $hasFestivalDateCols ? ", festival_start_date, festival_end_date" : "";
$sql = "
  SELECT place_id, state{$districtSelectCol}, category, name, description, address, latitude, longitude, opening_hours, estimated_cost{$entranceFeeSelectCol}{$visitDurationSelectCol}{$festivalDateSelectCol}
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

$hasOriginColumns = table_has_column($conn, "itineraries", "origin_name")
    && table_has_column($conn, "itineraries", "origin_lat")
    && table_has_column($conn, "itineraries", "origin_lng");

if ($hasOriginColumns) {
    $originNameDb = $originName !== "" ? $originName : null;
    $originLatDb = $hasOrigin ? $originLat : null;
    $originLngDb = $hasOrigin ? $originLng : null;
    $stmt = $conn->prepare("
      INSERT INTO itineraries
        (traveller_id, preference_id, title, start_date, total_days, origin_name, origin_lat, origin_lng, total_estimated_cost)
      VALUES
        (?,?,?,?,?,?,?,?,0.00)
    ");
    $stmt->bind_param("iissisdd", $travellerId, $preferenceId, $title, $sd, $tripDays, $originNameDb, $originLatDb, $originLngDb);
} else {
    $stmt = $conn->prepare("
      INSERT INTO itineraries
        (traveller_id, preference_id, title, start_date, total_days, total_estimated_cost)
      VALUES
        (?,?,?,?,?,0.00)
    ");
    $stmt->bind_param("iissi", $travellerId, $preferenceId, $title, $sd, $tripDays);
}

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
    header("Location: itinerary_review.php?itinerary_id=" . $itineraryId);
    exit;
}

// ===================== 4) GROUP BY STATE (or DISTRICT when selected) =====================
// When user selected specific districts, group by "State > District" for finer routing.
// Otherwise group by state only (existing behaviour).
$byState = [];
foreach ($places as $p) {
    $st = (string)($p["state"] ?? "");
    if ($st === "") $st = "Unknown";

    if ($userSelectedDistricts && isset($p["district"]) && $p["district"] !== "") {
        // Key = "State > District" so nearby-routing stays within district
        $key = $st . " > " . (string)$p["district"];
    } else {
        $key = $st;
    }
    $byState[$key][] = $p;
}

// Shuffle each pool once. When a budget is entered, put cheaper places earlier
// so the generator plans inside the user's RM limit instead of only checking
// the budget after generation.
foreach ($byState as $st => $pool) {
    shuffle($pool);
    sort_pool_by_preference($pool, $travellerType, $accessibilityNeeds, $budget > 0);
    $byState[$st] = $pool;
}

// ===================== 5) PREPARE INSERT STATEMENT =====================
$ins = $conn->prepare("
  INSERT INTO itinerary_items
    (itinerary_id, day_no, sequence_no, item_type, place_id, item_title, start_time, end_time, estimated_cost, distance_km, travel_time_min, notes)
  VALUES
    (?,?,?,?,?,?,?,?,?,?,?,?)
");
if (!$ins) {
    // Do not block: redirect to view with message
    $_SESSION["success_message"] = "Itinerary created, but cannot insert items right now.";
    header("Location: itinerary_review.php?itinerary_id=" . $itineraryId);
    exit;
}

// Instantiate RouteService for distance/time calculation
$apiKey    = defined("GOOGLE_MAPS_API_KEY") ? GOOGLE_MAPS_API_KEY : "";
$routeSvc  = new RouteService($transportType, $apiKey);

$totalCost = 0.0;
$insertedItems = [];
$totalDistanceKm = 0.0;
$tierDefaults = CostEstimationService::budgetTierDefaults($budgetTier, $budget, $tripDays);
$projectedAttractionCost = 0.0;
$projectedFoodCost = 0.0;
$maxDayKm  = get_daily_max_km($transportType, $travelPace, $travellerType, $accessibilityNeeds);

// Used place ids (rule #1)
$usedPlaceIds = []; // place_id => true
$dayStartMin = daily_start_minutes($preferredVisitTime);

// Track previous location for distance calculation
// Start from user's origin if provided
$prevLat = $hasOrigin ? $originLat : null;
$prevLng = $hasOrigin ? $originLng : null;

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
    $dayCursorMin = $dayStartMin; // User preferred visit time, default 8:00 AM.
    $dayDate = itinerary_day_date($sd, $dayNo);

    // Remove used items from every pool (safety)
    foreach ($byState as $st => $pool) {
        remove_used_from_pool($pool, $usedPlaceIds);
        remove_unavailable_festivals_for_day($pool, $dayDate);
        $byState[$st] = $pool;
    }

    // Candidates: same state -> neighbors -> others (rule #3)
    $candidates = build_day_state_candidates($currentState, $byState);

    $chosenState = null;
    $selected = [];

    foreach ($candidates as $st) {
        if (!isset($byState[$st]) || empty($byState[$st])) continue;

        $dayPool = array_values(array_filter(
            $byState[$st],
            fn($place) => category_mode_allows($place, $foodHunterMode ? "food" : "activity")
        ));
        sort_pool_by_preference($dayPool, $travellerType, $accessibilityNeeds, $budget > 0);

        // Try compact first (nearby), then loose (still no reuse).
        $try = take_compact_from_pool($dayPool, $activityQuota, $maxDayKm, $budget > 0);
        if (empty($try)) {
            $try = take_loose_from_pool($dayPool, $activityQuota, $budget > 0);
        }

        if (!empty($try)) {
            $chosenState = $st;
            $selected = $try;
            break;
        }
    }

    if (!empty($selected) && $chosenState !== null) {
        // Remove unusable candidates before ordering, then top up from available
        // nearby pools so a valid day does not become empty after schedule checks.
        $selected = filter_and_top_up_day_selection(
            $selected,
            $byState,
            $candidates,
            $activityQuota,
            $usedPlaceIds,
            $dayDate,
            $dayStartMin,
            $foodHunterMode ? "food" : "activity"
        );
        if (empty($selected)) {
            $currentState = pick_start_state_best($byState) ?? $currentState;
            continue;
        }

        // Route ordering mode (both have real function)
        if ($routeStrategy === "google_optimize") {
            $opt = order_google_optimize($selected, $transportType);
            if ($opt !== null) $selected = $opt;
            else $selected = order_nearest_next($selected); // fallback
        } elseif ($routeStrategy === "nearest_next") {
            $selected = order_nearest_next($selected);
        }

        if ($foodHunterMode) {
            $selected = mark_food_hunter_plan($selected);
        } else {
            $mealStops = pick_standard_meal_stops($byState, $candidates, $selected, $usedPlaceIds, $dayDate, $maxDayKm);
            $selected = compose_standard_day_plan($selected, $mealStops);
        }

        // Insert items for this day (ONE state/day => no East/West mixing in same day)
        $seq = 1;
        foreach ($selected as $p) {
            $placeId = (int)$p["place_id"];
            if ($placeId <= 0) continue;
            if (isset($usedPlaceIds[$placeId])) continue; // hard rule #1
            if (!festival_available_on_date($p, $dayDate)) continue;

            $name = (string)$p["name"];
            $fee  = place_entrance_fee($p);
            $cat  = strtolower((string)$p["category"]);

            $itemType = ($cat === "food") ? "food" : (($cat === "festival") ? "festival" : "attraction");
            $districtLabel = (isset($p["district"]) && $p["district"] !== "") ? " | District: " . (string)$p["district"] : "";
            $notes    = "State: " . (string)$p["state"] . $districtLabel . " | Category: " . $cat;
            if ($itemType === "food" && !empty($p["_meal_slot"])) {
                $notes .= " | Meal: " . (string)$p["_meal_slot"];
            }

            // ---- RouteService: calculate distance & travel time from previous place ----
            $distKm   = null;
            $timeMin  = null;
            $pLat     = isset($p["latitude"])  ? (float)$p["latitude"]  : null;
            $pLng     = isset($p["longitude"]) ? (float)$p["longitude"] : null;

            if ($prevLat !== null && $prevLng !== null && $pLat !== null && $pLng !== null
                && !($pLat === 0.0 && $pLng === 0.0)) {
                $seg     = $routeSvc->getSegment($prevLat, $prevLng, $pLat, $pLng);
                $distKm  = $seg["distance_km"];
                $timeMin = $seg["travel_time_min"];
            }

            $nextAttractionCost = $projectedAttractionCost;
            $nextFoodCost = $projectedFoodCost;
            if ($itemType === "food") {
                $nextFoodCost += $fee;
            } else {
                $nextAttractionCost += $fee;
            }
            $nextDistanceKm = $totalDistanceKm + (float)($distKm ?? 0);
            $projectedCost = projected_trip_total_cost(
                $nextAttractionCost,
                $nextFoodCost,
                $nextDistanceKm,
                $transportType,
                $tripDays,
                (float)$tierDefaults["hotel"],
                (float)$tierDefaults["meal"],
                $travellerType
            );
            if ($budget > 0 && $projectedCost > $budget) {
                continue;
            }

            $candidateStartMin = $dayCursorMin;
            if ($timeMin !== null) {
                // Includes the trip origin -> first stop leg when a start location exists.
                $candidateStartMin += (int)$timeMin;
            } elseif ($seq > 1) {
                $candidateStartMin += (int)(($transportType === "walking") ? 20 : 15);
            }
            if (!empty($p["_slot_target_min"])) {
                $candidateStartMin = max($candidateStartMin, (int)$p["_slot_target_min"]);
            }

            if (!opening_hours_allows($p["opening_hours"] ?? null, $candidateStartMin)) {
                continue;
            }

            $durationMin = place_visit_duration_min($p);
            $dayCursorMin = $candidateStartMin;
            $startTime = sql_time_from_minutes($dayCursorMin);
            $endTime = sql_time_from_minutes($dayCursorMin + $durationMin);
            $reason = reason_selected_text($p, $preferredCategories, $budget, $distKm, $travellerType, $accessibilityNeeds, $transportType);
            $notes = substr($notes . " | Reason: " . $reason, 0, 255);

            // Update previous location
            if ($pLat !== null && $pLng !== null && !($pLat === 0.0 && $pLng === 0.0)) {
                $prevLat = $pLat;
                $prevLng = $pLng;
            }

            $ins->bind_param(
                "iiisisssddis",
                $itineraryId,
                $dayNo,
                $seq,
                $itemType,
                $placeId,
                $name,
                $startTime,
                $endTime,
                $fee,
                $distKm,
                $timeMin,
                $notes
            );

            // Never die; if fail, skip
            if ($ins->execute()) {
                $totalCost += $fee;
                $totalDistanceKm += (float)($distKm ?? 0);
                $insertedItems[] = ["estimated_cost" => $fee, "item_type" => $itemType];
                $projectedAttractionCost = $nextAttractionCost;
                $projectedFoodCost = $nextFoodCost;
                $usedPlaceIds[$placeId] = true;
                $seq++;
                $dayCursorMin += $durationMin + pace_buffer_minutes($travelPace, $travellerType, $accessibilityNeeds);
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
$costService = new CostEstimationService($transportType, $tripDays, $budget, $travellerType);
$fullCost = $costService->calculate($insertedItems, $totalDistanceKm, $tierDefaults["hotel"], 3, $tierDefaults["meal"]);
$totalCost = (float)($fullCost["total_cost"] ?? $totalCost);

$upd = $conn->prepare("UPDATE itineraries SET total_estimated_cost = ?, total_days = ? WHERE itinerary_id = ?");
$upd->bind_param("dii", $totalCost, $tripDays, $itineraryId);
$upd->execute();
$upd->close();

// ===================== 8) REDIRECT → Review page =====================
// Send user to the review page so they can accept/reject places before viewing the final itinerary.
header("Location: itinerary_review.php?itinerary_id=" . $itineraryId);
exit;
